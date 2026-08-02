<?php

declare(strict_types=1);

namespace App\Modules\Documents\Application\Actions;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Audit\Application\Actions\RecordAuditEventAction;
use App\Modules\Documents\Application\Services\ClinicalDocumentCidService;
use App\Modules\Documents\Application\Services\ClinicalDocumentVersionService;
use App\Modules\Documents\Domain\Enums\ClinicalDocumentType;
use App\Modules\Documents\Infrastructure\Eloquent\ClinicalDocument;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Medical\Infrastructure\Eloquent\MedicalConsultation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class IssueClinicalDocumentAction
{
    public function __construct(
        private ClinicalDocumentVersionService $versions,
        private ClinicalDocumentCidService $cid,
        private RecordAuditEventAction $audit,
    ) {}

    /** @param array<string, mixed> $data */
    public function execute(
        MedicalConsultation $consultation,
        array $data,
        User $user,
        HealthUnit $unit,
        Request $request,
    ): ClinicalDocument {
        $type = ClinicalDocumentType::from((string) $data['document_type']);

        return $this->executeStructured(
            $consultation,
            $type,
            collect($data)->except(['document_type', 'title', 'reason'])->all(),
            $user,
            $unit,
            $request,
        );
    }

    /** @param array<string, mixed> $content */
    public function executeStructured(
        MedicalConsultation $consultation,
        ClinicalDocumentType $type,
        array $content,
        User $user,
        HealthUnit $unit,
        Request $request,
    ): ClinicalDocument {
        return DB::transaction(function () use ($consultation, $type, $content, $user, $unit, $request): ClinicalDocument {
            $consultation->loadMissing('encounter.patient');
            $document = ClinicalDocument::query()->create([
                'verification_code' => Str::upper(Str::random(20)),
                'health_unit_id' => $unit->getKey(),
                'encounter_id' => $consultation->encounter_id,
                'patient_id' => $consultation->encounter->patient_id,
                'medical_consultation_id' => $consultation->getKey(),
                'document_type' => $type,
                'title' => $type->label(),
                'status' => 'active',
                'created_by' => $user->getKey(),
            ]);
            $version = $this->versions->create(
                $document,
                $this->cid->normalize($content),
                $user,
                1,
                'Emissão inicial',
            );
            $document->update(['current_version_id' => $version->getKey()]);
            $this->audit->execute(
                'document.issued',
                $request,
                $user,
                ['document' => $document->public_id, 'document_type' => $type->value, 'version' => 1],
                (int) $unit->getKey(),
                (int) $consultation->encounter->patient_id,
                (int) $consultation->encounter_id,
            );

            return $document->fresh(['currentVersion']) ?? $document;
        });
    }
}
