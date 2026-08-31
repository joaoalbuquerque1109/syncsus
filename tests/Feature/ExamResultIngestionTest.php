<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Administration\Infrastructure\Eloquent\ArrivalMethod;
use App\Modules\Administration\Infrastructure\Eloquent\EntryType;
use App\Modules\Laboratory\Application\Actions\DispatchReceivedLaboratoryResultsAction;
use App\Modules\Laboratory\Application\Actions\ProcessSynclabExamResultAction;
use App\Modules\Laboratory\Application\Actions\RotateSynclabResultTokenAction;
use App\Modules\Laboratory\Application\Jobs\ProcessSynclabExamResultJob;
use App\Modules\Laboratory\Infrastructure\Eloquent\LaboratoryIntegration;
use App\Modules\Laboratory\Infrastructure\Eloquent\LaboratoryOrderTransmission;
use App\Modules\Laboratory\Infrastructure\Eloquent\LaboratoryResultIngestion;
use App\Modules\Medical\Infrastructure\Eloquent\ExamOrder;
use App\Modules\Medical\Infrastructure\Eloquent\ExamOrderItem;
use App\Modules\Patients\Domain\Enums\PatientSex;
use App\Modules\Patients\Domain\Enums\PatientStatus;
use App\Modules\Patients\Infrastructure\Eloquent\Patient;
use App\Modules\Reception\Infrastructure\Eloquent\Encounter;
use Database\Seeders\OperationalCatalogSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Concerns\RefreshCoreAndTenantDatabase;
use Tests\TestCase;

final class ExamResultIngestionTest extends TestCase
{
    use RefreshCoreAndTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('sync_sus.synclab.results_enabled', true);
    }

    public function test_authenticated_webhook_queues_and_applies_result_to_the_persisted_tenant(): void
    {
        Queue::fake();
        [$integration, $transmission, $item, $token] = $this->resultContext('RESULT-LOCAL');
        $foreignUnit = $this->createHealthUnit('RESULT-FOREIGN');
        $payload = [
            ...$this->payload($transmission),
            'codigo_exame' => 127,
            'organization_id' => $foreignUnit->organization_id,
            'health_unit_id' => $foreignUnit->getKey(),
        ];

        $this->withHeader('X-Synclab-Result-Token', $token)
            ->postJson('/api/v1/laboratory/synclab/results', $payload)
            ->assertAccepted()
            ->assertJsonPath('status', 'received');

        $ingestion = LaboratoryResultIngestion::query()->sole();
        $this->assertSame($integration->getKey(), $ingestion->laboratory_integration_id);
        $this->assertStringNotContainsString(
            'Hemoglobina 13,2 g/dL',
            (string) $ingestion->getRawOriginal('payload'),
        );
        $this->assertDatabaseCount('exam_results', 0);
        Queue::assertPushed(ProcessSynclabExamResultJob::class);

        app(ProcessSynclabExamResultAction::class)->execute((int) $ingestion->getKey());

        $result = $item->result()->sole();
        $this->assertSame('synclab', $result->source);
        $this->assertNull($result->recorded_by);
        $this->assertSame('Hemoglobina 13,2 g/dL', $result->result_text);
        $this->assertSame($ingestion->getKey(), $result->laboratory_result_ingestion_id);
        $this->assertSame('resulted', $item->fresh()?->status);
        $this->assertSame('applied', $ingestion->fresh()?->status->value);
        $this->assertSame($transmission->health_unit_id, $result->item->order->health_unit_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'laboratory.result_received']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'laboratory.result_applied']);
    }

    public function test_replayed_payload_is_idempotent_and_does_not_create_another_result(): void
    {
        Queue::fake();
        [, $transmission, , $token] = $this->resultContext('RESULT-DUPLICATE');
        $payload = $this->payload($transmission);

        $this->withHeader('X-Synclab-Result-Token', $token)
            ->postJson('/api/v1/laboratory/synclab/results', $payload)
            ->assertAccepted();
        $ingestion = LaboratoryResultIngestion::query()->sole();
        app(ProcessSynclabExamResultAction::class)->execute((int) $ingestion->getKey());

        $this->withHeader('X-Synclab-Result-Token', $token)
            ->postJson('/api/v1/laboratory/synclab/results', $payload)
            ->assertAccepted()
            ->assertJsonPath('status', 'duplicate');

        $this->assertDatabaseCount('laboratory_result_ingestions', 1);
        $this->assertDatabaseCount('exam_results', 1);
        $this->assertSame(1, $ingestion->fresh()?->duplicate_count);

        $this->withHeader('X-Synclab-Result-Token', $token)
            ->postJson('/api/v1/laboratory/synclab/results', [
                ...$payload,
                'resultado' => 'Conteúdo alterado sob a mesma referência',
            ])
            ->assertStatus(409)
            ->assertJsonPath('status', 'conflict');

        $conflict = LaboratoryResultIngestion::query()->latest('id')->firstOrFail();
        $this->assertSame('manual_review', $conflict->status->value);
        $this->assertSame('idempotency_conflict', $conflict->error_code);
        $this->assertDatabaseCount('laboratory_result_ingestions', 2);
        $this->assertDatabaseCount('exam_results', 1);
    }

    public function test_signature_is_required_and_verified_when_enabled(): void
    {
        Queue::fake();
        config()->set('sync_sus.synclab.require_result_signature', true);
        [, $transmission, , $token] = $this->resultContext('RESULT-SIGNATURE');
        $payload = $this->payload($transmission);
        $body = json_encode($payload);
        $validSignature = hash_hmac('sha256', (string) $body, $token);

        $this->withHeader('X-Synclab-Result-Token', $token)
            ->postJson('/api/v1/laboratory/synclab/results', $payload)
            ->assertUnauthorized();
        $this->withHeaders([
            'X-Synclab-Result-Token' => $token,
            'X-Synclab-Result-Signature' => 'wrong-signature',
        ])->postJson('/api/v1/laboratory/synclab/results', $payload)
            ->assertUnauthorized();
        $this->assertDatabaseCount('laboratory_result_ingestions', 0);

        $this->withHeaders([
            'X-Synclab-Result-Token' => $token,
            'X-Synclab-Result-Signature' => $validSignature,
        ])->postJson('/api/v1/laboratory/synclab/results', $payload)
            ->assertAccepted();
        $this->assertDatabaseCount('laboratory_result_ingestions', 1);
    }

    public function test_invalid_authentication_and_disabled_reception_are_rejected(): void
    {
        [, $transmission, , $token] = $this->resultContext('RESULT-AUTH');

        $this->postJson('/api/v1/laboratory/synclab/results', $this->payload($transmission))
            ->assertUnauthorized();
        $this->withHeader('X-Synclab-Result-Token', 'invalid-token')
            ->postJson('/api/v1/laboratory/synclab/results', $this->payload($transmission))
            ->assertUnauthorized();

        config()->set('sync_sus.synclab.results_enabled', false);
        $this->withHeader('X-Synclab-Result-Token', $token)
            ->postJson('/api/v1/laboratory/synclab/results', $this->payload($transmission))
            ->assertForbidden();
        $this->assertDatabaseCount('laboratory_result_ingestions', 0);
    }

    public function test_valid_token_is_rejected_when_result_reception_is_disabled_for_the_integration(): void
    {
        [$integration, $transmission, , $token] = $this->resultContext('RESULT-INTEGRATION-FLAG');
        $integration->update(['result_sync_enabled' => false]);

        $this->withHeader('X-Synclab-Result-Token', $token)
            ->postJson('/api/v1/laboratory/synclab/results', $this->payload($transmission))
            ->assertForbidden();

        $this->assertTrue((bool) config('sync_sus.synclab.results_enabled'));
        $this->assertDatabaseCount('laboratory_result_ingestions', 0);
    }

    public function test_malformed_payload_is_preserved_as_rejected_without_queueing(): void
    {
        Queue::fake();
        [, , , $token] = $this->resultContext('RESULT-INVALID');

        $this->withHeader('X-Synclab-Result-Token', $token)
            ->postJson('/api/v1/laboratory/synclab/results', ['codigo_pedido' => 'UNKNOWN'])
            ->assertUnprocessable()
            ->assertJsonPath('status', 'rejected');

        $ingestion = LaboratoryResultIngestion::query()->sole();
        $this->assertSame('rejected', $ingestion->status->value);
        $this->assertSame('invalid_payload', $ingestion->error_code);
        $this->assertNotEmpty($ingestion->validation_errors);
        Queue::assertNothingPushed();
        $this->assertDatabaseHas('audit_logs', ['action' => 'laboratory.result_rejected']);
    }

    public function test_token_from_one_unit_cannot_resolve_another_units_order(): void
    {
        Queue::fake();
        [, $transmissionA, , $tokenA] = $this->resultContext('RESULT-UNIT-A');
        [, $transmissionB, $itemB] = $this->resultContext('RESULT-UNIT-B');
        $collidingExternalNumber = (string) $transmissionA->external_order_number;
        $transmissionA->forceFill(['external_order_number' => 'UNIT-A-'.$collidingExternalNumber])->save();
        $transmissionB->forceFill(['external_order_number' => $collidingExternalNumber])->save();

        $this->withHeader('X-Synclab-Result-Token', $tokenA)
            ->postJson('/api/v1/laboratory/synclab/results', $this->payload($transmissionB))
            ->assertAccepted();
        $ingestion = LaboratoryResultIngestion::query()->sole();
        app(ProcessSynclabExamResultAction::class)->execute((int) $ingestion->getKey());

        $this->assertSame('manual_review', $ingestion->fresh()?->status->value);
        $this->assertSame('order_not_found', $ingestion->fresh()?->error_code);
        $this->assertNull($ingestion->fresh()?->laboratory_order_transmission_id);
        $this->assertNull($itemB->result()->first());
        $this->assertDatabaseCount('exam_results', 0);
        $this->assertDatabaseHas('audit_logs', ['action' => 'laboratory.result_manual_review']);
    }

    public function test_unknown_exam_and_conflicting_existing_result_are_never_applied_silently(): void
    {
        Queue::fake();
        [, $transmission, $item, $token] = $this->resultContext('RESULT-REVIEW');
        $unknownPayload = [...$this->payload($transmission), 'codigo_exame' => 'UNKNOWN'];

        $this->withHeader('X-Synclab-Result-Token', $token)
            ->postJson('/api/v1/laboratory/synclab/results', $unknownPayload)
            ->assertAccepted();
        $unknown = LaboratoryResultIngestion::query()->sole();
        app(ProcessSynclabExamResultAction::class)->execute((int) $unknown->getKey());
        $this->assertSame('item_not_found', $unknown->fresh()?->error_code);

        $firstPayload = $this->payload($transmission, 'RESULT-REF-1');
        $this->withHeader('X-Synclab-Result-Token', $token)
            ->postJson('/api/v1/laboratory/synclab/results', $firstPayload)
            ->assertAccepted();
        $first = LaboratoryResultIngestion::query()->latest('id')->firstOrFail();
        app(ProcessSynclabExamResultAction::class)->execute((int) $first->getKey());

        $conflictingPayload = [
            ...$this->payload($transmission, 'RESULT-REF-2'),
            'resultado' => 'Resultado divergente',
        ];
        $this->withHeader('X-Synclab-Result-Token', $token)
            ->postJson('/api/v1/laboratory/synclab/results', $conflictingPayload)
            ->assertAccepted();
        $conflict = LaboratoryResultIngestion::query()->latest('id')->firstOrFail();
        app(ProcessSynclabExamResultAction::class)->execute((int) $conflict->getKey());

        $this->assertSame('result_conflict', $conflict->fresh()?->error_code);
        $this->assertSame('Hemoglobina 13,2 g/dL', $item->result()->sole()->result_text);
        $this->assertDatabaseCount('exam_results', 1);
    }

    public function test_received_ingestion_is_requeued_for_recovery_after_worker_interruption(): void
    {
        Queue::fake();
        [, $transmission, , $token] = $this->resultContext('RESULT-RECOVERY');
        $this->withHeader('X-Synclab-Result-Token', $token)
            ->postJson('/api/v1/laboratory/synclab/results', $this->payload($transmission))
            ->assertAccepted();
        Queue::fake();
        LaboratoryResultIngestion::query()->sole()->update([
            'received_at' => now()->subMinutes(2),
            'error_code' => 'processing_deferred',
        ]);

        $dispatched = app(DispatchReceivedLaboratoryResultsAction::class)->execute();

        $this->assertSame(1, $dispatched);
        Queue::assertPushed(ProcessSynclabExamResultJob::class);
        $this->assertSame('received', LaboratoryResultIngestion::query()->sole()->status->value);
    }

    public function test_exhausted_processing_attempts_move_ingestion_to_manual_review_and_stop_redispatch(): void
    {
        Queue::fake();
        [, $transmission, , $token] = $this->resultContext('RESULT-TERMINAL-FAILURE');
        $this->withHeader('X-Synclab-Result-Token', $token)
            ->postJson('/api/v1/laboratory/synclab/results', $this->payload($transmission))
            ->assertAccepted();
        $ingestion = LaboratoryResultIngestion::query()->sole();

        (new ProcessSynclabExamResultJob((int) $ingestion->getKey()))
            ->failed(new RuntimeException('Falha determinística de processamento.'));

        $ingestion->refresh();
        $this->assertSame('manual_review', $ingestion->status->value);
        $this->assertSame('processing_deferred', $ingestion->error_code);
        $this->assertStringContainsString('Falha determinística', (string) $ingestion->last_error);
        Queue::fake();
        $this->assertSame(0, app(DispatchReceivedLaboratoryResultsAction::class)->execute());
        Queue::assertNothingPushed();
    }

    public function test_multipart_result_with_pdf_attachment_is_stored_and_linked_to_the_final_result(): void
    {
        Queue::fake();
        Storage::fake('local_private');
        [, $transmission, $item, $token] = $this->resultContext('RESULT-PDF');
        $file = UploadedFile::fake()->create('laudo.pdf', 500);

        $this->withHeader('X-Synclab-Result-Token', $token)
            ->post('/api/v1/laboratory/synclab/results', [
                ...$this->payload($transmission),
                'resultado_anexo' => $file,
            ])
            ->assertAccepted()
            ->assertJsonPath('status', 'received');

        $ingestion = LaboratoryResultIngestion::query()->sole();
        $this->assertSame('local_private', $ingestion->result_pdf_disk);
        $this->assertNotNull($ingestion->result_pdf_path);
        $this->assertSame(64, strlen((string) $ingestion->result_pdf_hash));
        Storage::disk('local_private')->assertExists((string) $ingestion->result_pdf_path);

        app(ProcessSynclabExamResultAction::class)->execute((int) $ingestion->getKey());

        $result = $item->result()->sole();
        $this->assertSame($ingestion->result_pdf_path, $result->result_pdf_path);
        $this->assertSame($ingestion->result_pdf_hash, $result->result_pdf_hash);
        $this->assertSame('laudo.pdf', $result->result_pdf_original_filename);
    }

    public function test_non_pdf_attachment_is_rejected_without_being_stored(): void
    {
        Queue::fake();
        Storage::fake('local_private');
        [, $transmission, , $token] = $this->resultContext('RESULT-BADFILE');
        $file = UploadedFile::fake()->create('laudo.txt', 10);

        $this->withHeader('X-Synclab-Result-Token', $token)
            ->post('/api/v1/laboratory/synclab/results', [
                ...$this->payload($transmission),
                'resultado_anexo' => $file,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('status', 'rejected');

        $ingestion = LaboratoryResultIngestion::query()->sole();
        $this->assertSame('rejected', $ingestion->status->value);
        $this->assertNull($ingestion->result_pdf_path);
        Storage::disk('local_private')->assertDirectoryEmpty('laboratory-results');
    }

    public function test_resend_with_same_reference_but_a_different_pdf_is_flagged_for_manual_review(): void
    {
        Queue::fake();
        Storage::fake('local_private');
        [, $transmission, , $token] = $this->resultContext('RESULT-PDF-CORRECTION');
        $payload = $this->payload($transmission, 'RESULT-PDF-REF');

        $this->withHeader('X-Synclab-Result-Token', $token)
            ->post('/api/v1/laboratory/synclab/results', [
                ...$payload,
                'resultado_anexo' => UploadedFile::fake()->createWithContent('laudo-v1.pdf', str_repeat('A', 1000)),
            ])
            ->assertAccepted();

        $this->withHeader('X-Synclab-Result-Token', $token)
            ->post('/api/v1/laboratory/synclab/results', [
                ...$payload,
                'resultado_anexo' => UploadedFile::fake()->createWithContent('laudo-v2.pdf', str_repeat('B', 1000)),
            ])
            ->assertStatus(409)
            ->assertJsonPath('status', 'conflict');

        $conflict = LaboratoryResultIngestion::query()->latest('id')->firstOrFail();
        $this->assertSame('manual_review', $conflict->status->value);
        $this->assertSame('idempotency_conflict', $conflict->error_code);
    }

    /** @return array{LaboratoryIntegration, LaboratoryOrderTransmission, ExamOrderItem, string} */
    private function resultContext(string $code): array
    {
        $unit = $this->createHealthUnit($code);
        $this->seed(OperationalCatalogSeeder::class);
        $user = $this->createUserWithUnit($unit);
        $patient = Patient::query()->create([
            'organization_id' => $unit->organization_id,
            'medical_record_number' => 'P-'.$code,
            'full_name' => 'Paciente '.$code,
            'normalized_name' => 'PACIENTE '.$code,
            'birth_date' => '1985-01-23',
            'sex' => PatientSex::Female,
            'status' => PatientStatus::Active,
            'created_by' => $user->getKey(),
            'updated_by' => $user->getKey(),
        ]);
        $encounter = Encounter::query()->create([
            'encounter_number' => $code,
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
            'is_active' => true,
            'result_sync_enabled' => true,
        ]);
        $exam = $integration->exams()->create([
            'external_code' => '127',
            'name' => 'Hemograma completo',
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
            'clinical_indication' => 'Investigação laboratorial.',
            'requested_at' => now(),
        ]);
        $item = $order->items()->create([
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
            'external_order_number' => (string) $order->getKey(),
            'idempotency_key' => 'result-test:'.$integration->getKey().':'.$order->public_id,
            'status' => 'accepted',
            'accepted_at' => now(),
        ]);
        $token = app(RotateSynclabResultTokenAction::class)->execute($integration);

        return [$integration, $transmission, $item, $token];
    }

    /** @return array<string, string> */
    private function payload(
        LaboratoryOrderTransmission $transmission,
        string $reference = 'RESULT-REF-001',
    ): array {
        return [
            'codigo_pedido' => (string) $transmission->external_order_number,
            'codigo_exame' => '127',
            'resultado' => 'Hemoglobina 13,2 g/dL',
            'conclusao' => 'Dentro dos valores de referência.',
            'observacoes' => 'Resultado final.',
            'data_resultado' => now()->toIso8601String(),
            'referencia_resultado' => $reference,
        ];
    }
}
