<?php

declare(strict_types=1);

namespace App\Modules\Laboratory\Application\Services;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Laboratory\Infrastructure\Eloquent\LaboratoryIntegration;

final class SynclabIntegrationReadiness
{
    /**
     * Liga envio e recepcao Synclab por padrao para a unidade - toda unidade
     * real ja chega com CNES cadastrado no Synclab antes de existir no
     * SyncHosp, entao a integracao pode nascer pronta nos dois sentidos.
     *
     * So toca em integracoes sem sinal de configuracao manual: credenciais ou
     * CNES divergente do proprio CNES da unidade - nunca sobrescreve uma
     * configuracao real que um administrador ja tenha feito. Note que
     * `transmission_enabled` ja em `true` NAO conta como "configurado
     * manualmente" (e o proprio valor que esta funcao aplica por padrao) -
     * senao rodar este metodo de novo numa unidade ja habilitada antes
     * nunca conseguiria aplicar um novo campo de padrao adicionado depois
     * (foi exatamente isso que aconteceu com result_sync_enabled).
     *
     * Assume que o contexto de tenant/conexao correto para $unit ja foi
     * resolvido pelo chamador antes desta chamada.
     *
     * @return bool true quando a integracao nao tinha sinal de configuracao manual e foi (re)habilitada agora
     */
    public function ensureReady(HealthUnit $unit): bool
    {
        $integration = LaboratoryIntegration::query()->firstOrNew([
            'health_unit_id' => $unit->getKey(),
            'provider' => 'synclab',
        ]);
        $externalTenantCodeLooksManual = filled($integration->external_tenant_code)
            && $integration->external_tenant_code !== $unit->cnes_code;
        $looksManuallyConfigured = filled($integration->username)
            || filled($integration->password)
            || $externalTenantCodeLooksManual;
        if ($looksManuallyConfigured) {
            return false;
        }

        $integration->fill([
            'organization_id' => $unit->organization_id,
            'base_url' => $integration->base_url ?: rtrim((string) config('sync_sus.synclab.base_url'), '/'),
            'external_tenant_code' => $unit->cnes_code,
            'is_active' => true,
            'transmission_enabled' => true,
            'result_sync_enabled' => true,
            'connection_status' => 'configured',
        ])->save();

        return true;
    }
}
