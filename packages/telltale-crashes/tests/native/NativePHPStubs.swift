import Foundation

// Protocol surface only; the SDK typecheck uses the real Foundation and MetricKit.
protocol BridgeFunction {
    func execute(parameters: [String: Any]) throws -> [String: Any]
}

enum BridgeResponse {
    static func success(data: [String: Any]) -> [String: Any] { ["status": "success", "data": data] }
}

enum NativeElementBridge {
    static func sendNativeEvent(eventName: String, payloadJson: String) {}
}
