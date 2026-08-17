<?php

declare(strict_types=1);

namespace App\Support\Models;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Administration\Infrastructure\Eloquent\Organization;
use App\Modules\Administration\Infrastructure\Eloquent\Specialty;
use App\Modules\Laboratory\Infrastructure\Eloquent\Exam;
use App\Modules\Patients\Infrastructure\Eloquent\Patient;

trait PopulatesCorePublicReferences
{
    protected static function bootPopulatesCorePublicReferences(): void
    {
        static::saving(function (self $model): void {
            foreach ($model->corePublicReferenceMap() as $publicColumn => [$coreModel, $legacyColumn]) {
                if (filled($model->getAttribute($publicColumn))) {
                    continue;
                }

                $legacyId = $model->getAttribute($legacyColumn);
                if (! filled($legacyId)) {
                    continue;
                }

                $model->setAttribute(
                    $publicColumn,
                    $coreModel::query()->whereKey($legacyId)->value('public_id'),
                );
            }
        });
    }

    /** @return array<string, array{class-string<CoreModel>, string}> */
    protected function corePublicReferenceMap(): array
    {
        return match ($this->getTable()) {
            'departments' => ['health_unit_public_id' => [HealthUnit::class, 'health_unit_id']],
            'entry_types', 'arrival_methods' => ['organization_public_id' => [Organization::class, 'organization_id']],
            'encounters' => [
                'patient_public_id' => [Patient::class, 'patient_id'],
                'health_unit_public_id' => [HealthUnit::class, 'health_unit_id'],
                'assigned_specialty_public_id' => [Specialty::class, 'assigned_specialty_id'],
            ],
            'queues' => [
                'health_unit_public_id' => [HealthUnit::class, 'health_unit_id'],
                'specialty_public_id' => [Specialty::class, 'specialty_id'],
            ],
            'panels' => ['health_unit_public_id' => [HealthUnit::class, 'health_unit_id']],
            'medical_consultations', 'clinical_notes', 'referrals' => ['specialty_public_id' => [Specialty::class, 'specialty_id']],
            'exam_orders' => [
                'organization_public_id' => [Organization::class, 'organization_id'],
                'health_unit_public_id' => [HealthUnit::class, 'health_unit_id'],
            ],
            'documents', 'medical_certificates' => [
                'patient_public_id' => [Patient::class, 'patient_id'],
                'health_unit_public_id' => [HealthUnit::class, 'health_unit_id'],
            ],
            'laboratory_integrations', 'laboratory_order_transmissions', 'medical_shift_attendances' => [
                'organization_public_id' => [Organization::class, 'organization_id'],
                'health_unit_public_id' => [HealthUnit::class, 'health_unit_id'],
            ],
            'health_unit_exams' => ['health_unit_public_id' => [HealthUnit::class, 'health_unit_id']],
            'exam_catalog_import_candidates' => [
                'organization_public_id' => [Organization::class, 'organization_id'],
                'suggested_exam_public_id' => [Exam::class, 'suggested_exam_id'],
            ],
            'exam_group_import_conflicts' => ['organization_public_id' => [Organization::class, 'organization_id']],
            default => [],
        };
    }
}
