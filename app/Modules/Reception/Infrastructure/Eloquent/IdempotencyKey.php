<?php

declare(strict_types=1);

namespace App\Modules\Reception\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;

final class IdempotencyKey extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['response_body' => 'array', 'expires_at' => 'immutable_datetime'];
    }

    public function encounterPublicId(): ?string
    {
        $body = $this->getAttribute('response_body');
        if (! is_array($body) || ! isset($body['encounter_public_id']) || ! is_string($body['encounter_public_id'])) {
            return null;
        }

        return $body['encounter_public_id'];
    }
}
