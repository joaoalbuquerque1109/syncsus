<?php

declare(strict_types=1);

namespace App\Modules\Professionals\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Administration\Infrastructure\Eloquent\ServicePoint;
use App\Modules\Administration\Infrastructure\Eloquent\Specialty;
use App\Modules\Audit\Application\Actions\RecordAuditEventAction;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Professionals\Application\Actions\SaveHealthProfessionalAction;
use App\Modules\Professionals\Infrastructure\Eloquent\HealthProfessional;
use App\Modules\Professionals\Presentation\Http\Requests\SaveHealthProfessionalRequest;
use App\Modules\Queues\Infrastructure\Eloquent\Queue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class HealthProfessionalController extends Controller
{
    public function index(Request $request): View
    {
        $unit = $this->unit($request);
        $term = trim((string) $request->query('q'));
        $professionals = HealthProfessional::query()
            ->where('organization_id', $unit->organization_id)
            ->with(['registrations', 'specialties', 'healthUnits', 'queues', 'servicePoints'])
            ->when($term !== '', fn ($query) => $query->where(
                fn ($query) => $query->where('full_name', 'like', "%{$term}%")
                    ->orWhere('institutional_code', 'like', "%{$term}%")
                    ->orWhere('cpf', 'like', '%'.preg_replace('/\D+/', '', $term).'%'),
            ))
            ->orderBy('full_name')
            ->paginate(20)
            ->withQueryString();

        return view('administration.professionals.index', compact('professionals', 'term'));
    }

    public function create(Request $request): View
    {
        return view('administration.professionals.form', $this->formData($request));
    }

    public function store(
        SaveHealthProfessionalRequest $request,
        SaveHealthProfessionalAction $action,
        RecordAuditEventAction $audit,
    ): RedirectResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        $unit = $this->unit($request);
        $professional = $action->execute($request->validated(), $actor);
        $audit->execute('professional.created', $request, $actor, [
            'professional' => $professional->public_id,
            'profession_type' => $professional->profession_type,
        ], (int) $unit->getKey());

        return redirect()->route('administration.professionals.index')
            ->with('success', 'Profissional cadastrado com sucesso.');
    }

    public function edit(Request $request, HealthProfessional $professional): View
    {
        $unit = $this->unit($request);
        abort_unless((int) $professional->organization_id === (int) $unit->organization_id, 404);
        $professional->load(['registrations', 'specialties', 'healthUnits', 'queues', 'servicePoints']);

        return view('administration.professionals.form', $this->formData($request, $professional));
    }

    public function update(
        SaveHealthProfessionalRequest $request,
        HealthProfessional $professional,
        SaveHealthProfessionalAction $action,
        RecordAuditEventAction $audit,
    ): RedirectResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        $unit = $this->unit($request);
        abort_unless((int) $professional->organization_id === (int) $unit->organization_id, 404);
        $action->execute($request->validated(), $actor, $professional);
        $audit->execute('professional.updated', $request, $actor, [
            'professional' => $professional->public_id,
        ], (int) $unit->getKey());

        return redirect()->route('administration.professionals.index')
            ->with('success', 'Profissional atualizado com sucesso.');
    }

    /** @return array<string, mixed> */
    private function formData(Request $request, ?HealthProfessional $professional = null): array
    {
        $unit = $this->unit($request);
        $users = User::query()
            ->where('organization_id', $unit->organization_id)
            ->where('is_active', true)
            ->where(function ($query) use ($professional): void {
                $query->whereDoesntHave('professionalProfile');

                if ($professional?->user_id !== null) {
                    $query->orWhere('id', $professional->user_id);
                }
            })
            ->orderBy('name')
            ->get();
        $queues = Queue::query()
            ->whereHas('healthUnit', fn ($query) => $query->where('organization_id', $unit->organization_id))
            ->where('is_active', true)
            ->with(['healthUnit', 'department', 'specialty'])
            ->orderBy('health_unit_id')
            ->orderBy('display_order')
            ->orderBy('name')
            ->get();
        $servicePoints = ServicePoint::query()
            ->whereHas('room.department.healthUnit', fn ($query) => $query->where('organization_id', $unit->organization_id))
            ->where('is_active', true)
            ->whereHas('queues', fn ($query) => $query->whereIn('queues.id', $queues->modelKeys()))
            ->with(['room.department.healthUnit', 'queues' => fn ($query) => $query->whereIn('queues.id', $queues->modelKeys())])
            ->orderBy('name')
            ->get();

        return [
            'professional' => $professional,
            'users' => $users,
            'healthUnits' => HealthUnit::query()
                ->where('organization_id', $unit->organization_id)
                ->where('is_active', true)->orderBy('name')->get(),
            'specialties' => Specialty::query()
                ->where('organization_id', $unit->organization_id)
                ->where('is_active', true)->orderBy('name')->get(),
            'queues' => $queues,
            'servicePoints' => $servicePoints,
        ];
    }

    private function unit(Request $request): HealthUnit
    {
        $unit = $request->attributes->get('active_health_unit');
        abort_unless($unit instanceof HealthUnit, 403);

        return $unit;
    }
}
