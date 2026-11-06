package com.artisanbuild.plugins.telltale_crashes

import org.json.JSONArray
import org.json.JSONObject
import java.io.File
import java.io.FileOutputStream
import java.time.Instant

object JvmCapture {
    fun utf8Bounded(value: String, limit: Int): String {
        val output = StringBuilder()
        var size = 0
        var index = 0
        while (index < value.length) {
            val code = value.codePointAt(index)
            index += Character.charCount(code)
            if (code < 32 && code != 9 && code != 10 || code == 127 || code in 0xd800..0xdfff) continue
            val next = String(Character.toChars(code))
            val bytes = next.toByteArray(Charsets.UTF_8).size
            if (size + bytes > limit) break
            output.append(next)
            size += bytes
        }
        return output.toString()
    }

    fun frames(error: Throwable): List<Map<String, Any>> = error.stackTrace.take(64).map {
        mapOf("symbol" to utf8Bounded(it.className + "." + it.methodName, 1024))
    }

    fun trace(error: Throwable): String = buildString {
        append(error.javaClass.name.take(1024))
        // Do not collect the exception message, which commonly contains user data.
        for (frame in error.stackTrace.take(64)) {
            append("\n at ")
            append((frame.className + "." + frame.methodName).take(1024))
        }
    }.let { utf8Bounded(it, 16384) }

    fun anr(data: ByteArray): Pair<String, List<Map<String, Any>>>? {
        if (data.size > CrashStore.MAX_BYTES) return null
        return try {
            val text = Charsets.UTF_8.newDecoder().onMalformedInput(java.nio.charset.CodingErrorAction.REPORT)
                .decode(java.nio.ByteBuffer.wrap(data)).toString()
            val safe = text.lineSequence().map { it.trim() }
                .filter { it.matches(Regex("at [A-Za-z0-9_.$]+\\([^\\r\\n]*\\)")) }
                .take(64).map { it.substringBefore('(').take(1024) }.toList()
            Pair(utf8Bounded(safe.joinToString("\n"), 16384), safe.map { mapOf("symbol" to it.removePrefix("at ")) })
        } catch (_: Exception) { null }
    }

    class Handler(private val previous: Thread.UncaughtExceptionHandler?, private val persist: (Thread, Throwable) -> Unit) : Thread.UncaughtExceptionHandler {
        override fun uncaughtException(thread: Thread, error: Throwable) {
            try { persist(thread, error) } catch (_: Throwable) {
                // Even OOM or an unavailable filesystem must not swallow the host crash.
            } finally { previous?.uncaughtException(thread, error) }
        }
    }

    fun report(error: Throwable, timestamp: Long, pid: Int, process: String, version: String?, build: String?): JSONObject {
        val id = CrashStore.digest("jvm|$process|$pid|$timestamp")
        val report = JSONObject().put("schema_version", 1).put("report_id", id).put("platform", "android")
            .put("kind", "crash").put("source", "jvm_exception").put("reason", "uncaught_exception").put("fatal", true)
            .put("occurred_at", Instant.ofEpochMilli(timestamp).toString()).put("timestamp_precision", "exact")
            .put("occurrence_count", 1).put("frames", JSONArray(frames(error))).put("trace", trace(error))
        version?.let { report.put("app_version", utf8Bounded(it, 255)) }
        build?.let { report.put("build", utf8Bounded(it, 255)) }
        return JSONObject().put("schema_version", 1).put("timestamp", timestamp).put("pid", pid)
            .put("process", process).put("report", report)
    }

    // One bounded crash-time write, with no executor, network, history scan or bridge.
    fun save(file: File, saved: JSONObject) {
        val bytes = saved.toString().toByteArray(Charsets.UTF_8)
        if (bytes.size > CrashStore.MAX_BYTES) return
        val temporary = File(file.parentFile, file.name + ".tmp")
        FileOutputStream(temporary).use { it.write(bytes); it.fd.sync() }
        if (!temporary.renameTo(file)) temporary.delete()
    }

    fun matches(saved: JSONObject, timestamp: Long, pid: Int, process: String, crash: Boolean): Boolean =
        crash && saved.optInt("pid") == pid && saved.optString("process") == process &&
            timestamp >= saved.optLong("timestamp") && timestamp - saved.optLong("timestamp") <= 10_000
}
