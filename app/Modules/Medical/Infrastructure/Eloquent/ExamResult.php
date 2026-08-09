<?php

declare(strict_types=1);

namespace App\Modules\Medical\Infrastructure\Eloquent;

use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Laboratory\Infrastructure\Eloquent\LaboratoryResultIngestion;
use App\Support\Models\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ExamResult extends Model
{
    use HasPublicId;

    protected $guarded = [];

    /** @return BelongsTo<ExamOrderItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(ExamOrderItem::class, 'exam_order_item_id');
    }

    /** @return BelongsTo<User, $this> */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
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
