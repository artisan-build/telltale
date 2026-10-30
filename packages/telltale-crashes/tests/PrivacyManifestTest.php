<?php

declare(strict_types=1);

use ArtisanBuild\TelltaleCrashes\Build\PrivacyManifest;
use Illuminate\Filesystem\Filesystem;

it('merges into the app resource without losing host or other plugin declarations in either arrival order', function (bool $pluginFirst): void {
    $root = sys_get_temp_dir().'/telltale-privacy-'.bin2hex(random_bytes(8));
    mkdir($root.'/NativePHP', recursive: true);
    $path = $root.'/NativePHP/PrivacyInfo.xcprivacy';
    $merger = new PrivacyManifest;
    try {
        if ($pluginFirst) {
            $merger->install($root);
        }
        $host = new DOMDocument;
        $host->loadXML('<?xml version="1.0"?><plist version="1.0"><dict>
            <key>NSPrivacyTracking</key><true/>
            <key>NSPrivacyTrackingDomains</key><array><string>host.example.test</string></array>
            <key>NSPrivacyCollectedDataTypes</key><array>
                <dict><key>NSPrivacyCollectedDataType</key><string>NSPrivacyCollectedDataTypeDeviceID</string>
                <key>NSPrivacyCollectedDataTypeLinked</key><false/>
                <key>NSPrivacyCollectedDataTypeTracking</key><true/>
                <key>NSPrivacyCollectedDataTypePurposes</key><array><string>NSPrivacyCollectedDataTypePurposeAnalytics</string></array></dict>
                <dict><key>NSPrivacyCollectedDataType</key><string>NSPrivacyCollectedDataTypeEmailAddress</string>
                <key>NSPrivacyCollectedDataTypeLinked</key><true/></dict>
            </array>
            <key>NSPrivacyAccessedAPITypes</key><array><dict>
                <key>NSPrivacyAccessedAPIType</key><string>NSPrivacyAccessedAPICategoryUserDefaults</string>
                <key>NSPrivacyAccessedAPITypeReasons</key><array><string>CA92.1</string></array>
            </dict></array>
        </dict></plist>');
        if ($pluginFirst) {
            // A host contributor writes after our first hook; the next merge still preserves it.
            $installed = new DOMDocument;
            $installed->load($path);
            $host->documentElement->firstElementChild->appendChild($host->importNode($installed->createElement('key', 'HostMarker'), true));
            $host->documentElement->firstElementChild->appendChild($host->createElement('string', 'late host'));
        }
        $host->save($path);
        $merger->install($root);
        $once = file_get_contents($path);
        $merger->install($root);
        expect(file_get_contents($path))->toBe($once);
        $merged = new DOMDocument;
        $merged->load($path);
        $xpath = new DOMXPath($merged);
        expect($xpath->query('/plist/dict/key[.="NSPrivacyTracking"]/following-sibling::*[1][self::true]')->length)->toBe(1)
            ->and($xpath->query('//string[.="host.example.test"]')->length)->toBe(1)
            ->and($xpath->query('//string[.="CA92.1"]')->length)->toBe(1)
            ->and($xpath->query('//string[.="NSPrivacyCollectedDataTypeEmailAddress"]')->length)->toBe(1)
            ->and($xpath->query('//string[.="NSPrivacyCollectedDataTypeCrashData"]')->length)->toBe(1)
            ->and($xpath->query('//string[.="NSPrivacyCollectedDataTypePerformanceData"]')->length)->toBe(1);
        $id = '//dict[key="NSPrivacyCollectedDataType" and string="NSPrivacyCollectedDataTypeDeviceID"]';
        expect($xpath->query($id)->length)->toBe(1)
            ->and($xpath->query($id.'/key[.="NSPrivacyCollectedDataTypeLinked"]/following-sibling::*[1][self::true]')->length)->toBe(1)
            ->and($xpath->query($id.'/key[.="NSPrivacyCollectedDataTypeTracking"]/following-sibling::*[1][self::true]')->length)->toBe(1)
            ->and($xpath->query($id.'//string[.="NSPrivacyCollectedDataTypePurposeAnalytics"]')->length)->toBe(1)
            ->and($xpath->query($id.'//string[.="NSPrivacyCollectedDataTypePurposeAppFunctionality"]')->length)->toBe(1);
        if ($pluginFirst) {
            expect($xpath->query('//string[.="late host"]')->length)->toBe(1);
        }
    } finally {
        (new Filesystem)->deleteDirectory($root);
    }
})->with([false, true]);

it('fails closed on an invalid host manifest without overwriting it', function (): void {
    $root = sys_get_temp_dir().'/telltale-privacy-'.bin2hex(random_bytes(8));
    mkdir($root.'/NativePHP', recursive: true);
    $path = $root.'/NativePHP/PrivacyInfo.xcprivacy';
    file_put_contents($path, 'invalid host plist');
    try {
        expect(fn () => (new PrivacyManifest)->install($root))->toThrow(RuntimeException::class);
        expect(file_get_contents($path))->toBe('invalid host plist');
    } finally {
        (new Filesystem)->deleteDirectory($root);
    }
});

it('runs the supported hook options on disposable iOS resources and leaves Android untouched', function (): void {
    $root = sys_get_temp_dir().'/telltale-privacy-'.bin2hex(random_bytes(8));
    mkdir($root.'/NativePHP', recursive: true);
    try {
        $options = ['--platform' => 'android', '--build-path' => $root, '--plugin-path' => dirname(__DIR__), '--app-id' => 'test.app', '--config' => '{}', '--plugins' => '[]'];
        $this->artisan('nativephp:telltale-crashes:privacy', $options)->assertExitCode(0);
        expect(file_exists($root.'/NativePHP/PrivacyInfo.xcprivacy'))->toBeFalse();
        $options['--platform'] = 'ios';
        $this->artisan('nativephp:telltale-crashes:privacy', $options)->assertExitCode(0);
        expect(file_exists($root.'/NativePHP/PrivacyInfo.xcprivacy'))->toBeTrue();
    } finally {
        (new Filesystem)->deleteDirectory($root);
    }
});
