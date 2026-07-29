<?php

declare(strict_types=1);

namespace App\Modules\Patients\Infrastructure\Eloquent;

use App\Modules\Patients\Domain\Enums\PatientIdentifierType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PatientIdentifier extends Model
{
    protected $guarded = [];

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function maskedValue(): string
    {
        $value = (string) $this->getAttribute('normalized_value');
        $visible = min(4, mb_strlen($value));

        return str_repeat('*', max(0, mb_strlen($value) - $visible)).mb_substr($value, -$visible);
    }

    public function typeValue(): string
    {
        $type = $this->getAttribute('type');

        return $type instanceof PatientIdentifierType ? $type->value : (string) $type;
    }

    protected function casts(): array
    {
        return [
            'type' => PatientIdentifierType::class,
            'issued_at' => 'immutable_date',
            'verified_at' => 'immutable_datetime',
            'is_primary' => 'boolean',
        ];
    }
}
