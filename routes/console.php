<?php

declare(strict_types=1);

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
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('synclab:dispatch-pending', function (
    DispatchPendingLaboratoryTransmissionsAction $action,
): void {
    $this->info($action->execute().' transmissao(oes) Synclab encaminhada(s) para a fila.');
})->purpose('Encaminha requisicoes laboratoriais pendentes para o worker Synclab.');

Artisan::command('synclab:dispatch-received-results', function (
    DispatchReceivedLaboratoryResultsAction $action,
): void {
    $this->info($action->execute().' resultado(s) Synclab recebido(s) encaminhado(s) para a fila.');
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
        ->with(['laboratoryExam:id,external_code,name', 'suggestedExam:id,public_id,name'])
        ->where('laboratory_integration_id', $integration->getKey())
        ->pending()
        ->orderBy('match_status')
        ->get()
        ->map(fn (ExamCatalogImportCandidate $candidate): array => [
            $candidate->public_id,
            $candidate->match_status->value,
            $candidate->laboratoryExam->external_code,
            $candidate->laboratoryExam->name,
            $candidate->suggestedExam?->public_id,
            $candidate->suggestedExam?->name,
        ])->all();
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
