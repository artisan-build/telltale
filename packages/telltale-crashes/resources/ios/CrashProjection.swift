import Foundation
import CoreFoundation
import CryptoKit

// Shared by the MetricKit adapter and the executable Foundation harness.
enum CrashProjection {
    static let maxSourceBytes = 256 * 1024

    static func digest(_ data: Data) -> String {
        SHA256.hash(data: data).map { String(format: "%02x", $0) }.joined()
    }

    static func json(_ value: Any) -> Data? {
        try? JSONSerialization.data(withJSONObject: value, options: [.sortedKeys])
    }

    static func text(_ value: Any?, limit: Int) -> String? {
        guard let value = value as? String, !value.isEmpty,
              value.utf8.count <= limit,
              !value.unicodeScalars.contains(where: { $0.value < 32 || $0.value == 127 }) else { return nil }
        return value
    }

    static func unsigned(_ value: Any?) -> UInt64? {
        if let number = value as? NSNumber {
            guard CFGetTypeID(number) != CFBooleanGetTypeID() else { return nil }
            // Do not round through Double: addresses may exceed the signed integer range.
            return UInt64(number.stringValue)
        }
        if let string = value as? String {
            return string.hasPrefix("0x") ? UInt64(string.dropFirst(2), radix: 16) : UInt64(string)
        }
        return nil
    }

    static func date(_ value: String) -> Date? {
        let formatter = ISO8601DateFormatter()
        formatter.formatOptions = [.withInternetDateTime, .withFractionalSeconds]
        return formatter.date(from: value) ?? ISO8601DateFormatter().date(from: value)
    }

    static func timestamp(_ date: Date) -> String {
        let formatter = ISO8601DateFormatter()
        formatter.formatOptions = [.withInternetDateTime, .withFractionalSeconds]
        return formatter.string(from: date)
    }

    static func window(_ root: [String: Any]) -> (String, String)? {
        guard let start = text(root["timeStampBegin"], limit: 255),
              let end = text(root["timeStampEnd"], limit: 255),
              let startDate = date(start), let endDate = date(end), startDate <= endDate else { return nil }
        return (start, end)
    }

    static func frames(_ diagnostic: [String: Any]) -> [[String: Any]] {
        guard let tree = diagnostic["callStackTree"] as? [String: Any],
              let stacks = tree["callStacks"] as? [[String: Any]] else { return [] }
        var result = [[String: Any]]()
        var visited = 0
        func visit(_ nodes: [[String: Any]], depth: Int) {
            guard depth <= 64 else { return }
            for node in nodes {
                guard result.count < 64, visited < 2048 else { return }
                visited += 1
                var frame = [String: Any]()
                if let name = text(node["binaryName"], limit: 255) { frame["binary_name"] = name }
                if let uuid = node["binaryUUID"] as? String, UUID(uuidString: uuid) != nil {
                    frame["binary_uuid"] = uuid.lowercased()
                }
                let address = unsigned(node["address"])
                let offset = unsigned(node["offsetIntoBinaryTextSegment"])
                // Contradictory locations cannot be used to guess a load address.
                if let address, let offset, address >= offset {
                    frame["address"] = "0x" + String(address, radix: 16)
                    frame["offset"] = "0x" + String(offset, radix: 16)
                    frame["load_address"] = "0x" + String(address - offset, radix: 16)
                } else if let address, offset == nil {
                    frame["address"] = "0x" + String(address, radix: 16)
                } else if address == nil, let offset {
                    frame["offset"] = "0x" + String(offset, radix: 16)
                }
                if (frame["binary_name"] != nil || frame["binary_uuid"] != nil),
                   (frame["address"] != nil || frame["offset"] != nil) { result.append(frame) }
                if let children = node["subFrames"] as? [[String: Any]] { visit(children, depth: depth + 1) }
            }
        }
        // Preserve tree order within each attributed stack, then the other stacks.
        for attributed in [true, false] {
            for stack in stacks where (stack["threadAttributed"] as? Bool ?? false) == attributed {
                visit(stack["callStackRootFrames"] as? [[String: Any]] ?? [], depth: 0)
            }
        }
        return result
    }

    static func base(kind: String, source: String, reason: String, start: String, end: String) -> [String: Any] {
        ["schema_version": 1, "platform": "ios", "kind": kind, "source": source,
         "reason": reason, "fatal": kind != "hang", "occurred_at": end,
         "timestamp_precision": "window_end", "occurrence_count": 1,
         "reporting_window_start": start, "reporting_window_end": end, "frames": [[String: Any]]()]
    }

    // A diagnostic does not expose an individual crash timestamp. Attribute it to the
    // reporting window end rather than inventing a precise crash time.
    static func diagnostic(_ data: Data, kind: String, start: String, end: String,
                           build: String? = nil, version: String? = nil) -> [String: Any]? {
        guard data.count <= maxSourceBytes, ["crash", "hang"].contains(kind),
              let root = try? JSONSerialization.jsonObject(with: data) as? [String: Any],
              window(["timeStampBegin": start, "timeStampEnd": end]) != nil else { return nil }
        let metadata = root["diagnosticMetaData"] as? [String: Any] ?? [:]
        var reason = kind == "hang" ? "hang" : "crash"
        if kind == "crash", let signal = unsigned(metadata["signal"]) { reason = "signal_\(signal)" }
        var report = base(kind: kind, source: "metrickit_diagnostic", reason: reason, start: start, end: end)
        report["frames"] = frames(root)
        if let version = text(version ?? metadata["appVersion"], limit: 255) { report["app_version"] = version }
        if let build = text(build ?? metadata["appBuildVersion"], limit: 255) { report["build"] = build }
        // Canonical JSON makes a replay independent of dictionary key serialization order.
        guard let identity = json(["diagnostic": root, "kind": kind, "start": start, "end": end,
                                   "build": report["build"] ?? "", "version": report["app_version"] ?? ""]) else { return nil }
        report["report_id"] = digest(identity)
        return report
    }

    static func exits(_ data: Data, build: String? = nil, multipleVersions: Bool? = nil) -> [[String: Any]] {
        guard data.count <= maxSourceBytes,
              let root = try? JSONSerialization.jsonObject(with: data) as? [String: Any],
              let (start, end) = window(root),
              let exits = root["applicationExitMetrics"] as? [String: Any] else { return [] }
        let metadata = root["metaData"] as? [String: Any] ?? [:]
        let mixed = multipleVersions ?? root["includesMultipleApplicationVersions"] as? Bool ?? false
        var reports = [[String: Any]]()
        for state in ["foreground", "background"] {
            guard let counts = exits[state + "ExitData"] as? [String: Any] else { continue }
            for (category, kind) in [("cumulativeMemoryResourceLimitExitCount", "memory_exit"),
                                     ("cumulativeMemoryPressureExitCount", "memory_exit"),
                                     ("cumulativeAppWatchdogExitCount", "watchdog_exit")] {
                guard let count = unsigned(counts[category]), count > 0, count <= 1_000_000 else { continue }
                var report = base(kind: kind, source: "metrickit_exit", reason: category, start: start, end: end)
                report["occurrence_count"] = count
                report["metadata"] = ["execution_state": state, "exit_category": category, "multiple_app_versions": mixed]
                // A multi-version aggregate cannot be assigned to the latest/current build.
                if !mixed {
                    if let version = text(root["appVersion"], limit: 255) { report["app_version"] = version }
                    if let build = text(build ?? metadata["appBuildVersion"], limit: 255) { report["build"] = build }
                }
                guard let identity = json(["start": start, "end": end, "state": state, "category": category,
                                          "count": count, "build": report["build"] ?? ""]) else { continue }
                report["report_id"] = digest(identity)
                reports.append(report)
            }
        }
        return reports
    }
}
