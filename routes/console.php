<?php

declare(strict_types=1);

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Administration\Infrastructure\Eloquent\TenantDatabase;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Laboratory\Application\Actions\BackfillCanonicalExamCatalogAction;
use App\Modules\Laboratory\Application\Actions\DispatchPendingLaboratoryTransmissionsAction;
use App\Modules\Laboratory\Application\Actions\DispatchReceivedLaboratoryResultsAction;
use App\Modules\Laboratory\Application\Actions\ImportExamGroupsAction;
use App\Modules\Laboratory\Application\Actions\MatchSynclabExamCatalogAction;
use App\Modules\Laboratory\Application\Actions\ResolveExamCatalogCandidateAction;
use App\Modules\Laboratory\Application\Actions\ResolveExamGroupImportConflictAction;
use App\Modules\Laboratory\Infrastructure\Eloquent\Exam;
use App\Modules\Laboratory\Infrastructure\Eloquent\ExamCatalogImportCandidate;
use App\Modules\Laboratory\Infrastructure\Eloquent\ExamGroupImportConflict;
use App\Modules\Laboratory\Infrastructure\Eloquent\LaboratoryIntegration;
use App\Modules\Patients\Application\Services\BackfillPatientIdentifierProtection;
use App\Modules\Patients\Application\Services\MigrateLegacyUnitPatientRecords;
use App\Modules\Patients\Application\Services\ResolveUnitPatientMigrationConflict;
use App\Modules\Patients\Infrastructure\Eloquent\PatientUnitMigrationConflict;
use App\Support\Tenancy\TenantConnectionManager;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantDatabaseLifecycle;
use App\Support\Tenancy\TenantDatabaseProvisioner;
use App\Support\Tenancy\TenantDatabaseReconciler;
use App\Support\Tenancy\TenantDatabaseState;
use App\Support\Tenancy\TenantPilotDataSynchronizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command(
    'tenant:pilot-register {unit} {profile} {actor} {--database=} {--apply}',
    function (TenantDatabaseLifecycle $lifecycle): void {
        if (! $this->option('apply')) {
            $this->warn('Simulação: nenhum banco piloto foi registrado. Use --apply após conferir unidade e perfil.');

            return;
        }
        $unit = HealthUnit::query()->where('public_id', (string) $this->argument('unit'))->firstOrFail();
        $actor = User::query()->where('public_id', (string) $this->argument('actor'))->firstOrFail();
        $tenantDatabase = $lifecycle->register(
            $unit,
            (string) $this->argument('profile'),
            filled($this->option('database')) ? (string) $this->option('database') : null,
            $actor,
        );
        $this->info("Piloto {$tenantDatabase->public_id} registrado em LEGACY.");
    },
)->purpose('Registra de forma auditada o perfil físico de uma unidade piloto.');

Artisan::command(
    'tenant:pilot-provision {unit} {actor} {--apply}',
    function (TenantDatabaseProvisioner $provisioner): void {
        if (! $this->option('apply')) {
            $this->warn('Simulação: nenhuma migration foi executada. Use --apply para provisionar e entrar em SHADOW.');

            return;
        }
        $unit = HealthUnit::query()->where('public_id', (string) $this->argument('unit'))->firstOrFail();
        $actor = User::query()->where('public_id', (string) $this->argument('actor'))->firstOrFail();
        $tenantDatabase = TenantDatabase::query()->where('health_unit_id', $unit->getKey())->firstOrFail();
        $tenantDatabase = $provisioner->provision($tenantDatabase, $actor);
        $this->info("Schema provisionado; unidade {$unit->public_id} em {$tenantDatabase->stateEnum()->value}.");
    },
)->purpose('Executa migrations e remove FKs cross-database no banco físico piloto.');

Artisan::command(
    'tenant:pilot-sync {unit} {actor} {--apply}',
    function (TenantPilotDataSynchronizer $synchronizer): void {
        if (! $this->option('apply')) {
            $this->warn('Simulação: nenhum dado foi copiado. Use --apply para executar a carga inicial idempotente.');

            return;
        }
        $unit = HealthUnit::query()->where('public_id', (string) $this->argument('unit'))->firstOrFail();
        $actor = User::query()->where('public_id', (string) $this->argument('actor'))->firstOrFail();
        $tenantDatabase = TenantDatabase::query()->where('health_unit_id', $unit->getKey())->firstOrFail();
        $counts = $synchronizer->synchronize($tenantDatabase, $actor);
        $this->info(array_sum($counts).' registro(s) sincronizado(s) no banco piloto.');
    },
)->purpose('Copia idempotentemente os dados da unidade LEGACY para o banco SHADOW.');

Artisan::command(
    'tenant:pilot-reconcile {unit} {actor} {--apply}',
    function (TenantDatabaseReconciler $reconciler): void {
        if (! $this->option('apply')) {
            $this->warn('Simulação: a reconciliação não foi registrada. Use --apply para comparar e auditar o resultado.');

            return;
        }
        $unit = HealthUnit::query()->where('public_id', (string) $this->argument('unit'))->firstOrFail();
        $actor = User::query()->where('public_id', (string) $this->argument('actor'))->firstOrFail();
        $tenantDatabase = TenantDatabase::query()->where('health_unit_id', $unit->getKey())->firstOrFail();
        $result = $reconciler->reconcile($tenantDatabase, $actor);
        $this->info('Reconciliação concluída: '.mb_strtoupper($result['status']).'.');
    },
)->purpose('Compara contagens e hashes entre LEGACY e SHADOW sem corrigir divergências.');

Artisan::command(
    'tenant:pilot-transition {unit} {state} {actor} {--apply}',
    function (TenantDatabaseLifecycle $lifecycle): void {
        $target = TenantDatabaseState::tryFrom(mb_strtoupper((string) $this->argument('state')));
        if ($target === null) {
            throw new InvalidArgumentException('Estado Tenant inválido.');
        }
        if (! $this->option('apply')) {
            $this->warn("Simulação: nenhuma transição para {$target->value} foi aplicada.");

            return;
        }
        $unit = HealthUnit::query()->where('public_id', (string) $this->argument('unit'))->firstOrFail();
        $actor = User::query()->where('public_id', (string) $this->argument('actor'))->firstOrFail();
        $tenantDatabase = TenantDatabase::query()->where('health_unit_id', $unit->getKey())->firstOrFail();
        $tenantDatabase = $lifecycle->transition($tenantDatabase, $target, $actor, ['source' => 'artisan']);
        $this->info("Unidade {$unit->public_id} movida para {$tenantDatabase->stateEnum()->value}.");
    },
)->purpose('Executa uma transição válida e auditada no ciclo do banco piloto.');

Artisan::command(
    'tenant:pilot-continuity {unit} {actor} {--backup-reference=} {--restore-reference=} {--apply}',
    function (TenantDatabaseLifecycle $lifecycle): void {
        if (! $this->option('apply')) {
            $this->warn('Simulação: nenhuma evidência foi registrada. Use --apply após validar backup e restore.');

            return;
        }
        $unit = HealthUnit::query()->where('public_id', (string) $this->argument('unit'))->firstOrFail();
        $actor = User::query()->where('public_id', (string) $this->argument('actor'))->firstOrFail();
        $tenantDatabase = TenantDatabase::query()->where('health_unit_id', $unit->getKey())->firstOrFail();
        $lifecycle->recordContinuityEvidence($tenantDatabase, $actor, [
            'backup_reference' => (string) $this->option('backup-reference'),
            'restore_reference' => (string) $this->option('restore-reference'),
        ]);
        $this->info('Evidências de backup e restauração registradas para o piloto.');
    },
)->purpose('Registra evidências externas verificáveis de backup e teste de restore antes do CUTOVER.');

Artisan::command('tenant:status', function (): void {
    $rows = [];
    foreach (TenantDatabase::query()->orderBy('health_unit_id')->get() as $database) {
        $rows[] = [
            $database->healthUnit()->firstOrFail()->code,
            $database->stateEnum()->value,
            $database->schema_status,
            $database->connection_profile,
            $database->last_reconciliation_status ?? '-',
            $database->lastReconciledAtLabel() ?? '-',
        ];
    }
    $this->table(['Unidade', 'Estado', 'Schema', 'Perfil', 'Reconciliação', 'Última execução'], $rows);
})->purpose('Exibe estado, schema e última reconciliação de todos os bancos por unidade.');

Artisan::command('patients:migrate-unit-records {--connection=} {--apply}', function (
    MigrateLegacyUnitPatientRecords $migration,
): void {
    $connection = trim((string) $this->option('connection')) ?: (string) config('database.default');
    $result = $migration->execute($connection, (bool) $this->option('apply'));
    $mode = $this->option('apply') ? 'aplicado' : 'simulado';
    $this->info(
        "Modo {$mode}: {$result['unit_patients']} prontuário(s) local(is), "
        ."{$result['participations']} participação(ões), {$result['migrated_records']} registro(s) "
        ."inequívoco(s) e {$result['conflicts']} conflito(s).",
    );
})->purpose('Migra registros históricos para UnitPatient; sem --apply apenas simula.');

Artisan::command('patients:protect-identifiers {--apply}', function (
    BackfillPatientIdentifierProtection $backfill,
): void {
    $count = $backfill->execute((bool) $this->option('apply'));
    $mode = $this->option('apply') ? 'protegido(s)' : 'pendente(s)';
    $this->info("{$count} identificador(es) {$mode}.");
})->purpose('Preenche criptografia autenticada e fingerprint HMAC; sem --apply apenas conta.');

Artisan::command('patients:list-unit-conflicts {--status=pending}', function (): void {
    $status = trim((string) $this->option('status'));
    $conflicts = PatientUnitMigrationConflict::query()
        ->when($status !== '', fn ($query) => $query->where('status', $status))
        ->orderBy('created_at')
        ->get()
        ->map(fn (PatientUnitMigrationConflict $conflict): array => [
            $conflict->public_id,
            $conflict->tenant_connection,
            $conflict->source_table.':'.$conflict->source_id,
            $conflict->patient_public_id ?? '-',
            $conflict->reason,
            implode(', ', $conflict->candidate_health_unit_public_ids ?? []),
            $conflict->status,
        ])->all();
    $this->table(
        ['Conflito', 'Conexão', 'Origem', 'Paciente', 'Motivo', 'Unidades candidatas', 'Estado'],
        $conflicts,
    );
})->purpose('Lista conflitos auditáveis da migração de prontuário por unidade.');

Artisan::command('patients:resolve-unit-conflict {conflict} {unit} {actor}', function (
    ResolveUnitPatientMigrationConflict $resolver,
): void {
    $conflict = PatientUnitMigrationConflict::query()
        ->where('public_id', (string) $this->argument('conflict'))
        ->firstOrFail();
    $unit = HealthUnit::query()->where('public_id', (string) $this->argument('unit'))->firstOrFail();
    $actor = User::query()->where('public_id', (string) $this->argument('actor'))->firstOrFail();
    $resolved = $resolver->execute(
        $conflict,
        $unit,
        $actor,
        Request::create('/artisan/patients/resolve-unit-conflict', 'POST'),
    );
    $this->info("Conflito {$resolved->public_id} resolvido para a unidade {$unit->public_id}.");
})->purpose('Resolve manualmente um registro histórico ambíguo sem excluir o legado.');

Artisan::command('synclab:dispatch-pending', function (
    DispatchPendingLaboratoryTransmissionsAction $action,
    TenantContext $tenantContext,
    TenantConnectionManager $connectionManager,
): void {
    $dispatched = 0;
    foreach (HealthUnit::query()->where('is_active', true)->get() as $unit) {
        $tenantContext->reset();
        $tenantContext->resolve($unit, $connectionManager->connectionName($unit));
        try {
            $dispatched += $action->execute($unit);
        } finally {
            $tenantContext->reset();
        }
    }
    $this->info($dispatched.' transmissao(oes) Synclab encaminhada(s) para a fila.');
})->purpose('Encaminha requisicoes laboratoriais pendentes para o worker Synclab.');

Artisan::command('synclab:dispatch-received-results', function (
    DispatchReceivedLaboratoryResultsAction $action,
    TenantContext $tenantContext,
    TenantConnectionManager $connectionManager,
): void {
    $dispatched = 0;
    foreach (HealthUnit::query()->where('is_active', true)->get() as $unit) {
        $tenantContext->reset();
        $tenantContext->resolve($unit, $connectionManager->connectionName($unit));
        try {
            $dispatched += $action->execute($unit);
        } finally {
            $tenantContext->reset();
        }
    }
    $this->info($dispatched.' resultado(s) Synclab recebido(s) encaminhado(s) para a fila.');
})->purpose('Recupera resultados Synclab recebidos que ainda aguardam processamento.');

Artisan::command('laboratory:backfill-exam-catalog', function (
    BackfillCanonicalExamCatalogAction $action,
): void {
    $result = $action->execute();
    $this->info(
        "{$result['processed']} exame(s) processado(s); "
        ."{$result['exams_created']} canônico(s), "
        ."{$result['mappings_created']} mapping(s) e "
        ."{$result['availabilities_created']} habilitação(ões) criado(s).",
    );
})->purpose('Cria o catálogo canônico inicial a partir dos exames laboratoriais ativos.');

Artisan::command('laboratory:match-exam-catalog {integration?}', function (
    MatchSynclabExamCatalogAction $action,
): void {
    $publicId = trim((string) $this->argument('integration'));
    $integrations = LaboratoryIntegration::query()
        ->where('provider', 'synclab')
        ->when($publicId !== '', fn ($query) => $query->where('public_id', $publicId))
        ->get();
    if ($integrations->isEmpty()) {
        $this->error('Nenhuma integração Synclab encontrada.');

        return;
    }
    foreach ($integrations as $integration) {
        $result = $action->execute($integration);
        $this->info(
            "{$integration->public_id}: {$result['exact']} exato(s), {$result['probable']} provável(is), "
            ."{$result['unmatched']} sem correspondência e {$result['conflict']} conflito(s).",
        );
    }
})->purpose('Classifica o catálogo Synclab importado para revisão assistida.');

Artisan::command('laboratory:review-exam-catalog {integration}', function (): void {
    $integration = LaboratoryIntegration::query()
        ->where('public_id', (string) $this->argument('integration'))
        ->firstOrFail();
    $rows = ExamCatalogImportCandidate::query()
        ->with('laboratoryExam:id,external_code,name')
        ->where('laboratory_integration_id', $integration->getKey())
        ->pending()
        ->orderBy('match_status')
        ->get()
        ->map(function (ExamCatalogImportCandidate $candidate): array {
            $suggestedExam = $candidate->resolveSuggestedExam();

            return [
                $candidate->public_id,
                $candidate->match_status->value,
                $candidate->laboratoryExam->external_code,
                $candidate->laboratoryExam->name,
                $suggestedExam?->public_id,
                $suggestedExam?->name,
            ];
        })->all();
    $this->table(['Candidato', 'Estado', 'Código', 'Exame importado', 'Sugestão', 'Exame sugerido'], $rows);
})->purpose('Lista candidatos pendentes de revisão do catálogo Synclab.');

Artisan::command(
    'laboratory:resolve-exam-match {candidate} {decision} {actor} {--exam=} {--enable}',
    function (ResolveExamCatalogCandidateAction $action): void {
        $candidate = ExamCatalogImportCandidate::query()
            ->where('public_id', (string) $this->argument('candidate'))
            ->firstOrFail();
        $actor = User::query()->where('public_id', (string) $this->argument('actor'))->firstOrFail();
        $examPublicId = trim((string) $this->option('exam'));
        $exam = $examPublicId === '' ? null : Exam::query()->where('public_id', $examPublicId)->firstOrFail();
        $resolved = $action->execute(
            $candidate,
            (string) $this->argument('decision'),
            $actor,
            $exam,
            (bool) $this->option('enable'),
        );
        $this->info("Candidato {$resolved->public_id} resolvido como {$resolved->resolution}.");
    },
)->purpose('Registra uma decisão humana de matching e a audita.');

Artisan::command('laboratory:import-exam-groups {integration} {path}', function (
    ImportExamGroupsAction $action,
): void {
    $integration = LaboratoryIntegration::query()
        ->where('public_id', (string) $this->argument('integration'))
        ->firstOrFail();
    $path = (string) $this->argument('path');
    $path = is_file($path) ? $path : base_path($path);
    $result = $action->execute($integration, $path);
    $this->info(
        "{$result['created']} grupo(s) criado(s), {$result['unchanged']} inalterado(s) e "
        ."{$result['conflicts']} conflito(s) registrado(s).",
    );
})->purpose('Importa grupos de exames por CSV sem sobrescrever grupos locais.');

Artisan::command('laboratory:review-exam-group-conflicts {integration}', function (): void {
    $integration = LaboratoryIntegration::query()
        ->where('public_id', (string) $this->argument('integration'))
        ->firstOrFail();
    $rows = ExamGroupImportConflict::query()
        ->where('laboratory_integration_id', $integration->getKey())
        ->pending()
        ->orderBy('created_at')
        ->get()
        ->map(fn (ExamGroupImportConflict $conflict): array => [
            $conflict->public_id,
            $conflict->conflict_type,
            $conflict->external_group_code,
            $conflict->external_name,
            implode(', ', $conflict->missing_external_codes ?? []),
        ])->all();
    $this->table(['Conflito', 'Tipo', 'Código', 'Grupo', 'Mappings ausentes'], $rows);
})->purpose('Lista conflitos pendentes da importação de grupos.');

Artisan::command(
    'laboratory:resolve-exam-group-conflict {conflict} {decision} {actor}',
    function (ResolveExamGroupImportConflictAction $action): void {
        $conflict = ExamGroupImportConflict::query()
            ->where('public_id', (string) $this->argument('conflict'))
            ->firstOrFail();
        $actor = User::query()->where('public_id', (string) $this->argument('actor'))->firstOrFail();
        $resolved = $action->execute($conflict, (string) $this->argument('decision'), $actor);
        $this->info("Conflito {$resolved->public_id} resolvido como {$resolved->decision}.");
    },
)->purpose('Aplica e audita uma decisão sobre conflito de grupo importado.');

Schedule::command('synclab:dispatch-pending')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('synclab:dispatch-received-results')
    ->everyFiveMinutes()
    ->withoutOverlapping();
