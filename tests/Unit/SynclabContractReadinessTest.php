<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Laboratory\Application\Services\SynclabContractReadiness;
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
    }

    public function test_outbound_contract_is_ready_and_future_capabilities_are_parked(): void
    {
        $readiness = app(SynclabContractReadiness::class);

        $this->assertTrue($readiness->allowsTransmission());
        $this->assertSame([], $readiness->blockingDecisions());
        $this->assertSame('not_implemented', config('synclab_contract.standby.barcode_generation'));
        $this->assertSame('not_implemented', config('synclab_contract.standby.result_reception'));
    }
}
