<?php

declare(strict_types=1);

namespace App\Modules\Medical\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PhysicalExam extends Model
{
    protected $guarded = [];

    /** @return BelongsTo<MedicalConsultation, $this> */
    public function consultation(): BelongsTo
    {
        return $this->belongsTo(MedicalConsultation::class, 'medical_consultation_id');
    }
}
