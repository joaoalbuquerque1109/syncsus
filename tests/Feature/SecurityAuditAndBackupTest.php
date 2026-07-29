<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Audit\Application\Actions\RecordAuditEventAction;
use App\Modules\Audit\Infrastructure\Eloquent\AuditLog;
use App\Modules\Identity\Application\Services\LimitConcurrentSessions;
use App\Modules\Operations\Application\Services\BackupSetVerifier;
use App\Modules\Patients\Domain\Enums\PatientSex;
use App\Modules\Patients\Domain\Enums\PatientStatus;
use App\Modules\Patients\Infrastructure\Eloquent\Patient;
use App\Modules\Patients\Infrastructure\Eloquent\PatientAccessLog;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class SecurityAuditAndBackupTest extends TestCase
{
    use RefreshDatabase;

    public function test_auditor_searches_only_active_unit_and_context_is_redacted(): void
    {
        $unit = $this->createHealthUnit();
        $otherUnit = $this->createHealthUnit('NORTH');
        $this->seed(RolePermissionSeeder::class);
        $auditor = $this->createUserWithUnit($unit, ['name' => 'Auditora Local', 'must_change_password' => false]);
        $auditor->assignRole('auditor');
        $manager = $this->createUserWithUnit($unit, ['must_change_password' => false]);
        $manager->assignRole('manager');
        $patient = Patient::query()->create([
            'organization_id' => $unit->organization_id,
            'medical_record_number' => 'P00000777',
            'full_name' => 'Paciente Fictício de Auditoria',
            'normalized_name' => 'PACIENTE FICTICIO DE AUDITORIA',
            'birth_date' => '1990-05-10',
            'sex' => PatientSex::Female,
            'status' => PatientStatus::Active,
            'reference_health_unit_id' => $unit->getKey(),
            'created_by' => $auditor->getKey(),
            'updated_by' => $auditor->getKey(),
        ]);
        AuditLog::query()->create([
            'user_id' => $auditor->getKey(),
            'health_unit_id' => $unit->getKey(),
            'patient_id' => $patient->getKey(),
            'action' => 'patient.viewed',
            'context' => ['access_type' => 'record_view'],
            'occurred_at' => now(),
        ]);
        AuditLog::query()->create([
            'user_id' => $auditor->getKey(),
            'health_unit_id' => $otherUnit->getKey(),
            'action' => 'cross.unit.secret_event',
            'occurred_at' => now(),
        ]);
        PatientAccessLog::query()->create([
            'user_id' => $auditor->getKey(),
            'patient_id' => $patient->getKey(),
            'health_unit_id' => $unit->getKey(),
            'access_type' => 'medical_record_view',
            'purpose' => 'assistência',
            'route_name' => 'medical.show',
            'occurred_at' => now(),
        ]);

        $session = ['active_health_unit_id' => $unit->getKey()];
        $this->actingAs($auditor)->withSession($session)
            ->get(route('audit.index', [
                'date_from' => today()->toDateString(),
                'date_to' => today()->toDateString(),
                'patient' => 'P00000777',
            ]))
            ->assertOk()
            ->assertSee('Auditoria e acessos ao prontuário')
            ->assertSee('patient.viewed')
            ->assertSee('medical_record_view')
            ->assertSee('P00000777')
            ->assertDontSee('cross.unit.secret_event');

        $this->actingAs($manager)->withSession($session)->get(route('audit.index'))->assertOk();

        $request = Request::create('/internal-test', 'POST', server: [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_USER_AGENT' => 'Phase 7 test',
        ]);
        app(RecordAuditEventAction::class)->execute(
            'security.redaction_test',
            $request,
            $auditor,
            [
                'password' => 'NeverPersistThis',
                'nested' => ['api_token' => 'NeverPersistThisEither', 'safe' => 'kept'],
            ],
            (int) $unit->getKey(),
        );
        $context = AuditLog::query()->where('action', 'security.redaction_test')->sole()->context;
        $this->assertSame('[REDACTED]', $context['password']);
        $this->assertSame('[REDACTED]', $context['nested']['api_token']);
        $this->assertSame('kept', $context['nested']['safe']);
        $this->assertStringNotContainsString('NeverPersistThis', json_encode($context, JSON_THROW_ON_ERROR));
    }

    public function test_security_headers_https_enforcement_and_session_limit_are_active(): void
    {
        $response = $this->get(route('health.live'))
            ->assertOk()
            ->assertHeader('x-content-type-options', 'nosniff')
            ->assertHeader('x-frame-options', 'DENY')
            ->assertHeader('referrer-policy', 'strict-origin-when-cross-origin')
            ->assertHeader('content-security-policy');
        $this->assertStringNotContainsString(
            'localhost:5173',
            (string) $response->headers->get('content-security-policy'),
        );

        config(['sync_sus.require_https' => true]);
        $this->get(route('health.live'))
            ->assertStatus(308)
            ->assertRedirect('https://localhost/health/live');
        $this->get('https://localhost/health/live')
            ->assertOk()
            ->assertHeader('strict-transport-security', 'max-age=31536000; includeSubDomains');
        $this->post('http://localhost/login', [])->assertStatus(400);

        config(['sync_sus.require_https' => false, 'session.driver' => 'database', 'sync_sus.max_concurrent_sessions' => 2]);
        $unit = $this->createHealthUnit();
        $user = $this->createUserWithUnit($unit, ['must_change_password' => false]);
        foreach ([100, 200, 300] as $lastActivity) {
            DB::table('sessions')->insert([
                'id' => Str::random(40),
                'user_id' => $user->getKey(),
                'ip_address' => '127.0.0.1',
                'user_agent' => 'Test',
                'payload' => 'test',
                'last_activity' => $lastActivity,
            ]);
        }
        app(LimitConcurrentSessions::class)->enforce($user);
        $this->assertDatabaseCount('sessions', 1);
        $this->assertDatabaseHas('sessions', ['user_id' => $user->getKey(), 'last_activity' => 300]);
    }

    public function test_local_environment_allows_only_loopback_vite_and_hmr_sources(): void
    {
        $this->app->detectEnvironment(fn (): string => 'local');
        config(['sync_sus.vite_dev_server_origins' => [
            'http://localhost:5173',
            'http://127.0.0.1:5173',
            'http://[::1]:5173',
            'https://untrusted.example',
            'http://localhost:5173; script-src *',
        ]]);

        $response = $this->get(route('health.live'))->assertOk();
        $policy = (string) $response->headers->get('content-security-policy');

        $this->assertStringContainsString('http://localhost:5173', $policy);
        $this->assertStringContainsString('http://127.0.0.1:5173', $policy);
        $this->assertStringContainsString('http://[::1]:5173', $policy);
        $this->assertStringContainsString('ws://localhost:5173', $policy);
        $this->assertStringContainsString('ws://127.0.0.1:5173', $policy);
        $this->assertStringContainsString('ws://[::1]:5173', $policy);
        $this->assertStringNotContainsString('untrusted.example', $policy);
        $this->assertStringNotContainsString('script-src *', $policy);
    }

    public function test_backup_set_integrity_is_verified_recorded_and_rejects_tampering(): void
    {
        $root = storage_path('framework/testing-backups-'.Str::lower((string) Str::ulid()));
        $set = $root.DIRECTORY_SEPARATOR.'20260724T120000Z';
        File::ensureDirectoryExists($set);
        config(['sync_sus.backup_path' => $root]);

        try {
            file_put_contents($set.DIRECTORY_SEPARATOR.'database.sql.gz', gzencode("-- synthetic database dump\n", 9));
            file_put_contents($set.DIRECTORY_SEPARATOR.'private-files.tar.gz', gzencode("synthetic private archive\n", 9));
            $databaseHash = hash_file('sha256', $set.DIRECTORY_SEPARATOR.'database.sql.gz');
            $filesHash = hash_file('sha256', $set.DIRECTORY_SEPARATOR.'private-files.tar.gz');
            file_put_contents(
                $set.DIRECTORY_SEPARATOR.'SHA256SUMS',
                "{$databaseHash}  database.sql.gz\n{$filesHash}  private-files.tar.gz\n",
            );

            $verification = app(BackupSetVerifier::class)->verify($set);
            $this->assertSame('completed', $verification->status);
            $this->assertTrue($verification->checks['hashes']);
            $this->assertFalse($verification->checks['encrypted']);

            file_put_contents($set.DIRECTORY_SEPARATOR.'database.sql.gz', 'tampered');
            try {
                app(BackupSetVerifier::class)->verify($set);
                $this->fail('A adulteração do backup deveria ser rejeitada.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('integridade', $exception->getMessage());
            }
            $this->assertDatabaseHas('backup_verifications', ['backup_set' => '20260724T120000Z', 'status' => 'failed']);
        } finally {
            File::deleteDirectory($root);
        }
    }

    public function test_only_administrator_can_view_operational_continuity(): void
    {
        $unit = $this->createHealthUnit();
        $this->seed(RolePermissionSeeder::class);
        $administrator = $this->createPlatformAdministrator();
        $administrator->assignRole('administrator');
        $auditor = $this->createUserWithUnit($unit, ['must_change_password' => false]);
        $auditor->assignRole('auditor');
        $session = ['active_health_unit_id' => $unit->getKey()];

        $this->actingAs($administrator)->withSession($session)
            ->get(route('administration.operations'))
            ->assertOk()
            ->assertSee('Operação e continuidade');
        $this->actingAs($auditor)->withSession($session)
            ->get(route('administration.operations'))
            ->assertForbidden();
    }
}
