<?php

declare(strict_types=1);

namespace App\Modules\Laboratory\Application\Services;

use JsonException;

final class SynclabResultPayloadHasher
{
    /** @param array<string, mixed> $payload
     * @throws JsonException
     */
    public function contentHash(array $payload): string
    {
        return hash('sha256', json_encode($this->canonicalize($payload), JSON_THROW_ON_ERROR));
    }

    public function idempotencyKey(
        string $externalOrderNumber,
        string $externalExamCode,
        ?string $externalResultReference,
        string $contentHash,
    ): string {
        $resultIdentity = filled($externalResultReference)
            ? 'reference:'.$externalResultReference
            : 'content:'.$contentHash;

        return hash('sha256', implode('|', [
            $externalOrderNumber,
            $externalExamCode,
            $resultIdentity,
        ]));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map($this->canonicalize(...), $value);
    }
}
