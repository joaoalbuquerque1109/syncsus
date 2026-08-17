<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Laboratory\Application\Services\SynclabContractReadiness;
use App\Modules\Laboratory\Application\Services\SynclabOutboundContract;
use Tests\TestCase;

final class SynclabContractReadinessTest extends TestCase
{
    public function test_confirmed_decisions_are_recorded(): void
    {
        $this->assertSame([200], config('synclab_contract.confirmed.accepted_http_statuses'));
        $this->assertSame('name_and_cpf_or_cns', config('synclab_contract.confirmed.patient_identification'));
        $this->assertSame('synclab_after_request', config('synclab_contract.confirmed.sample_identification'));
        $this->assertSame('sync_sus_laboratory_exams', config('synclab_contract.confirmed.catalog_source'));
        $this->assertSame('health_unit_cnes', config('synclab_contract.confirmed.endpoint_scope'));
        $this->assertSame('exam_orders_id', config('synclab_contract.confirmed.external_order_number'));
        $this->assertFalse(config('sync_sus.synclab.samples_enabled'));
        $this->assertFalse(config('sync_sus.synclab.barcodes_enabled'));
        $this->assertFalse(config('sync_sus.synclab.results_enabled'));
        $this->assertFalse(config('sync_sus.synclab.public_identifiers_enabled'));
        $this->assertSame('outbound-orders-2026-08-03', config('synclab_contract.version'));
    }

    public function test_outbound_contract_is_ready_and_future_capabilities_are_parked(): void
    {
        $readiness = app(SynclabContractReadiness::class);

        $this->assertTrue($readiness->allowsTransmission());
        $this->assertSame([], $readiness->blockingDecisions());
        $this->assertSame('not_implemented', config('synclab_contract.standby.barcode_generation'));
        $this->assertSame('feature_gated_webhook', config('synclab_contract.standby.result_reception'));
        $this->assertSame(
            'blocked_provider_api_unavailable',
            config('synclab_contract.standby.incremental_catalog_sync'),
        );
        $this->assertSame(
            'blocked_provider_api_unavailable',
            config('synclab_contract.transitions.incremental_catalog_sync.status'),
        );
        $this->assertNull(config('synclab_contract.transitions.incremental_catalog_sync.endpoint'));
        $this->assertSame(
            'versioned_csv',
            config('synclab_contract.transitions.incremental_catalog_sync.fallback_source'),
        );
        $this->assertSame(
            'inbound-results-2026-08-08',
            config('synclab_contract.transitions.result_reception.version'),
        );
    }

    public function test_public_identifier_contract_has_an_explicit_feature_gated_version(): void
    {
        $contract = app(SynclabOutboundContract::class);
        $this->assertFalse($contract->includesPublicIdentifiers());
        $this->assertSame('outbound-orders-2026-08-03', $contract->version());
        $this->assertTrue(config('synclab_contract.transitions.public_identifiers.legacy_fields_preserved'));

        config()->set('sync_sus.synclab.public_identifiers_enabled', true);

        $this->assertTrue($contract->includesPublicIdentifiers());
        $this->assertSame('outbound-orders-2026-08-08-public-identifiers', $contract->version());
    }
}
