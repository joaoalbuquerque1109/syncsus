<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Laboratory\Application\Jobs\SubmitLaboratoryOrderJob;
use App\Modules\Queues\Infrastructure\Eloquent\Panel;
use App\Support\Tenancy\PublicLookupIndex;
use App\Support\Tenancy\TenantConnectionManager;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantContextNotResolvedException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CoreTenantBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_models_fail_closed_without_context_but_allow_explicit_resolved_connection(): void
    {
        $unit = $this->createHealthUnit('BOUNDARY-CONTEXT');
        $connection = app(TenantConnectionManager::class)->connectionName($unit);
        app(TenantContext::class)->reset();

        try {
            Panel::query()->count();
            $this->fail('TenantModel deveria falhar sem contexto resolvido.');
        } catch (TenantContextNotResolvedException) {
            $this->addToAssertionCount(1);
        }

        // Infrastructure with a previously resolved connection may bypass request context.
        $this->assertSame(0, Panel::on($connection)->count());
    }

    public function test_tenant_writes_store_public_core_reference_and_public_index_idempotently(): void
    {
        $unit = $this->createHealthUnit('BOUNDARY-INDEX');
        $panel = Panel::query()->create([
            'health_unit_id' => $unit->getKey(),
            'name' => 'Painel principal',
            'public_code' => 'BOUNDARY-PANEL',
        ]);

        $this->assertSame($unit->public_id, $panel->health_unit_public_id);
        $this->assertDatabaseHas('public_lookup_index', [
            'public_id_or_code' => 'BOUNDARY-PANEL',
            'entity_type' => 'panel',
        ]);

        $panel->touch();
        $this->assertSame(1, PublicLookupIndex::query()->where('public_id_or_code', 'BOUNDARY-PANEL')->count());
    }

    public function test_public_panel_never_falls_back_to_a_global_tenant_query(): void
    {
        $unit = $this->createHealthUnit('BOUNDARY-PUBLIC');
        Panel::query()->create([
            'health_unit_id' => $unit->getKey(),
            'name' => 'Painel público',
            'public_code' => 'PUBLIC-WITHOUT-INDEX',
        ]);
        PublicLookupIndex::query()->where('public_id_or_code', 'PUBLIC-WITHOUT-INDEX')->delete();
        app(TenantContext::class)->reset();

        $this->get('/panels/PUBLIC-WITHOUT-INDEX')->assertNotFound();
        $this->assertFalse(app(TenantContext::class)->isResolved());
    }

    public function test_laboratory_job_payload_carries_unit_and_connection(): void
    {
        $unit = $this->createHealthUnit('BOUNDARY-JOB');
        $connection = app(TenantConnectionManager::class)->connectionName($unit);
        $job = new SubmitLaboratoryOrderJob(321, (string) $unit->public_id, $connection);

        $this->assertSame(321, $job->transmissionId);
        $this->assertSame($unit->public_id, $job->healthUnitPublicId);
        $this->assertSame($connection, $job->tenantConnection);
        $this->assertSame($connection.':321', $job->uniqueId());
    }
}
