<?php

declare(strict_types=1);

namespace App\Domain\Ingest;

final class Credential
{
    public static function issue(string $prefix): IssuedCredential
    {
        $value = $prefix.'_'.bin2hex(random_bytes(32));

        return new IssuedCredential($value, self::hash($value));
    }

    public static function hash(string $value): string
    {
        return hash('sha256', $value);
    }

    public static function hasFormat(string $value, string $prefix): bool
    {
        return preg_match('/^'.preg_quote($prefix, '/').'_[a-f0-9]{64}$/D', $value) === 1;
    }

    public static function matches(string $value, string $prefix, string $storedHash): bool
    {
        if (! self::hasFormat($value, $prefix)) {
            return false;
        }

        return hash_equals($storedHash, self::hash($value));
    }
}
