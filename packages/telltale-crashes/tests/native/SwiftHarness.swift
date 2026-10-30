import Foundation

@main
enum SwiftHarness {
    static var checks = 0
    static func expect(_ value: @autoclosure () -> Bool, _ message: String) {
        checks += 1
        guard value() else { fatalError("FAIL: " + message) }
    }

    static func main() throws {
        let package = URL(fileURLWithPath: CommandLine.arguments[1])
        let fixtures = package.appendingPathComponent("tests/fixtures")
        let crash = try Data(contentsOf: fixtures.appendingPathComponent("ios-crash.json"))
        let hang = try Data(contentsOf: fixtures.appendingPathComponent("ios-hang.json"))
        let exits = try Data(contentsOf: fixtures.appendingPathComponent("ios-exits.json"))
        let start = "2026-10-28T00:00:00Z", end = "2026-10-29T00:00:00Z"
        let report = CrashProjection.diagnostic(crash, kind: "crash", start: start, end: end)!
        let frames = report["frames"] as! [[String: Any]]
        expect(frames.count == 3, "nested frames retained")
        expect(frames[0]["binary_name"] as? String == "MetricKitTestApp", "attributed thread precedes unrelated thread")
        expect(frames[0]["binary_uuid"] as? String == "70b89f27-1634-3580-a695-57cdb41d7743", "UUID preserved")
        expect(frames[0]["address"] as? String == "0x1ab6935b8", "absolute address preserved")
        expect(frames[0]["offset"] as? String == "0x285b8", "relative offset preserved")
        expect(frames[0]["load_address"] as? String == "0x1ab66b000", "load address is unsigned difference")
        expect(report["build"] as? String == "210", "historical build survives relaunch")
        expect(report["timestamp_precision"] as? String == "window_end", "no invented exact diagnostic timestamp")
        expect(report["occurred_at"] as? String == end, "diagnostic retains reporting end")
        let orderedAgain = CrashProjection.json(try JSONSerialization.jsonObject(with: crash))!
        expect(CrashProjection.diagnostic(orderedAgain, kind: "crash", start: start, end: end)!["report_id"] as? String == report["report_id"] as? String, "canonical stable replay ID")
        let hanging = CrashProjection.diagnostic(hang, kind: "hang", start: start, end: end)!
        expect(hanging["fatal"] as? Bool == false, "MetricKit hang nonfatal")
        expect((hanging["frames"] as! [[String: Any]]).count == 2, "hang nested stack parsed")
        let aggregates = CrashProjection.exits(exits)
        expect(aggregates.count == 4, "positive categories only, separate states")
        expect(aggregates.map { $0["occurrence_count"] as! UInt64 }.sorted() == [1, 2, 3, 4], "aggregate counts preserved")
        expect(Set(aggregates.map { $0["report_id"] as! String }).count == 4, "state/category identity distinct")
        expect(aggregates.allSatisfy { $0["fatal"] as? Bool == true && $0["occurred_at"] as? String == end }, "exit fatal window attribution")
        expect(aggregates.allSatisfy { $0["reporting_window_start"] as? String == start }, "exit start retained")
        expect(CrashProjection.exits(exits, multipleVersions: true).allSatisfy { $0["build"] == nil && $0["app_version"] == nil }, "mixed-version exit cannot borrow latest build")
        var zero = try JSONSerialization.jsonObject(with: exits) as! [String: Any]
        zero["applicationExitMetrics"] = ["foregroundExitData": ["cumulativeMemoryResourceLimitExitCount": 0, "cumulativeAppWatchdogExitCount": 0],
                                          "backgroundExitData": ["cumulativeMemoryPressureExitCount": 0]]
        expect(CrashProjection.exits(CrashProjection.json(zero)!).isEmpty, "zero counts emit no report")
        expect(CrashProjection.diagnostic(Data("{".utf8), kind: "crash", start: start, end: end) == nil, "malformed isolated")
        expect(CrashProjection.diagnostic(crash, kind: "crash", start: end, end: start) == nil, "reversed window isolated")
        expect(CrashProjection.exits(Data(repeating: 32, count: 262145)).isEmpty, "oversized source bounded")
        expect(CrashProjection.unsigned(NSNumber(value: true)) == nil, "boolean is not an address/count")
        expect(CrashProjection.unsigned(NSNumber(value: UInt64.max)) == UInt64.max, "unsigned precision preserved")

        // Shift absolute addresses, retaining UUID/offset inputs used by the contracts fingerprint.
        var shifted = try JSONSerialization.jsonObject(with: crash) as! [String: Any]
        var tree = shifted["callStackTree"] as! [String: Any]
        var stacks = tree["callStacks"] as! [[String: Any]]
        var roots = stacks[1]["callStackRootFrames"] as! [[String: Any]]
        roots[0]["address"] = 7170766264 + 65536
        stacks[1]["callStackRootFrames"] = roots
        tree["callStacks"] = stacks
        shifted["callStackTree"] = tree
        let shiftedReport = CrashProjection.diagnostic(CrashProjection.json(shifted)!, kind: "crash", start: start, end: end)!
        let shiftedFrame = (shiftedReport["frames"] as! [[String: Any]])[0]
        expect(shiftedFrame["binary_uuid"] as? String == frames[0]["binary_uuid"] as? String && shiftedFrame["offset"] as? String == frames[0]["offset"] as? String, "ASLR grouping inputs stable")

        let scratch = package.appendingPathComponent(".native-build/store-" + UUID().uuidString)
        defer { try? FileManager.default.removeItem(at: scratch) }
        var now = Date(timeIntervalSince1970: 1_800_000_000)
        let store = CrashStore(directory: scratch, launch: "B", now: { now })
        store.load()
        expect(store.persist(report: report, source: crash, currentProcess: false), "durable historical write")
        expect(store.pending().count == 1, "new historical report eligible in B")
        expect(store.pending()[0]["report_id"] as? String == store.pending()[0]["report_id"] as? String, "pending non destructive")
        expect(store.persist(report: hanging, source: hang, currentProcess: true), "current process write")
        expect(store.pending().count == 1, "current process waits next launch")
        let relaunch = CrashStore(directory: scratch, launch: "C", now: { now })
        relaunch.load()
        expect(relaunch.pending().count == 2, "persisted current process eligible next launch")
        expect(relaunch.acknowledge([report["report_id"] as! String, "../../bad"]), "named ack durable")
        expect(relaunch.pending().count == 1, "ack removes only named report")
        let replay = CrashStore(directory: scratch, launch: "D", now: { now })
        replay.load()
        expect(replay.persist(report: report, source: crash, currentProcess: false), "ack receipt accepts replay idempotently")
        expect(replay.pending().count == 1, "acknowledged payload not resurrected")
        try Data("broken".utf8).write(to: scratch.appendingPathComponent(String(repeating: "a", count: 64) + ".json"))
        replay.load()
        expect(replay.pending().count == 1, "corrupt neighbor isolated")
        for i in 0..<40 {
            now = now.addingTimeInterval(1)
            var item = report
            item["report_id"] = CrashProjection.digest(Data("report-\(i)".utf8))
            expect(replay.persist(report: item, source: crash, currentProcess: false), "eviction write \(i)")
        }
        expect(replay.pending().count == 32, "max 32 eviction")
        expect(!replay.pending().contains { $0["report_id"] as? String == hanging["report_id"] as? String }, "oldest first eviction")
        now = now.addingTimeInterval(CrashStore.ttl + 1)
        expect(replay.pending().isEmpty, "TTL expiry")
        expect(replay.persist(report: report, source: crash, currentProcess: false), "post expiry write")
        expect(replay.setSuppressed(true), "durable opt-out")
        expect(replay.pending().isEmpty && !replay.persist(report: report, source: crash, currentProcess: false), "opt-out suppresses capture and backlog")
        let optedOut = CrashStore(directory: scratch, launch: "E", now: { now })
        optedOut.load()
        expect(optedOut.suppressed && optedOut.pending().isEmpty, "suppression persists across launch")
        expect(optedOut.setSuppressed(false), "opt-in allowed")
        expect(optedOut.persist(report: report, source: crash, currentProcess: false), "capture resumes")
        expect(!optedOut.persist(report: report, source: Data(repeating: 32, count: 262145), currentProcess: false), "store source byte bound")
        let blocked = scratch.appendingPathComponent("blocked")
        try Data("not a directory".utf8).write(to: blocked)
        let unavailable = CrashStore(directory: blocked, launch: "failed")
        unavailable.load()
        expect(!unavailable.persist(report: report, source: crash, currentProcess: false), "storage unavailable is inert, not durable acceptance")

        let runtimeDirectory = scratch.appendingPathComponent("runtime")
        let notifications = DispatchSemaphore(value: 0)
        let runtime = CrashRuntime(store: CrashStore(directory: runtimeDirectory, launch: "B"), ready: { _ in notifications.signal() })
        expect(notifications.wait(timeout: .now() + 5) == .success, "background startup completes")
        expect((runtime.pending()["reports"] as! [[String: Any]]).isEmpty, "early empty poll")
        runtime.collect { [(report, crash, false)] }
        expect(notifications.wait(timeout: .now() + 5) == .success, "late collection signals same launch")
        expect((runtime.pending()["reports"] as! [[String: Any]]).count == 1, "late payload imports in B without C")
        runtime.acknowledge([report["report_id"] as! String])
        expect(notifications.wait(timeout: .now() + 5) == .success, "background ack completes")
        expect((runtime.pending()["reports"] as! [[String: Any]]).isEmpty, "cached snapshot refresh after ack")
        runtime.setSuppressed(true)
        expect(runtime.pending()["suppressed"] as? Bool == true, "opt-out hides cache immediately")
        expect(notifications.wait(timeout: .now() + 5) == .success, "background discard completes")

        // Real native output is consumed by the PHP contracts/outbox harness, not a handwritten wire fixture.
        let output = package.appendingPathComponent(".native-build/ios-projected.json")
        try CrashProjection.json([report, hanging, shiftedReport] + aggregates)!.write(to: output)
        print("PASS: Swift parser/store/runtime harness (\(checks) assertions)")
    }
}
