<?php

declare(strict_types=1);

namespace App\Modules\Reports\Infrastructure\Eloquent;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Support\Models\CoreModel;
use App\Support\Models\HasPublicId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

final class UnitReportSnapshot extends CoreModel
{
    use HasPublicId;

    protected $guarded = [];

    /** @return BelongsTo<HealthUnit, $this> */
    public function healthUnit(): BelongsTo
    {
        return $this->belongsTo(HealthUnit::class);
    }

    /** @return array<string, int|string> */
    public function metricsPayload(): array
    {
        $metrics = $this->getAttribute('metrics');

        return is_array($metrics) ? $metrics : [];
    }

    public function generatedAt(): CarbonImmutable
    {
        $generatedAt = $this->getAttribute('generated_at');

        return $generatedAt instanceof CarbonImmutable
            ? $generatedAt
            : Carbon::parse((string) $generatedAt)->toImmutable();
    }

    protected function casts(): array
    {
        return [
            'period_date' => 'immutable_date',
            'metrics' => 'array',
            'generated_at' => 'immutable_datetime',
        ];
    }
}
