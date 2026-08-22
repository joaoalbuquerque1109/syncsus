<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Administration\Infrastructure\Eloquent\EntryType;
use App\Modules\Queues\Infrastructure\Eloquent\Queue;
use Database\Seeders\OperationalCatalogSeeder;
use Tests\Concerns\RefreshCoreAndTenantDatabase;
use Tests\TestCase;

final class BackfillLabIntakeQueueCommandTest extends TestCase
{
    use RefreshCoreAndTenantDatabase;

    public function test_backfill_creates_lab_intake_queue_and_wires_default_queue_id_for_existing_units(): void
    {
        $unitA = $this->createHealthUnit('BACKFILL-A');
        $unitB = $this->createHealthUnit('BACKFILL-B');
        $this->seed([OperationalCatalogSeeder::class]);

        foreach ([$unitA, $unitB] as $unit) {
            $this->activateTenant($unit);
            Queue::query()->where('health_unit_id', $unit->getKey())->where('code', 'QUEUE-LAB_INTAKE')->delete();
            EntryType::query()
                ->where('organization_id', $unit->organization_id)
                ->where('code', 'RETURN')
                ->update(['default_queue_id' => null]);
        }

        $exitCode = $this->artisan('sync-sus:backfill-lab-intake-queue')->run();
        $this->assertSame(0, $exitCode);

        foreach ([$unitA, $unitB] as $unit) {
            $this->activateTenant($unit);
            $queue = Queue::query()->where('health_unit_id', $unit->getKey())->where('code', 'QUEUE-LAB_INTAKE')->first();
            $this->assertNotNull($queue, "Unidade {$unit->code} deveria ter a fila de recepcao de exames criada.");
            $entryType = EntryType::query()
                ->where('organization_id', $unit->organization_id)
                ->where('code', 'RETURN')
                ->sole();
            $this->assertSame($queue->getKey(), $entryType->default_queue_id);
        }
    }

    public function test_backfill_is_idempotent(): void
    {
        $unit = $this->createHealthUnit('BACKFILL-IDEMPOTENT');
        $this->seed([OperationalCatalogSeeder::class]);

        $this->artisan('sync-sus:backfill-lab-intake-queue')->run();
        $exitCode = $this->artisan('sync-sus:backfill-lab-intake-queue')->run();

        $this->assertSame(0, $exitCode);
        $this->activateTenant($unit);
        $this->assertDatabaseCount('queues', 5);
    }
}
