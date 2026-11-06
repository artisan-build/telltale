import Foundation

// All disk methods are worker-only. The bridge reads only CrashRuntime's cached snapshot.
final class CrashStore {
    static let ttl: TimeInterval = 7 * 24 * 3600
    static let maxReports = 32
    private let directory: URL
    private let launch: String
    private let now: () -> Date
    private var entries = [String: [String: Any]]()
    private var receipts = [String: Double]()
    private(set) var suppressed = false

    init(directory: URL, launch: String, now: @escaping () -> Date = Date.init) {
        self.directory = directory
        self.launch = launch
        self.now = now
    }

    private func validID(_ id: String) -> Bool {
        id.count == 64 && id.allSatisfy { "0123456789abcdef".contains($0) }
    }

    private func read(_ url: URL, max: Int) -> [String: Any]? {
        guard let size = try? url.resourceValues(forKeys: [.fileSizeKey]).fileSize, size <= max,
              let data = try? Data(contentsOf: url), data.count <= max else { return nil }
        return try? JSONSerialization.jsonObject(with: data) as? [String: Any]
    }

    private func write(_ object: [String: Any], to url: URL) -> Bool {
        guard let data = CrashProjection.json(object) else { return false }
        do {
            try data.write(to: url, options: .atomic)
            try FileManager.default.setAttributes([.posixPermissions: 0o600], ofItemAtPath: url.path)
            return true
        } catch { return false }
    }

    func load() {
        let fm = FileManager.default
        do {
            try fm.createDirectory(at: directory, withIntermediateDirectories: true, attributes: [.posixPermissions: 0o700])
            var excluded = URLResourceValues()
            excluded.isExcludedFromBackup = true
            var url = directory
            try url.setResourceValues(excluded)
        } catch { return }
        let state = read(directory.appendingPathComponent("state.json"), max: 32 * 1024) ?? [:]
        suppressed = state["suppressed"] as? Bool ?? false
        receipts = (state["receipts"] as? [String: Double] ?? [:]).filter {
            validID($0.key) && $0.value >= now().timeIntervalSince1970 - Self.ttl
        }
        entries.removeAll()
        // Enumerating is off the main thread. Files are individually bounded and isolated.
        guard let files = try? fm.contentsOfDirectory(at: directory, includingPropertiesForKeys: [.fileSizeKey]) else { return }
        for file in files where file.pathExtension == "json" && file.lastPathComponent != "state.json" {
            let id = file.deletingPathExtension().lastPathComponent
            guard validID(id), !suppressed, receipts[id] == nil,
                  let entry = read(file, max: 512 * 1024), entry["schema_version"] as? Int == 1,
                  let storedAt = entry["stored_at"] as? Double,
                  storedAt >= now().timeIntervalSince1970 - Self.ttl,
                  let report = entry["report"] as? [String: Any], report["report_id"] as? String == id,
                  let source = entry["source"] as? [String: Any],
                  let bytes = CrashProjection.json(source), bytes.count <= CrashProjection.maxSourceBytes else {
                try? fm.removeItem(at: file)
                continue
            }
            entries[id] = entry
        }
        prune()
    }

    @discardableResult
    func persist(report: [String: Any], source: Data, currentProcess: Bool) -> Bool {
        guard !suppressed, source.count <= CrashProjection.maxSourceBytes,
              let raw = try? JSONSerialization.jsonObject(with: source) as? [String: Any],
              let canonical = CrashProjection.json(raw), canonical.count <= CrashProjection.maxSourceBytes,
              let id = report["report_id"] as? String, validID(id) else { return false }
        if entries[id] != nil || receipts[id] != nil { return true }
        let entry: [String: Any] = ["schema_version": 1, "stored_at": now().timeIntervalSince1970,
                                    "launch": currentProcess ? launch : "historical", "source": raw, "report": report]
        guard write(entry, to: directory.appendingPathComponent(id + ".json")) else { return false }
        entries[id] = entry
        prune()
        return true
    }

    private func prune() {
        let expiry = now().timeIntervalSince1970 - Self.ttl
        for id in Array(entries.keys) where (entries[id]?["stored_at"] as? Double ?? 0) < expiry { remove(id) }
        let ordered = entries.keys.sorted {
            let left = entries[$0]?["stored_at"] as? Double ?? 0
            let right = entries[$1]?["stored_at"] as? Double ?? 0
            return left == right ? $0 < $1 : left < right
        }
        for id in ordered.prefix(max(0, ordered.count - Self.maxReports)) { remove(id) }
        receipts = receipts.filter { $0.value >= expiry }
        for id in receipts.keys.sorted(by: { receipts[$0]! > receipts[$1]! }).dropFirst(128) { receipts.removeValue(forKey: id) }
    }

    private func remove(_ id: String) {
        try? FileManager.default.removeItem(at: directory.appendingPathComponent(id + ".json"))
        entries.removeValue(forKey: id)
    }

    func pending() -> [[String: Any]] {
        prune()
        guard !suppressed else { return [] }
        return entries.keys.sorted().compactMap { id in
            guard let entry = entries[id], entry["launch"] as? String != launch else { return nil }
            return entry["report"] as? [String: Any]
        }
    }

    @discardableResult
    func acknowledge(_ ids: [String]) -> Bool {
        let old = receipts
        for id in ids.prefix(Self.maxReports) where validID(id) && entries[id] != nil {
            receipts[id] = now().timeIntervalSince1970
        }
        prune()
        // Durable receipt precedes deletion, so replay/kill between these operations is safe.
        guard saveState() else { receipts = old; return false }
        for id in ids.prefix(Self.maxReports) where validID(id) { remove(id) }
        return true
    }

    @discardableResult
    func setSuppressed(_ value: Bool) -> Bool {
        let old = suppressed
        suppressed = value
        guard saveState() else { suppressed = old; return false }
        if value { for id in Array(entries.keys) { remove(id) } }
        return true
    }

    private func saveState() -> Bool {
        write(["schema_version": 1, "suppressed": suppressed, "receipts": receipts],
              to: directory.appendingPathComponent("state.json"))
    }
}
