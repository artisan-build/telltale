import Foundation

final class CrashRuntime: @unchecked Sendable {
    private let worker = DispatchQueue(label: "com.artisanbuild.telltale.crashes", qos: .utility)
    private let lock = NSLock()
    private let store: CrashStore
    private let ready: (Int) -> Void
    private var snapshot = [[String: Any]]()
    private var suppressed = false
    private var revision = 0

    init(store: CrashStore, ready: @escaping (Int) -> Void) {
        self.store = store
        self.ready = ready
        worker.async {
            store.load()
            self.lock.lock()
            self.suppressed = self.suppressed || store.suppressed
            self.lock.unlock()
            self.publish()
        }
    }

    // Only a bounded in-memory copy occurs while the bridge holds this lock.
    func pending() -> [String: Any] {
        lock.lock()
        defer { lock.unlock() }
        return ["schema_version": 1, "reports": suppressed ? [] : snapshot,
                "revision": revision, "suppressed": suppressed]
    }

    func collect(_ operation: @escaping () -> [(report: [String: Any], source: Data, currentProcess: Bool)]) {
        worker.async {
            self.lock.lock()
            let disabled = self.suppressed
            self.lock.unlock()
            guard !disabled, !self.store.suppressed else { return }
            for item in operation().prefix(CrashStore.maxReports) {
                self.lock.lock()
                let stopped = self.suppressed
                self.lock.unlock()
                guard !stopped else { break }
                _ = self.store.persist(report: item.report, source: item.source, currentProcess: item.currentProcess)
            }
            self.publish()
        }
    }

    func acknowledge(_ ids: [String]) {
        let bounded = Array(ids.prefix(CrashStore.maxReports))
        worker.async {
            _ = self.store.acknowledge(bounded)
            self.publish()
        }
    }

    func setSuppressed(_ value: Bool) {
        // Hide immediately; slow disk removal never blocks the host bridge.
        if value {
            lock.lock()
            suppressed = true
            snapshot = []
            lock.unlock()
        }
        worker.async {
            let saved = self.store.setSuppressed(value)
            self.lock.lock()
            // A failed opt-out write must not resume delivery in this process.
            if saved { self.suppressed = value }
            self.lock.unlock()
            self.publish()
        }
    }

    private func publish() {
        let reports = store.pending()
        lock.lock()
        snapshot = suppressed ? [] : reports
        revision += 1
        let count = snapshot.count
        lock.unlock()
        // Completion emits on the background worker even if an early poll was empty.
        ready(count)
    }
}
