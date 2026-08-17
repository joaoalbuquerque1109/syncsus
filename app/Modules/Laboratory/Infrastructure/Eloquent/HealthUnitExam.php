<?php

declare(strict_types=1);

namespace App\Modules\Laboratory\Infrastructure\Eloquent;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Laboratory\Application\Services\CatalogReader;
use App\Support\Models\HasPublicId;
use App\Support\Models\TenantModel;
use Illuminate\Database\Eloquent\Builder;
use LogicException;

final class HealthUnitExam extends TenantModel
{
    use HasPublicId;

    protected $guarded = [];

    protected $attributes = [
        'is_enabled' => false,
    ];

    protected static function booted(): void
    {
        self::saving(function (HealthUnitExam $availability): void {
            $exam = app(CatalogReader::class)->exam($availability->exam_public_id, $availability->exam_id);
            $examOrganizationId = $exam?->organization_id;
            if ($exam !== null) {
                $availability->exam_public_id = $exam->public_id;
            }
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

    public function resolveExam(): ?Exam
    {
        return app(CatalogReader::class)->exam($this->exam_public_id, $this->exam_id);
    }

    public function getExamAttribute(): ?Exam
    {
        return $this->resolveExam();
    }

    public function resolveHealthUnit(): ?HealthUnit
    {
        return $this->resolveCoreReference(HealthUnit::class, 'health_unit_public_id', 'health_unit_id');
    }

    public function resolveEnabledBy(): ?User
    {
        return User::query()->find($this->enabled_by);
    }

    /**
     * @param  Builder<HealthUnitExam>  $query
     * @return Builder<HealthUnitExam>
     */
    public function scopeForHealthUnit(Builder $query, HealthUnit $unit): Builder
    {
        return $query
            ->where('health_unit_id', $unit->getKey())
            ->whereIn('exam_public_id', app(CatalogReader::class)
                ->activeExamPublicIdsForOrganization((int) $unit->organization_id));
    }

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'enabled_at' => 'immutable_datetime',
        ];
    }
}
