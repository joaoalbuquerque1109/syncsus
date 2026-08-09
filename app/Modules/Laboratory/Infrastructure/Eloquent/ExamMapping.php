<?php

declare(strict_types=1);

namespace App\Modules\Laboratory\Infrastructure\Eloquent;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Laboratory\Application\Services\CatalogReader;
use App\Modules\Laboratory\Domain\Enums\ExamMappingMatchType;
use App\Support\Models\HasPublicId;
use App\Support\Models\TenantModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

final class ExamMapping extends TenantModel
{
    use HasPublicId;

    protected $guarded = [];

    protected $attributes = [
        'is_active' => true,
    ];

    protected static function booted(): void
    {
        self::saving(function (ExamMapping $mapping): void {
            $exam = app(CatalogReader::class)->exam($mapping->exam_public_id, $mapping->exam_id);
            $examOrganizationId = $exam?->organization_id;
            if ($exam !== null) {
                $mapping->exam_public_id = $exam->public_id;
            }
            $integrationOrganizationId = LaboratoryIntegration::query()
                ->whereKey($mapping->laboratory_integration_id)
                ->value('organization_id');

            if ($examOrganizationId === null || $integrationOrganizationId === null) {
                return;
            }
            if ((string) $examOrganizationId !== (string) $integrationOrganizationId) {
                throw new LogicException('O mapping de exame deve pertencer à mesma organização da integração.');
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

    /** @return BelongsTo<LaboratoryIntegration, $this> */
    public function integration(): BelongsTo
    {
        return $this->belongsTo(LaboratoryIntegration::class, 'laboratory_integration_id');
    }

    public function resolveMappedBy(): ?User
    {
        return User::query()->find($this->mapped_by);
    }

    /**
     * @param  Builder<ExamMapping>  $query
     * @return Builder<ExamMapping>
     */
    public function scopeForHealthUnit(Builder $query, HealthUnit $unit): Builder
    {
        return $query
            ->whereIn('exam_public_id', app(CatalogReader::class)
                ->activeExamPublicIdsForOrganization((int) $unit->organization_id))
            ->whereHas('integration', fn (Builder $integration) => $integration
                ->where('organization_id', $unit->organization_id)
                ->where('health_unit_id', $unit->getKey()));
    }

    protected function casts(): array
    {
        return [
            'match_type' => ExamMappingMatchType::class,
            'match_confidence' => 'decimal:4',
            'mapped_at' => 'immutable_datetime',
            'is_active' => 'boolean',
        ];
    }
}
