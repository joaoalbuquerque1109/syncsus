<?php

declare(strict_types=1);

namespace App\Modules\Laboratory\Infrastructure\Eloquent;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Laboratory\Domain\Enums\ExamMappingMatchType;
use App\Support\Models\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

final class ExamMapping extends Model
{
    use HasPublicId;

    protected $guarded = [];

    protected $attributes = [
        'is_active' => true,
    ];

    protected static function booted(): void
    {
        self::saving(function (ExamMapping $mapping): void {
            $examOrganizationId = Exam::query()
                ->whereKey($mapping->exam_id)
                ->value('organization_id');
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

    /** @return BelongsTo<Exam, $this> */
    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    /** @return BelongsTo<LaboratoryIntegration, $this> */
    public function integration(): BelongsTo
    {
        return $this->belongsTo(LaboratoryIntegration::class, 'laboratory_integration_id');
    }

    /** @return BelongsTo<User, $this> */
    public function mappedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'mapped_by');
    }

    /**
     * @param  Builder<ExamMapping>  $query
     * @return Builder<ExamMapping>
     */
    public function scopeForHealthUnit(Builder $query, HealthUnit $unit): Builder
    {
        return $query
            ->whereHas('exam', fn (Builder $exam) => $exam
                ->where('organization_id', $unit->organization_id))
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
