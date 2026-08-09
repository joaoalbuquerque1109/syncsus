<?php

declare(strict_types=1);

namespace App\Modules\Laboratory\Infrastructure\Eloquent;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Support\Models\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

final class HealthUnitExam extends Model
{
    use HasPublicId;

    protected $guarded = [];

    protected $attributes = [
        'is_enabled' => false,
    ];

    protected static function booted(): void
    {
        self::saving(function (HealthUnitExam $availability): void {
            $examOrganizationId = Exam::query()
                ->whereKey($availability->exam_id)
                ->value('organization_id');
            $unit = HealthUnit::query()->find($availability->health_unit_id);

            if ($examOrganizationId === null || $unit === null) {
                return;
            }
            if ((string) $examOrganizationId !== (string) $unit->organization_id) {
                throw new LogicException('A disponibilidade do exame deve pertencer à organização da unidade.');
            }
            if (! $availability->is_enabled) {
                return;
            }

            $hasActiveMapping = ExamMapping::query()
                ->where('exam_id', $availability->exam_id)
                ->where('is_active', true)
                ->whereHas('integration', fn (Builder $integration) => $integration
                    ->where('organization_id', $unit->organization_id)
                    ->where('health_unit_id', $unit->getKey()))
                ->exists();
            if (! $hasActiveMapping) {
                throw new LogicException('Um exame só pode ser habilitado quando possui mapping ativo para a unidade.');
            }
        });
    }

    /** @return BelongsTo<Exam, $this> */
    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    /** @return BelongsTo<HealthUnit, $this> */
    public function healthUnit(): BelongsTo
    {
        return $this->belongsTo(HealthUnit::class);
    }

    /** @return BelongsTo<User, $this> */
    public function enabledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enabled_by');
    }

    /**
     * @param  Builder<HealthUnitExam>  $query
     * @return Builder<HealthUnitExam>
     */
    public function scopeForHealthUnit(Builder $query, HealthUnit $unit): Builder
    {
        return $query
            ->where('health_unit_id', $unit->getKey())
            ->whereHas('exam', fn (Builder $exam) => $exam
                ->where('organization_id', $unit->organization_id));
    }

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'enabled_at' => 'immutable_datetime',
        ];
    }
}
