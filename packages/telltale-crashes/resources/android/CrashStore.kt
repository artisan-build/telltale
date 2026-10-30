package com.artisanbuild.plugins.telltale_crashes

import org.json.JSONArray
import org.json.JSONObject
import java.io.File
import java.io.FileOutputStream
import java.security.MessageDigest

// Worker-only disk core. No Android dependency: this actual source runs in the JVM harness.
class CrashStore(private val directory: File, private val launch: String, private val now: () -> Long = System::currentTimeMillis) {
    companion object {
        const val TTL = 7L * 24 * 60 * 60 * 1000
        const val MAX_REPORTS = 32
        const val MAX_BYTES = 256 * 1024
        fun digest(value: String): String = MessageDigest.getInstance("SHA-256").digest(value.toByteArray(Charsets.UTF_8))
            .joinToString("") { "%02x".format(it.toInt() and 255) }
    }
    private val entries = mutableMapOf<String, JSONObject>()
    private val seen = mutableMapOf<String, Long>()
    var suppressed = false
        private set
    private fun valid(id: String) = id.matches(Regex("[0-9a-f]{64}"))
    private fun file(id: String) = File(directory, "$id.json")

    private fun read(file: File, max: Int): JSONObject? = try {
        if (file.length() > max) null else file.inputStream().use { stream ->
            val output = java.io.ByteArrayOutputStream()
            val buffer = ByteArray(8192)
            while (output.size() <= max) {
                val count = stream.read(buffer, 0, minOf(buffer.size, max + 1 - output.size()))
                if (count < 0) break
                if (count == 0) throw java.io.IOException("no read progress")
                output.write(buffer, 0, count)
            }
            val bytes = output.toByteArray()
            if (bytes.size > max) null else JSONObject(String(bytes, Charsets.UTF_8))
        }
    } catch (_: Exception) { null }

    private fun write(file: File, value: JSONObject): Boolean {
        val temporary = File(directory, file.name + ".tmp")
        return try {
            FileOutputStream(temporary).use { output ->
                output.write(value.toString().toByteArray(Charsets.UTF_8))
                output.fd.sync()
            }
            if (!temporary.renameTo(file)) throw java.io.IOException("atomic rename failed")
            true
        } catch (_: Exception) { temporary.delete(); false }
    }

    fun load() {
        try {
            if (!directory.isDirectory && !directory.mkdirs()) return
            val state = read(File(directory, "state.json"), 64 * 1024) ?: JSONObject()
            suppressed = state.optBoolean("suppressed", false)
            val receipts = state.optJSONObject("seen") ?: JSONObject()
            for (id in receipts.keys()) if (valid(id) && receipts.optLong(id) >= now() - TTL) seen[id] = receipts.getLong(id)
            entries.clear()
            directory.listFiles()?.forEach { source ->
                if (source.extension == "tmp") { source.delete(); return@forEach }
                if (source.extension != "json" || source.name == "state.json") return@forEach
                val id = source.nameWithoutExtension
                val entry = read(source, MAX_BYTES)
                if (!valid(id) || suppressed || entry == null || entry.optInt("schema_version") != 1 ||
                    entry.optLong("stored_at") < now() - TTL || entry.optJSONObject("report")?.optString("report_id") != id) {
                    source.delete()
                } else entries[id] = entry
            }
            prune()
        } catch (_: Exception) { /* App-private storage can be unavailable. */ }
    }

    private fun prune() {
        entries.keys.toList().filter { entries[it]!!.optLong("stored_at") < now() - TTL }.forEach { remove(it) }
        entries.keys.sortedWith(compareBy<String> { entries[it]!!.optLong("stored_at") }.thenBy { it })
            .take(maxOf(0, entries.size - MAX_REPORTS)).forEach { remove(it) }
        seen.keys.toList().filter { seen[it]!! < now() - TTL }.forEach { seen.remove(it) }
        seen.keys.sortedByDescending { seen[it] }.drop(256).forEach { seen.remove(it) }
    }

    private fun state() = write(File(directory, "state.json"), JSONObject()
        .put("schema_version", 1).put("suppressed", suppressed).put("seen", JSONObject(seen as Map<*, *>)))
    private fun remove(id: String) { file(id).delete(); entries.remove(id) }
    fun hasSeen(id: String): Boolean = seen.containsKey(id) || entries.containsKey(id)

    // The history identity is the report identity, not a timestamp high-water mark.
    // Never advance seen until the report has been durably persisted.
    fun persist(report: JSONObject, currentProcess: Boolean): Boolean {
        val id = report.optString("report_id")
        if (suppressed || !valid(id)) return false
        if (hasSeen(id)) return true
        val entry = JSONObject().put("schema_version", 1).put("stored_at", now())
            .put("launch", if (currentProcess) launch else "historical").put("report", report)
        if (entry.toString().toByteArray(Charsets.UTF_8).size > MAX_BYTES || !write(file(id), entry)) return false
        entries[id] = entry
        seen[id] = now()
        prune()
        state() // If this fails, the durable report still proves seen on the next launch.
        return true
    }

    fun pending(): List<JSONObject> {
        prune()
        if (suppressed) return emptyList()
        return entries.keys.sorted().mapNotNull { id -> entries[id]!!.takeIf { it.optString("launch") != launch }?.getJSONObject("report") }
    }

    fun acknowledge(ids: List<String>): Boolean {
        val old = seen.toMap()
        ids.take(MAX_REPORTS).filter { valid(it) && entries.containsKey(it) }.forEach { seen[it] = now() }
        prune()
        if (!state()) { seen.clear(); seen.putAll(old); return false }
        ids.take(MAX_REPORTS).filter { valid(it) }.forEach { remove(it) }
        return true
    }

    fun setSuppressed(value: Boolean): Boolean {
        val old = suppressed
        suppressed = value
        if (!state()) { suppressed = old; return false }
        if (value) entries.keys.toList().forEach { remove(it) }
        return true
    }
}
