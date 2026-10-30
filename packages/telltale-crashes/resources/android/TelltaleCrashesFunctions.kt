package com.artisanbuild.plugins.telltale_crashes

import android.app.ActivityManager
import android.app.Application
import android.app.ApplicationExitInfo
import android.content.Context
import android.os.Build
import android.os.Process
import com.nativephp.mobile.bridge.BridgeFunction
import com.nativephp.mobile.bridge.BridgeResponse
import com.nativephp.mobile.ui.nativerender.NativeElementBridge
import org.json.JSONArray
import org.json.JSONObject
import java.io.File
import java.io.InputStream
import java.time.Instant
import java.util.UUID
import java.util.concurrent.Executors

object TelltaleCrashesFunctions {
    @Volatile private var runtime: CrashRuntime? = null
    @Synchronized fun initialize(context: Context) {
        if (runtime == null) runtime = CrashRuntime(context.applicationContext)
    }

    class Pending(context: Context) : BridgeFunction {
        init { initialize(context) }
        override fun execute(parameters: Map<String, Any>): Map<String, Any> =
            BridgeResponse.success(runtime?.pending() ?: mapOf("schema_version" to 1, "reports" to emptyList<Any>(), "revision" to 0))
    }
    class Acknowledge(context: Context) : BridgeFunction {
        init { initialize(context) }
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            runtime?.acknowledge((parameters["report_ids"] as? List<*>)?.take(32)?.filterIsInstance<String>() ?: emptyList())
            return BridgeResponse.success(mapOf("scheduled" to true))
        }
    }
    class SetSuppressed(context: Context) : BridgeFunction {
        init { initialize(context) }
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            (parameters["suppressed"] as? Boolean)?.let { runtime?.setSuppressed(it) }
            return BridgeResponse.success(mapOf("scheduled" to true))
        }
    }
}

class CrashRuntime(private val context: Context) {
    private val worker = Executors.newSingleThreadExecutor { task -> Thread(task, "telltale-crashes").apply { isDaemon = true } }
    private val directory = File(context.noBackupFilesDir, "telltale-crashes")
    private val store = CrashStore(File(directory, "reports"), UUID.randomUUID().toString())
    private val savedFile = File(directory, "jvm-capture.json")
    @Volatile private var suppressed = true // Fail closed until persisted consent is loaded off-main.
    @Volatile private var suppressionRequested = false
    @Volatile private var snapshot: Map<String, Any> = mapOf("schema_version" to 1, "reports" to emptyList<Any>(), "revision" to 0)
    private var revision = 0

    init {
        // No history/file scan in init. The handler itself writes only at process death.
        val previous = Thread.getDefaultUncaughtExceptionHandler()
        if (previous != null) Thread.setDefaultUncaughtExceptionHandler(JvmCapture.Handler(previous) { _, error ->
            if (!suppressed) {
                val info = context.packageManager.getPackageInfo(context.packageName, 0)
                val process = if (Build.VERSION.SDK_INT >= 28) Application.getProcessName() else context.packageName
                JvmCapture.save(savedFile, JvmCapture.report(error, System.currentTimeMillis(), Process.myPid(), process,
                    info.versionName, if (Build.VERSION.SDK_INT >= 28) info.longVersionCode.toString() else null))
            }
        })
        worker.execute {
            try {
                directory.mkdirs()
                store.load()
                suppressed = suppressionRequested || store.suppressed
                if (suppressed) discardStacks() else collect()
                publish()
            } catch (_: Exception) { publish() }
        }
    }

    fun pending(): Map<String, Any> = if (suppressed) mapOf("schema_version" to 1, "reports" to emptyList<Any>(), "suppressed" to true)
        else snapshot

    fun acknowledge(ids: List<String>) { worker.execute { try { store.acknowledge(ids); publish() } catch (_: Exception) {} } }
    fun setSuppressed(value: Boolean) {
        suppressionRequested = value
        if (value) suppressed = true
        worker.execute {
            try {
                if (store.setSuppressed(value)) suppressed = value
                if (value) discardStacks()
                publish()
            } catch (_: Exception) {}
        }
    }

    private fun publish() {
        try {
            val reports = if (suppressed) emptyList() else store.pending().map { jsonMap(it) }
            snapshot = mapOf("schema_version" to 1, "reports" to reports, "revision" to ++revision, "suppressed" to suppressed)
            NativeElementBridge.sendNativeEvent("ArtisanBuild\\TelltaleCrashes\\Events\\ReportsReady",
                JSONObject().put("schema_version", 1).put("pending_count", reports.size).toString())
        } catch (_: Exception) { /* No host exceptions, including unavailable event bridge. */ }
    }

    private fun jsonMap(value: JSONObject): Map<String, Any> = value.keys().asSequence().associateWith { key ->
        when (val item = value.get(key)) {
            is JSONArray -> (0 until item.length()).map { index ->
                val child = item.get(index)
                if (child is JSONObject) jsonMap(child) else child
            }
            is JSONObject -> jsonMap(item)
            else -> item
        }
    }

    private fun read(file: File): JSONObject? = try {
        file.inputStream().use { stream ->
            val data = bounded(stream)
            if (data == null) null else JSONObject(String(data, Charsets.UTF_8))
        }
    } catch (_: Exception) { null }

    private fun bounded(stream: InputStream): ByteArray? {
        val output = java.io.ByteArrayOutputStream()
        val buffer = ByteArray(8192)
        while (true) {
            val count = stream.read(buffer, 0, minOf(buffer.size, CrashStore.MAX_BYTES + 1 - output.size()))
            if (count < 0) break
            if (count == 0) return null
            output.write(buffer, 0, count)
            if (output.size() > CrashStore.MAX_BYTES) return null
        }
        return output.toByteArray()
    }

    private fun stackFiles(): List<File> = directory.listFiles()?.filter {
        it.name == "jvm-capture.json" || it.name.matches(Regex("jvm-[0-9a-f]{64}\\.json"))
    } ?: emptyList()

    private fun discardStacks() { stackFiles().forEach { it.delete() } }

    private fun collect() {
        // Preserve bounded correlation evidence after ack, since OS history can arrive later.
        val saved = mutableListOf<JSONObject>()
        for (file in stackFiles().sortedByDescending { it.lastModified() }) {
            val item = read(file)
            if (item == null || item.optLong("timestamp") < System.currentTimeMillis() - CrashStore.TTL || saved.size >= 32) {
                file.delete(); continue
            }
            saved.add(item)
            if (file == savedFile) {
                val id = item.optJSONObject("report")?.optString("report_id") ?: ""
                if (id.matches(Regex("[0-9a-f]{64}"))) file.renameTo(File(directory, "jvm-$id.json"))
            }
        }
        if (Build.VERSION.SDK_INT >= 30) {
            val activity = context.getSystemService(Context.ACTIVITY_SERVICE) as? ActivityManager
            val history = try { activity?.getHistoricalProcessExitReasons(null, 0, 128) ?: emptyList() } catch (_: Exception) { emptyList() }
            for (exit in history.sortedBy { it.timestamp }) {
                if (exit.timestamp < System.currentTimeMillis() - CrashStore.TTL) continue
                val kind = when (exit.reason) {
                    ApplicationExitInfo.REASON_CRASH, ApplicationExitInfo.REASON_CRASH_NATIVE -> "crash"
                    ApplicationExitInfo.REASON_ANR -> "hang"
                    ApplicationExitInfo.REASON_LOW_MEMORY -> "memory_exit"
                    else -> continue // Including SIGKILL: no evidence of OOM.
                }
                val linked = saved.firstOrNull { JvmCapture.matches(it, exit.timestamp, exit.pid, exit.processName, kind == "crash") }
                val historyId = CrashStore.digest("exit|${exit.processName}|${exit.pid}|${exit.timestamp}|${exit.reason}")
                val id = linked?.getJSONObject("report")?.getString("report_id") ?: historyId
                if (store.hasSeen(id)) continue
                val report = JSONObject().put("schema_version", 1).put("report_id", id).put("platform", "android")
                    .put("kind", kind).put("source", "application_exit_info").put("reason", "exit_${exit.reason}")
                    .put("fatal", true).put("occurred_at", Instant.ofEpochMilli(exit.timestamp).toString())
                    .put("timestamp_precision", "exact").put("occurrence_count", 1).put("frames", JSONArray())
                val metadata = JSONObject().put("exit_status", exit.status)
                if (linked != null) {
                    val jvm = linked.getJSONObject("report")
                    for (key in listOf("frames", "trace", "app_version", "build")) if (jvm.has(key)) report.put(key, jvm.get(key))
                    metadata.put("jvm_correlated", true)
                }
                if (exit.reason == ApplicationExitInfo.REASON_CRASH_NATIVE && Build.VERSION.SDK_INT >= 31) {
                    try {
                        val trace = exit.traceInputStream?.use { bounded(it) }
                        val result = if (trace == null) TombstoneDecoder.Result(emptyList(), emptyList(), "unavailable_or_oversized") else TombstoneDecoder.decode(trace)
                        report.put("frames", JSONArray(result.frames))
                        metadata.put("parse_status", result.status)
                        result.mapOffsets.firstOrNull()?.let { metadata.put("first_frame_file_map_offset", it) }
                    } catch (_: Exception) { metadata.put("parse_status", "unavailable") }
                } else if (exit.reason == ApplicationExitInfo.REASON_ANR) {
                    // ANR traces contain arbitrary messages/paths. Export only bounded stack method lines.
                    try {
                        val trace = exit.traceInputStream?.use { bounded(it) }
                        val parsed = trace?.let { JvmCapture.anr(it) }
                        if (parsed != null) {
                            report.put("trace", parsed.first)
                            report.put("frames", JSONArray(parsed.second))
                        } else metadata.put("parse_status", "unavailable_or_malformed")
                    } catch (_: Exception) { metadata.put("parse_status", "unavailable") }
                }
                report.put("metadata", metadata)
                // OS-history records do not identify the old app build. Never use this launch's package info.
                store.persist(report, false)
            }
        }
        for (item in saved) store.persist(item.getJSONObject("report"), false)
    }
}

fun registerTelltaleCrashes(context: Context) {
    try { TelltaleCrashesFunctions.initialize(context) } catch (_: Exception) { /* Optional plugin must be inert. */ }
}
