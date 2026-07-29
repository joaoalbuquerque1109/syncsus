<?php

declare(strict_types=1);

namespace App\Modules\Medical\Infrastructure\Eloquent;

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
}
