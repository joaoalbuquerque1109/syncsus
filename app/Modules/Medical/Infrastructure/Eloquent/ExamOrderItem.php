<?php

declare(strict_types=1);

namespace App\Modules\Medical\Infrastructure\Eloquent;

use App\Modules\Laboratory\Infrastructure\Eloquent\LaboratoryExam;
use App\Modules\Laboratory\Infrastructure\Eloquent\LaboratoryIntegration;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class ExamOrderItem extends Model
{
    protected $guarded = [];

    /** @return BelongsTo<ExamOrder, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(ExamOrder::class, 'exam_order_id');
    }

    /** @return HasOne<ExamResult, $this> */
    public function result(): HasOne
    {
        return $this->hasOne(ExamResult::class);
    }

    /** @return BelongsTo<LaboratoryIntegration, $this> */
    public function laboratoryIntegration(): BelongsTo
    {
        return $this->belongsTo(LaboratoryIntegration::class);
    }

    /** @return BelongsTo<LaboratoryExam, $this> */
    public function laboratoryExam(): BelongsTo
    {
        return $this->belongsTo(LaboratoryExam::class);
    }

    protected function casts(): array
    {
        return ['laboratory_snapshot' => 'array'];
    }
}
