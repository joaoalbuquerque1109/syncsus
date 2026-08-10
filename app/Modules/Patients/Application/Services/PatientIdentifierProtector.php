<?php

declare(strict_types=1);

namespace App\Modules\Patients\Application\Services;

use Illuminate\Contracts\Encryption\Encrypter;
use RuntimeException;

final readonly class PatientIdentifierProtector
{
    public function __construct(private Encrypter $encrypter) {}

    /** @return array{encrypted_value: string, fingerprint: string, fingerprint_key_version: int} */
    public function protect(string $type, string $normalizedValue): array
    {
        return [
            'encrypted_value' => $this->encrypter->encryptString($normalizedValue),
            'fingerprint' => $this->fingerprint($type, $normalizedValue),
            'fingerprint_key_version' => $this->keyVersion(),
        ];
    }

    public function reveal(string $encryptedValue): string
    {
        return $this->encrypter->decryptString($encryptedValue);
    }

    public function fingerprint(string $type, string $normalizedValue): string
    {
        return hash_hmac(
            'sha256',
            mb_strtolower(trim($type)).':'.$normalizedValue,
            $this->key(),
        );
    }

    /** @return list<string> */
    public function fingerprintsForValue(string $normalizedValue): array
    {
        return array_map(
            fn (string $type): string => $this->fingerprint($type, $normalizedValue),
            ['cpf', 'cns', 'rg', 'passport'],
        );
    }

    private function key(): string
    {
        $key = config('patient_identifiers.hmac_key');
        if (! is_string($key) || mb_strlen($key) < 16) {
            throw new RuntimeException('PATIENT_IDENTIFIER_HMAC_KEY deve ter ao menos 16 caracteres.');
        }

        return $key;
    }

    private function keyVersion(): int
    {
        $version = (int) config('patient_identifiers.hmac_key_version', 0);
        if ($version < 1) {
            throw new RuntimeException('PATIENT_IDENTIFIER_HMAC_KEY_VERSION deve ser positivo.');
        }

        return $version;
    }
}
