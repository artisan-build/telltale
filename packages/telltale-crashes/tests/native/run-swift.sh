#!/bin/bash
set -euo pipefail
PACKAGE="$(cd "$(dirname "$0")/../.." && pwd)"
mkdir -p "$PACKAGE/.native-build"
swiftc -swift-version 5 \
  "$PACKAGE/resources/ios/CrashProjection.swift" \
  "$PACKAGE/resources/ios/CrashStore.swift" \
  "$PACKAGE/resources/ios/CrashRuntime.swift" \
  "$PACKAGE/tests/native/SwiftHarness.swift" \
  -o "$PACKAGE/.native-build/swift-harness"
"$PACKAGE/.native-build/swift-harness" "$PACKAGE"
xcrun swiftc -swift-version 5 -target arm64-apple-ios18.0 \
  -sdk "$(xcrun --sdk iphoneos --show-sdk-path)" -typecheck \
  "$PACKAGE/resources/ios/CrashProjection.swift" \
  "$PACKAGE/resources/ios/CrashStore.swift" \
  "$PACKAGE/resources/ios/CrashRuntime.swift" \
  "$PACKAGE/resources/ios/TelltaleCrashesFunctions.swift" \
  "$PACKAGE/tests/native/NativePHPStubs.swift"
