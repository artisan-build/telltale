package com.artisanbuild.plugins.telltale_crashes

import org.json.JSONObject
import java.io.File

object AndroidHarness {
    private var assertions = 0
    private fun expect(value: Boolean, message: String) {
        assertions++
        check(value) { message }
    }
    private fun varint(input: ULong): ByteArray {
        var value = input
        val output = java.io.ByteArrayOutputStream()
        do {
            var byte = (value and 127uL).toInt()
            value = value shr 7
            if (value > 0uL) byte = byte or 128
            output.write(byte)
        } while (value > 0uL)
        return output.toByteArray()
    }
    private fun scalar(field: Int, value: ULong) = varint((field * 8).toULong()) + varint(value)
    private fun bytes(field: Int, value: ByteArray) = varint((field * 8 + 2).toULong()) + varint(value.size.toULong()) + value
    private fun text(field: Int, value: String) = bytes(field, value.toByteArray(Charsets.UTF_8))

    @JvmStatic fun main(args: Array<String>) {
        // Frozen AOSP field numbers; sensitive fields are distinct canaries, not generic placeholders.
        val frame = scalar(1, 0x1234uL) + scalar(2, 0x100001234uL) + scalar(3, 0x123456uL) +
            text(4, "native_work") + text(6, "/data/app/private-path/libexample.so") + scalar(7, 0x4000uL) +
            text(8, "0123456789abcdef0123456789abcdef")
        val wrongThread = bytes(4, text(4, "WRONG_THREAD_CANARY"))
        val selected = scalar(1, 42uL) + bytes(3, text(1, "REGISTER_CANARY")) + bytes(4, frame) +
            bytes(5, text(4, "THREAD_MEMORY_CANARY"))
        val protobuf = scalar(6, 42uL) + bytes(16, scalar(1, 7uL) + bytes(2, wrongThread)) +
            bytes(16, scalar(1, 42uL) + bytes(2, selected)) + text(17, "MAPPING_CANARY") +
            text(18, "LOG_CANARY") + text(19, "FD_PATH_CANARY") + text(21, "CRASH_DETAIL_CANARY")
        val decoded = TombstoneDecoder.decode(protobuf)
        expect(decoded.status == "parsed" && decoded.frames.size == 1, "decode selected thread only")
        val wire = JSONObject(mapOf("frames" to decoded.frames)).toString()
        expect(decoded.frames[0]["binary_name"] == "libexample.so", "basename retained, app-private path removed")
        expect(decoded.frames[0]["binary_uuid"] == "0123456789abcdef0123456789abcdef", "ELF build ID retained")
        expect(decoded.frames[0]["offset"] == "0x1234" && decoded.frames[0]["address"] == "0x100001234", "relative/absolute PC retained")
        expect(decoded.frames[0]["load_address"] == "0x100000000", "load address valid subtraction")
        expect(decoded.mapOffsets == listOf("0x4000"), "file map offset retained separately, not confused with rel_pc")
        for (canary in listOf("WRONG_THREAD_CANARY", "REGISTER_CANARY", "THREAD_MEMORY_CANARY", "MAPPING_CANARY", "LOG_CANARY", "FD_PATH_CANARY", "CRASH_DETAIL_CANARY", "private-path")) {
            expect(!wire.contains(canary), "forbidden field did not enter projection: $canary")
        }
        expect(TombstoneDecoder.decode(byteArrayOf(0x80.toByte())).status == "malformed", "truncated varint bounded")
        expect(TombstoneDecoder.decode(bytes(16, byteArrayOf(0x12, 0x7f))).status == "malformed", "nested malformed length bounded")
        expect(TombstoneDecoder.decode(ByteArray(262145)).status == "oversized", "source byte bound")
        expect(TombstoneDecoder.decode(byteArrayOf(0x0f)).status == "malformed", "unsupported wire type rejected")
        val shifted = TombstoneDecoder.decode(scalar(6, 42uL) + bytes(16, scalar(1, 42uL) + bytes(2,
            bytes(4, scalar(1, 0x1234uL) + scalar(2, 0x200001234uL) + text(6, "libexample.so") + text(8, "0123456789abcdef0123456789abcdef")))))
        expect(shifted.frames[0]["offset"] == decoded.frames[0]["offset"] && shifted.frames[0]["binary_uuid"] == decoded.frames[0]["binary_uuid"], "ASLR grouping inputs stable")

        var chained = 0
        val previous = Thread.UncaughtExceptionHandler { _, _ -> chained++ }
        JvmCapture.Handler(previous) { _, _ -> throw java.io.IOException("disk failed") }.uncaughtException(Thread.currentThread(), IllegalStateException("SENSITIVE_MESSAGE_CANARY"))
        expect(chained == 1, "previous handler called after persistence exception")
        JvmCapture.Handler(previous) { _, _ -> throw OutOfMemoryError() }.uncaughtException(Thread.currentThread(), IllegalStateException())
        expect(chained == 2, "previous handler called after persistence Error")
        JvmCapture.Handler(previous) { _, _ -> }.uncaughtException(Thread.currentThread(), IllegalStateException())
        expect(chained == 3, "previous handler called after successful capture")
        val error = IllegalStateException("SENSITIVE_MESSAGE_CANARY")
        val saved = JvmCapture.report(error, 100000, 123, "example.app", "2.4", "210")
        expect(!saved.toString().contains("SENSITIVE_MESSAGE_CANARY"), "JVM message never persisted")
        expect(JvmCapture.matches(saved, 100100, 123, "example.app", true), "pid/process/time correlation")
        expect(!JvmCapture.matches(saved, 100100, 124, "example.app", true), "different pid cannot correlate")
        expect(!JvmCapture.matches(saved, 100100, 123, "different.process", true), "different process cannot correlate")
        expect(!JvmCapture.matches(saved, 120000, 123, "example.app", true), "distant timestamp cannot correlate")
        expect(!JvmCapture.matches(saved, 100100, 123, "example.app", false), "noncrash exit cannot correlate")
        val anr = JvmCapture.anr(("header ANR_HEADER_CANARY\n at example.Work.run(ANR_PATH_CANARY.java:42)\n" +
            "arbitrary ANR_LOG_CANARY\n at example.Other.wait(Native Method)").toByteArray())!!
        expect(anr.second.size == 2 && anr.second[0]["symbol"] == "example.Work.run", "actual ANR parser projects only methods")
        for (canary in listOf("ANR_HEADER_CANARY", "ANR_PATH_CANARY", "ANR_LOG_CANARY")) {
            expect(!anr.first.contains(canary), "ANR sensitive line/path excluded")
        }
        expect(JvmCapture.anr(byteArrayOf(0xc3.toByte(), 0x28)) == null, "malformed UTF-8 ANR rejected")
        expect(JvmCapture.utf8Bounded("\u00e9".repeat(2000), 255).toByteArray().size <= 255, "text byte limit, not character count")

        val packageDir = File(args[0])
        val scratch = File(packageDir, ".native-build/android-store-${java.util.UUID.randomUUID()}")
        var now = 1800000000000L
        val store = CrashStore(scratch, "B") { now }
        store.load()
        val first = saved.getJSONObject("report")
        expect(store.persist(first, false), "durable report write")
        expect(store.pending().size == 1 && store.pending().size == 1, "non destructive pending")
        val tied = JSONObject(first.toString()).put("report_id", CrashStore.digest("exit|other.process|124|100000|4"))
        expect(store.persist(tied, false) && store.pending().size == 2, "same timestamp distinct death retained")
        val current = JSONObject(first.toString()).put("report_id", CrashStore.digest("current-process"))
        expect(store.persist(current, true) && store.pending().size == 2, "current process deferred")
        expect(store.acknowledge(listOf(first.getString("report_id"), "../../bad")), "named ack")
        expect(store.pending().size == 1, "ack removes named only")
        val next = CrashStore(scratch, "C") { now }
        next.load()
        expect(next.pending().size == 2, "current report eligible next launch")
        expect(next.hasSeen(first.getString("report_id")), "history receipt survives ack/relaunch")
        expect(next.persist(first, false) && next.pending().size == 2, "saved/history replay not duplicated after ack")
        File(scratch, "a".repeat(64) + ".json").writeText("broken")
        next.load()
        expect(next.pending().size == 2, "corruption isolated")
        repeat(40) { index ->
            now++
            expect(next.persist(JSONObject(first.toString()).put("report_id", CrashStore.digest("report-$index")), false), "eviction write")
        }
        expect(next.pending().size == 32, "max 32 reports")
        now += CrashStore.TTL + 1
        expect(next.pending().isEmpty(), "seven day TTL")
        expect(next.setSuppressed(true) && !next.persist(tied, false), "suppression discards and blocks capture")
        val optedOut = CrashStore(scratch, "D") { now }
        optedOut.load()
        expect(optedOut.suppressed && optedOut.pending().isEmpty(), "opt out persists")
        expect(optedOut.setSuppressed(false) && optedOut.persist(tied, false), "opt in resumes")
        val blocked = File(scratch, "blocked")
        blocked.writeText("not a directory")
        val unavailable = CrashStore(blocked, "blocked") { now }
        unavailable.load()
        expect(!unavailable.persist(tied, false) && !unavailable.hasSeen(tied.getString("report_id")), "failed disk persistence cannot advance seen")
        // Deliberately narrow cleanup: only this harness's generated directory.
        scratch.listFiles()?.forEach { it.delete() }
        scratch.delete()

        // The real decoder output is validated/imported by the separate PHP outbox harness.
        first.put("frames", org.json.JSONArray(decoded.frames))
        File(packageDir, ".native-build/android-projected.json").writeText(org.json.JSONArray(listOf(first)).toString())
        println("PASS: Android real-source JVM harness ($assertions assertions; Android SDK compile, not APK/device delivery)")
    }
}
