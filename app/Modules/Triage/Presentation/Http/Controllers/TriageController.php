<?php

declare(strict_types=1);

namespace App\Modules\Triage\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Administration\Infrastructure\Eloquent\RiskLevel;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Patients\Application\Actions\LogPatientAccessAction;
use App\Modules\Queues\Application\Services\QueueVisibilityService;
use App\Modules\Queues\Infrastructure\Eloquent\Queue;
use App\Modules\Queues\Infrastructure\Eloquent\QueueEntry;
use App\Modules\Reception\Application\Actions\CancelEncounterAction;
use App\Modules\Triage\Application\Actions\CompleteTriageAction;
use App\Modules\Triage\Application\Actions\CreateTriageAddendumAction;
use App\Modules\Triage\Application\Actions\RecordVitalSignsAction;
use App\Modules\Triage\Application\Actions\SaveTriageDraftAction;
use App\Modules\Triage\Application\Actions\StartTriageAction;
use App\Modules\Triage\Application\Services\TriageAssessmentContextLoader;
use App\Modules\Triage\Infrastructure\Eloquent\TriageAssessment;
use App\Modules\Triage\Infrastructure\Eloquent\TriageProtocol;
use App\Modules\Triage\Presentation\Http\Requests\CompleteTriageRequest;
use App\Modules\Triage\Presentation\Http\Requests\SaveTriageDraftRequest;
use App\Modules\Triage\Presentation\Http\Requests\StoreTriageAddendumRequest;
use App\Modules\Triage\Presentation\Http\Requests\StoreVitalSignsRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class TriageController extends Controller
{
    public function queue(Request $request, QueueVisibilityService $visibility): RedirectResponse
    {
        $unit = $this->unit($request);
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $queue = $visibility->apply(Queue::query(), $user)
            ->where('health_unit_id', $unit->getKey())
            ->whereHas('department', fn ($query) => $query->where('type', 'triage'))
            ->where('is_active', true)
            ->orderBy('display_order')
            ->firstOrFail();

        return redirect()->route('queues.index', ['queue' => $queue->public_id]);
    }

    public function start(Request $request, QueueEntry $entry, StartTriageAction $action): JsonResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        [$user, $unit] = $this->context($request);
        $assessment = $action->execute($entry, (int) $data['version'], $user, $unit, $request);

        return response()->json([
            'message' => 'Triagem iniciada.',
            'redirect_url' => route('triage.show', $assessment),
            'triage' => $assessment->public_id,
        ]);
    }

    public function show(
        Request $request,
        TriageAssessment $triage,
        LogPatientAccessAction $access,
        TriageAssessmentContextLoader $contextLoader,
        CancelEncounterAction $cancelAction,
    ): View {
        [$user, $unit] = $this->context($request);
        abort_unless($triage->encounter()->where('health_unit_id', $unit->getKey())->exists(), 404);
        if ($triage->statusEnum()->value === 'draft') {
            abort_unless($user->canManageClinicalRecordOwnedBy($triage->professional_id), 403);
        }
        $contextLoader->load($triage);
        $access->execute($request, $user, $triage->encounter->patient, (int) $unit->getKey(), 'triage_record_view');

        return view('triage.show', [
            'triage' => $triage,
            'protocols' => TriageProtocol::query()->with(['flowcharts' => fn ($query) => $query->where('is_active', true)->with(['discriminators' => fn ($query) => $query->where('is_active', true)->orderBy('display_order')])->orderBy('display_order')])->where('is_active', true)->get(),
            'riskLevels' => RiskLevel::query()->where('is_active', true)->orderByDesc('priority_weight')->get(),
            'destinationQueues' => Queue::query()->with('department')->where('health_unit_id', $unit->getKey())->whereHas('department', fn ($query) => $query->where('type', 'medical'))->where('is_active', true)->whereKeyNot($triage->queueEntry->queue_id)->orderBy('display_order')->get(),
            'canTransferQueue' => $user->can('queues.transfer'),
            'transferQueues' => Queue::query()->where('health_unit_id', $unit->getKey())->where('is_active', true)->whereKeyNot($triage->queueEntry->queue_id)->orderBy('display_order')->get(['id', 'public_id', 'name']),
            'canCancelEncounter' => $cancelAction->canCancel($triage->encounter, $user),
            'isPlatformAdministrator' => $user->isPlatformAdministrator(),
        ]);
    }

    public function draft(SaveTriageDraftRequest $request, TriageAssessment $triage, SaveTriageDraftAction $action): RedirectResponse
    {
        [$user, $unit] = $this->context($request);
        $this->ensureAssessmentBelongsToUnit($triage, $unit);
        $action->execute($triage, $request->validated(), $user, $unit, $request);

        return back()->with('success', 'Rascunho da triagem salvo.');
    }

    public function vitalSigns(StoreVitalSignsRequest $request, TriageAssessment $triage, RecordVitalSignsAction $action): RedirectResponse
    {
        [$user, $unit] = $this->context($request);
        $this->ensureAssessmentBelongsToUnit($triage, $unit);
        $measurement = $action->execute($triage, $request->validated(), $user, $unit, $request);

        return redirect()->route('triage.show', ['triage' => $triage, 'tab' => 'vitals'])
            ->with('success', 'Sinais vitais registrados. IMC: '.($measurement->bmi ?? 'não calculado').'.');
    }

    public function complete(CompleteTriageRequest $request, TriageAssessment $triage, CompleteTriageAction $action): RedirectResponse
    {
        [$user, $unit] = $this->context($request);
        $this->ensureAssessmentBelongsToUnit($triage, $unit);
        $destination = $action->execute($triage, $request->validated(), $user, $unit, $request);

        return redirect()->route('triage.show', $triage)
            ->with('success', "Triagem finalizada. Nova senha: {$destination->ticket_number}.");
    }

    public function addendum(StoreTriageAddendumRequest $request, TriageAssessment $triage, CreateTriageAddendumAction $action): RedirectResponse
    {
        [$user, $unit] = $this->context($request);
        $this->ensureAssessmentBelongsToUnit($triage, $unit);
        $action->execute($triage, (string) $request->input('reason'), (string) $request->input('content'), $user, $unit, $request);

        return back()->with('success', 'Adendo registrado sem alterar o conteúdo original.');
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

    private function ensureAssessmentBelongsToUnit(TriageAssessment $triage, HealthUnit $unit): void
    {
        abort_unless($triage->encounter()->where('health_unit_id', $unit->getKey())->exists(), 404);
    }
}
