<?php

declare(strict_types=1);

namespace ArtisanBuild\TelltaleContracts;

use DateTimeImmutable;
use stdClass;

/** @internal */
final class CrashValue
{
    public static function text(mixed $value, int $limit, string $field, bool $empty = false): string
    {
        if (! is_string($value) || (! $empty && $value === '') || strlen($value) > $limit
            || preg_match('//u', $value) !== 1 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value) === 1) {
            throw new ContractException("{$field} must be bounded UTF-8 text.");
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    public static function optionalText(array $data, string $key, int $limit = 255): ?string
    {
        return array_key_exists($key, $data) ? self::text($data[$key], $limit, $key) : null;
    }

    /** @return array<string, mixed> */
    public static function object(mixed $value, string $field): array
    {
        if ($value instanceof stdClass) {
            $value = get_object_vars($value);
        }
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new ContractException("{$field} must be an object.");
        }
        foreach ($value as $key => $_) {
            if (! is_string($key)) {
                throw new ContractException("{$field} keys must be strings.");
            }
        }

        return $value;
    }

    /** @param array<string, mixed> $data
     * @param  list<string>  $allowed
     */
    public static function keys(array $data, array $allowed): void
    {
        if (array_diff(array_keys($data), $allowed) !== []) {
            throw new ContractException('Unknown crash field.');
        }
    }

    public static function timestamp(string $value): DateTimeImmutable
    {
        self::text($value, 255, 'timestamp');
        Event::assertTimestamp($value);

        return new DateTimeImmutable($value);
    }

    public static function hex(?string $value): void
    {
        if ($value !== null && preg_match('/^0x(?:0|[1-9a-f][0-9a-f]{0,15})$/D', $value) !== 1) {
            throw new ContractException('Frame addresses must be canonical unsigned 64-bit hex strings.');
        }
    }

    public static function compareTimestamps(string $left, string $right): int
    {
        $seconds = self::timestamp($left)->getTimestamp() <=> self::timestamp($right)->getTimestamp();
        if ($seconds !== 0) {
            return $seconds;
        }
        preg_match('/\.(\d+)/', $left, $a);
        preg_match('/\.(\d+)/', $right, $b);
        $a = $a[1] ?? '';
        $b = $b[1] ?? '';
        $length = max(strlen($a), strlen($b));

        return strcmp(str_pad($a, $length, '0'), str_pad($b, $length, '0'));
    }

    /** Unsigned subtraction without casting 64-bit addresses to PHP numbers. */
    public static function subtract(string $a, string $b): string
    {
        $a = str_pad(substr($a, 2), 16, '0', STR_PAD_LEFT);
        $b = str_pad(substr($b, 2), 16, '0', STR_PAD_LEFT);
        if (strcmp($a, $b) < 0) {
            throw new ContractException('Frame address cannot be below its offset or load address.');
        }
        $result = '';
        $borrow = 0;
        for ($i = 15; $i >= 0; $i--) {
            $digit = (int) hexdec($a[$i]) - (int) hexdec($b[$i]) - $borrow;
            $borrow = $digit < 0 ? 1 : 0;
            $result = dechex(($digit + 16) % 16).$result;
        }

        return '0x'.(ltrim($result, '0') ?: '0');
    }
}
