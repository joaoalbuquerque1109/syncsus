<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Administration\Infrastructure\Eloquent\ArrivalMethod;
use App\Modules\Administration\Infrastructure\Eloquent\EntryType;
use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Administration\Infrastructure\Eloquent\RiskLevel;
use App\Modules\Documents\Application\Actions\CreateDocumentVersionAction;
use App\Modules\Documents\Application\Actions\GenerateSourceClinicalDocumentAction;
use App\Modules\Documents\Application\Actions\IssueClinicalDocumentAction;
use App\Modules\Documents\Application\Contracts\PdfRenderer;
use App\Modules\Documents\Domain\Enums\ClinicalDocumentType;
use App\Modules\Documents\Infrastructure\Eloquent\ClinicalDocument;
use App\Modules\Documents\Infrastructure\Eloquent\MedicalCertificate;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Medical\Domain\Enums\DestinationType;
use App\Modules\Medical\Domain\Enums\MedicalConsultationStatus;
use App\Modules\Medical\Infrastructure\Eloquent\DiagnosisCode;
use App\Modules\Medical\Infrastructure\Eloquent\EncounterDestination;
use App\Modules\Medical\Infrastructure\Eloquent\ExamOrder;
use App\Modules\Medical\Infrastructure\Eloquent\MedicalConsultation;
use App\Modules\Medical\Infrastructure\Eloquent\Prescription;
use App\Modules\Medical\Infrastructure\Eloquent\Referral;
use App\Modules\Patients\Domain\Enums\PatientSex;
use App\Modules\Patients\Domain\Enums\PatientStatus;
use App\Modules\Patients\Infrastructure\Eloquent\Patient;
use App\Modules\Queues\Domain\Enums\QueueEntryStatus;
use App\Modules\Queues\Infrastructure\Eloquent\Queue;
use App\Modules\Queues\Infrastructure\Eloquent\QueueEntry;
use App\Modules\Reception\Domain\Enums\AdministrativePriority;
use App\Modules\Reception\Domain\Enums\EncounterStatus;
use App\Modules\Reception\Infrastructure\Eloquent\Encounter;
use App\Modules\Reports\Application\Queries\OperationalDashboardQuery;
use Database\Seeders\OperationalCatalogSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\Concerns\RefreshCoreAndTenantDatabase;
use Tests\TestCase;

final class DocumentsAndReportsTest extends TestCase
{
    use RefreshCoreAndTenantDatabase;

    public function test_medical_certificate_uses_an_active_catalog_cid_and_ignores_free_text(): void
    {
        Storage::fake('local_private');
        [$unit, $doctor, , , $consultation] = $this->context();
        $session = ['active_health_unit_id' => $unit->getKey()];
        $cid = DiagnosisCode::query()->create([
            'code' => 'A00',
            'description' => 'Cólera',
            'is_active' => true,
        ]);

        $this->actingAs($doctor)->withSession($session)
            ->post(route('medical.medical-certificates.store', $consultation), [
                'starts_at' => now()->format('Y-m-d H:i:s'),
                'statement' => 'Paciente necessita afastamento temporário de suas atividades habituais.',
                'duration_value' => 2,
                'duration_unit' => 'days',
                'include_cid' => '1',
                'cid_code_id' => $cid->getKey(),
                'cid_authorization' => '1',
                'cid_text' => 'CONTEÚDO LIVRE NÃO CONFIÁVEL',
            ])
            ->assertRedirect();

        $certificate = MedicalCertificate::query()->sole();
        $document = ClinicalDocument::query()->with('currentVersion')->sole();
        $this->assertSame($document->getKey(), $certificate->document_id);
        $this->assertSame('Atestado médico', $document->title);
        $content = $document->currentVersion?->structured_content ?? [];
        $this->assertSame($cid->getKey(), $content['cid_code_id'] ?? null);
        $this->assertSame('A00', $content['cid_code'] ?? null);
        $this->assertSame('Cólera', $content['cid_description'] ?? null);
        $this->assertSame('A00 · Cólera', $content['cid_text'] ?? null);
        $this->assertTrue($content['cid_authorization'] ?? false);

        $this->actingAs($doctor)->withSession($session)
            ->get(route('medical.show', ['consultation' => $consultation, 'tab' => 'corrections']))
            ->assertOk()
            ->assertSee('delete_document_'.$document->public_id, false);

        $this->actingAs($doctor)->withSession($session)
            ->post(route('medical.medical-certificates.store', $consultation), [
                'starts_at' => now()->format('Y-m-d H:i:s'),
                'statement' => 'Paciente necessita afastamento temporário de suas atividades habituais.',
                'duration_value' => 1,
                'duration_unit' => 'days',
                'include_cid' => '1',
                'cid_code_id' => $cid->getKey(),
            ])
            ->assertSessionHasErrors('cid_authorization');
    }

    public function test_document_pdf_is_private_versioned_verifiable_and_voidable(): void
    {
        Storage::fake('local_private');
        [$unit, $doctor, , $receptionist, $consultation] = $this->context();
        $session = ['active_health_unit_id' => $unit->getKey()];

        $this->actingAs($doctor)->withSession($session)
            ->post(route('medical.documents', $consultation), [
                'document_type' => 'medical_report',
                'title' => 'Relatório clínico de alta',
                'body' => 'Paciente avaliado, tratado e liberado em condições clínicas estáveis.',
                'recipient_name' => 'Unidade de referência',
                'additional_information' => 'Manter acompanhamento na atenção primária.',
            ])
            ->assertRedirect();

        $document = ClinicalDocument::query()->with('currentVersion')->sole();
        $this->assertSame('Relatório médico', $document->title);
        $this->actingAs($doctor)->withSession($session)
            ->get(route('medical.show', ['consultation' => $consultation, 'tab' => 'corrections']))
            ->assertOk()
            ->assertSee('delete_document_'.$document->public_id, false);
        $firstVersion = $document->currentVersion;
        $this->assertNotNull($firstVersion);
        Storage::disk('local_private')->assertExists($firstVersion->rendered_html_path);
        Storage::disk('local_private')->assertExists($firstVersion->pdf_path);
        $firstPdf = Storage::disk('local_private')->get($firstVersion->pdf_path);
        $this->assertStringStartsWith('%PDF', $firstPdf);
        $this->assertSame(hash('sha256', $firstPdf), $firstVersion->file_hash);
        $this->assertDatabaseHas('audit_logs', ['action' => 'document.issued']);

        $this->get(route('documents.verify', $document->verification_code))
            ->assertOk()
            ->assertSee('Relatório médico')
            ->assertDontSee('Ana Beatriz dos Santos');

        $download = $this->actingAs($doctor)->withSession($session)
            ->get(route('documents.pdf', $document))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertHeader('cache-control', 'no-store, private');
        $this->assertStringStartsWith('%PDF', $download->getFile()->getContent());
        $this->assertDatabaseHas('audit_logs', ['action' => 'document.downloaded']);

        $this->actingAs($receptionist)->withSession($session)
            ->get(route('documents.pdf', $document))
            ->assertForbidden();

        $this->actingAs($doctor)->withSession($session)
            ->post(route('documents.versions', $document), [
                'reason' => 'Correção de orientação clínica',
                'body' => 'Paciente avaliado, tratado e liberado com retorno programado em sete dias.',
            ])
            ->assertRedirect();

        $document->refresh()->load(['currentVersion', 'versions']);
        $this->assertCount(2, $document->versions);
        $this->assertSame(2, $document->currentVersion?->version_number);
        $this->assertSame(
            'Unidade de referência',
            $document->currentVersion?->structured_content['recipient_name'] ?? null,
        );
        $this->assertNotSame($firstVersion->file_hash, $document->currentVersion?->file_hash);
        Storage::disk('local_private')->assertExists($firstVersion->pdf_path);

        $this->actingAs($doctor)->withSession($session)
            ->post(route('documents.void', $document), [
                'reason' => 'Documento substituído por registro institucional externo.',
                'confirmation' => '1',
            ])
            ->assertRedirect();

        $this->assertSame('voided', $document->fresh()?->status);
        $this->get(route('documents.verify', $document->verification_code))
            ->assertOk()
            ->assertSee('Documento anulado');
        $this->actingAs($doctor)->withSession($session)
            ->post(route('documents.versions', $document), [
                'reason' => 'Alteração posterior indevida',
                'body' => 'Este conteúdo não deve gerar uma nova versão válida.',
            ])
            ->assertSessionHasErrors('status');
        Storage::disk('local_private')->assertExists($firstVersion->pdf_path);
        Storage::disk('local_private')->assertExists($document->currentVersion?->pdf_path ?? '');
        $this->assertDatabaseHas('audit_logs', ['action' => 'document.version_created']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'document.voided']);
    }

    public function test_structured_records_generate_one_pdf_from_their_own_tabs(): void
    {
        Storage::fake('local_private');
        [$unit, $doctor, , , $consultation] = $this->context();
        $session = ['active_health_unit_id' => $unit->getKey()];
        $unbrokenClinicalText = str_repeat('TEXTOCLINICOSEMESPACO', 30);
        $prescription = Prescription::query()->create([
            'encounter_id' => $consultation->encounter_id,
            'medical_consultation_id' => $consultation->getKey(),
            'professional_id' => $doctor->getKey(),
            'prescription_type' => 'home',
            'status' => 'finalized',
            'general_instructions' => 'Usar após alimentação.',
            'version' => 1,
            'finalized_at' => now(),
        ]);
        $prescription->items()->create([
            'medication_name' => 'Paracetamol',
            'presentation' => 'Comprimido',
            'dose' => 500,
            'dose_unit' => 'mg',
            'route' => 'Oral',
            'frequency' => 'A cada 8 horas',
            'display_order' => 1,
        ]);
        $prescription->items()->create([
            'medication_name' => 'Dipirona',
            'presentation' => 'Comprimido',
            'dose' => 500,
            'dose_unit' => 'mg',
            'route' => 'Oral',
            'frequency' => 'A cada 6 horas',
            'display_order' => 2,
        ]);
        $order = ExamOrder::query()->create([
            'encounter_id' => $consultation->encounter_id,
            'medical_consultation_id' => $consultation->getKey(),
            'requested_by' => $doctor->getKey(),
            'priority' => 'routine',
            'clinical_indication' => 'Investigação de anemia persistente.',
            'requested_at' => now(),
        ]);
        $order->items()->create([
            'exam_name' => 'Hemograma completo',
            'group' => 'laboratory',
            'priority' => 'routine',
            'status' => 'requested',
        ]);
        $order->items()->create([
            'exam_name' => 'Ferritina',
            'group' => 'laboratory',
            'priority' => 'routine',
            'status' => 'requested',
        ]);
        $referral = Referral::query()->create([
            'encounter_id' => $consultation->encounter_id,
            'medical_consultation_id' => $consultation->getKey(),
            'requested_by' => $doctor->getKey(),
            'referral_type' => 'external',
            'destination' => 'Ambulatório de cardiologia',
            'reason' => $unbrokenClinicalText,
            'clinical_summary' => $unbrokenClinicalText,
            'priority' => 'routine',
            'status' => 'issued',
            'issued_at' => now(),
        ]);

        $this->actingAs($doctor)->withSession($session)
            ->post(route('medical.prescriptions.document', [$consultation, $prescription]))
            ->assertRedirect();
        $this->actingAs($doctor)->withSession($session)
            ->post(route('medical.prescriptions.document', [$consultation, $prescription]))
            ->assertRedirect();
        $this->actingAs($doctor)->withSession($session)
            ->post(route('medical.exam-orders.document', [$consultation, $order]))
            ->assertRedirect();
        $this->actingAs($doctor)->withSession($session)
            ->post(route('medical.referrals.document', [$consultation, $referral]))
            ->assertRedirect();

        $this->assertDatabaseCount('documents', 3);
        $this->assertNotNull($prescription->fresh()?->document_id);
        $this->assertNotNull($order->fresh()?->document_id);
        $this->assertNotNull($referral->fresh()?->document_id);
        $prescriptionDocument = ClinicalDocument::query()
            ->with('currentVersion')
            ->findOrFail($prescription->fresh()?->document_id);
        $this->assertSame(
            ['Paracetamol', 'Dipirona'],
            collect($prescriptionDocument->currentVersion?->structured_content['items'] ?? [])->pluck('medication_name')->all(),
        );
        $examDocument = ClinicalDocument::query()
            ->with('currentVersion')
            ->findOrFail($order->fresh()?->document_id);
        $this->assertSame(
            ['Hemograma completo', 'Ferritina'],
            collect($examDocument->currentVersion?->structured_content['items'] ?? [])->pluck('exam_name')->all(),
        );
        $referralDocument = ClinicalDocument::query()
            ->with('currentVersion')
            ->findOrFail($referral->fresh()?->document_id);
        $referralDocument->forceFill(['created_at' => now()->subYears(2)])->save();
        $referralHtml = Storage::disk('local_private')->get(
            $referralDocument->currentVersion?->rendered_html_path ?? '',
        );
        $this->assertStringContainsString($unbrokenClinicalText, $referralHtml);
        $this->assertStringContainsString('class="document-content safe-wrap"', $referralHtml);
        $this->assertStringContainsString('word-break: break-all', $referralHtml);
        $this->actingAs($doctor)->withSession($session)
            ->get(route('documents.show', $referralDocument))
            ->assertOk()
            ->assertSee('safe-wrap', false)
            ->assertSee($unbrokenClinicalText);
        $corrections = $this->actingAs($doctor)->withSession($session)
            ->get(route('medical.show', ['consultation' => $consultation, 'tab' => 'corrections']))
            ->assertOk();
        foreach ([$prescriptionDocument, $examDocument, $referralDocument] as $generatedDocument) {
            $corrections->assertSee('delete_document_'.$generatedDocument->public_id, false);
        }

        $otherDoctor = $this->createUserWithUnit($unit, ['must_change_password' => false]);
        $otherDoctor->assignRole('doctor');
        $this->registerDoctor($otherDoctor, $unit);
        $voidPayload = [
            'reason' => 'Documento emitido com informações clínicas incorretas.',
            'confirmation' => '1',
        ];
        $this->actingAs($otherDoctor)->withSession($session)
            ->post(route('documents.void', $referralDocument), $voidPayload)
            ->assertForbidden();
        $this->actingAs($doctor)->withSession($session)
            ->post(route('documents.void', $referralDocument), $voidPayload)
            ->assertRedirect();
        $this->assertDatabaseHas('documents', [
            'id' => $referralDocument->getKey(),
            'status' => 'voided',
            'voided_by' => $doctor->getKey(),
        ]);
        $this->assertDatabaseHas('referrals', [
            'id' => $referral->getKey(),
            'status' => 'issued',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'document.voided',
            'health_unit_id' => $unit->getKey(),
        ]);
        $this->get(route('documents.verify', $referralDocument->verification_code))
            ->assertOk()
            ->assertSee('Documento anulado');
        Storage::disk('local_private')->assertExists($referralDocument->currentVersion?->pdf_path ?? '');

        $administrator = $this->createPlatformAdministrator();
        $administrator->assignRole('administrator');
        $this->actingAs($administrator)->withSession($session)
            ->get(route('medical.show', ['consultation' => $consultation, 'tab' => 'corrections']))
            ->assertOk()
            ->assertSee('delete_document_'.$examDocument->public_id, false);
        $this->actingAs($administrator)->withSession($session)
            ->post(route('documents.void', $examDocument), [
                'reason' => 'Documento invalidado pelo administrador global.',
                'confirmation' => '1',
            ])
            ->assertRedirect();
        $this->assertDatabaseHas('documents', [
            'id' => $examDocument->getKey(),
            'status' => 'voided',
            'voided_by' => $administrator->getKey(),
        ]);
        ClinicalDocument::query()->with('currentVersion')->each(function (ClinicalDocument $document): void {
            Storage::disk('local_private')->assertExists($document->currentVersion?->pdf_path ?? '');
        });

        $this->actingAs($doctor)->withSession($session)
            ->post(route('medical.documents', $consultation), [
                'document_type' => 'prescription',
                'body' => 'Tentativa de duplicar receita pelo formulário genérico.',
            ])
            ->assertSessionHasErrors('document_type');
    }

    public function test_pdf_render_failure_occurs_without_transaction_and_leaves_no_persisted_state(): void
    {
        Storage::fake('local_private');
        [$unit, $doctor, , , $consultation] = $this->context();
        $initialAuditCount = DB::table('audit_logs')->count();
        $baselineTransactionLevel = DB::transactionLevel();
        $renderer = new class implements PdfRenderer
        {
            /** @var list<int> */
            public array $transactionLevels = [];

            public function render(string $html): string
            {
                $this->transactionLevels[] = DB::transactionLevel();

                throw new RuntimeException('Falha controlada ao renderizar PDF.');
            }
        };
        $this->app->instance(PdfRenderer::class, $renderer);
        $request = Request::create('/documents', 'POST');

        try {
            app(IssueClinicalDocumentAction::class)->executeStructured(
                $consultation,
                ClinicalDocumentType::MedicalReport,
                ['body' => 'Conteúdo que não deve ser persistido.'],
                $doctor,
                $unit,
                $request,
            );
            $this->fail('A falha controlada do renderizador deveria ter sido propagada.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Falha controlada ao renderizar PDF.', $exception->getMessage());
        }

        $this->assertSame([$baselineTransactionLevel], $renderer->transactionLevels);
        $this->assertDatabaseCount('documents', 0);
        $this->assertDatabaseCount('document_versions', 0);
        $this->assertSame($initialAuditCount, DB::table('audit_logs')->count());
        $this->assertSame([], Storage::disk('local_private')->allFiles());
    }

    public function test_concurrent_document_version_is_rejected_without_overwriting_the_winner(): void
    {
        Storage::fake('local_private');
        [$unit, $doctor, , , $consultation] = $this->context();
        $request = Request::create('/documents', 'POST');
        $document = app(IssueClinicalDocumentAction::class)->executeStructured(
            $consultation,
            ClinicalDocumentType::MedicalReport,
            ['body' => 'Versão inicial.'],
            $doctor,
            $unit,
            $request,
        );
        $baselineTransactionLevel = DB::transactionLevel();
        $winnerPdf = '%PDF-versao-concorrente';
        $renderer = new class($document, $doctor, $winnerPdf) implements PdfRenderer
        {
            public int $transactionLevel = -1;

            public function __construct(
                private readonly ClinicalDocument $document,
                private readonly User $user,
                private readonly string $winnerPdf,
            ) {}

            public function render(string $html): string
            {
                $this->transactionLevel = DB::transactionLevel();
                $directory = "documents/{$this->document->healthUnit->public_id}/{$this->document->public_id}";
                $htmlPath = "{$directory}/v2.html";
                $pdfPath = "{$directory}/v2.pdf";
                Storage::disk('local_private')->put($htmlPath, 'HTML DO VENCEDOR');
                Storage::disk('local_private')->put($pdfPath, $this->winnerPdf);
                $winner = $this->document->versions()->create([
                    'version_number' => 2,
                    'structured_content' => ['body' => 'Versão concorrente vencedora.'],
                    'rendered_html_path' => $htmlPath,
                    'pdf_path' => $pdfPath,
                    'file_hash' => hash('sha256', $this->winnerPdf),
                    'size_bytes' => strlen($this->winnerPdf),
                    'created_by' => $this->user->getKey(),
                    'reason' => 'Processo concorrente',
                ]);
                $this->document->update(['current_version_id' => $winner->getKey()]);

                return '%PDF-versao-perdedora';
            }
        };
        $this->app->instance(PdfRenderer::class, $renderer);

        try {
            app(CreateDocumentVersionAction::class)->execute(
                $document,
                ['body' => 'Versão que perdeu a corrida.', 'reason' => 'Correção concorrente'],
                $doctor,
                $unit,
                $request,
            );
            $this->fail('A versão com estado otimista desatualizado deveria ser rejeitada.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('version', $exception->errors());
        }

        $document->refresh()->load(['currentVersion', 'versions']);
        $this->assertSame($baselineTransactionLevel, $renderer->transactionLevel);
        $this->assertCount(2, $document->versions);
        $currentVersion = $document->currentVersion;
        $this->assertNotNull($currentVersion);
        $structuredContent = $currentVersion->structured_content;
        $this->assertIsArray($structuredContent);
        $this->assertSame('Versão concorrente vencedora.', $structuredContent['body'] ?? null);
        $this->assertSame(
            $winnerPdf,
            Storage::disk('local_private')->get($currentVersion->pdf_path),
        );
    }

    public function test_concurrent_source_document_generation_returns_the_winner_without_duplicate(): void
    {
        Storage::fake('local_private');
        [$unit, $doctor, , , $consultation] = $this->context();
        $prescription = Prescription::query()->create([
            'encounter_id' => $consultation->encounter_id,
            'medical_consultation_id' => $consultation->getKey(),
            'professional_id' => $doctor->getKey(),
            'prescription_type' => 'home',
            'status' => 'finalized',
            'general_instructions' => 'Usar conforme orientação.',
            'version' => 1,
            'finalized_at' => now(),
        ]);
        $prescription->items()->create([
            'medication_name' => 'Paracetamol',
            'presentation' => 'Comprimido',
            'dose' => 500,
            'dose_unit' => 'mg',
            'route' => 'Oral',
            'frequency' => 'A cada 8 horas',
            'display_order' => 1,
        ]);
        $request = Request::create('/documents/source', 'POST');
        $baselineTransactionLevel = DB::transactionLevel();
        $renderer = new class($consultation, $prescription, $doctor, $unit, $request) implements PdfRenderer
        {
            /** @var list<int> */
            public array $transactionLevels = [];

            public ?ClinicalDocument $winner = null;

            private bool $competing = false;

            public function __construct(
                private readonly MedicalConsultation $consultation,
                private readonly Prescription $prescription,
                private readonly User $user,
                private readonly HealthUnit $unit,
                private readonly Request $request,
            ) {}

            public function render(string $html): string
            {
                $this->transactionLevels[] = DB::transactionLevel();
                if (! $this->competing) {
                    $this->competing = true;
                    $this->winner = app(GenerateSourceClinicalDocumentAction::class)->prescription(
                        $this->consultation,
                        $this->prescription,
                        $this->user,
                        $this->unit,
                        $this->request,
                    );
                }

                return '%PDF-documento-estruturado';
            }
        };
        $this->app->instance(PdfRenderer::class, $renderer);

        $result = app(GenerateSourceClinicalDocumentAction::class)->prescription(
            $consultation,
            $prescription,
            $doctor,
            $unit,
            $request,
        );

        $this->assertSame(
            [$baselineTransactionLevel, $baselineTransactionLevel],
            $renderer->transactionLevels,
        );
        $winner = $renderer->winner;
        $this->assertInstanceOf(ClinicalDocument::class, $winner);
        $this->assertSame($winner->getKey(), $result->getKey());
        $this->assertSame($result->getKey(), $prescription->fresh()?->document_id);
        $this->assertDatabaseCount('documents', 1);
        $this->assertDatabaseCount('document_versions', 1);
        $this->assertSame(2, count(Storage::disk('local_private')->allFiles()));
    }

    public function test_dashboard_and_reports_filter_mask_export_audit_and_isolate_units(): void
    {
        $this->travelTo(today()->addHours(12));
        [$unit, , $manager, $receptionist, , $encounter] = $this->context();
        $session = ['active_health_unit_id' => $unit->getKey()];
        $this->createActiveEncounter($unit, $manager);
        $otherUnit = $this->createHealthUnit('NORTH');
        $this->createExternalEncounter($otherUnit, $manager);

        $metrics = $this->actingAs($manager)->withSession($session)
            ->getJson(route('dashboard.metrics'))
            ->assertOk()
            ->assertHeader('cache-control', 'no-store, private')
            ->assertJsonPath('data.waiting_triage', 1)
            ->assertJsonPath('data.discharges_today', 1);
        $this->assertNotNull($metrics->json('data.server_time'));

        $this->actingAs($manager)->withSession($session)
            ->getJson(route('dashboard.active-encounters'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.patient', 'A*** B*** d*** S***')
            ->assertJsonPath('data.0.ticket', 'T101');

        $this->actingAs($manager)->withSession($session)
            ->getJson(route('dashboard.state'))
            ->assertOk()
            ->assertJsonPath('data.metrics.waiting_triage', 1)
            ->assertJsonCount(1, 'data.active_encounters')
            ->assertJsonPath('data.active_encounters.0.ticket', 'T101');

        $filters = [
            'date_from' => today()->subDay()->toDateString(),
            'date_to' => today()->toDateString(),
        ];
        $this->actingAs($manager)->withSession($session)
            ->get(route('reports.index', $filters))
            ->assertOk()
            ->assertSee('Relatório de atendimentos')
            ->assertSee('A*** B*** d*** S***')
            ->assertDontSee('Ana Beatriz dos Santos')
            ->assertDontSee('Paciente de Outra Unidade');

        $csv = $this->actingAs($manager)->withSession($session)
            ->get(route('reports.csv', $filters))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $csvContent = $csv->streamedContent();
        $this->assertStringContainsString('A*** B*** d*** S***', $csvContent);
        $this->assertStringNotContainsString('Ana Beatriz dos Santos', $csvContent);
        $this->assertStringNotContainsString('Paciente de Outra Unidade', $csvContent);

        $pdf = $this->actingAs($manager)->withSession($session)
            ->get(route('reports.pdf', [
                ...$filters,
                'status' => $encounter->currentStatusEnum()->value,
                'destination_type' => DestinationType::Discharge->value,
            ]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertHeader('cache-control', 'no-store, private');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->assertDatabaseCount('audit_logs', 2);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'report.exported',
            'health_unit_id' => $unit->getKey(),
        ]);

        $this->actingAs($receptionist)->withSession($session)
            ->get(route('reports.index', $filters))
            ->assertForbidden();
        $this->actingAs($manager)->withSession($session)
            ->from(route('reports.index'))
            ->get(route('reports.index', [
                'date_from' => today()->subDays(400)->toDateString(),
                'date_to' => today()->toDateString(),
            ]))
            ->assertRedirect(route('reports.index'))
            ->assertSessionHasErrors('date_to');
    }

    public function test_dashboard_metrics_use_two_aggregate_queries_and_the_new_index_exists(): void
    {
        [$unit, , $manager] = $this->context();
        $this->createActiveEncounter($unit, $manager);
        config()->set('sync_sus.performance_cache.enabled', false);

        DB::flushQueryLog();
        DB::enableQueryLog();
        app(OperationalDashboardQuery::class)->metrics($unit);
        $queries = collect(DB::getQueryLog())
            ->filter(fn (array $query): bool => str_starts_with(
                mb_strtolower(ltrim((string) $query['query'])),
                'select',
            ));
        DB::disableQueryLog();

        $this->assertCount(2, $queries);
        $indexes = collect(Schema::getIndexes('encounters'))
            ->pluck('name');
        $this->assertContains('encounters_unit_status_closed_at_index', $indexes);
    }

    /** @return array{HealthUnit, User, User, User, MedicalConsultation, Encounter} */
    private function context(): array
    {
        $unit = $this->createHealthUnit();
        $this->seed([RolePermissionSeeder::class, OperationalCatalogSeeder::class]);
        $doctor = $this->createUserWithUnit($unit, ['name' => 'Dra. Marina Lopes', 'must_change_password' => false]);
        $doctor->assignRole('doctor');
        $this->registerDoctor($doctor, $unit);
        $manager = $this->createUserWithUnit($unit, ['name' => 'Gestor da Unidade', 'must_change_password' => false]);
        $manager->assignRole('manager');
        $receptionist = $this->createUserWithUnit($unit, ['must_change_password' => false]);
        $receptionist->assignRole('receptionist');
        $patient = $this->createPatient($unit, $doctor, 'P00000100', 'Ana Beatriz dos Santos');
        $queue = Queue::query()->where('health_unit_id', $unit->getKey())->where('code', 'QUEUE-CLINIC')->sole();
        $arrivalAt = now()->subMinutes(90);
        $encounter = Encounter::query()->create([
            'encounter_number' => 'CENTRAL-20260724-0100',
            'patient_id' => $patient->getKey(),
            'health_unit_id' => $unit->getKey(),
            'entry_type_id' => EntryType::query()->where('code', 'EMERGENCY')->value('id'),
            'arrival_method_id' => ArrivalMethod::query()->where('code', 'WALK_IN')->value('id'),
            'risk_level_id' => RiskLevel::query()->where('code', 'YELLOW')->value('id'),
            'current_status' => EncounterStatus::Discharged,
            'administrative_priority' => AdministrativePriority::None,
            'arrival_at' => $arrivalAt,
            'registration_at' => $arrivalAt,
            'triage_started_at' => $arrivalAt->copy()->addMinutes(10),
            'triage_finished_at' => $arrivalAt->copy()->addMinutes(20),
            'medical_started_at' => $arrivalAt->copy()->addMinutes(35),
            'medical_finished_at' => $arrivalAt->copy()->addMinutes(70),
            'closed_at' => $arrivalAt->copy()->addMinutes(75),
            'current_department_id' => $queue->department_id,
            'created_by' => $doctor->getKey(),
        ]);
        $entry = QueueEntry::query()->create([
            'encounter_id' => $encounter->getKey(),
            'queue_id' => $queue->getKey(),
            'ticket_number' => 'C100',
            'priority_weight' => 60,
            'status' => QueueEntryStatus::Completed,
            'entered_at' => $arrivalAt->copy()->addMinutes(20),
            'service_started_at' => $arrivalAt->copy()->addMinutes(35),
            'exited_at' => $arrivalAt->copy()->addMinutes(70),
            'assigned_user_id' => $doctor->getKey(),
            'lock_version' => 1,
        ]);
        $consultation = MedicalConsultation::query()->create([
            'encounter_id' => $encounter->getKey(),
            'queue_entry_id' => $entry->getKey(),
            'professional_id' => $doctor->getKey(),
            'status' => MedicalConsultationStatus::Finalized,
            'chief_complaint' => 'Dor de cabeça.',
            'conduct_summary' => 'Tratamento sintomático e alta.',
            'content_hash' => hash('sha256', 'consulta-finalizada'),
            'started_at' => $arrivalAt->copy()->addMinutes(35),
            'finalized_at' => $arrivalAt->copy()->addMinutes(70),
            'finalized_by' => $doctor->getKey(),
        ]);
        EncounterDestination::query()->create([
            'encounter_id' => $encounter->getKey(),
            'medical_consultation_id' => $consultation->getKey(),
            'destination_type' => DestinationType::Discharge,
            'reason' => 'Melhora clínica após tratamento.',
            'clinical_condition' => 'Estável.',
            'recorded_by' => $doctor->getKey(),
            'occurred_at' => $arrivalAt->copy()->addMinutes(70),
        ]);

        return [$unit, $doctor, $manager, $receptionist, $consultation, $encounter];
    }

    private function createActiveEncounter(HealthUnit $unit, User $actor): void
    {
        $patient = $this->createPatient($unit, $actor, 'P00000101', 'Ana Beatriz dos Santos');
        $queue = Queue::query()->where('health_unit_id', $unit->getKey())->where('code', 'QUEUE-TRIAGE')->sole();
        $encounter = Encounter::query()->create([
            'encounter_number' => 'CENTRAL-20260724-0101',
            'patient_id' => $patient->getKey(),
            'health_unit_id' => $unit->getKey(),
            'entry_type_id' => EntryType::query()->where('code', 'EMERGENCY')->value('id'),
            'arrival_method_id' => ArrivalMethod::query()->where('code', 'WALK_IN')->value('id'),
            'current_status' => EncounterStatus::WaitingTriage,
            'administrative_priority' => AdministrativePriority::None,
            'arrival_at' => now()->subMinutes(12),
            'registration_at' => now()->subMinutes(12),
            'current_department_id' => $queue->department_id,
            'created_by' => $actor->getKey(),
        ]);
        QueueEntry::query()->create([
            'encounter_id' => $encounter->getKey(),
            'queue_id' => $queue->getKey(),
            'ticket_number' => 'T101',
            'priority_weight' => 10,
            'status' => QueueEntryStatus::Waiting,
            'entered_at' => now()->subMinutes(12),
            'lock_version' => 1,
        ]);
    }

    private function createExternalEncounter(HealthUnit $unit, User $actor): void
    {
        $patient = $this->createPatient($unit, $actor, 'P00000999', 'Paciente de Outra Unidade');
        Encounter::query()->create([
            'encounter_number' => 'NORTH-20260724-0999',
            'patient_id' => $patient->getKey(),
            'health_unit_id' => $unit->getKey(),
            'entry_type_id' => EntryType::query()->where('code', 'EMERGENCY')->value('id'),
            'arrival_method_id' => ArrivalMethod::query()->where('code', 'WALK_IN')->value('id'),
            'current_status' => EncounterStatus::WaitingTriage,
            'administrative_priority' => AdministrativePriority::None,
            'arrival_at' => now()->subMinutes(5),
            'registration_at' => now()->subMinutes(5),
            'created_by' => $actor->getKey(),
        ]);
    }

    private function createPatient(
        HealthUnit $unit,
        User $actor,
        string $record,
        string $name,
    ): Patient {
        return Patient::query()->create([
            'organization_id' => $unit->organization_id,
            'medical_record_number' => $record,
            'full_name' => $name,
            'normalized_name' => mb_strtoupper($name),
            'birth_date' => '1988-04-12',
            'sex' => PatientSex::Female,
            'status' => PatientStatus::Active,
            'reference_health_unit_id' => $unit->getKey(),
            'created_by' => $actor->getKey(),
            'updated_by' => $actor->getKey(),
        ]);
    }
}
