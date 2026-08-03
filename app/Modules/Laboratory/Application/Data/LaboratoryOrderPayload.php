<?php

declare(strict_types=1);

namespace App\Modules\Laboratory\Application\Data;

final readonly class LaboratoryOrderPayload
{
    /** @param array<string, mixed> $data */
    public function __construct(public array $data) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['pedido_lab' => $this->data];
    }
}
