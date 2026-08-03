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
    }

    public function test_unresolved_decisions_keep_real_transmission_blocked(): void
    {
        $readiness = app(SynclabContractReadiness::class);

        $this->assertFalse($readiness->allowsTransmission());
        $this->assertContains('external_tenant_code_semantics', $readiness->blockingDecisions());
        $this->assertContains('duplicate_order_behaviour', $readiness->blockingDecisions());
        $this->assertContains('external_order_number_format', $readiness->blockingDecisions());
        $this->assertContains('result_partial_final_indicator', $readiness->blockingDecisions());
        $this->assertContains('stable_result_identifiers', $readiness->blockingDecisions());
        $this->assertContains('catalog_source', $readiness->blockingDecisions());
        $this->assertContains('success_response_identifiers', $readiness->blockingDecisions());
    }
}
