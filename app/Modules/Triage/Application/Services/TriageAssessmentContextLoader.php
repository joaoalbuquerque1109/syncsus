<?php

declare(strict_types=1);

namespace App\Modules\Triage\Application\Services;

use App\Modules\Administration\Infrastructure\Eloquent\RiskLevel;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Patients\Infrastructure\Eloquent\Patient;
use App\Modules\Triage\Infrastructure\Eloquent\TriageAssessment;

final class TriageAssessmentContextLoader
{
    public function load(TriageAssessment $assessment): TriageAssessment
    {
        $assessment->load([
            'encounter.arrivalMethod',
            'queueEntry.queue',
            'servicePoint.room',
            'protocol',
            'flowchart',
            'discriminator',
            'destinationQueue',
            'vitalSigns',
            'addenda',
        ]);

        $patient = Patient::query()
            ->with(['identifiers', 'allergies'])
            ->findOrFail($assessment->encounter->patient_id);
        $assessment->encounter->setRelation('patient', $patient);

        $userIds = collect([$assessment->professional_id])
            ->merge($assessment->vitalSigns->pluck('recorded_by'))
            ->merge($assessment->addenda->pluck('author_id'))
            ->filter()
            ->unique()
            ->values();
        $users = User::query()->whereKey($userIds)->get()->keyBy('id');
        $assessment->setRelation('professional', $users->get($assessment->professional_id));
        foreach ($assessment->vitalSigns as $measurement) {
            $measurement->setRelation('recordedBy', $users->get($measurement->recorded_by));
        }
        foreach ($assessment->addenda as $addendum) {
            $addendum->setRelation('author', $users->get($addendum->author_id));
        }

        $assessment->setRelation(
            'riskLevel',
            filled($assessment->risk_level_id)
                ? RiskLevel::query()->find($assessment->risk_level_id)
                : null,
        );

        return $assessment;
    }
}
