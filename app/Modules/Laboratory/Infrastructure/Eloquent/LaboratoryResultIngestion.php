<?php

declare(strict_types=1);

namespace App\Modules\Laboratory\Infrastructure\Eloquent;

use App\Modules\Laboratory\Domain\Enums\LaboratoryResultIngestionStatus;
use App\Modules\Medical\Infrastructure\Eloquent\ExamOrderItem;
use App\Modules\Medical\Infrastructure\Eloquent\ExamResult;
use App\Support\Models\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use UnexpectedValueException;

final class LaboratoryResultIngestion extends Model
{
    use HasPublicId;

    protected $guarded = [];

    protected $attributes = [
        'status' => LaboratoryResultIngestionStatus::Received->value,
        'duplicate_count' => 0,
    ];

    /** @return BelongsTo<LaboratoryIntegration, $this> */
    public function integration(): BelongsTo
    {
        return $this->belongsTo(LaboratoryIntegration::class, 'laboratory_integration_id');
    }

    /** @return BelongsTo<LaboratoryOrderTransmission, $this> */
    public function transmission(): BelongsTo
    {
        return $this->belongsTo(LaboratoryOrderTransmission::class, 'laboratory_order_transmission_id');
    }

    /** @return BelongsTo<ExamOrderItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(ExamOrderItem::class, 'exam_order_item_id');
    }

    /** @return HasOne<ExamResult, $this> */
    public function result(): HasOne
    {
        return $this->hasOne(ExamResult::class, 'laboratory_result_ingestion_id');
    }

    public function statusEnum(): LaboratoryResultIngestionStatus
    {
        $status = $this->getAttribute('status');

        return $status instanceof LaboratoryResultIngestionStatus
            ? $status
            : LaboratoryResultIngestionStatus::from((string) $status);
    }

    /** @return array<string, mixed> */
    public function payloadData(): array
    {
        $payload = $this->getAttribute('payload');
        if (! is_array($payload)) {
            throw new UnexpectedValueException('O payload persistido do resultado laboratorial é inválido.');
        }

        return $payload;
    }

    protected function casts(): array
    {
        return [
            'status' => LaboratoryResultIngestionStatus::class,
            'payload' => 'encrypted:array',
            'validation_errors' => 'encrypted:array',
            'received_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
            'last_duplicate_at' => 'immutable_datetime',
        ];
    }
}
