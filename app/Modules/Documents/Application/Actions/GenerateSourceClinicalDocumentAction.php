<?php

declare(strict_types=1);

namespace App\Modules\Documents\Application\Actions;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Administration\Infrastructure\Eloquent\Specialty;
use App\Modules\Documents\Application\Services\ClinicalDocumentVersionService;
use App\Modules\Documents\Domain\Enums\ClinicalDocumentType;
use App\Modules\Documents\Infrastructure\Eloquent\ClinicalDocument;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Medical\Infrastructure\Eloquent\ExamOrder;
use App\Modules\Medical\Infrastructure\Eloquent\MedicalConsultation;
use App\Modules\Medical\Infrastructure\Eloquent\Prescription;
use App\Modules\Medical\Infrastructure\Eloquent\Referral;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Throwable;

/** @phpstan-import-type RenderedVersion from ClinicalDocumentVersionService */
final readonly class GenerateSourceClinicalDocumentAction
{
    public function __construct(private IssueClinicalDocumentAction $documents) {}

    public function prescription(MedicalConsultation $consultation, Prescription $prescription, User $user, HealthUnit $unit, Request $request): ClinicalDocument
    {
        return $this->generate($consultation, $prescription, $user, $unit, $request);
    }

    public function examOrder(MedicalConsultation $consultation, ExamOrder $order, User $user, HealthUnit $unit, Request $request): ClinicalDocument
    {
        return $this->generate($consultation, $order, $user, $unit, $request);
    }

    public function referral(MedicalConsultation $consultation, Referral $referral, User $user, HealthUnit $unit, Request $request): ClinicalDocument
    {
        return $this->generate($consultation, $referral, $user, $unit, $request);
    }

    private function generate(
        MedicalConsultation $consultation,
        Model $source,
        User $user,
        HealthUnit $unit,
        Request $request,
    ): ClinicalDocument {
        $current = $source->newQuery()->whereKey($source->getKey())->firstOrFail();
        abort_unless((int) $current->getAttribute('medical_consultation_id') === (int) $consultation->getKey(), 404);
        $documentId = $current->getAttribute('document_id');
        if (filled($documentId)) {
            return ClinicalDocument::query()->findOrFail($documentId);
        }

        [$type, $content] = $this->sourceContent($current);
        $prepared = $this->documents->render($consultation, $type, $content, $user, $unit);

        try {
            return DB::transaction(function () use ($consultation, $source, $user, $unit, $request, $type, $prepared): ClinicalDocument {
                $locked = $source->newQuery()->whereKey($source->getKey())->lockForUpdate()->firstOrFail();
                abort_unless((int) $locked->getAttribute('medical_consultation_id') === (int) $consultation->getKey(), 404);
                $documentId = $locked->getAttribute('document_id');
                if (filled($documentId)) {
                    return ClinicalDocument::query()->findOrFail($documentId);
                }
                $this->validateSource($locked);

                $document = $this->documents->persist(
                    $prepared['document'],
                    $prepared['version'],
                    $consultation,
                    $type,
                    $user,
                    $unit,
                    $request,
                );
                $locked->update(['document_id' => $document->getKey()]);

                return $document;
            });
        } catch (Throwable $exception) {
            $this->documents->discardRenderedVersion($prepared['version']);

            throw $exception;
        }
    }

    /** @return array{ClinicalDocumentType, array<string, mixed>} */
    private function sourceContent(Model $source): array
    {
        return match (true) {
            $source instanceof Prescription => [ClinicalDocumentType::Prescription, $this->prescriptionContent($source)],
            $source instanceof ExamOrder => [ClinicalDocumentType::ExamRequest, $this->examOrderContent($source)],
            $source instanceof Referral => [ClinicalDocumentType::Referral, $this->referralContent($source)],
            default => throw new LogicException('Tipo de origem documental não suportado.'),
        };
    }

    private function validateSource(Model $source): void
    {
        if ($source instanceof Prescription && $source->status !== 'finalized') {
            throw ValidationException::withMessages(['prescription' => 'Somente uma prescrição vigente pode gerar PDF.']);
        }
    }

    /** @return array<string, mixed> */
    private function prescriptionContent(Prescription $prescription): array
    {
        $this->validateSource($prescription);
        $prescription->loadMissing('items');

        return [
            'source_public_id' => $prescription->public_id,
            'prescription_type' => $prescription->prescription_type,
            'general_instructions' => $prescription->general_instructions,
            'issued_at' => filled($prescription->finalized_at)
                ? Carbon::parse($prescription->finalized_at)->toIso8601String()
                : null,
            'items' => $prescription->items->map(fn ($item): array => [
                'medication_name' => $item->medication_name,
                'presentation' => $item->presentation,
                'concentration' => $item->concentration,
                'dose' => $item->dose,
                'dose_unit' => $item->dose_unit,
                'route' => $item->route,
                'frequency' => $item->frequency,
                'duration_value' => $item->duration_value,
                'duration_unit' => $item->duration_unit,
                'quantity' => $item->quantity,
                'instructions' => $item->instructions,
            ])->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function examOrderContent(ExamOrder $order): array
    {
        $order->loadMissing('items');

        return [
            'source_public_id' => $order->public_id,
            'priority' => $order->priority,
            'clinical_indication' => $order->clinical_indication,
            'notes' => $order->notes,
            'issued_at' => Carbon::parse($order->requested_at)->toIso8601String(),
            'items' => $order->items->map(fn ($item): array => [
                'internal_code' => $item->internal_code,
                'exam_name' => $item->exam_name,
                'group' => $item->group,
                'priority' => $item->priority,
                'laterality' => $item->laterality,
                'preparation' => $item->preparation,
                'justification' => $item->justification,
            ])->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function referralContent(Referral $referral): array
    {
        $referral->setRelation(
            'specialty',
            $referral->specialty_id === null ? null : Specialty::query()->find($referral->specialty_id),
        );

        return [
            'source_public_id' => $referral->public_id,
            'referral_type' => $referral->referral_type,
            'destination' => $referral->destination,
            'specialty' => $referral->specialty?->name,
            'recipient_professional' => $referral->recipient_professional,
            'reason' => $referral->reason,
            'clinical_summary' => $referral->clinical_summary,
            'priority' => $referral->priority,
            'diagnostic_hypothesis' => $referral->diagnostic_hypothesis,
            'guidance' => $referral->guidance,
            'issued_at' => Carbon::parse($referral->issued_at)->toIso8601String(),
        ];
    }
}
