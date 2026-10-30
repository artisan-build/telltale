package com.artisanbuild.plugins.telltale_crashes

import java.nio.ByteBuffer
import java.nio.charset.CodingErrorAction

// https://android.googlesource.com/platform/system/core/+/refs/heads/main/debuggerd/proto/tombstone.proto
// AOSP debuggerd/proto/tombstone.proto, checked 2026-10-30:
// Tombstone.tid=6, threads=16; map entry key=1/value=2;
// Thread.current_backtrace=4; BacktraceFrame rel_pc=1, pc=2,
// function_name=4, file_name=6, file_map_offset=7, build_id=8.
// Unknown fields are skipped by wire type, never decoded as text or returned.
object TombstoneDecoder {
    const val MAX_BYTES = 256 * 1024
    data class Result(val frames: List<Map<String, Any>>, val mapOffsets: List<String>, val status: String)
    private class Malformed : Exception()
    private data class Field(val number: Int, val wire: Int, val scalar: ULong?, val bytes: ByteArray?)

    private class Reader(private val data: ByteArray) {
        private var position = 0
        private var fields = 0
        private fun varint(): ULong {
            var value = 0uL
            for (i in 0..9) {
                if (position >= data.size) throw Malformed()
                val byte = data[position++].toInt() and 255
                if (i == 9 && byte > 1) throw Malformed()
                value = value or ((byte and 127).toULong() shl (7 * i))
                if (byte and 128 == 0) return value
            }
            throw Malformed()
        }
        private fun advance(bytes: Int) {
            if (bytes < 0 || bytes > data.size - position) throw Malformed()
            position += bytes
        }
        fun next(): Field? {
            if (position == data.size) return null
            if (++fields > 8192) throw Malformed()
            val tag = varint()
            val number = tag shr 3
            if (number == 0uL || number > 536870911uL) throw Malformed()
            val wire = (tag and 7uL).toInt()
            var scalar: ULong? = null
            var bytes: ByteArray? = null
            when (wire) {
                0 -> scalar = varint()
                1 -> advance(8)
                2 -> {
                    val length = varint()
                    if (length > (data.size - position).toULong()) throw Malformed()
                    bytes = data.copyOfRange(position, position + length.toInt())
                    advance(length.toInt())
                }
                5 -> advance(4)
                else -> throw Malformed()
            }
            return Field(number.toInt(), wire, scalar, bytes)
        }
    }

    private fun string(bytes: ByteArray?, limit: Int): String? {
        if (bytes == null || bytes.size > limit) return null
        return try {
            Charsets.UTF_8.newDecoder().onMalformedInput(CodingErrorAction.REPORT)
                .onUnmappableCharacter(CodingErrorAction.REPORT).decode(ByteBuffer.wrap(bytes)).toString()
                .takeIf { value -> value.isNotEmpty() && value.none { it.code < 32 || it.code == 127 } }
        } catch (_: Exception) { null }
    }

    private fun hex(value: ULong) = "0x" + value.toString(16)

    fun decode(data: ByteArray): Result {
        if (data.size > MAX_BYTES) return Result(emptyList(), emptyList(), "oversized")
        return try {
            var tid = 0uL
            val threads = mutableListOf<ByteArray>()
            val reader = Reader(data)
            while (true) {
                val field = reader.next() ?: break
                when {
                    field.number == 6 && field.wire == 0 -> tid = field.scalar!!
                    field.number == 16 && field.wire == 2 -> {
                        if (threads.size >= 512) throw Malformed()
                        threads.add(field.bytes!!)
                    }
                }
            }
            var selected: ByteArray? = null
            for (entry in threads) {
                var key = 0uL
                var thread: ByteArray? = null
                val map = Reader(entry)
                while (true) {
                    val field = map.next() ?: break
                    if (field.number == 1 && field.wire == 0) key = field.scalar!!
                    if (field.number == 2 && field.wire == 2) thread = field.bytes
                }
                if (key == tid) selected = thread
            }
            if (selected == null) return Result(emptyList(), emptyList(), "missing_thread")
            val frames = mutableListOf<Map<String, Any>>()
            val offsets = mutableListOf<String>()
            val thread = Reader(selected)
            while (true) {
                val field = thread.next() ?: break
                if (field.number != 4 || field.wire != 2 || frames.size >= 64) continue
                var rel = 0uL
                var pc = 0uL
                var mapOffset = 0uL
                var name: String? = null
                var symbol: String? = null
                var build: String? = null
                val frame = Reader(field.bytes!!)
                while (true) {
                    val part = frame.next() ?: break
                    when {
                        part.number == 1 && part.wire == 0 -> rel = part.scalar!!
                        part.number == 2 && part.wire == 0 -> pc = part.scalar!!
                        part.number == 4 && part.wire == 2 -> symbol = string(part.bytes, 1024)
                        part.number == 6 && part.wire == 2 -> name = string(part.bytes, 4096)?.substringAfterLast('/')?.takeIf { it.length <= 255 }
                        part.number == 7 && part.wire == 0 -> mapOffset = part.scalar!!
                        part.number == 8 && part.wire == 2 -> build = string(part.bytes, 128)
                            ?.takeIf { it.matches(Regex("[a-fA-F0-9]{8,128}")) }?.lowercase()
                    }
                }
                val projected = mutableMapOf<String, Any>()
                name?.takeIf { it.isNotEmpty() }?.let { projected["binary_name"] = it }
                build?.let { projected["binary_uuid"] = it }
                symbol?.let { projected["symbol"] = it }
                if (name != null || build != null) {
                    // Proto3 absent scalars have zero defaults. A zero PC is not a valid
                    // load-address input; keep rel_pc without inventing a load base.
                    projected["offset"] = hex(rel)
                    if (pc > 0uL && pc >= rel) {
                        projected["address"] = hex(pc)
                        projected["load_address"] = hex(pc - rel)
                    }
                }
                if (projected["offset"] != null || projected["symbol"] != null) {
                    frames.add(projected)
                    offsets.add(hex(mapOffset))
                }
            }
            Result(frames, offsets, "parsed")
        } catch (_: Exception) { Result(emptyList(), emptyList(), "malformed") }
    }
}
