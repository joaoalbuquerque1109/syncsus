<?php

declare(strict_types=1);

namespace App\Modules\Documents\Application\Actions;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Audit\Application\Actions\RecordAuditEventAction;
use App\Modules\Documents\Domain\Enums\ClinicalDocumentType;
use App\Modules\Documents\Infrastructure\Eloquent\MedicalCertificate;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Medical\Infrastructure\Eloquent\MedicalConsultation;
use App\Modules\Patients\Infrastructure\Eloquent\Patient;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final readonly class IssueMedicalCertificateAction
{
    public function __construct(
        private IssueClinicalDocumentAction $documents,
        private RecordAuditEventAction $audit,
    ) {}

    /** @param array<string, mixed> $data */
    public function execute(
        MedicalConsultation $consultation,
        array $data,
        User $user,
        HealthUnit $unit,
        Request $request,
    ): MedicalCertificate {
        return DB::transaction(function () use ($consultation, $data, $user, $unit, $request): MedicalCertificate {
            $consultation->loadMissing('encounter');
            $patient = Patient::query()->findOrFail($consultation->encounter->patient_id);
            $includeCid = (bool) ($data['include_cid'] ?? false);
            $certificate = MedicalCertificate::query()->create([
                'health_unit_id' => $unit->getKey(),
                'encounter_id' => $consultation->encounter_id,
                'patient_id' => $consultation->encounter->patient_id,
                'patient_public_id' => $patient->public_id,
                'medical_consultation_id' => $consultation->getKey(),
                'issued_by' => $user->getKey(),
                'starts_at' => $data['starts_at'],
                'duration_value' => $data['duration_value'],
                'duration_unit' => $data['duration_unit'],
                'statement' => $data['statement'],
                'additional_information' => $data['additional_information'] ?? null,
                'include_cid' => $includeCid,
                'diagnosis_code_id' => $includeCid ? $data['cid_code_id'] : null,
                'cid_authorized_at' => $includeCid ? now() : null,
                'status' => 'issued',
                'issued_at' => now(),
            ]);
            $document = $this->documents->executeStructured(
                $consultation,
                ClinicalDocumentType::MedicalCertificate,
                [
                    'source_public_id' => $certificate->public_id,
                    'body' => $certificate->statement,
                    'starts_at' => Carbon::parse($certificate->starts_at)->toIso8601String(),
                    'duration_value' => $certificate->duration_value,
                    'duration_unit' => $certificate->duration_unit,
                    'additional_information' => $certificate->additional_information,
                    'include_cid' => $includeCid,
                    'cid_code_id' => $certificate->diagnosis_code_id,
                    'cid_authorization' => $includeCid,
                ],
                $user,
                $unit,
                $request,
            );
            $certificate->update(['document_id' => $document->getKey()]);
            $this->audit->execute(
                'medical.certificate_issued',
                $request,
                $user,
                ['consultation' => $consultation->public_id, 'certificate' => $certificate->public_id, 'document' => $document->public_id],
                (int) $unit->getKey(),
                (int) $certificate->patient_id,
                (int) $certificate->encounter_id,
            );

            $certificate = $certificate->fresh('document.currentVersion') ?? $certificate;
            $certificate->setRelation('diagnosisCode', $certificate->resolveDiagnosisCode());

            return $certificate;
        });
    }
}
