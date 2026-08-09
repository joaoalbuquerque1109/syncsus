<?php

declare(strict_types=1);

namespace App\Modules\Documents\Application\Services;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Documents\Infrastructure\Eloquent\ClinicalDocument;
use App\Modules\Documents\Infrastructure\Eloquent\DocumentVersion;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Patients\Infrastructure\Eloquent\Patient;

final class ClinicalDocumentContextLoader
{
    public function load(ClinicalDocument $document, bool $includeVersions = false): ClinicalDocument
    {
        $tenantRelations = ['encounter', 'consultation', 'currentVersion'];
        if ($includeVersions) {
            $tenantRelations[] = 'versions';
        }
        $document->loadMissing($tenantRelations);

        $unit = HealthUnit::query()->with('organization')->findOrFail($document->health_unit_id);
        $patient = Patient::query()
            ->with('identifiers')
            ->when(
                filled($document->patient_public_id),
                fn ($query) => $query->where('public_id', $document->patient_public_id),
                fn ($query) => $query->whereKey($document->patient_id),
            )
            ->firstOrFail();
        $userIds = collect([
            $document->created_by,
            $document->voided_by,
            $document->consultation?->professional_id,
        ])->merge($document->versions->pluck('created_by'))->filter()->unique()->all();
        $users = User::query()
            ->with(['professionalProfile.registrations', 'professionalProfile.specialties'])
            ->whereKey($userIds)
            ->get()
            ->keyBy(fn (User $user): string => (string) $user->getKey());

        $document->setRelation('healthUnit', $unit);
        $document->setRelation('patient', $patient);
        $document->setRelation('creator', $users->get((string) $document->created_by));
        $document->setRelation('voidedBy', $users->get((string) $document->voided_by));
        if ($document->consultation !== null) {
            $document->consultation->setRelation(
                'professional',
                $users->get((string) $document->consultation->professional_id),
            );
        }
        foreach ($document->versions as $version) {
            $version->setRelation('creator', $users->get((string) $version->created_by));
        }
        if ($document->currentVersion instanceof DocumentVersion) {
            $document->currentVersion->setRelation(
                'creator',
                $users->get((string) $document->currentVersion->created_by),
            );
        }

        return $document;
    }
}
