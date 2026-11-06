import Foundation
import MetricKit

enum TelltaleCrashesFunctions {
    static let runtime = CrashRuntime(
        store: CrashStore(
            directory: (FileManager.default.urls(for: .applicationSupportDirectory, in: .userDomainMask).first
                ?? URL(fileURLWithPath: NSHomeDirectory()).appendingPathComponent("Library/Application Support", isDirectory: true))
                .appendingPathComponent("TelltaleCrashes", isDirectory: true),
            launch: UUID().uuidString
        ),
        ready: { count in
            NativeElementBridge.sendNativeEvent(
                eventName: "ArtisanBuild\\TelltaleCrashes\\Events\\ReportsReady",
                payloadJson: "{\"schema_version\":1,\"pending_count\":\(count)}"
            )
        }
    )

    class Pending: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            BridgeResponse.success(data: runtime.pending())
        }
    }

    class Acknowledge: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            runtime.acknowledge(parameters["report_ids"] as? [String] ?? [])
            return BridgeResponse.success(data: ["scheduled": true])
        }
    }

    class SetSuppressed: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            if let value = parameters["suppressed"] as? Bool { runtime.setSuppressed(value) }
            return BridgeResponse.success(data: ["scheduled": true])
        }
    }
}

final class TelltaleMetricSubscriber: NSObject, MXMetricManagerSubscriber {
    static let shared = TelltaleMetricSubscriber()

    func didReceive(_ payloads: [MXDiagnosticPayload]) {
        TelltaleCrashesFunctions.runtime.collect {
            var reports = [(report: [String: Any], source: Data, currentProcess: Bool)]()
            for payload in payloads.prefix(32) {
                let start = CrashProjection.timestamp(payload.timeStampBegin)
                let end = CrashProjection.timestamp(payload.timeStampEnd)
                let diagnostics: [(MXDiagnostic, String)] = (payload.crashDiagnostics ?? []).map { ($0, "crash") }
                    + (payload.hangDiagnostics ?? []).map { ($0, "hang") }
                for (diagnostic, kind) in diagnostics.prefix(32) {
                    let source = diagnostic.jsonRepresentation()
                    if let report = CrashProjection.diagnostic(source, kind: kind, start: start, end: end,
                                                               build: diagnostic.metaData.applicationBuildVersion,
                                                               version: diagnostic.applicationVersion) {
                        // MetricKit vends previous usage diagnostics, including late delivery in B.
                        reports.append((report, source, false))
                    }
                }
            }
            return reports
        }
    }

    func didReceive(_ payloads: [MXMetricPayload]) {
        TelltaleCrashesFunctions.runtime.collect {
            payloads.prefix(32).flatMap { payload in
                let source = payload.jsonRepresentation()
                return CrashProjection.exits(source,
                                             start: CrashProjection.timestamp(payload.timeStampBegin),
                                             end: CrashProjection.timestamp(payload.timeStampEnd),
                                             build: payload.metaData?.applicationBuildVersion,
                                             multipleVersions: payload.includesMultipleApplicationVersions)
                    .map { (report: $0, source: source, currentProcess: false) }
            }
        }
    }
}

func registerTelltaleCrashes() {
    // Registration only. Serialization, history reads, parsing and persistence run off-main.
    _ = TelltaleCrashesFunctions.runtime
    MXMetricManager.shared.add(TelltaleMetricSubscriber.shared)
    DispatchQueue.global(qos: .utility).async {
        TelltaleMetricSubscriber.shared.didReceive(MXMetricManager.shared.pastDiagnosticPayloads)
        TelltaleMetricSubscriber.shared.didReceive(MXMetricManager.shared.pastPayloads)
    }
}
