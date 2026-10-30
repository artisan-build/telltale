<?php

declare(strict_types=1);

use ArtisanBuild\TelltaleContracts\ContractException;
use ArtisanBuild\TelltaleContracts\CrashDetails;
use ArtisanBuild\TelltaleContracts\CrashFrame;
use ArtisanBuild\TelltaleContracts\EnvelopeDecoder;
use ArtisanBuild\TelltaleContracts\EnvelopeV1;
use ArtisanBuild\TelltaleContracts\EnvelopeV2;
use ArtisanBuild\TelltaleContracts\Event;
use ArtisanBuild\TelltaleContracts\EventType;

function crashPayload(): array
{
    return json_decode(file_get_contents(__DIR__.'/fixtures/crash-v2.json'), true, flags: JSON_THROW_ON_ERROR);
}

function crashData(array $overrides = []): array
{
    return array_replace(crashPayload()['events'][1]['crash'], $overrides);
}

it('roundtrips mixed v2 JSON and preserves crashed build and binary inputs', function (): void {
    $fixture = json_decode(file_get_contents(__DIR__.'/fixtures/crash-v2.json'), flags: JSON_THROW_ON_ERROR);
    $canonical = json_encode($fixture, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $envelope = EnvelopeDecoder::decode($canonical);

    expect($envelope)->toBeInstanceOf(EnvelopeV2::class)
        ->and($envelope->toJson())->toBe($canonical)
        ->and(EnvelopeV2::fromJson($envelope->toJson())->toArray())->toBe($envelope->toArray())
        ->and($envelope->events[1]->crash->appVersion)->toBe('test-crashed-version')
        ->and($envelope->events[1]->crash->build)->toBe('test-crashed-build');
});

it('keeps ordinary v1 JSON byte-identical through the shared decoder', function (): void {
    $payload = crashPayload();
    $payload['envelope_version'] = 1;
    $payload['events'] = [$payload['events'][0]];
    $payload['events'][0]['props'] = new stdClass;
    $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    expect(EnvelopeDecoder::decode($json))->toBeInstanceOf(EnvelopeV1::class)
        ->and(EnvelopeDecoder::decode($json)->toJson())->toBe($json);
});

it('rejects crashes in v1 through both public construction paths', function (): void {
    $payload = crashPayload();
    $payload['envelope_version'] = 1;
    expect(fn () => EnvelopeV1::fromArray($payload))->toThrow(ContractException::class);
    $event = Event::fromArray($payload['events'][1]);
    expect(fn () => new EnvelopeV1('test', 0, [$event]))->toThrow(ContractException::class);
});

it('rejects unknown or incorrectly typed envelope versions', function (mixed $version): void {
    $payload = crashPayload();
    $payload['envelope_version'] = $version;
    expect(fn () => EnvelopeDecoder::decode($payload))->toThrow(ContractException::class)
        ->and(fn () => EnvelopeDecoder::decode(json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)))->toThrow(ContractException::class);
})->with([0, -1, 3, 99, '2', 2.0, null]);

it('roundtrips supported crash classifications', function (array $overrides): void {
    $data = crashData($overrides);
    $payload = crashPayload();
    $payload['events'][1]['crash'] = $data;
    $envelope = EnvelopeV2::fromArray($payload);
    expect(EnvelopeV2::fromJson($envelope->toJson())->events[1]->crash->toArray())->toEqual($data);
})->with([
    'ios crash' => [[]],
    'ios nonfatal hang' => [['kind' => 'hang', 'fatal' => false]],
    'android crash' => [['platform' => 'android', 'source' => 'application_exit_info']],
    'android fatal ANR' => [['platform' => 'android', 'source' => 'application_exit_info', 'kind' => 'hang']],
    'android low memory' => [['platform' => 'android', 'source' => 'application_exit_info', 'kind' => 'memory_exit']],
    'jvm exception' => [['platform' => 'android', 'source' => 'jvm_exception']],
    'ios memory aggregate' => [['source' => 'metrickit_exit', 'kind' => 'memory_exit', 'timestamp_precision' => 'window_end', 'occurrence_count' => 3,
        'reporting_window_start' => '2026-10-02T12:34:56Z', 'reporting_window_end' => '2026-10-03T12:34:56Z']],
    'ios watchdog aggregate' => [['source' => 'metrickit_exit', 'kind' => 'watchdog_exit', 'timestamp_precision' => 'window_end',
        'reporting_window_start' => '2026-10-02T12:34:56Z', 'reporting_window_end' => '2026-10-03T12:34:56Z']],
]);

it('rejects invalid crash values', function (array $overrides): void {
    expect(fn () => CrashDetails::fromArray(crashData($overrides)))->toThrow(ContractException::class);
})->with([
    'schema version' => [['schema_version' => 2]],
    'string schema version' => [['schema_version' => '1']],
    'report digest' => [['report_id' => 'ABC']],
    'uppercase digest' => [['report_id' => str_repeat('A', 64)]],
    'platform type' => [['platform' => 1]],
    'unsupported platform' => [['platform' => 'desktop']],
    'kind' => [['kind' => 'error']],
    'ios android source' => [['source' => 'application_exit_info']],
    'jvm hang' => [['platform' => 'android', 'source' => 'jvm_exception', 'kind' => 'hang']],
    'exit diagnostic' => [['kind' => 'memory_exit']],
    'nonfatal crash' => [['fatal' => false]],
    'nonfatal memory exit' => [['platform' => 'android', 'source' => 'application_exit_info', 'kind' => 'memory_exit', 'fatal' => false]],
    'nonfatal watchdog exit' => [['platform' => 'android', 'source' => 'application_exit_info', 'kind' => 'watchdog_exit', 'fatal' => false]],
    'fatal MetricKit hang' => [['kind' => 'hang', 'fatal' => true]],
    'nonfatal Android ANR death' => [['platform' => 'android', 'source' => 'application_exit_info', 'kind' => 'hang', 'fatal' => false]],
    'fatal type' => [['fatal' => 1]],
    'count zero' => [['occurrence_count' => 0]],
    'count over cap' => [['occurrence_count' => 1000001]],
    'count float' => [['occurrence_count' => 1.0]],
    'reason text' => [['reason' => 'message with spaces']],
    'reason empty' => [['reason' => '']],
    'reason over cap' => [['reason' => str_repeat('x', 256)]],
    'timestamp date' => [['occurred_at' => '2026-02-30T12:00:00Z']],
    'timestamp syntax' => [['occurred_at' => 'yesterday']],
    'precision' => [['timestamp_precision' => 'approximate']],
    'missing window' => [['timestamp_precision' => 'window_end']],
    'one endpoint' => [['reporting_window_start' => '2026-10-02T12:34:56Z']],
    'reversed window' => [['reporting_window_start' => '2026-10-04T12:34:56Z', 'reporting_window_end' => '2026-10-03T12:34:56Z']],
    'outside window' => [['reporting_window_start' => '2026-10-01T12:34:56Z', 'reporting_window_end' => '2026-10-02T12:34:56Z']],
    'not window end' => [['timestamp_precision' => 'window_end', 'reporting_window_start' => '2026-10-01T12:34:56Z', 'reporting_window_end' => '2026-10-04T12:34:56Z']],
    'exact aggregate' => [['source' => 'metrickit_exit', 'kind' => 'memory_exit']],
    'app version limit' => [['app_version' => str_repeat('v', 256)]],
    'build null' => [['build' => null]],
    'trace null' => [['trace' => null]],
    'trace bytes' => [['trace' => str_repeat('a', 16385)]],
    'trace UTF8' => [['trace' => "\xff"]],
    'trace binary' => [['trace' => "binary\0payload"]],
    'opaque base64' => [['tombstone_base64' => base64_encode(str_repeat('binary', 20))]],
    'malformed opaque base64' => [['tombstone_base64' => '!invalid!']],
    'opaque field' => [['tombstone_base64' => 'AAAA']],
    'app identity' => [['app_id' => 'forged']],
    'metadata keys' => [['metadata' => array_fill_keys(range('a', 'q'), 'x')]],
    'metadata nested' => [['metadata' => ['nested' => ['x' => true]]]],
    'metadata value cap' => [['metadata' => ['x' => str_repeat('v', 1025)]]],
    'metadata key cap' => [['metadata' => [str_repeat('k', 256) => true]]],
    'metadata UTF8' => [['metadata' => ['x' => "\xff"]]],
    'metadata infinity' => [['metadata' => ['x' => INF]]],
    'metadata credentials' => [['metadata' => ['install_token' => 'forged']]],
    'metadata identity' => [['metadata' => ['app_id' => 'forged']]],
    'metadata list' => [['metadata' => ['x']]],
    'metadata null' => [['metadata' => null]],
    'frame count' => [['frames' => array_fill(0, 65, ['symbol' => 'Test.run'])]],
    'frames null' => [['frames' => null]],
    'frames map' => [['frames' => ['a' => ['symbol' => 'Test.run']]]],
]);

it('rejects malformed frames', function (array $frame): void {
    expect(fn () => CrashFrame::fromArray($frame))->toThrow(ContractException::class);
})->with([
    [[]],
    [['binary_name' => 'Test']],
    [['address' => '0x1234']],
    [['symbol' => null]],
    [['symbol' => '']],
    [['symbol' => str_repeat('s', 1025)]],
    [['binary_name' => str_repeat('n', 256), 'offset' => '0x1']],
    [['binary_uuid' => 'invalid', 'offset' => '0x1']],
    [['symbol' => "\xff"]],
    [['symbol' => 'Test.run', 'raw' => 'opaque']],
    [['symbol' => 'Test.run', 'address' => 123]],
    [['symbol' => 'Test.run', 'offset' => '-0x1']],
    [['symbol' => 'Test.run', 'offset' => '0x01']],
    [['symbol' => 'Test.run', 'offset' => '0xAB']],
    [['symbol' => 'Test.run', 'offset' => '0x10000000000000000']],
    [['symbol' => 'Test.run', 'address' => '0x1', 'offset' => '0x2']],
    [['symbol' => 'Test.run', 'address' => '0x10', 'offset' => '0x1', 'load_address' => '0x10']],
]);

it('enforces type and detail consistency including the event timestamp', function (): void {
    $base = crashPayload()['events'][1];
    $bad = [array_replace($base, ['ts' => '2026-10-03T12:34:57Z']),
        array_replace($base, ['type' => 'event']), array_replace($base, ['crash' => null]),
        array_replace($base, ['error' => ['class' => 'Test', 'message' => '', 'file' => 'test', 'line' => 1, 'stack' => []]])];
    $missing = $base;
    unset($missing['crash']);
    $bad[] = $missing;
    foreach ($bad as $event) {
        expect(fn () => Event::fromArray($event))->toThrow(ContractException::class);
    }
    $details = CrashDetails::fromArray(crashData());
    expect(fn () => new Event($base['event_id'], 'test', EventType::Event, $base['ts'], 'test', [], crash: $details))->toThrow(ContractException::class);
});

it('requires all crash identity classification and occurrence fields', function (): void {
    foreach (['schema_version', 'report_id', 'platform', 'kind', 'source', 'reason', 'fatal', 'occurred_at', 'timestamp_precision', 'occurrence_count'] as $key) {
        $data = crashData();
        unset($data[$key]);
        expect(fn () => CrashDetails::fromArray($data))->toThrow(ContractException::class);
    }
});

it('accepts unknown build and empty frames without inventing metadata', function (): void {
    $data = crashData(['frames' => []]);
    unset($data['app_version'], $data['build'], $data['metadata']);
    $details = CrashDetails::fromArray($data);
    expect($details->toArray())->toBe($data)
        ->and($details->appVersion)->toBeNull()
        ->and($details->build)->toBeNull();
});

it('compares reporting windows as instants across timezone offsets', function (): void {
    $data = crashData(['timestamp_precision' => 'window_end',
        'reporting_window_start' => '2026-10-03T14:34:56+02:00',
        'reporting_window_end' => '2026-10-03T12:34:56Z']);
    expect(CrashDetails::fromArray($data)->reportingWindowStart)->toBe($data['reporting_window_start']);
    $data['reporting_window_start'] = '2026-10-03T14:34:57+02:00';
    expect(fn () => CrashDetails::fromArray($data))->toThrow(ContractException::class);
});

it('rejects malformed direct DTO construction rather than only validating decoding', function (): void {
    $args = [hash('sha256', 'direct'), 'ios', 'crash', 'metrickit_diagnostic', 'signal_11', true, '2026-10-03T12:34:56Z', 'exact', 1];
    expect(fn () => new CrashDetails(...array_replace($args, [5 => false])))->toThrow(ContractException::class)
        ->and(fn () => new CrashDetails(...array_merge($args, [['not-a-frame']])))->toThrow(ContractException::class)
        ->and(fn () => new CrashDetails(...array_merge($args, [[], null, null, null, null, null, ['bad' => new stdClass]])))->toThrow(ContractException::class);
});

it('does not truncate fractional seconds while validating reporting windows', function (): void {
    $data = crashData(['occurred_at' => '2026-10-03T12:34:56.1234567Z',
        'timestamp_precision' => 'window_end',
        'reporting_window_start' => '2026-10-03T12:34:56.1234566Z',
        'reporting_window_end' => '2026-10-03T12:34:56.1234567Z']);
    expect(CrashDetails::fromArray($data)->occurredAt)->toBe($data['occurred_at']);
    $data['reporting_window_start'] = '2026-10-03T12:34:56.1234568Z';
    expect(fn () => CrashDetails::fromArray($data))->toThrow(ContractException::class);
});

it('normalizes full-width unsigned addresses without float precision loss', function (): void {
    $frame = new CrashFrame(binaryUuid: 'aabbccdd', address: '0xffffffffffffffff', offset: '0x1234');
    expect($frame->loadAddress)->toBe('0xffffffffffffedcb');
    $fromLoad = new CrashFrame(binaryName: 'Test', address: '0xffffffffffffffff', loadAddress: '0xffffffffffffedcb');
    expect($fromLoad->offset)->toBe('0x1234')
        ->and((new CrashFrame(binaryName: 'Test', address: '0x0', offset: '0x0'))->loadAddress)->toBe('0x0');
});

it('groups ASLR relocation and splits changed top offsets while retaining symbol inputs', function (): void {
    $original = CrashDetails::fromArray(crashData());
    $movedData = crashData();
    $movedData['frames'][0]['address'] = '0x70001234';
    $movedData['frames'][0]['load_address'] = '0x70000000';
    $moved = CrashDetails::fromArray($movedData);
    expect($original->fingerprint())->toBe($moved->fingerprint())
        ->and($original->frames[0]->address)->toBe('0xffff000000001234')
        ->and($moved->frames[0]->loadAddress)->toBe('0x70000000');
    $movedData['frames'][0]['address'] = '0x70001235';
    $movedData['frames'][0]['offset'] = '0x1235';
    expect(CrashDetails::fromArray($movedData)->fingerprint())->not->toBe($original->fingerprint());
});

it('uses JVM symbols rather than exception messages, reports, build or trace strings', function (): void {
    $data = crashData(['platform' => 'android', 'source' => 'jvm_exception', 'reason' => 'uncaught_exception',
        'frames' => [['symbol' => 'com.test.Worker.run']], 'trace' => "IllegalStateException: message one\n at com.test.Worker.run(Worker.java:12)"]);
    $first = CrashDetails::fromArray($data);
    $second = CrashDetails::fromArray(array_replace($data, ['trace' => "IllegalStateException: message two\n at com.test.Worker.run(Worker.java:12)",
        'report_id' => hash('sha256', 'another'), 'build' => 'another-build']));
    expect($first->fingerprint())->toBe($second->fingerprint());
    $data['frames'][0]['symbol'] = 'com.test.Other.run';
    expect(CrashDetails::fromArray($data)->fingerprint())->not->toBe($first->fingerprint());
});

it('uses only the top five frames and deterministic empty-trace fallback', function (): void {
    $data = crashData(['frames' => []]);
    $empty = CrashDetails::fromArray($data);
    expect($empty->fingerprint())->toBe(hash('sha256', '["ios","crash","signal_11",[]]'));
    $data['frames'] = array_fill(0, 6, ['symbol' => 'Test.run']);
    $before = CrashDetails::fromArray($data)->fingerprint();
    $data['frames'][5]['symbol'] = 'Test.ignored';
    expect(CrashDetails::fromArray($data)->fingerprint())->toBe($before);
});

it('uses binary name and relative offset when UUID is unavailable', function (): void {
    $data = crashData(['frames' => [['binary_name' => 'Test', 'offset' => '0x1', 'address' => '0x1001']]]);
    $before = CrashDetails::fromArray($data)->fingerprint();
    $data['frames'][0]['address'] = '0x2001';
    expect(CrashDetails::fromArray($data)->fingerprint())->toBe($before);
    $data['frames'][0]['offset'] = '0x2';
    expect(CrashDetails::fromArray($data)->fingerprint())->not->toBe($before);
});

it('accepts upper boundaries and empty traces without retaining opaque fields', function (): void {
    $data = crashData(['occurrence_count' => 1000000, 'reason' => str_repeat('r', 255),
        'frames' => array_fill(0, 64, ['symbol' => str_repeat('s', 1024)]),
        'trace' => str_repeat("at Test.run()\n", 1170).'abcd',
        'metadata' => array_fill_keys(range('a', 'p'), str_repeat('v', 1024))]);
    expect(strlen($data['trace']))->toBe(16384);
    $details = CrashDetails::fromArray($data);
    expect($details->toArray())->toEqual($data)
        ->and(CrashDetails::fromArray(crashData(['trace' => '', 'frames' => []]))->trace)->toBe('');
});

it('derives canonical stable ULIDs from the first 128 digest bits with fixed vectors', function (): void {
    $zero = CrashDetails::fromArray(crashData(['report_id' => str_repeat('0', 64)]));
    $max = CrashDetails::fromArray(crashData(['report_id' => str_repeat('f', 64)]));
    $sample = CrashDetails::fromArray(crashData());
    expect($zero->eventId())->toBe(str_repeat('0', 26))
        ->and($max->eventId())->toBe('7'.str_repeat('Z', 25))
        ->and($sample->eventId())->toBe('014D2PF2DBSQQG28T5CY4TQKFF')
        ->and(CrashDetails::fromArray($sample->toArray())->eventId())->toBe($sample->eventId());
    $event = crashPayload()['events'][1];
    $event['event_id'] = $sample->eventId();
    expect(Event::fromArray($event)->eventId)->toBe($sample->eventId());
});

it('never projects supplied envelope or event app identities or fingerprints', function (): void {
    $payload = crashPayload();
    $payload['app_id'] = 'forged-envelope';
    $payload['events'][1]['install_id'] = 'forged-install';
    $payload['events'][1]['app_id'] = 'forged-event';
    $payload['events'][1]['fingerprint'] = 'forged-fingerprint';
    $json = EnvelopeDecoder::decode($payload)->toJson();
    foreach (['forged-envelope', 'forged-install', 'forged-event', 'forged-fingerprint'] as $value) {
        expect($json)->not->toContain($value);
    }
    expect(fn () => CrashDetails::fromArray(crashData(['fingerprint' => 'forged'])))->toThrow(ContractException::class);
});
