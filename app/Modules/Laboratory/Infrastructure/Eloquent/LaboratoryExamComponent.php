<?php

declare(strict_types=1);

namespace App\Modules\Laboratory\Infrastructure\Eloquent;

use App\Support\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class LaboratoryExamComponent extends TenantModel
{
    protected $guarded = [];

    /** @return BelongsTo<LaboratoryExam, $this> */
    public function exam(): BelongsTo
    {
        return $this->belongsTo(LaboratoryExam::class, 'laboratory_exam_id');
    }

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
