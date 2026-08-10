<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Administration\Infrastructure\Eloquent\ArrivalMethod;
use App\Modules\Administration\Infrastructure\Eloquent\EntryType;
use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Laboratory\Application\Actions\DispatchPendingLaboratoryTransmissionsAction;
use App\Modules\Laboratory\Application\Actions\SubmitLaboratoryOrderTransmissionAction;
use App\Modules\Laboratory\Application\Jobs\SubmitLaboratoryOrderJob;
use App\Modules\Laboratory\Infrastructure\Eloquent\LaboratoryIntegration;
use App\Modules\Laboratory\Infrastructure\Eloquent\LaboratoryOrderTransmission;
use App\Modules\Medical\Infrastructure\Eloquent\ExamOrder;
use App\Modules\Patients\Domain\Enums\PatientIdentifierType;
use App\Modules\Patients\Domain\Enums\PatientSex;
use App\Modules\Patients\Domain\Enums\PatientStatus;
use App\Modules\Patients\Infrastructure\Eloquent\Patient;
use App\Modules\Reception\Infrastructure\Eloquent\Encounter;
use Database\Seeders\OperationalCatalogSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Concerns\RefreshCoreAndTenantDatabase;
use Tests\TestCase;

final class SynclabOrderSubmissionTest extends TestCase
{
    use RefreshCoreAndTenantDatabase;

    public function test_http_200_accepts_order_using_database_id_as_service_order(): void
    {
        [$order, $transmission] = $this->outboundOrder();
        Http::fake(['*' => Http::response(['message' => 'ok'], 200)]);

        app(SubmitLaboratoryOrderTransmissionAction::class)->execute((int) $transmission->getKey());

        $transmission->refresh();
        $this->assertSame('accepted', $transmission->statusEnum()->value);
        $this->assertSame((string) $order->getKey(), $transmission->external_order_number);
        $this->assertSame(1, $transmission->attempt_count);
        $this->assertSame(200, $transmission->last_http_status);
        $this->assertNotNull($transmission->accepted_at);
        $this->assertIsArray($transmission->request_payload);
        $this->assertStringNotContainsString(
            'Paciente Teste Synclab',
            (string) $transmission->getRawOriginal('request_payload'),
        );
        $this->assertDatabaseHas('laboratory_transmission_attempts', [
            'laboratory_order_transmission_id' => $transmission->getKey(),
            'attempt_number' => 1,
            'status' => 'accepted',
            'http_status' => 200,
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'laboratory.transmission_accepted']);

        Http::assertSent(function (Request $request) use ($order): bool {
            $payload = $request->data();
            $exam = data_get($payload, 'pedido_lab.exames.0', []);

            return $request->url() === 'https://synclabweb.unisync.com.br/app/addrequisicao/6612547'
                && $request->hasHeader('Authorization')
                && data_get($payload, 'pedido_lab.ordem_servico') === (string) $order->getKey()
                && data_get($payload, 'pedido_lab.usuario_web_id') === (string) $order->created_by
                && data_get($payload, 'pedido_lab.pedido.profissional') === 'Dra. Solicitante'
                && data_get($payload, 'pedido_lab.pedido.cnesUnidadeExecutante') === 6612547
                && data_get($payload, 'pedido_lab.paciente.codigo') === (string) $order->encounter->patient_id
                && data_get($payload, 'pedido_lab.paciente.cpf') === '52998224725'
                && data_get($payload, 'pedido_lab.exames.0.codigo') === 127
                && ($exam['amostras'] ?? null) === []
                && ! array_key_exists('cbarra', $exam)
                && ($exam['itens'] ?? null) === []
                && ! array_key_exists('sigla', $exam)
                && ! array_key_exists('resultado', $exam);
        });
    }

    public function test_reception_order_sends_receptionist_as_web_user_and_doctor_as_requester(): void
    {
        [$order, $transmission, $unit] = $this->outboundOrder();
        $receptionist = $this->createUserWithUnit($unit, ['name' => 'Recepcionista Teste']);
        $order->update(['created_by' => $receptionist->getKey()]);
        Http::fake(['*' => Http::response(['message' => 'ok'], 200)]);

        app(SubmitLaboratoryOrderTransmissionAction::class)->execute((int) $transmission->getKey());

        Http::assertSent(function (Request $request) use ($receptionist): bool {
            $payload = $request->data();

            return data_get($payload, 'pedido_lab.usuario_web_id') === (string) $receptionist->getKey()
                && data_get($payload, 'pedido_lab.pedido.profissional') === 'Dra. Solicitante';
        });
    }

    public function test_approved_transition_adds_ulids_and_preserves_legacy_identifiers(): void
    {
        config()->set('sync_sus.synclab.public_identifiers_enabled', true);
        [$order, $transmission] = $this->outboundOrder('PUBLIC-IDS');
        Http::fake(['*' => Http::response(['message' => 'ok'], 200)]);

        app(SubmitLaboratoryOrderTransmissionAction::class)->execute((int) $transmission->getKey());

        Http::assertSent(function (Request $request) use ($order): bool {
            $payload = $request->data();

            return data_get($payload, 'pedido_lab.ordem_servico') === (string) $order->getKey()
                && data_get($payload, 'pedido_lab.codigo_pedido') === (string) $order->getKey()
                && data_get($payload, 'pedido_lab.pedido.codigo') === (string) $order->getKey()
                && data_get($payload, 'pedido_lab.paciente.codigo') === (string) $order->encounter->patient_id
                && data_get($payload, 'pedido_lab.pedido.identificador_externo') === $order->public_id
                && data_get($payload, 'pedido_lab.paciente.identificador_externo') === $order->encounter->patient->public_id;
        });
    }

    public function test_only_http_200_is_success_and_server_failures_are_retried(): void
    {
        Http::fake(['*' => Http::sequence()->push([], 201)->push([], 503)]);
        [, $rejected] = $this->outboundOrder('CNS-201');
        app(SubmitLaboratoryOrderTransmissionAction::class)->execute((int) $rejected->getKey());
        $this->assertSame('rejected', $rejected->fresh()?->statusEnum()->value);

        [, $retrying] = $this->outboundOrder('CNS-503');

        try {
            app(SubmitLaboratoryOrderTransmissionAction::class)->execute((int) $retrying->getKey());
            $this->fail('A resposta HTTP 503 deveria disparar uma nova tentativa.');
        } catch (RuntimeException) {
            $this->assertSame('retrying', $retrying->fresh()->statusEnum()->value);
            $this->assertNotNull($retrying->fresh()->next_attempt_at);
        }
    }

    public function test_administrator_retries_a_rejected_order_but_not_an_accepted_order(): void
    {
        Queue::fake();
        $this->seed(RolePermissionSeeder::class);
        [$order, $transmission, $unit] = $this->outboundOrder('RETRY-TEST');
        $transmission->update(['status' => 'rejected', 'error_code' => 'http_400']);
        $administrator = $this->createPlatformAdministrator();
        $administrator->assignRole('administrator');

        $this->actingAs($administrator)
            ->withSession(['active_health_unit_id' => $unit->getKey()])
            ->post(route('laboratory.orders.retry', $order))
            ->assertRedirect(route('laboratory.orders.show', $order));

        $this->assertSame('pending', $transmission->fresh()->statusEnum()->value);
        $this->assertDatabaseHas('audit_logs', ['action' => 'laboratory.transmission_manual_retry']);
        Queue::assertPushed(SubmitLaboratoryOrderJob::class);

        $transmission->update(['status' => 'accepted']);
        $this->actingAs($administrator)
            ->withSession(['active_health_unit_id' => $unit->getKey()])
            ->post(route('laboratory.orders.retry', $order))
            ->assertSessionHasErrors('transmission');
    }

    public function test_enabling_integration_does_not_send_old_unreviewed_orders_in_bulk(): void
    {
        Queue::fake();
        [, $transmission] = $this->outboundOrder('OLD-ORDER');
        $transmission->update(['status' => 'awaiting_configuration']);

        $dispatched = app(DispatchPendingLaboratoryTransmissionsAction::class)->execute();

        $this->assertSame(0, $dispatched);
        $this->assertSame('awaiting_configuration', $transmission->fresh()->statusEnum()->value);
        Queue::assertNothingPushed();
    }

    public function test_retries_reuse_the_immutable_encrypted_payload_snapshot(): void
    {
        [$order, $transmission] = $this->outboundOrder('SNAPSHOT-503');
        Http::fake(['*' => Http::sequence()->push([], 503)->push(['message' => 'ok'], 200)]);

        try {
            app(SubmitLaboratoryOrderTransmissionAction::class)->execute((int) $transmission->getKey());
        } catch (RuntimeException) {
            // A falha temporaria e esperada para preparar a segunda tentativa.
        }

        User::query()->findOrFail($order->requested_by)
            ->update(['name' => 'Nome alterado depois da solicitacao']);
        config()->set('sync_sus.synclab.public_identifiers_enabled', true);
        app(SubmitLaboratoryOrderTransmissionAction::class)->execute((int) $transmission->getKey());

        $requests = Http::recorded();
        $this->assertCount(2, $requests);
        $this->assertSame($requests[0][0]->data(), $requests[1][0]->data());
        $this->assertSame('Dra. Solicitante', data_get($requests[1][0]->data(), 'pedido_lab.pedido.profissional'));
        $this->assertArrayNotHasKey('identificador_externo', $requests[1][0]->data()['pedido_lab']['pedido']);
        $this->assertArrayNotHasKey('identificador_externo', $requests[1][0]->data()['pedido_lab']['paciente']);
        $this->assertSame('accepted', $transmission->fresh()?->statusEnum()->value);
    }

    public function test_expired_sending_lease_requires_manual_review_instead_of_duplicating_delivery(): void
    {
        Queue::fake();
        [, $transmission] = $this->outboundOrder('STALE-SENDING');
        $transmission->update([
            'status' => 'sending',
            'attempt_count' => 1,
            'worker_token' => 'worker-interrompido',
            'sending_started_at' => now()->subMinutes(3),
            'lease_expires_at' => now()->subMinute(),
        ]);
        $transmission->attempts()->create([
            'attempt_number' => 1,
            'status' => 'sending',
            'started_at' => now()->subMinutes(3),
        ]);

        $dispatched = app(DispatchPendingLaboratoryTransmissionsAction::class)->execute();

        $this->assertSame(0, $dispatched);
        $this->assertSame('manual_review', $transmission->fresh()?->statusEnum()->value);
        $this->assertSame('sending_lease_expired', $transmission->fresh()?->error_code);
        $this->assertDatabaseHas('laboratory_transmission_attempts', [
            'laboratory_order_transmission_id' => $transmission->getKey(),
            'status' => 'manual_review',
            'error_code' => 'sending_lease_expired',
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'laboratory.transmission_lease_expired']);
        Queue::assertNothingPushed();
    }

    /** @return array{ExamOrder, LaboratoryOrderTransmission, HealthUnit, User} */
    private function outboundOrder(string $encounterNumber = 'SYNC-TEST-001'): array
    {
        config()->set('sync_sus.synclab.enabled', true);
        config()->set('sync_sus.synclab.connect_timeout_seconds', 2);
        config()->set('sync_sus.synclab.timeout_seconds', 5);

        $unitCode = match ($encounterNumber) {
            'SYNC-TEST-001' => 'CENTRAL',
            'CNS-201' => 'CENTRAL-201',
            default => 'CENTRAL-503',
        };
        $cnes = match ($encounterNumber) {
            'SYNC-TEST-001' => '6612547',
            'CNS-201' => '6612548',
            default => '6612549',
        };
        $unit = $this->createHealthUnit($unitCode);
        $unit->update(['cnes_code' => $cnes]);
        $unit->organization()->update(['cnes_code' => $cnes]);
        $this->seed(OperationalCatalogSeeder::class);
        $user = $this->createUserWithUnit($unit, ['name' => 'Dra. Solicitante']);
        $patient = Patient::query()->create([
            'organization_id' => $unit->organization_id,
            'medical_record_number' => 'P-'.$encounterNumber,
            'full_name' => 'Paciente Teste Synclab',
            'normalized_name' => 'PACIENTE TESTE SYNCLAB',
            'birth_date' => '1985-01-23',
            'sex' => PatientSex::Female,
            'status' => PatientStatus::Active,
            'created_by' => $user->getKey(),
            'updated_by' => $user->getKey(),
        ]);
        $patient->identifiers()->create([
            'type' => PatientIdentifierType::Cpf,
            'normalized_value' => match ($encounterNumber) {
                'SYNC-TEST-001' => '52998224725',
                'CNS-201' => '11144477735',
                default => '12345678909',
            },
            'display_value' => match ($encounterNumber) {
                'SYNC-TEST-001' => '529.982.247-25',
                'CNS-201' => '111.444.777-35',
                default => '123.456.789-09',
            },
            'is_primary' => true,
        ]);
        $encounter = Encounter::query()->create([
            'encounter_number' => $encounterNumber,
            'patient_id' => $patient->getKey(),
            'health_unit_id' => $unit->getKey(),
            'entry_type_id' => EntryType::query()->where('organization_id', $unit->organization_id)->valueOrFail('id'),
            'arrival_method_id' => ArrivalMethod::query()->where('organization_id', $unit->organization_id)->valueOrFail('id'),
            'current_status' => 'waiting_medical',
            'arrival_at' => now(),
            'registration_at' => now(),
            'created_by' => $user->getKey(),
        ]);
        $integration = LaboratoryIntegration::query()->create([
            'organization_id' => $unit->organization_id,
            'health_unit_id' => $unit->getKey(),
            'provider' => 'synclab',
            'base_url' => 'https://synclabweb.unisync.com.br',
            'username' => 'test-user',
            'password' => 'test-password',
            'settings' => ['agreement' => 'SUS'],
            'is_active' => true,
            'transmission_enabled' => true,
            'result_sync_enabled' => false,
        ]);
        $exam = $integration->exams()->create([
            'external_code' => '127',
            'acronym' => 'HEM',
            'name' => 'Hemograma completo',
            'sus_procedure_code' => '0202020380',
        ]);
        $order = ExamOrder::query()->create([
            'organization_id' => $unit->organization_id,
            'health_unit_id' => $unit->getKey(),
            'encounter_id' => $encounter->getKey(),
            'medical_consultation_id' => null,
            'requested_by' => $user->getKey(),
            'created_by' => $user->getKey(),
            'origin' => 'reception',
            'status' => 'pending',
            'priority' => 'routine',
            'clinical_indication' => 'Investigacao laboratorial.',
            'requested_at' => now(),
        ]);
        $order->items()->create([
            'laboratory_integration_id' => $integration->getKey(),
            'laboratory_exam_id' => $exam->getKey(),
            'external_exam_code' => '127',
            'exam_name' => 'Hemograma completo',
            'group' => 'laboratory',
            'priority' => 'routine',
            'status' => 'requested',
        ]);
        $transmission = LaboratoryOrderTransmission::query()->create([
            'organization_id' => $unit->organization_id,
            'health_unit_id' => $unit->getKey(),
            'laboratory_integration_id' => $integration->getKey(),
            'exam_order_id' => $order->getKey(),
            'idempotency_key' => 'synclab:'.$integration->getKey().':'.$order->public_id.':v1',
            'status' => 'pending',
        ]);

        return [$order, $transmission, $unit, $user];
    }
}
