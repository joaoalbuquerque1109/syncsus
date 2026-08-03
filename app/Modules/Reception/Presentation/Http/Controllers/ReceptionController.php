<?php

declare(strict_types=1);

namespace App\Modules\Reception\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Administration\Infrastructure\Eloquent\ArrivalMethod;
use App\Modules\Administration\Infrastructure\Eloquent\Department;
use App\Modules\Administration\Infrastructure\Eloquent\EntryType;
use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Administration\Infrastructure\Eloquent\Specialty;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Laboratory\Infrastructure\Eloquent\LaboratoryExam;
use App\Modules\Patients\Infrastructure\Eloquent\Patient;
use App\Modules\Professionals\Infrastructure\Eloquent\HealthProfessional;
use App\Modules\Queues\Infrastructure\Eloquent\Queue;
use App\Modules\Reception\Application\Actions\CancelEncounterAction;
use App\Modules\Reception\Application\Actions\OpenEncounterAction;
use App\Modules\Reception\Application\Services\ReceptionDraftService;
use App\Modules\Reception\Domain\Enums\AdministrativePriority;
use App\Modules\Reception\Infrastructure\Eloquent\Encounter;
use App\Modules\Reception\Presentation\Http\Requests\CancelEncounterRequest;
use App\Modules\Reception\Presentation\Http\Requests\OpenEncounterRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

final class ReceptionController extends Controller
{
    public function create(Request $request): View
    {
        $unit = $request->attributes->get('active_health_unit');
        abort_unless($unit instanceof HealthUnit, 403);

        $patient = null;
        if ($request->filled('patient')) {
            $patient = Patient::query()->with('identifiers')->where('public_id', $request->query('patient'))->first();
        }

        return view('reception.create', [
            'patient' => $patient,
            'idempotencyKey' => (string) Str::ulid(),
            'entryTypes' => EntryType::query()->where('organization_id', $unit->organization_id)
                ->where('is_active', true)->orderBy('display_order')->get(),
            'arrivalMethods' => ArrivalMethod::query()->where('organization_id', $unit->organization_id)
                ->where('is_active', true)->orderBy('display_order')->get(),
            'departments' => Department::query()->where('health_unit_id', $unit->getKey())->where('is_active', true)->orderBy('display_order')->get(),
            'specialties' => Specialty::query()->where('organization_id', $unit->organization_id)
                ->where('is_active', true)->orderBy('display_order')->get(),
            'queues' => Queue::query()->where('health_unit_id', $unit->getKey())->where('is_active', true)->orderBy('display_order')->get(),
            'priorities' => AdministrativePriority::cases(),
            'examRequesters' => HealthProfessional::query()
                ->with(['user:id,name,is_active', 'registrations'])
                ->where('organization_id', $unit->organization_id)
                ->where('profession_type', 'doctor')
                ->where('is_active', true)
                ->whereHas('healthUnits', fn ($query) => $query->whereKey($unit->getKey()))
                ->whereHas('user', fn ($query) => $query->where('is_active', true))
                ->orderBy('full_name')
                ->get(),
            'selectedExams' => LaboratoryExam::query()
                ->whereIn('id', (array) $request->old('exam_ids', []))
                ->whereHas('integration', fn ($query) => $query
                    ->where('organization_id', $unit->organization_id)
                    ->where('health_unit_id', $unit->getKey()))
                ->get()
                ->map(fn (LaboratoryExam $exam): array => [
                    'id' => $exam->getKey(),
                    'code' => $exam->external_code,
                    'name' => $exam->name,
                    'label' => trim(($exam->acronym ? $exam->acronym.' · ' : '').$exam->name),
                ])
                ->values(),
        ]);
    }

    public function store(OpenEncounterRequest $request, OpenEncounterAction $action): RedirectResponse
    {
        $user = $request->user();
        $unit = $request->attributes->get('active_health_unit');
        abort_unless($user instanceof User && $unit instanceof HealthUnit, 403);
        $encounter = $action->execute($request->validated(), $user, $unit, $request);

        return redirect()->route('reception.receipt', $encounter)
            ->with('success', 'Atendimento aberto e senha emitida com sucesso.');
    }

    public function draftForPatient(Request $request, ReceptionDraftService $drafts): RedirectResponse
    {
        $unit = $request->attributes->get('active_health_unit');
        abort_unless($unit instanceof HealthUnit, 403);
        $drafts->store($request, (int) $unit->getKey());

        return redirect()->route('patients.create', ['return_to_reception' => 1]);
    }

    public function draftForProvisionalPatient(Request $request, ReceptionDraftService $drafts): RedirectResponse
    {
        $unit = $request->attributes->get('active_health_unit');
        abort_unless($unit instanceof HealthUnit, 403);
        $drafts->store($request, (int) $unit->getKey());

        return redirect()->route('patients.provisional.create');
    }

    public function resumeDraft(Request $request, ReceptionDraftService $drafts): RedirectResponse
    {
        $unit = $request->attributes->get('active_health_unit');
        abort_unless($unit instanceof HealthUnit, 403);

        return redirect()->route('reception.create')
            ->withInput($drafts->pull($request, (int) $unit->getKey()));
    }

    public function receipt(Request $request, Encounter $encounter): View
    {
        $unit = $request->attributes->get('active_health_unit');
        abort_unless($unit instanceof HealthUnit && $encounter->health_unit_id === $unit->getKey(), 404);

        return view('reception.receipt', [
            'encounter' => $encounter->load([
                'patient.identifiers', 'healthUnit', 'entryType', 'arrivalMethod',
                'currentDepartment', 'assignedSpecialty', 'receptionRecord', 'companions',
                'queueEntries' => fn ($query) => $query->with('queue')->latest(),
            ]),
        ]);
    }

    public function cancel(
        CancelEncounterRequest $request,
        Encounter $encounter,
        CancelEncounterAction $action,
    ): RedirectResponse {
        $user = $request->user();
        $unit = $request->attributes->get('active_health_unit');
        abort_unless($user instanceof User && $unit instanceof HealthUnit, 403);
        $action->execute(
            $encounter,
            $request->integer('version'),
            (string) $request->input('reason'),
            $user,
            $unit,
            $request,
        );

        return redirect()->route('reception.receipt', $encounter)
            ->with('success', 'Atendimento cancelado e removido das filas ativas.');
    }
}
