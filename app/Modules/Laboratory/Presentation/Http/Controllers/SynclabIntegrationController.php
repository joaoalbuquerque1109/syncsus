<?php

declare(strict_types=1);

namespace App\Modules\Laboratory\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Audit\Application\Actions\RecordAuditEventAction;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Laboratory\Application\Actions\RotateSynclabResultTokenAction;
use App\Modules\Laboratory\Infrastructure\Eloquent\LaboratoryIntegration;
use App\Modules\Laboratory\Presentation\Http\Requests\UpdateSynclabIntegrationRequest;
use App\Modules\Patients\Infrastructure\Eloquent\Patient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class SynclabIntegrationController extends Controller
{
    public function edit(Request $request): View
    {
        $unit = $this->unit($request);
        $integration = LaboratoryIntegration::query()->firstOrNew([
            'health_unit_id' => $unit->getKey(),
            'provider' => 'synclab',
        ], [
            'organization_id' => $unit->organization_id,
            'base_url' => config('sync_sus.synclab.base_url'),
        ]);
        $statusCounts = $integration->exists
            ? $integration->transmissions()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status')
            : collect();
        $recentTransmissions = $integration->exists
            ? $integration->transmissions()
                ->with('order.encounter')
                ->latest('updated_at')
                ->limit(10)
                ->get()
            : collect();
        $patients = Patient::query()
            ->whereKey($recentTransmissions->pluck('order.encounter.patient_id')->filter()->unique()->all())
            ->get()
            ->keyBy(fn (Patient $patient): int => (int) $patient->getKey());
        foreach ($recentTransmissions as $transmission) {
            $transmission->order->encounter->setRelation(
                'patient',
                $patients->get((int) $transmission->order->encounter->patient_id),
            );
        }
        $catalogTotal = $integration->exists ? $integration->exams()->where('is_active', true)->count() : 0;
        $catalogMapped = $integration->exists
            ? $integration->exams()->where('is_active', true)->whereNotNull('sus_procedure_code')->count()
            : 0;
        $resultStatusCounts = $integration->exists
            ? $integration->resultIngestions()
                ->selectRaw('status, COUNT(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status')
            : collect();
        $recentResultIngestions = $integration->exists
            ? $integration->resultIngestions()->latest('received_at')->limit(10)->get()
            : collect();

        return view('laboratory.integrations.synclab', [
            'integration' => $integration,
            'statusCounts' => $statusCounts,
            'recentTransmissions' => $recentTransmissions,
            'globalEnabled' => (bool) config('sync_sus.synclab.enabled'),
            'resultsGlobalEnabled' => (bool) config('sync_sus.synclab.results_enabled'),
            'catalogTotal' => $catalogTotal,
            'catalogMapped' => $catalogMapped,
            'resultStatusCounts' => $resultStatusCounts,
            'recentResultIngestions' => $recentResultIngestions,
        ]);
    }

    public function update(
        UpdateSynclabIntegrationRequest $request,
        RecordAuditEventAction $audit,
    ): RedirectResponse {
        $unit = $this->unit($request);
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $data = $request->validated();

        DB::transaction(function () use ($unit, $user, $request, $audit, $data): void {
            $integration = LaboratoryIntegration::query()->firstOrNew([
                'health_unit_id' => $unit->getKey(),
                'provider' => 'synclab',
            ]);
            $username = trim((string) ($data['username'] ?? '')) ?: (string) $integration->username;
            $password = (string) ($data['password'] ?? '') ?: (string) $integration->password;
            $enabled = $request->boolean('transmission_enabled');
            $resultsEnabled = $request->boolean('result_sync_enabled');
            // O envio ao Synclab (addrequisicao) nao usa usuario/senha - a unidade
            // e identificada pelo CNES na propria URL. Usuario/senha continuam
            // opcionais aqui apenas para um eventual override futuro, sem bloquear
            // a habilitacao do envio.
            if ($resultsEnabled && blank($integration->result_api_token_hash)) {
                throw ValidationException::withMessages([
                    'result_sync_enabled' => 'Gere o token do webhook antes de habilitar a recepção de resultados.',
                ]);
            }

            $cnes = preg_replace('/\D/', '', (string) $data['cnes_code']);
            $unit->organization()->update(['cnes_code' => $cnes, 'code' => $cnes]);
            $unit->update(['cnes_code' => $cnes]);
            $integration->fill([
                'organization_id' => $unit->organization_id,
                'base_url' => rtrim((string) $data['base_url'], '/'),
                'external_tenant_code' => $cnes,
                'username' => $username !== '' ? $username : null,
                'password' => $password !== '' ? $password : null,
                'is_active' => true,
                'transmission_enabled' => $enabled,
                'result_sync_enabled' => $resultsEnabled,
                'connection_status' => $enabled ? 'configured' : 'disabled',
                'last_error' => null,
            ])->save();

            $audit->execute(
                'laboratory.integration_updated',
                $request,
                $user,
                [
                    'provider' => 'synclab',
                    'cnes' => $cnes,
                    'transmission_enabled' => $enabled,
                    'result_sync_enabled' => $resultsEnabled,
                    'credentials_present' => $integration->hasCredentials(),
                ],
                (int) $unit->getKey(),
            );
        });

        return redirect()->route('administration.synclab.edit')->with(
            'success',
            'Configuração Synclab atualizada. Pedidos antigos aguardam revisão manual.',
        );
    }

    public function rotateResultToken(
        Request $request,
        RotateSynclabResultTokenAction $rotateToken,
        RecordAuditEventAction $audit,
    ): RedirectResponse {
        $unit = $this->unit($request);
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $integration = LaboratoryIntegration::query()
            ->where('health_unit_id', $unit->getKey())
            ->where('provider', 'synclab')
            ->firstOrFail();
        $token = $rotateToken->execute($integration);
        $audit->execute(
            'laboratory.result_token_rotated',
            $request,
            $user,
            ['provider' => 'synclab'],
            (int) $unit->getKey(),
        );

        return redirect()->route('administration.synclab.edit')
            ->with('success', 'Token de recepção rotacionado. Copie-o agora; ele não será exibido novamente.')
            ->with('synclab_result_token', $token);
    }

    private function unit(Request $request): HealthUnit
    {
        $unit = $request->attributes->get('active_health_unit');
        abort_unless($unit instanceof HealthUnit, 403);

        return $unit;
    }
}
