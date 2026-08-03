<?php

declare(strict_types=1);

namespace App\Modules\Laboratory\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Audit\Application\Actions\RecordAuditEventAction;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Laboratory\Infrastructure\Eloquent\LaboratoryIntegration;
use App\Modules\Laboratory\Presentation\Http\Requests\UpdateSynclabIntegrationRequest;
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
                ->with('order.encounter.patient')
                ->latest('updated_at')
                ->limit(10)
                ->get()
            : collect();
        $catalogTotal = $integration->exists ? $integration->exams()->where('is_active', true)->count() : 0;
        $catalogMapped = $integration->exists
            ? $integration->exams()->where('is_active', true)->whereNotNull('sus_procedure_code')->count()
            : 0;

        return view('laboratory.integrations.synclab', [
            'integration' => $integration,
            'statusCounts' => $statusCounts,
            'recentTransmissions' => $recentTransmissions,
            'globalEnabled' => (bool) config('sync_sus.synclab.enabled'),
            'catalogTotal' => $catalogTotal,
            'catalogMapped' => $catalogMapped,
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
            if ($enabled && ($username === '' || $password === '')) {
                throw ValidationException::withMessages([
                    'username' => 'Usuário e senha do Synclab são obrigatórios para habilitar o envio.',
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
                'result_sync_enabled' => false,
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

    private function unit(Request $request): HealthUnit
    {
        $unit = $request->attributes->get('active_health_unit');
        abort_unless($unit instanceof HealthUnit, 403);

        return $unit;
    }
}
