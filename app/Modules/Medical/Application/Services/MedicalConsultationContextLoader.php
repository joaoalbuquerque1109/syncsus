<?php

declare(strict_types=1);

namespace App\Modules\Medical\Application\Services;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Administration\Infrastructure\Eloquent\Organization;
use App\Modules\Administration\Infrastructure\Eloquent\RiskLevel;
use App\Modules\Administration\Infrastructure\Eloquent\Specialty;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Medical\Infrastructure\Eloquent\DiagnosisCode;
use App\Modules\Medical\Infrastructure\Eloquent\MedicalConsultation;
use App\Modules\Patients\Infrastructure\Eloquent\Patient;

final class MedicalConsultationContextLoader
{
    public function load(MedicalConsultation $consultation): MedicalConsultation
    {
        $consultation->load([
            'encounter.arrivalMethod', 'encounter.triageAssessment.vitalSigns',
            'queueEntry.queue', 'room', 'physicalExam', 'diagnoses', 'prescriptions.items',
            'prescriptions.document.currentVersion', 'examOrders.items.result',
            'examOrders.document.currentVersion', 'clinicalNotes', 'referrals.document.currentVersion',
            'medicalCertificates.document.currentVersion', 'documents.currentVersion',
            'destination', 'addenda',
        ]);

        $patient = Patient::query()
            ->with(['identifiers', 'allergies', 'conditions', 'medications', 'socialHistory'])
            ->findOrFail($consultation->encounter->patient_id);
        $riskLevel = $consultation->encounter->risk_level_id === null
            ? null
            : RiskLevel::query()->find($consultation->encounter->risk_level_id);

        $userIds = collect([$consultation->professional_id])
            ->merge($consultation->diagnoses->pluck('diagnosed_by'))
            ->merge($consultation->prescriptions->pluck('professional_id'))
            ->merge($consultation->examOrders->pluck('requested_by'))
            ->merge($consultation->examOrders->pluck('created_by'))
            ->merge($consultation->examOrders->pluck('cancelled_by'))
            ->merge($consultation->examOrders->flatMap->items->pluck('result.recorded_by'))
            ->merge($consultation->clinicalNotes->pluck('author_id'))
            ->merge($consultation->referrals->pluck('requested_by'))
            ->merge($consultation->medicalCertificates->pluck('issued_by'))
            ->merge([$consultation->destination?->recorded_by])
            ->merge($consultation->addenda->pluck('author_id'))
            ->filter()->unique()->all();
        $users = User::query()
            ->with(['professionalProfile.registrations', 'professionalProfile.specialties'])
            ->whereKey($userIds)
            ->get()
            ->keyBy(fn (User $user): string => (string) $user->getKey());

        $specialtyIds = collect([$consultation->specialty_id])
            ->merge($consultation->clinicalNotes->pluck('specialty_id'))
            ->merge($consultation->referrals->pluck('specialty_id'))
            ->filter()->unique()->all();
        $specialties = Specialty::query()->whereKey($specialtyIds)->get()
            ->keyBy(fn (Specialty $specialty): int => (int) $specialty->getKey());
        $diagnosisCodes = DiagnosisCode::query()
            ->whereKey(
                $consultation->medicalCertificates->pluck('diagnosis_code_id')
                    ->merge($consultation->diagnoses->pluck('diagnosis_code_id'))
                    ->filter()->unique()->all(),
            )
            ->get()
            ->keyBy(fn (DiagnosisCode $code): int => (int) $code->getKey());
        $organizationIds = $consultation->examOrders->pluck('organization_id')->filter()->unique()->all();
        $organizations = Organization::query()->whereKey($organizationIds)->get()
            ->keyBy(fn (Organization $organization): int => (int) $organization->getKey());
        $unitIds = $consultation->examOrders->pluck('health_unit_id')->filter()->unique()->all();
        $units = HealthUnit::query()->whereKey($unitIds)->get()
            ->keyBy(fn (HealthUnit $unit): int => (int) $unit->getKey());

        $consultation->encounter->setRelation('patient', $patient);
        $consultation->encounter->setRelation('riskLevel', $riskLevel);
        $consultation->setRelation('professional', $users->get((string) $consultation->professional_id));
        $consultation->setRelation('specialty', $specialties->get((int) $consultation->specialty_id));
        foreach ($consultation->diagnoses as $diagnosis) {
            $diagnosis->setRelation('diagnosedBy', $users->get((string) $diagnosis->diagnosed_by));
            $diagnosis->setRelation('catalogCode', $diagnosisCodes->get((int) $diagnosis->diagnosis_code_id));
        }
        foreach ($consultation->prescriptions as $prescription) {
            $prescription->setRelation('professional', $users->get((string) $prescription->professional_id));
        }
        foreach ($consultation->examOrders as $order) {
            $order->setRelation('requestedBy', $users->get((string) $order->requested_by));
            $order->setRelation('createdBy', $users->get((string) $order->created_by));
            $order->setRelation('cancelledBy', $users->get((string) $order->cancelled_by));
            $order->setRelation('organization', $organizations->get((int) $order->organization_id));
            $order->setRelation('healthUnit', $units->get((int) $order->health_unit_id));
            foreach ($order->items as $item) {
                if ($item->result !== null) {
                    $item->result->setRelation('recordedBy', $users->get((string) $item->result->recorded_by));
                }
            }
        }
        foreach ($consultation->clinicalNotes as $note) {
            $note->setRelation('author', $users->get((string) $note->author_id));
            $note->setRelation('specialty', $specialties->get((int) $note->specialty_id));
        }
        foreach ($consultation->referrals as $referral) {
            $referral->setRelation('requestedBy', $users->get((string) $referral->requested_by));
            $referral->setRelation('specialty', $specialties->get((int) $referral->specialty_id));
        }
        foreach ($consultation->medicalCertificates as $certificate) {
            $certificate->setRelation('issuer', $users->get((string) $certificate->issued_by));
            $certificate->setRelation('diagnosisCode', $diagnosisCodes->get((int) $certificate->diagnosis_code_id));
        }
        if ($consultation->destination !== null) {
            $consultation->destination->setRelation(
                'recordedBy',
                $users->get((string) $consultation->destination->recorded_by),
            );
        }
        foreach ($consultation->addenda as $addendum) {
            $addendum->setRelation('author', $users->get((string) $addendum->author_id));
        }

        return $consultation;
    }
}
