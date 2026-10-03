<?php

declare(strict_types=1);

namespace ArtisanBuild\TelltaleContracts;

final readonly class ErrorDetails
{
    public const MAX_MESSAGE_LENGTH = 4096;

    public const MAX_FILE_LENGTH = 1024;

    public const MAX_STACK_FRAMES = 100;

    public const MAX_STACK_FRAME_LENGTH = 2048;

    /**
     * @var list<string>
     */
    public array $stack;

    /**
     * @param  array<mixed>  $stack
     */
    public function __construct(
        public string $class,
        public string $message,
        public string $file,
        public int $line,
        array $stack,
        public ?string $fingerprint = null,
    ) {
        self::assertNonEmpty($class, 'error.class');
        self::assertLength($message, self::MAX_MESSAGE_LENGTH, 'error.message');
        self::assertNonEmpty($file, 'error.file');
        self::assertLength($file, self::MAX_FILE_LENGTH, 'error.file');

        if (! array_is_list($stack)) {
            throw new ContractException('error.stack must be a list of strings.');
        }

        if ($line < 1) {
            throw new ContractException('error.line must be a positive integer.');
        }

        if (count($stack) > self::MAX_STACK_FRAMES) {
            throw new ContractException('error.stack exceeds the maximum frame count.');
        }

        $validatedStack = [];

        foreach ($stack as $frame) {
            if (! is_string($frame)) {
                throw new ContractException('error.stack must be a list of strings.');
            }

            self::assertLength($frame, self::MAX_STACK_FRAME_LENGTH, 'error.stack frame');
            $validatedStack[] = $frame;
        }

        if ($fingerprint !== null) {
            self::assertNonEmpty($fingerprint, 'error.fingerprint');
        }

        $this->stack = $validatedStack;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $stack = $data['stack'] ?? null;

        if (! is_array($stack) || ! array_is_list($stack)) {
            throw new ContractException('error.stack must be a list of strings.');
        }

        foreach ($stack as $frame) {
            if (! is_string($frame)) {
                throw new ContractException('error.stack must be a list of strings.');
            }
        }

        $hasFingerprint = array_key_exists('fingerprint', $data);
        $fingerprint = $hasFingerprint ? $data['fingerprint'] : null;

        if ($hasFingerprint && ! is_string($fingerprint)) {
            throw new ContractException('error.fingerprint must be a string when present.');
        }

        /** @var list<string> $stack */
        /** @var string|null $fingerprint */

        return new self(
            class: self::requiredString($data, 'class'),
            message: self::requiredString($data, 'message'),
            file: self::requiredString($data, 'file'),
            line: self::requiredInteger($data, 'line'),
            stack: $stack,
            fingerprint: $fingerprint,
        );
    }

    /**
     * @return array{class: string, message: string, file: string, line: int, stack: list<string>, fingerprint?: string}
     */
    public function toArray(): array
    {
        $data = [
            'class' => $this->class,
            'message' => $this->message,
            'file' => $this->file,
            'line' => $this->line,
            'stack' => $this->stack,
        ];

        if ($this->fingerprint !== null) {
            $data['fingerprint'] = $this->fingerprint;
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function requiredString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        if (! is_string($value)) {
            throw new ContractException("error.{$key} must be a string.");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function requiredInteger(array $data, string $key): int
    {
        $value = $data[$key] ?? null;

        if (! is_int($value)) {
            throw new ContractException("error.{$key} must be an integer.");
        }

        return $value;
    }

    private static function assertNonEmpty(string $value, string $field): void
    {
        self::assertValidUtf8($value, $field);

        if ($value === '') {
            throw new ContractException("{$field} must not be empty.");
        }
    }

    private static function assertLength(string $value, int $maximum, string $field): void
    {
        self::assertValidUtf8($value, $field);

        if (strlen($value) > $maximum) {
            throw new ContractException("{$field} exceeds the maximum length of {$maximum} bytes.");
        }
    }

    private static function assertValidUtf8(string $value, string $field): void
    {
        if (preg_match('//u', $value) !== 1) {
            throw new ContractException("{$field} must be valid UTF-8.");
        }

        if (str_contains($value, "\0")) {
            throw new ContractException("{$field} must not contain NUL bytes.");
        }
    }
}
