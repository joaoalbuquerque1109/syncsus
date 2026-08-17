<?php

declare(strict_types=1);

namespace App\Modules\Medical\Infrastructure\Eloquent;

use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Laboratory\Infrastructure\Eloquent\LaboratoryResultIngestion;
use App\Support\Models\HasPublicId;
use App\Support\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ExamResult extends TenantModel
{
    use HasPublicId;

    protected $guarded = [];

    /** @return BelongsTo<ExamOrderItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(ExamOrderItem::class, 'exam_order_item_id');
    }

    public function resolveRecordedBy(): ?User
    {
        return User::query()->find($this->recorded_by);
    }

    /** @return BelongsTo<LaboratoryResultIngestion, $this> */
    public function ingestion(): BelongsTo
    {
        return $this->belongsTo(LaboratoryResultIngestion::class, 'laboratory_result_ingestion_id');
    }

    protected function casts(): array
    {
        return ['resulted_at' => 'immutable_datetime'];
    }
}
