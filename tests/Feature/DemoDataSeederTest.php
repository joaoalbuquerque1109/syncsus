<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Documents\Infrastructure\Eloquent\ClinicalDocument;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Reports\Application\Queries\OperationalDashboardQuery;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class DemoDataSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_seed_creates_a_complete_idempotent_synthetic_journey(): void
    {
        config()->set('sync_sus.seed_demo_data', true);
        config()->set('sync_sus.admin', ['name' => null, 'email' => null, 'password' => null]);
        Storage::fake('local_private');

        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $administrator = User::query()->where('email', 'admin@syncsus.local')->firstOrFail();
        $document = ClinicalDocument::query()->with('currentVersion')->firstOrFail();
        $unit = HealthUnit::query()->where('code', 'URGENCIA-CENTRAL')->firstOrFail();
        $metrics = app(OperationalDashboardQuery::class)->metrics($unit);

        $this->assertDatabaseCount('users', 6);
        $this->assertDatabaseCount('patients', 10);
        $this->assertDatabaseCount('health_professionals', 2);
        $this->assertDatabaseCount('professional_registrations', 2);
        $this->assertDatabaseCount('patient_medications', 2);
        $this->assertDatabaseCount('patient_social_histories', 2);
        $this->assertDatabaseCount('encounters', 8);
        $this->assertDatabaseCount('triage_assessments', 7);
        $this->assertDatabaseCount('medical_consultations', 4);
        $this->assertDatabaseCount('encounter_destinations', 3);
        $this->assertDatabaseCount('documents', 1);
        $this->assertDatabaseCount('document_versions', 1);
        $this->assertTrue($administrator->hasRole('administrator'));
        $this->assertTrue(Hash::check('Demo#SyncSUS2026', (string) $administrator->password));
        $this->assertFalse($administrator->must_change_password);
        $this->assertTrue($administrator->isPlatformAdministrator());
        $this->assertNull($administrator->organization_id);
        $this->assertSame(1, $metrics['waiting_triage']);
        $this->assertSame(1, $metrics['in_triage']);
        $this->assertSame(2, $metrics['waiting_medical']);
        $this->assertSame(1, $metrics['in_medical_care']);
        $this->assertSame(1, $metrics['under_observation']);
        $this->assertSame(1, $metrics['discharges_today']);
        $this->assertSame(1, $metrics['transfers_today']);
        Storage::disk('local_private')->assertExists($document->currentVersion->pdf_path);
    }
}
