<?php

declare(strict_types=1);

namespace App\Modules\Administration\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Administration\Infrastructure\Eloquent\ArrivalMethod;
use App\Modules\Administration\Infrastructure\Eloquent\EntryType;
use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Administration\Infrastructure\Eloquent\Organization;
use App\Modules\Administration\Infrastructure\Eloquent\Specialty;
use App\Modules\Audit\Application\Actions\RecordAuditEventAction;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Laboratory\Infrastructure\Eloquent\LaboratoryExam;
use App\Modules\Laboratory\Infrastructure\Eloquent\LaboratoryIntegration;
use App\Modules\Queues\Infrastructure\Eloquent\Queue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class CatalogManagementController extends Controller
{
    public function index(Request $request): View
    {
        $unit = $this->unit($request);
        $examSearch = mb_substr(trim((string) $request->query('exam_q')), 0, 80);
        $normalizedExamSearch = mb_strtoupper(str_replace(['%', '_'], '', $examSearch));

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
            'laboratoryExams' => LaboratoryExam::query()
                ->whereHas('integration', fn ($query) => $query
                    ->where('organization_id', $unit->organization_id)
                    ->where('health_unit_id', $unit->getKey())
                    ->where('provider', 'synclab'))
                ->when($normalizedExamSearch !== '', function ($query) use ($examSearch, $normalizedExamSearch): void {
                    $query->where(function ($query) use ($examSearch, $normalizedExamSearch): void {
                        $query->where('external_code', 'like', $normalizedExamSearch.'%')
                            ->orWhere('acronym', 'like', $normalizedExamSearch.'%')
                            ->orWhere('sus_procedure_code', 'like', $normalizedExamSearch.'%')
                            ->orWhere('name', 'like', '%'.$examSearch.'%')
                            ->orWhere('short_name', 'like', '%'.$examSearch.'%')
                            ->orWhere('synonyms', 'like', '%'.$examSearch.'%');
                    });
                })
                ->orderBy('name')
                ->paginate(20, ['*'], 'exam_page')
                ->withQueryString(),
            'examSearch' => $examSearch,
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
        if ($catalog === 'health-units' && $recordId === null) {
            throw ValidationException::withMessages([
                'health_unit' => 'Crie novas unidades como tenants na administração global.',
            ]);
        }

        if ($catalog === 'laboratory-exams') {
            $request->merge([
                'external_code' => mb_strtoupper(trim((string) $request->input('external_code'))),
                'acronym' => mb_strtoupper(trim((string) $request->input('acronym'))),
                'integration_acronym' => mb_strtoupper(trim((string) $request->input('integration_acronym'))),
                'sus_procedure_code' => preg_replace('/\D/', '', (string) $request->input('sus_procedure_code')),
            ]);
        }

        $model = $this->model($catalog, $recordId);
        if ($model->exists && in_array($catalog, ['specialties', 'arrival-methods', 'entry-types'], true)) {
            abort_unless((int) $model->getAttribute('organization_id') === (int) $unit->organization_id, 404);
        }
        if ($catalog === 'health-units' && $model->exists) {
            abort_unless((int) $model->getAttribute('organization_id') === (int) $unit->organization_id, 404);
        }
        if ($catalog === 'laboratory-exams' && $model->exists) {
            abort_unless($model instanceof LaboratoryExam && $model->integration()
                ->where('organization_id', $unit->organization_id)
                ->where('health_unit_id', $unit->getKey())
                ->where('provider', 'synclab')
                ->exists(), 404);
        }
        $laboratoryIntegrationId = $catalog === 'laboratory-exams'
            ? ($model->exists
                ? (int) $model->getAttribute('laboratory_integration_id')
                : (int) (LaboratoryIntegration::query()
                    ->where('organization_id', $unit->organization_id)
                    ->where('health_unit_id', $unit->getKey())
                    ->where('provider', 'synclab')
                    ->value('id') ?? -1))
            : null;
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
                    Rule::in([(string) $unit->organization->code]),
                ],
                'name' => ['required', 'string', 'max:255'],
                'cnes_code' => [
                    'required',
                    'digits:7',
                    Rule::in([(string) $unit->organization->tenantIdentifier()]),
                ],
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
            'laboratory-exams' => $request->validate([
                'external_code' => [
                    'required', 'string', 'max:128',
                    Rule::unique('laboratory_exams')->where(
                        'laboratory_integration_id',
                        $laboratoryIntegrationId,
                    )->ignore($model->getKey()),
                ],
                'name' => ['required', 'string', 'max:255'],
                'short_name' => ['nullable', 'string', 'max:255'],
                'acronym' => ['nullable', 'string', 'max:64'],
                'integration_acronym' => ['nullable', 'string', 'max:128'],
                'sus_procedure_code' => ['nullable', 'digits:10'],
                'group_name' => ['nullable', 'string', 'max:255'],
                'turnaround_minutes' => ['nullable', 'integer', 'min:0', 'max:525600'],
                'collection_instructions' => ['nullable', 'string', 'max:5000'],
                'synonyms_text' => ['nullable', 'string', 'max:2000'],
                'is_active' => ['nullable', 'boolean'],
            ]),
            default => abort(404),
        };

        if (array_key_exists('code', $data)) {
            $data['code'] = mb_strtoupper(trim((string) $data['code']));
        }
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
        if ($catalog === 'health-units') {
            $data['cnes_code'] = preg_replace('/\D/', '', (string) $data['cnes_code']);
        }
        if ($catalog === 'laboratory-exams') {
            $integration = LaboratoryIntegration::query()->firstOrCreate(
                [
                    'health_unit_id' => $unit->getKey(),
                    'provider' => 'synclab',
                ],
                [
                    'organization_id' => $unit->organization_id,
                    'external_tenant_code' => $unit->organization->tenantIdentifier(),
                    'base_url' => config('sync_sus.synclab.base_url'),
                    'is_active' => true,
                ],
            );
            $data['laboratory_integration_id'] = $integration->getKey();
            $data['short_name'] = filled($data['short_name'] ?? null)
                ? trim((string) $data['short_name'])
                : null;
            $data['acronym'] = filled($data['acronym'] ?? null) ? $data['acronym'] : null;
            $data['integration_acronym'] = filled($data['integration_acronym'] ?? null)
                ? $data['integration_acronym']
                : null;
            $data['sus_procedure_code'] = filled($data['sus_procedure_code'] ?? null)
                ? $data['sus_procedure_code']
                : null;
            $data['group_name'] = filled($data['group_name'] ?? null)
                ? trim((string) $data['group_name'])
                : null;
            $data['collection_instructions'] = filled($data['collection_instructions'] ?? null)
                ? trim((string) $data['collection_instructions'])
                : null;
            $data['synonyms'] = $this->synonyms((string) ($data['synonyms_text'] ?? ''));
            $data['source_version'] = null;
            $data['content_hash'] = null;
            $data['source_updated_at'] = null;
            unset($data['synonyms_text']);
        }
        $model->fill($data)->save();
        $audit->execute('administration.catalog_saved', $request, $actor, [
            'catalog' => $catalog,
            'record_id' => $model->getKey(),
            'created' => $recordId === null,
            ...($catalog === 'laboratory-exams' ? [
                'external_code' => $model->getAttribute('external_code'),
                'is_active' => $model->getAttribute('is_active'),
            ] : []),
        ], (int) $unit->getKey());

        if ($catalog === 'laboratory-exams') {
            return redirect()->route('administration.catalogs.index', ['tab' => 'exams'])
                ->with('success', $recordId === null ? 'Exame cadastrado.' : 'Exame atualizado.');
        }

        return back()->with('success', $recordId === null ? 'Item cadastrado.' : 'Item atualizado.');
    }

    private function model(string $catalog, ?int $recordId): Model
    {
        $class = match ($catalog) {
            'specialties' => Specialty::class,
            'arrival-methods' => ArrivalMethod::class,
            'entry-types' => EntryType::class,
            'health-units' => HealthUnit::class,
            'laboratory-exams' => LaboratoryExam::class,
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

    /** @return list<string> */
    private function synonyms(string $value): array
    {
        return collect(preg_split('/[,;\r\n]+/u', $value) ?: [])
            ->map(fn (string $synonym): string => trim($synonym))
            ->filter()
            ->unique(fn (string $synonym): string => mb_strtoupper($synonym))
            ->take(30)
            ->values()
            ->all();
    }
}
