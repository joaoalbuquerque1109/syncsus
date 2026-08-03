<?php

declare(strict_types=1);

namespace App\Modules\Laboratory\Application\Data;

final readonly class LaboratorySubmissionResult
{
    /** @param array<string, mixed>|null $response */
    public function __construct(
        public bool $accepted,
        public int $httpStatus,
        public ?array $response,
        public string $responseHash,
    ) {}
}
