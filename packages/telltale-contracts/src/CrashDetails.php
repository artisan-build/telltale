<?php

declare(strict_types=1);

namespace ArtisanBuild\TelltaleContracts;

use JsonSerializable;

final readonly class CrashDetails implements JsonSerializable
{
    public const SCHEMA_VERSION = 1;

    /** @var list<CrashFrame> */
    public array $frames;

    /** @var array<string, string|int|float|bool|null> */
    public array $metadata;

    /** @param array<mixed> $frames
     * @param  array<mixed>  $metadata
     */
    public function __construct(
        public string $reportId,
        public string $platform,
        public string $kind,
        public string $source,
        public string $reason,
        public bool $fatal,
        public string $occurredAt,
        public string $timestampPrecision,
        public int $occurrenceCount,
        array $frames = [],
        public ?string $reportingWindowStart = null,
        public ?string $reportingWindowEnd = null,
        public ?string $appVersion = null,
        public ?string $build = null,
        public ?string $trace = null,
        array $metadata = [],
    ) {
        if (preg_match('/^[a-f0-9]{64}$/D', $reportId) !== 1) {
            throw new ContractException('report_id must be a lowercase SHA-256 hex digest.');
        }
        $kinds = match ($platform.':'.$source) {
            'ios:metrickit_diagnostic' => ['crash', 'hang'],
            'ios:metrickit_exit' => ['memory_exit', 'watchdog_exit'],
            'android:application_exit_info' => ['crash', 'hang', 'memory_exit', 'watchdog_exit'],
            'android:jvm_exception' => ['crash'],
            default => [],
        };
        if (! in_array($kind, $kinds, true) || ($kind !== 'hang' && ! $fatal)) {
            throw new ContractException('Incompatible crash platform, kind, source or fatal flag.');
        }
        if ($kind === 'hang' && $fatal !== ($source === 'application_exit_info')) {
            throw new ContractException('MetricKit hangs are nonfatal; ApplicationExitInfo ANR deaths are fatal.');
        }
        CrashValue::text($reason, 255, 'reason');
        if (preg_match('/^[a-zA-Z0-9_.:-]+$/D', $reason) !== 1) {
            throw new ContractException('reason must be a machine classification.');
        }
        if ($occurrenceCount < 1 || $occurrenceCount > 1000000) {
            throw new ContractException('occurrence_count must be between 1 and 1000000.');
        }
        if (! in_array($timestampPrecision, ['exact', 'window_end'], true)
            || ($source === 'metrickit_exit' && $timestampPrecision !== 'window_end')) {
            throw new ContractException('Invalid timestamp_precision for source.');
        }
        CrashValue::timestamp($occurredAt);
        if (($reportingWindowStart === null) !== ($reportingWindowEnd === null)
            || ($timestampPrecision === 'window_end' && $reportingWindowEnd === null)) {
            throw new ContractException('Reporting window endpoints are required together.');
        }
        if ($reportingWindowStart !== null && $reportingWindowEnd !== null) {
            if (CrashValue::compareTimestamps($reportingWindowStart, $reportingWindowEnd) > 0
                || CrashValue::compareTimestamps($occurredAt, $reportingWindowStart) < 0
                || CrashValue::compareTimestamps($occurredAt, $reportingWindowEnd) > 0
                || ($timestampPrecision === 'window_end' && $occurredAt !== $reportingWindowEnd)) {
                throw new ContractException('Inconsistent crash timestamp and reporting window.');
            }
        }
        foreach (['app_version' => $appVersion, 'build' => $build] as $key => $value) {
            if ($value !== null) {
                CrashValue::text($value, 255, $key);
            }
        }
        if (! array_is_list($frames) || count($frames) > 64) {
            throw new ContractException('frames must be a list of at most 64 frames.');
        }
        foreach ($frames as $frame) {
            if (! $frame instanceof CrashFrame) {
                throw new ContractException('frames must contain only CrashFrame values.');
            }
        }
        $this->frames = $frames;
        if ($trace !== null) {
            CrashValue::text($trace, 16384, 'trace', empty: true);
        }
        $metadata = CrashValue::object($metadata, 'metadata');
        if (count($metadata) > 16) {
            throw new ContractException('metadata exceeds 16 keys.');
        }
        foreach ($metadata as $key => $value) {
            CrashValue::text($key, 255, 'metadata key');
            if (preg_match('/(?:credential|token|secret|password|app[_-]?id|install[_-]?id|ingest|authorization)/i', $key) === 1) {
                throw new ContractException('metadata must not contain identity or credential fields.');
            }
            if (is_string($value)) {
                CrashValue::text($value, 1024, 'metadata value', empty: true);
            } elseif (! is_null($value) && ! is_bool($value) && ! is_int($value) && ! (is_float($value) && is_finite($value))) {
                throw new ContractException('metadata values must be finite JSON scalars.');
            }
        }
        $this->metadata = $metadata;
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        CrashValue::keys($data, ['schema_version', 'report_id', 'platform', 'kind', 'source', 'reason', 'fatal', 'occurred_at', 'timestamp_precision', 'occurrence_count', 'frames', 'reporting_window_start', 'reporting_window_end', 'app_version', 'build', 'trace', 'metadata']);
        if (($data['schema_version'] ?? null) !== self::SCHEMA_VERSION || ! is_bool($data['fatal'] ?? null)
            || ! is_int($data['occurrence_count'] ?? null)) {
            throw new ContractException('Invalid crash schema_version, fatal or occurrence_count type.');
        }
        $frames = array_key_exists('frames', $data) ? $data['frames'] : [];
        if (! is_array($frames) || ! array_is_list($frames)) {
            throw new ContractException('frames must be a list.');
        }
        $parsedFrames = [];
        foreach ($frames as $frame) {
            $parsedFrames[] = CrashFrame::fromArray(CrashValue::object($frame, 'frame'));
        }

        return new self(
            reportId: CrashValue::text($data['report_id'] ?? null, 64, 'report_id'),
            platform: CrashValue::text($data['platform'] ?? null, 255, 'platform'),
            kind: CrashValue::text($data['kind'] ?? null, 255, 'kind'),
            source: CrashValue::text($data['source'] ?? null, 255, 'source'),
            reason: CrashValue::text($data['reason'] ?? null, 255, 'reason'),
            fatal: $data['fatal'],
            occurredAt: CrashValue::text($data['occurred_at'] ?? null, 255, 'occurred_at'),
            timestampPrecision: CrashValue::text($data['timestamp_precision'] ?? null, 255, 'timestamp_precision'),
            occurrenceCount: $data['occurrence_count'],
            frames: $parsedFrames,
            reportingWindowStart: CrashValue::optionalText($data, 'reporting_window_start'),
            reportingWindowEnd: CrashValue::optionalText($data, 'reporting_window_end'),
            appVersion: CrashValue::optionalText($data, 'app_version'),
            build: CrashValue::optionalText($data, 'build'),
            trace: array_key_exists('trace', $data) ? CrashValue::text($data['trace'], 16384, 'trace', empty: true) : null,
            metadata: array_key_exists('metadata', $data) ? CrashValue::object($data['metadata'], 'metadata') : [],
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = [
            'schema_version' => self::SCHEMA_VERSION,
            'report_id' => $this->reportId,
            'platform' => $this->platform,
            'kind' => $this->kind,
            'source' => $this->source,
            'reason' => $this->reason,
            'fatal' => $this->fatal,
            'occurred_at' => $this->occurredAt,
            'timestamp_precision' => $this->timestampPrecision,
            'occurrence_count' => $this->occurrenceCount,
            'frames' => array_map(static fn (CrashFrame $frame): array => $frame->toArray(), $this->frames),
        ];
        foreach (['reporting_window_start' => $this->reportingWindowStart, 'reporting_window_end' => $this->reportingWindowEnd,
            'app_version' => $this->appVersion, 'build' => $this->build, 'trace' => $this->trace] as $key => $value) {
            if ($value !== null) {
                $data[$key] = $value;
            }
        }
        if ($this->metadata !== []) {
            $data['metadata'] = $this->metadata;
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function fingerprint(): string
    {
        $locations = array_map(
            fn (CrashFrame $frame): string => $frame->fingerprintLocation($this->source === 'jvm_exception'),
            array_slice($this->frames, 0, 5),
        );

        return hash('sha256', json_encode([$this->platform, $this->kind, $this->reason, $locations], JSON_THROW_ON_ERROR));
    }

    /** Deterministic opaque ULID; its timestamp bits do not represent occurrence time. */
    public function eventId(): string
    {
        $bits = '00';
        foreach (str_split(substr($this->reportId, 0, 32)) as $digit) {
            $bits .= str_pad(decbin((int) hexdec($digit)), 4, '0', STR_PAD_LEFT);
        }
        $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
        $ulid = '';
        foreach (str_split($bits, 5) as $chunk) {
            $ulid .= $alphabet[intval($chunk, 2)];
        }

        return $ulid;
    }
}
