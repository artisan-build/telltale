#!/usr/bin/env bash
set -euo pipefail
PACKAGE="$(cd "$(dirname "$0")/../.." && pwd)"
BUILD="$PACKAGE/.native-build/privacy-resource"
mkdir -p "$BUILD/NativePHP" "$BUILD/PrivacyFixture.xcodeproj"
cat > "$BUILD/NativePHP/main.m" <<'SOURCE'
#import <UIKit/UIKit.h>
int main(int argc, char *argv[]) { return 0; }
SOURCE
cat > "$BUILD/PrivacyFixture.xcodeproj/project.pbxproj" <<'PROJECT'
// !$*UTF8*$!
{
    archiveVersion = 1;
    classes = {};
    objectVersion = 77;
    objects = {
        A00000000000000000000001 = {isa = PBXProject; buildConfigurationList = A00000000000000000000002; compatibilityVersion = "Xcode 16.0"; developmentRegion = en; hasScannedForEncodings = 0; knownRegions = (en, Base); mainGroup = A00000000000000000000003; productRefGroup = A00000000000000000000004; projectDirPath = ""; projectRoot = ""; targets = (A00000000000000000000005); };
        A00000000000000000000002 = {isa = XCConfigurationList; buildConfigurations = (A00000000000000000000006); defaultConfigurationIsVisible = 0; defaultConfigurationName = Debug; };
        A00000000000000000000003 = {isa = PBXGroup; children = (A00000000000000000000007, A00000000000000000000004); sourceTree = "<group>"; };
        A00000000000000000000004 = {isa = PBXGroup; children = (A00000000000000000000008); name = Products; sourceTree = "<group>"; };
        A00000000000000000000005 = {isa = PBXNativeTarget; buildConfigurationList = A00000000000000000000009; buildPhases = (A00000000000000000000010, A00000000000000000000011, A00000000000000000000012); buildRules = (); dependencies = (); fileSystemSynchronizedGroups = (A00000000000000000000007); name = PrivacyFixture; productName = PrivacyFixture; productReference = A00000000000000000000008; productType = "com.apple.product-type.application"; };
        A00000000000000000000006 = {isa = XCBuildConfiguration; buildSettings = {SDKROOT = iphoneos; IPHONEOS_DEPLOYMENT_TARGET = 18.0; }; name = Debug; };
        A00000000000000000000007 = {isa = PBXFileSystemSynchronizedRootGroup; path = NativePHP; sourceTree = "<group>"; };
        A00000000000000000000008 = {isa = PBXFileReference; explicitFileType = wrapper.application; path = PrivacyFixture.app; sourceTree = BUILT_PRODUCTS_DIR; };
        A00000000000000000000009 = {isa = XCConfigurationList; buildConfigurations = (A00000000000000000000013); defaultConfigurationIsVisible = 0; defaultConfigurationName = Debug; };
        A00000000000000000000010 = {isa = PBXSourcesBuildPhase; buildActionMask = 2147483647; files = (); runOnlyForDeploymentPostprocessing = 0; };
        A00000000000000000000011 = {isa = PBXFrameworksBuildPhase; buildActionMask = 2147483647; files = (); runOnlyForDeploymentPostprocessing = 0; };
        A00000000000000000000012 = {isa = PBXResourcesBuildPhase; buildActionMask = 2147483647; files = (); runOnlyForDeploymentPostprocessing = 0; };
        A00000000000000000000013 = {isa = XCBuildConfiguration; buildSettings = {PRODUCT_BUNDLE_IDENTIFIER = test.telltale.privacy; PRODUCT_NAME = "$(TARGET_NAME)"; GENERATE_INFOPLIST_FILE = YES; TARGETED_DEVICE_FAMILY = "1,2"; CODE_SIGNING_ALLOWED = NO; }; name = Debug; };
    };
    rootObject = A00000000000000000000001;
}
PROJECT
php -r 'require $argv[1]."/vendor/autoload.php"; (new ArtisanBuild\TelltaleCrashes\Build\PrivacyManifest)->install($argv[2]);' "$PACKAGE" "$BUILD"
xcodebuild -quiet -project "$BUILD/PrivacyFixture.xcodeproj" -scheme PrivacyFixture -sdk iphonesimulator -destination 'generic/platform=iOS Simulator' \
    -configuration Debug -derivedDataPath "$BUILD/DerivedData" CODE_SIGNING_ALLOWED=NO build
MANIFEST="$BUILD/DerivedData/Build/Products/Debug-iphonesimulator/PrivacyFixture.app/PrivacyInfo.xcprivacy"
test -f "$MANIFEST"
plutil -lint "$MANIFEST"
echo "PASS: merged privacy manifest is at the built iOS fixture app root (no device or full NativePHP app)"
