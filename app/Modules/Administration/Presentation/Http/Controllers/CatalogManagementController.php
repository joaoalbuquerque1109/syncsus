<?php

declare(strict_types=1);

namespace App\Modules\Administration\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Administration\Application\Services\HealthUnitFlowBootstrapper;
use App\Modules\Administration\Infrastructure\Eloquent\ArrivalMethod;
use App\Modules\Administration\Infrastructure\Eloquent\EntryType;
use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Administration\Infrastructure\Eloquent\Organization;
use App\Modules\Administration\Infrastructure\Eloquent\Specialty;
use App\Modules\Audit\Application\Actions\RecordAuditEventAction;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Queues\Infrastructure\Eloquent\Queue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class CatalogManagementController extends Controller
{
    public function index(Request $request): View
    {
        $unit = $this->unit($request);

        return view('administration.catalogs.index', [
            'specialties' => Specialty::query()->where('organization_id', $unit->organization_id)
                ->orderBy('display_order')->orderBy('name')->get(),
            'arrivalMethods' => ArrivalMethod::query()->where('organization_id', $unit->organization_id)
                ->orderBy('display_order')->orderBy('name')->get(),
            'entryTypes' => EntryType::query()->where('organization_id', $unit->organization_id)
                ->with('defaultQueue')->orderBy('display_order')->orderBy('name')->get(),
            'healthUnits' => HealthUnit::query()->with('organization')
                ->where('organization_id', $unit->organization_id)->orderBy('name')->get(),
            'organizations' => Organization::query()->whereKey($unit->organization_id)->get(),
            'queues' => Queue::query()->with('healthUnit')
                ->where('health_unit_id', $unit->getKey())->orderBy('name')->get(),
        ]);
    }

    public function store(
        Request $request,
        string $catalog,
        RecordAuditEventAction $audit,
    ): RedirectResponse {
        return $this->save($request, $catalog, null, $audit);
    }

    public function update(
        Request $request,
        string $catalog,
        int $record,
        RecordAuditEventAction $audit,
    ): RedirectResponse {
        return $this->save($request, $catalog, $record, $audit);
    }

    private function save(
        Request $request,
        string $catalog,
        ?int $recordId,
        RecordAuditEventAction $audit,
    ): RedirectResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User && $actor->can('administration.manage'), 403);
        $unit = $this->unit($request);

        $model = $this->model($catalog, $recordId);
        if ($model->exists && in_array($catalog, ['specialties', 'arrival-methods', 'entry-types'], true)) {
            abort_unless((int) $model->getAttribute('organization_id') === (int) $unit->organization_id, 404);
        }
        if ($catalog === 'health-units' && $model->exists) {
            abort_unless((int) $model->getAttribute('organization_id') === (int) $unit->organization_id, 404);
        }
        $data = match ($catalog) {
            'specialties' => $request->validate([
                'code' => [
                    'required', 'string', 'max:32',
                    Rule::unique('specialties')->where('organization_id', $unit->organization_id)
                        ->ignore($model->getKey()),
                ],
                'name' => ['required', 'string', 'max:255'],
                'display_order' => ['nullable', 'integer', 'min:0', 'max:999'],
                'is_active' => ['nullable', 'boolean'],
            ]),
            'arrival-methods' => $request->validate([
                'code' => [
                    'required', 'string', 'max:32',
                    Rule::unique('arrival_methods')->where('organization_id', $unit->organization_id)
                        ->ignore($model->getKey()),
                ],
                'name' => ['required', 'string', 'max:255'],
                'display_order' => ['nullable', 'integer', 'min:0', 'max:999'],
                'requires_vehicle_data' => ['nullable', 'boolean'],
                'is_active' => ['nullable', 'boolean'],
            ]),
            'entry-types' => $request->validate([
                'code' => [
                    'required', 'string', 'max:32',
                    Rule::unique('entry_types')->where('organization_id', $unit->organization_id)
                        ->ignore($model->getKey()),
                ],
                'name' => ['required', 'string', 'max:255'],
                'display_order' => ['nullable', 'integer', 'min:0', 'max:999'],
                'requires_triage' => ['nullable', 'boolean'],
                'allows_provisional_patient' => ['nullable', 'boolean'],
                'default_queue_id' => [
                    'nullable',
                    Rule::exists('queues', 'id')->where('health_unit_id', $unit->getKey()),
                ],
                'is_active' => ['nullable', 'boolean'],
            ]),
            'health-units' => $request->validate([
                'organization_id' => ['required', Rule::in([(int) $unit->organization_id])],
                'code' => [
                    'required', 'string', 'max:32',
                    Rule::unique('health_units')->where('organization_id', $request->integer('organization_id'))->ignore($model->getKey()),
                ],
                'name' => ['required', 'string', 'max:255'],
                'cnes_code' => ['nullable', 'string', 'max:16'],
                'postal_code' => ['nullable', 'string', 'max:16'],
                'state' => ['nullable', 'string', 'size:2'],
                'city' => ['nullable', 'string', 'max:255'],
                'district' => ['nullable', 'string', 'max:255'],
                'street' => ['nullable', 'string', 'max:255'],
                'street_number' => ['nullable', 'string', 'max:32'],
                'address_complement' => ['nullable', 'string', 'max:255'],
                'phone' => ['nullable', 'string', 'max:32'],
                'is_active' => ['nullable', 'boolean'],
            ]),
            default => abort(404),
        };

        $data['code'] = mb_strtoupper(trim((string) $data['code']));
        $data['is_active'] = $request->boolean('is_active');
        if (in_array($catalog, ['specialties', 'arrival-methods', 'entry-types'], true)) {
            $data['organization_id'] = $unit->organization_id;
        }
        if ($catalog === 'arrival-methods') {
            $data['requires_vehicle_data'] = $request->boolean('requires_vehicle_data');
        }
        if ($catalog === 'entry-types') {
            $data['requires_triage'] = $request->boolean('requires_triage');
            $data['allows_provisional_patient'] = $request->boolean('allows_provisional_patient');
        }
        $model->fill($data)->save();
        if ($catalog === 'health-units' && $recordId === null && $model instanceof HealthUnit) {
            app(HealthUnitFlowBootstrapper::class)->bootstrap($model);
            $actor->healthUnits()->syncWithoutDetaching([$model->getKey()]);
        }
        $audit->execute('administration.catalog_saved', $request, $actor, [
            'catalog' => $catalog,
            'record_id' => $model->getKey(),
            'created' => $recordId === null,
        ], (int) $unit->getKey());

        return back()->with('success', $recordId === null ? 'Item cadastrado.' : 'Item atualizado.');
    }

    private function model(string $catalog, ?int $recordId): Model
    {
        $class = match ($catalog) {
            'specialties' => Specialty::class,
            'arrival-methods' => ArrivalMethod::class,
            'entry-types' => EntryType::class,
            'health-units' => HealthUnit::class,
            default => abort(404),
        };

        return $recordId === null ? new $class : $class::query()->findOrFail($recordId);
    }

    private function unit(Request $request): HealthUnit
    {
        $unit = $request->attributes->get('active_health_unit');
        abort_unless($unit instanceof HealthUnit, 403);

        return $unit;
    }
}
