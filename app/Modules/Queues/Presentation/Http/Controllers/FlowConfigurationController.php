<?php

declare(strict_types=1);

namespace App\Modules\Queues\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Administration\Infrastructure\Eloquent\Department;
use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Administration\Infrastructure\Eloquent\Room;
use App\Modules\Administration\Infrastructure\Eloquent\ServicePoint;
use App\Modules\Administration\Infrastructure\Eloquent\Specialty;
use App\Modules\Audit\Application\Actions\RecordAuditEventAction;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Queues\Domain\Enums\PanelIdentificationMode;
use App\Modules\Queues\Infrastructure\Eloquent\Panel;
use App\Modules\Queues\Infrastructure\Eloquent\Queue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class FlowConfigurationController extends Controller
{
    public function index(Request $request): View
    {
        $unit = $this->unit($request);

        return view('administration.flow', [
            'departments' => Department::query()->with('rooms.servicePoints')
                ->where('health_unit_id', $unit->getKey())->orderBy('display_order')->get(),
            'rooms' => Room::query()->with('department')
                ->whereHas('department', fn ($query) => $query->where('health_unit_id', $unit->getKey()))
                ->orderBy('name')->get(),
            'specialties' => Specialty::query()
                ->where('organization_id', $unit->organization_id)
                ->where('is_active', true)->orderBy('display_order')->get(),
            'queues' => Queue::query()->with(['department', 'servicePoints'])->where('health_unit_id', $unit->getKey())->orderBy('display_order')->get(),
            'servicePoints' => ServicePoint::query()
                ->with('room.department')
                ->whereHas('room.department', fn ($query) => $query->where('health_unit_id', $unit->getKey()))
                ->where('is_active', true)
                ->get(),
            'panels' => Panel::query()->with('queues')->where('health_unit_id', $unit->getKey())->get(),
            'identificationModes' => PanelIdentificationMode::cases(),
        ]);
    }

    public function storeDepartment(Request $request, RecordAuditEventAction $audit): RedirectResponse
    {
        $unit = $this->unit($request);
        $data = $request->validate([
            'code' => ['required', 'alpha_dash:ascii', 'max:32', Rule::unique('departments')->where('health_unit_id', $unit->getKey())],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['administrative', 'triage', 'medical', 'observation', 'diagnostic', 'procedure'])],
            'display_order' => ['nullable', 'integer', 'between:0,999'],
            'is_clinical' => ['nullable', 'boolean'],
        ]);
        $department = Department::query()->create([
            ...$data,
            'health_unit_id' => $unit->getKey(),
            'code' => mb_strtoupper($data['code']),
            'is_clinical' => $request->boolean('is_clinical'),
            'is_active' => true,
        ]);
        $this->audit($request, $audit, $unit, 'department.created', $department->getKey());

        return back()->with('success', 'Setor criado.');
    }

    public function storeRoom(Request $request, RecordAuditEventAction $audit): RedirectResponse
    {
        $unit = $this->unit($request);
        $departmentIds = Department::query()->where('health_unit_id', $unit->getKey())->pluck('id');
        $data = $request->validate([
            'department_id' => ['required', Rule::in($departmentIds->all())],
            'code' => ['required', 'alpha_dash:ascii', 'max:32', Rule::unique('rooms')->where('department_id', $request->integer('department_id'))],
            'name' => ['required', 'string', 'max:255'],
            'room_type' => ['required', 'string', 'max:32'],
        ]);
        $room = Room::query()->create([...$data, 'code' => mb_strtoupper($data['code']), 'is_active' => true]);
        $this->audit($request, $audit, $unit, 'room.created', $room->getKey());

        return back()->with('success', 'Sala criada.');
    }

    public function storeServicePoint(Request $request, RecordAuditEventAction $audit): RedirectResponse
    {
        $unit = $this->unit($request);
        $roomIds = Room::query()->whereHas('department', fn ($query) => $query->where('health_unit_id', $unit->getKey()))->pluck('id');
        $data = $request->validate([
            'room_id' => ['required', Rule::in($roomIds->all())],
            'code' => ['required', 'alpha_dash:ascii', 'max:32', Rule::unique('service_points')->where('room_id', $request->integer('room_id'))],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'max:32'],
        ]);
        $point = ServicePoint::query()->create([...$data, 'code' => mb_strtoupper($data['code']), 'is_active' => true]);
        $this->audit($request, $audit, $unit, 'service_point.created', $point->getKey());

        return back()->with('success', 'Ponto de atendimento criado.');
    }

    public function storeQueue(Request $request, RecordAuditEventAction $audit): RedirectResponse
    {
        $unit = $this->unit($request);
        $departmentIds = Department::query()->where('health_unit_id', $unit->getKey())->pluck('id');
        $pointIds = ServicePoint::query()
            ->whereHas('room.department', fn ($query) => $query->where('health_unit_id', $unit->getKey()))
            ->pluck('id');
        $data = $request->validate([
            'department_id' => ['required', Rule::in($departmentIds->all())],
            'specialty_id' => [
                'nullable',
                Rule::exists('specialties', 'id')
                    ->where('organization_id', $unit->organization_id)
                    ->where('is_active', true),
            ],
            'code' => ['required', 'alpha_dash:ascii', 'max:32', Rule::unique('queues')->where('health_unit_id', $unit->getKey())],
            'name' => ['required', 'string', 'max:255'],
            'prefix' => ['required', 'alpha_num:ascii', 'max:8'],
            'ticket_length' => ['required', 'integer', 'between:1,8'],
            'priority_strategy' => ['required', Rule::in(['fifo', 'priority_fifo'])],
            'minimum_calls_before_absent' => ['required', 'integer', 'between:1,10'],
            'service_points' => ['required', 'array', 'min:1'],
            'service_points.*' => ['integer', Rule::in($pointIds->all())],
        ]);
        $department = Department::query()->findOrFail($data['department_id']);
        if ($department->type === 'medical' && empty($data['specialty_id'])) {
            return back()->withErrors(['specialty_id' => 'Filas medicas devem possuir especialidade.'])->withInput();
        }
        $queue = Queue::query()->create([
            ...Arr::except($data, ['service_points']),
            'health_unit_id' => $unit->getKey(),
            'code' => mb_strtoupper($data['code']),
            'prefix' => mb_strtoupper($data['prefix']),
            'sequence_reset_policy' => 'daily',
            'is_active' => true,
            'display_order' => (int) Queue::query()->where('health_unit_id', $unit->getKey())->max('display_order') + 10,
        ]);
        $queue->servicePoints()->sync($data['service_points']);
        $this->audit($request, $audit, $unit, 'queue.created', $queue->getKey());

        return back()->with('success', 'Fila criada.');
    }

    public function storePanel(Request $request, RecordAuditEventAction $audit): RedirectResponse
    {
        $unit = $this->unit($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'identification_mode' => ['required', Rule::enum(PanelIdentificationMode::class)],
            'queues' => ['required', 'array', 'min:1'],
            'queues.*' => ['integer', Rule::exists('queues', 'id')->where('health_unit_id', $unit->getKey())],
        ]);
        $panel = Panel::query()->create([
            'health_unit_id' => $unit->getKey(),
            'name' => $data['name'],
            'public_code' => 'p-'.Str::lower(Str::random(40)),
            'identification_mode' => PanelIdentificationMode::FullName->value,
            // Fluxo configurável anterior: 'identification_mode' => $data['identification_mode'],
            'previous_calls_count' => 5,
            'sound_enabled' => true,
            'suggested_volume' => 80,
            'theme' => 'institutional',
            'is_active' => true,
        ]);
        $panel->queues()->sync($data['queues']);
        $this->audit($request, $audit, $unit, 'panel.created', $panel->getKey());

        return back()->with('success', 'Painel criado.');
    }

    public function updateQueue(Request $request, Queue $queue, RecordAuditEventAction $audit): RedirectResponse
    {
        $unit = $this->unit($request);
        abort_unless($queue->health_unit_id === $unit->getKey(), 404);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'prefix' => ['required', 'alpha_num:ascii', 'max:8'],
            'ticket_length' => ['required', 'integer', 'between:1,8'],
            'priority_strategy' => ['required', Rule::in(['fifo', 'priority_fifo'])],
            'minimum_calls_before_absent' => ['required', 'integer', 'between:1,10'],
            'service_points' => ['required', 'array', 'min:1'],
            'service_points.*' => [
                'integer',
                Rule::exists('service_points', 'id')->whereIn(
                    'room_id',
                    ServicePoint::query()
                        ->whereHas('room.department', fn ($query) => $query->where('health_unit_id', $unit->getKey()))
                        ->pluck('room_id'),
                ),
            ],
        ]);
        $queue->update([
            'name' => $data['name'],
            'prefix' => mb_strtoupper($data['prefix']),
            'ticket_length' => $data['ticket_length'],
            'priority_strategy' => $data['priority_strategy'],
            'minimum_calls_before_absent' => $data['minimum_calls_before_absent'],
        ]);
        $queue->servicePoints()->sync($data['service_points']);
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $audit->execute('queue.configuration_updated', $request, $user, ['queue' => $queue->public_id], (int) $unit->getKey());

        return back()->with('success', 'Configuração da fila atualizada.');
    }

    public function updatePanel(Request $request, Panel $panel, RecordAuditEventAction $audit): RedirectResponse
    {
        $unit = $this->unit($request);
        abort_unless($panel->health_unit_id === $unit->getKey(), 404);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'identification_mode' => ['required', Rule::enum(PanelIdentificationMode::class)],
            'previous_calls_count' => ['required', 'integer', 'between:1,20'],
            'sound_enabled' => ['nullable', 'boolean'],
            'suggested_volume' => ['required', 'integer', 'between:0,100'],
            'institutional_message' => ['nullable', 'string', 'max:255'],
            'queues' => ['required', 'array', 'min:1'],
            'queues.*' => ['integer', Rule::exists('queues', 'id')->where('health_unit_id', $unit->getKey())],
        ]);
        $panel->update([
            'name' => $data['name'],
            'identification_mode' => PanelIdentificationMode::FullName->value,
            // Fluxo configurável anterior: 'identification_mode' => $data['identification_mode'],
            'previous_calls_count' => $data['previous_calls_count'],
            'sound_enabled' => $request->boolean('sound_enabled'),
            'suggested_volume' => $data['suggested_volume'],
            'institutional_message' => $data['institutional_message'] ?? null,
        ]);
        $panel->queues()->sync($data['queues']);
        Cache::forget("syncsus:unit:{$unit->getKey()}:panel:{$panel->getKey()}:queue-ids");
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $audit->execute('panel.configuration_updated', $request, $user, ['panel' => $panel->public_id], (int) $unit->getKey());

        return back()->with('success', 'Configuração do painel atualizada.');
    }

    private function unit(Request $request): HealthUnit
    {
        $unit = $request->attributes->get('active_health_unit');
        abort_unless($unit instanceof HealthUnit, 403);

        return $unit;
    }

    private function audit(
        Request $request,
        RecordAuditEventAction $audit,
        HealthUnit $unit,
        string $action,
        int $recordId,
    ): void {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $audit->execute($action, $request, $user, ['record_id' => $recordId], (int) $unit->getKey());
    }
}
