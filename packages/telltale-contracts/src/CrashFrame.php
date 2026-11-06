<?php

declare(strict_types=1);

namespace ArtisanBuild\TelltaleContracts;

final readonly class CrashFrame
{
    public ?string $offset;

    public ?string $loadAddress;

    public function __construct(
        public ?string $binaryName = null,
        public ?string $binaryUuid = null,
        public ?string $address = null,
        ?string $offset = null,
        ?string $loadAddress = null,
        public ?string $symbol = null,
    ) {
        foreach (['binary_name' => $binaryName, 'binary_uuid' => $binaryUuid, 'symbol' => $symbol] as $key => $value) {
            if ($value !== null) {
                CrashValue::text($value, $key === 'symbol' ? 1024 : 255, $key);
            }
        }
        if ($binaryUuid !== null && preg_match('/^(?:[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}|[a-f0-9]{8,128})$/iD', $binaryUuid) !== 1) {
            throw new ContractException('binary_uuid must be a UUID or Android build ID.');
        }
        foreach ([$address, $offset, $loadAddress] as $value) {
            CrashValue::hex($value);
        }
        if ($address !== null && $offset !== null) {
            $derived = CrashValue::subtract($address, $offset);
            if ($loadAddress !== null && $loadAddress !== $derived) {
                throw new ContractException('Inconsistent frame load address.');
            }
            $loadAddress = $derived;
        } elseif ($address !== null && $loadAddress !== null) {
            $offset = CrashValue::subtract($address, $loadAddress);
        }
        if ($symbol === null && (($binaryUuid === null && $binaryName === null) || ($offset === null && $address === null))) {
            throw new ContractException('A frame requires a symbol or binary identity and a location.');
        }
        $this->offset = $offset;
        $this->loadAddress = $loadAddress;
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        CrashValue::keys($data, ['binary_name', 'binary_uuid', 'address', 'offset', 'load_address', 'symbol']);

        return new self(
            CrashValue::optionalText($data, 'binary_name'),
            CrashValue::optionalText($data, 'binary_uuid'),
            CrashValue::optionalText($data, 'address'),
            CrashValue::optionalText($data, 'offset'),
            CrashValue::optionalText($data, 'load_address'),
            CrashValue::optionalText($data, 'symbol', 1024),
        );
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return array_filter([
            'binary_name' => $this->binaryName,
            'binary_uuid' => $this->binaryUuid,
            'address' => $this->address,
            'offset' => $this->offset,
            'load_address' => $this->loadAddress,
            'symbol' => $this->symbol,
        ], static fn (?string $value): bool => $value !== null);
    }

    public function fingerprintLocation(bool $jvm): string
    {
        if ($jvm && $this->symbol !== null) {
            return 'symbol:'.$this->symbol;
        }
        if ($this->binaryUuid !== null && $this->offset !== null) {
            return 'uuid:'.strtolower($this->binaryUuid).':'.$this->offset;
        }
        if ($this->binaryName !== null && $this->offset !== null) {
            return 'binary:'.$this->binaryName.':'.$this->offset;
        }
        if ($this->symbol !== null) {
            return 'symbol:'.$this->symbol;
        }

        // An absolute address alone cannot identify an ASLR-independent location.
        return 'binary:'.strtolower($this->binaryUuid ?? $this->binaryName ?? '');
    }
}
