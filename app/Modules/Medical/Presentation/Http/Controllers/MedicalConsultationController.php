<?php

declare(strict_types=1);

namespace App\Modules\Medical\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Administration\Infrastructure\Eloquent\Specialty;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Medical\Application\Actions\AddDiagnosisAction;
use App\Modules\Medical\Application\Actions\CompleteMedicalConsultationAction;
use App\Modules\Medical\Application\Actions\CreateClinicalNoteAction;
use App\Modules\Medical\Application\Actions\CreateExamOrderAction;
use App\Modules\Medical\Application\Actions\CreateMedicalAddendumAction;
use App\Modules\Medical\Application\Actions\CreatePrescriptionAction;
use App\Modules\Medical\Application\Actions\CreateReferralAction;
use App\Modules\Medical\Application\Actions\SaveMedicalDraftAction;
use App\Modules\Medical\Application\Actions\StartMedicalConsultationAction;
use App\Modules\Medical\Application\Actions\VoidClinicalRecordAction;
use App\Modules\Medical\Application\Services\MedicalConsultationContextLoader;
use App\Modules\Medical\Infrastructure\Eloquent\ClinicalNote;
use App\Modules\Medical\Infrastructure\Eloquent\Diagnosis;
use App\Modules\Medical\Infrastructure\Eloquent\MedicalConsultation;
use App\Modules\Medical\Infrastructure\Eloquent\Prescription;
use App\Modules\Medical\Presentation\Http\Requests\CompleteMedicalConsultationRequest;
use App\Modules\Medical\Presentation\Http\Requests\SaveMedicalDraftRequest;
use App\Modules\Medical\Presentation\Http\Requests\StoreClinicalNoteRequest;
use App\Modules\Medical\Presentation\Http\Requests\StoreDiagnosisRequest;
use App\Modules\Medical\Presentation\Http\Requests\StoreExamOrderRequest;
use App\Modules\Medical\Presentation\Http\Requests\StoreMedicalAddendumRequest;
use App\Modules\Medical\Presentation\Http\Requests\StorePrescriptionRequest;
use App\Modules\Medical\Presentation\Http\Requests\StoreReferralRequest;
use App\Modules\Medical\Presentation\Http\Requests\VoidClinicalRecordRequest;
use App\Modules\Patients\Application\Actions\LogPatientAccessAction;
use App\Modules\Queues\Application\Services\QueueVisibilityService;
use App\Modules\Queues\Infrastructure\Eloquent\Queue;
use App\Modules\Queues\Infrastructure\Eloquent\QueueEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class MedicalConsultationController extends Controller
{
    public function queue(Request $request, QueueVisibilityService $visibility): RedirectResponse
    {
        $unit = $this->unit($request);
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $queue = $visibility->apply(Queue::query(), $user)
            ->where('health_unit_id', $unit->getKey())
            ->whereHas('department', fn ($query) => $query->where('type', 'medical'))
            ->where('is_active', true)
            ->orderBy('display_order')
            ->firstOrFail();

        return redirect()->route('queues.index', ['queue' => $queue->public_id]);
    }

    public function start(Request $request, QueueEntry $entry, StartMedicalConsultationAction $action): JsonResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        [$user, $unit] = $this->context($request);
        $consultation = $action->execute($entry, (int) $data['version'], $user, $unit, $request);

        return response()->json([
            'message' => 'Atendimento médico iniciado.',
            'redirect_url' => route('medical.show', $consultation),
            'consultation' => $consultation->public_id,
        ]);
    }

    public function show(
        Request $request,
        MedicalConsultation $consultation,
        LogPatientAccessAction $access,
        MedicalConsultationContextLoader $contextLoader,
    ): View {
        [$user, $unit] = $this->context($request);
        $this->ensureUnit($consultation, $unit);
        if ($consultation->statusEnum()->value === 'draft') {
            abort_unless($user->canManageClinicalRecordOwnedBy($consultation->professional_id), 403);
        }
        $contextLoader->load($consultation);
        $access->execute($request, $user, $consultation->encounter->patient, (int) $unit->getKey(), 'medical_record_view');

        return view('medical.show', [
            'consultation' => $consultation,
            'specialties' => Specialty::query()->where('organization_id', $unit->organization_id)
                ->where('is_active', true)->orderBy('display_order')->get(),
        ]);
    }

    public function draft(
        SaveMedicalDraftRequest $request,
        MedicalConsultation $consultation,
        SaveMedicalDraftAction $action,
    ): RedirectResponse {
        [$user, $unit] = $this->context($request);
        $this->ensureUnit($consultation, $unit);
        $action->execute($consultation, $request->validated(), $user, $unit, $request);

        return back()->with('success', 'Rascunho do atendimento salvo.');
    }

    public function diagnosis(
        StoreDiagnosisRequest $request,
        MedicalConsultation $consultation,
        AddDiagnosisAction $action,
    ): RedirectResponse {
        [$user, $unit] = $this->context($request);
        $this->ensureUnit($consultation, $unit);
        $action->execute($consultation, $request->validated(), $user, $unit, $request);

        return redirect()->route('medical.show', ['consultation' => $consultation, 'tab' => 'diagnosis'])
            ->with('success', 'Diagnóstico registrado.');
    }

    public function prescription(
        StorePrescriptionRequest $request,
        MedicalConsultation $consultation,
        CreatePrescriptionAction $action,
    ): RedirectResponse {
        [$user, $unit] = $this->context($request);
        $this->ensureUnit($consultation, $unit);
        $prescription = $action->execute($consultation, $request->validated(), $user, $unit, $request);

        $message = $prescription->parent_prescription_id
            ? "Nova versão da prescrição {$prescription->public_id} finalizada."
            : "Prescrição {$prescription->public_id} finalizada.";

        return redirect()->route('medical.show', ['consultation' => $consultation, 'tab' => 'prescriptions'])
            ->with('success', $message);
    }

    public function examOrder(
        StoreExamOrderRequest $request,
        MedicalConsultation $consultation,
        CreateExamOrderAction $action,
    ): RedirectResponse {
        [$user, $unit] = $this->context($request);
        $this->ensureUnit($consultation, $unit);
        $action->execute($consultation, $request->validated(), $user, $unit, $request);

        return redirect()->route('medical.show', ['consultation' => $consultation, 'tab' => 'exams'])
            ->with('success', 'Solicitação de exames registrada.');
    }

    public function clinicalNote(
        StoreClinicalNoteRequest $request,
        MedicalConsultation $consultation,
        CreateClinicalNoteAction $action,
    ): RedirectResponse {
        [$user, $unit] = $this->context($request);
        $this->ensureUnit($consultation, $unit);
        $action->execute($consultation, $request->validated(), $user, $unit, $request);

        return redirect()->route('medical.show', ['consultation' => $consultation, 'tab' => 'evolution'])
            ->with('success', 'Evolução clínica finalizada.');
    }

    public function referral(
        StoreReferralRequest $request,
        MedicalConsultation $consultation,
        CreateReferralAction $action,
    ): RedirectResponse {
        [$user, $unit] = $this->context($request);
        $this->ensureUnit($consultation, $unit);
        $action->execute($consultation, $request->validated(), $user, $unit, $request);

        return redirect()->route('medical.show', ['consultation' => $consultation, 'tab' => 'referrals'])
            ->with('success', 'Encaminhamento emitido.');
    }

    public function complete(
        CompleteMedicalConsultationRequest $request,
        MedicalConsultation $consultation,
        CompleteMedicalConsultationAction $action,
    ): RedirectResponse {
        [$user, $unit] = $this->context($request);
        $this->ensureUnit($consultation, $unit);
        $destination = $action->execute($consultation, $request->validated(), $user, $unit, $request);

        return redirect()->route('medical.show', $consultation)
            ->with('success', 'Atendimento finalizado com destinação: '.$destination->typeEnum()->label().'.');
    }

    public function addendum(
        StoreMedicalAddendumRequest $request,
        MedicalConsultation $consultation,
        CreateMedicalAddendumAction $action,
    ): RedirectResponse {
        [$user, $unit] = $this->context($request);
        $this->ensureUnit($consultation, $unit);
        $action->execute(
            $consultation,
            (string) $request->input('reason'),
            (string) $request->input('content'),
            $user,
            $unit,
            $request,
        );

        return back()->with('success', 'Adendo médico registrado sem alterar o conteúdo original.');
    }

    public function voidDiagnosis(
        VoidClinicalRecordRequest $request,
        MedicalConsultation $consultation,
        Diagnosis $diagnosis,
        VoidClinicalRecordAction $action,
    ): RedirectResponse {
        [$user, $unit] = $this->context($request);
        $action->diagnosis($consultation, $diagnosis, (string) $request->input('reason'), $user, $unit, $request);

        return back()->with('success', 'Diagnostico anulado; o registro original foi preservado.');
    }

    public function cancelPrescription(
        VoidClinicalRecordRequest $request,
        MedicalConsultation $consultation,
        Prescription $prescription,
        VoidClinicalRecordAction $action,
    ): RedirectResponse {
        [$user, $unit] = $this->context($request);
        $action->prescription($consultation, $prescription, (string) $request->input('reason'), $user, $unit, $request);

        return back()->with('success', 'Prescricao cancelada; o registro original foi preservado.');
    }

    public function voidClinicalNote(
        VoidClinicalRecordRequest $request,
        MedicalConsultation $consultation,
        ClinicalNote $note,
        VoidClinicalRecordAction $action,
    ): RedirectResponse {
        [$user, $unit] = $this->context($request);
        $action->note($consultation, $note, (string) $request->input('reason'), $user, $unit, $request);

        return back()->with('success', 'Evolucao anulada; o registro original foi preservado.');
    }

    /** @return array{User, HealthUnit} */
    private function context(Request $request): array
    {
        $user = $request->user();
        $unit = $this->unit($request);
        abort_unless($user instanceof User, 403);

        return [$user, $unit];
    }

    private function unit(Request $request): HealthUnit
    {
        $unit = $request->attributes->get('active_health_unit');
        abort_unless($unit instanceof HealthUnit, 403);

        return $unit;
    }

    private function ensureUnit(MedicalConsultation $consultation, HealthUnit $unit): void
    {
        abort_unless($consultation->encounter()->where('health_unit_id', $unit->getKey())->exists(), 404);
    }
}
