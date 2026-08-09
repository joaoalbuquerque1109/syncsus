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
use App\Modules\Patients\Infrastructure\Eloquent\Patient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/** @phpstan-import-type RenderedVersion from ClinicalDocumentVersionService */
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
        $prepared = $this->render($consultation, $type, $content, $user, $unit);

        try {
            $document = DB::transaction(fn (): ClinicalDocument => $this->persist(
                $prepared['document'],
                $prepared['version'],
                $consultation,
                $type,
                $user,
                $unit,
                $request,
            ));
            $document->setRelation('healthUnit', $unit);
            $document->setRelation('patient', Patient::query()->findOrFail($document->patient_id));

            return $document;
        } catch (Throwable $exception) {
            $this->discardRenderedVersion($prepared['version']);

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array{document: ClinicalDocument, version: RenderedVersion}
     */
    public function render(
        MedicalConsultation $consultation,
        ClinicalDocumentType $type,
        array $content,
        User $user,
        HealthUnit $unit,
    ): array {
        $consultation->loadMissing('encounter');
        $patient = Patient::query()->findOrFail($consultation->encounter->patient_id);
        $document = new ClinicalDocument;
        $document->forceFill([
            'public_id' => (string) Str::ulid(),
            'verification_code' => Str::upper(Str::random(20)),
            'health_unit_id' => $unit->getKey(),
            'encounter_id' => $consultation->encounter_id,
            'patient_id' => $consultation->encounter->patient_id,
            'patient_public_id' => $patient->public_id,
            'medical_consultation_id' => $consultation->getKey(),
            'document_type' => $type,
            'title' => $type->label(),
            'status' => 'active',
            'created_by' => $user->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $normalizedContent = $this->cid->normalize($content);

        return [
            'document' => $document,
            'version' => $this->versions->render($document, $normalizedContent, 1),
        ];
    }

    /** @param RenderedVersion $rendered */
    public function persist(
        ClinicalDocument $document,
        array $rendered,
        MedicalConsultation $consultation,
        ClinicalDocumentType $type,
        User $user,
        HealthUnit $unit,
        Request $request,
    ): ClinicalDocument {
        $document->save();
        $version = $this->versions->persist(
            $document,
            $rendered,
            $user,
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
    }

    /** @param RenderedVersion $rendered */
    public function discardRenderedVersion(array $rendered): void
    {
        $this->versions->discardIfUnpersisted($rendered);
    }
}
