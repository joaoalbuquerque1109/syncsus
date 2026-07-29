<?php

declare(strict_types=1);

namespace App\Modules\Medical\Infrastructure\Eloquent;

use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Reception\Infrastructure\Eloquent\Encounter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Diagnosis extends Model
{
    protected $guarded = [];

    /** @return BelongsTo<MedicalConsultation, $this> */
    public function consultation(): BelongsTo
    {
        return $this->belongsTo(MedicalConsultation::class, 'medical_consultation_id');
    }

    /** @return BelongsTo<Encounter, $this> */
    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
    }

    /** @return BelongsTo<DiagnosisCode, $this> */
    public function catalogCode(): BelongsTo
    {
        return $this->belongsTo(DiagnosisCode::class, 'diagnosis_code_id');
    }

    /** @return BelongsTo<User, $this> */
    public function diagnosedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diagnosed_by');
    }

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'diagnosed_at' => 'immutable_datetime',
            'voided_at' => 'immutable_datetime',
        ];
    }
}
