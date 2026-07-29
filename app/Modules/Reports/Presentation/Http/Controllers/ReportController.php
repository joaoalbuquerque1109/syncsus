<?php

declare(strict_types=1);

namespace App\Modules\Reports\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Administration\Infrastructure\Eloquent\RiskLevel;
use App\Modules\Administration\Infrastructure\Eloquent\Specialty;
use App\Modules\Audit\Application\Actions\RecordAuditEventAction;
use App\Modules\Documents\Application\Contracts\PdfRenderer;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Reports\Application\Queries\EncounterReportQuery;
use App\Modules\Reports\Presentation\Http\Requests\EncounterReportRequest;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ReportController extends Controller
{
    public function index(EncounterReportRequest $request, EncounterReportQuery $report): View
    {
        [$user, $unit] = $this->context($request);
        $filters = $request->validated();
        $data = $report->run($unit, $filters, $user->can('patients.view'));

        return view('reports.index', [
            ...$data,
            'filters' => $filters,
            'riskLevels' => RiskLevel::query()->where('is_active', true)->orderBy('display_order')->get(),
            'specialties' => Specialty::query()->where('organization_id', $unit->organization_id)
                ->where('is_active', true)->orderBy('display_order')->get(),
            'professionals' => User::query()
                ->whereHas('healthUnits', fn ($query) => $query->whereKey($unit->getKey()))
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function csv(
        EncounterReportRequest $request,
        EncounterReportQuery $report,
        RecordAuditEventAction $audit,
    ): StreamedResponse {
        [$user, $unit] = $this->context($request);
        $filters = $request->validated();
        $data = $report->run($unit, $filters, $user->can('patients.view'));
        $this->auditExport($audit, $request, $user, $unit, $filters, 'csv', count($data['rows']));

        return response()->streamDownload(function () use ($data): void {
            $output = fopen('php://output', 'wb');
            if ($output === false) {
                return;
            }
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, [
                'Atendimento', 'Paciente', 'Prontuário', 'Idade', 'Sexo', 'Chegada',
                'Entrada', 'Forma de chegada', 'Risco', 'Especialidade', 'Profissional',
                'Status', 'Destinação', 'Espera triagem (min)', 'Duração triagem (min)',
                'Espera médica (min)', 'Duração médica (min)', 'Tempo total (min)',
            ], ';');
            foreach ($data['rows'] as $row) {
                fputcsv($output, [
                    $row['encounter'], $row['patient'], $row['medical_record'], $row['age'], $row['sex'],
                    $row['arrival_at'], $row['entry_type'], $row['arrival_method'], $row['risk'],
                    $row['specialty'], $row['professional'], $row['status'], $row['destination'],
                    $row['arrival_to_triage_minutes'], $row['triage_duration_minutes'],
                    $row['triage_to_medical_minutes'], $row['medical_duration_minutes'], $row['total_minutes'],
                ], ';');
            }
            fclose($output);
        }, 'relatorio-atendimentos-'.$filters['date_from'].'-'.$filters['date_to'].'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    public function pdf(
        EncounterReportRequest $request,
        EncounterReportQuery $report,
        PdfRenderer $renderer,
        RecordAuditEventAction $audit,
    ): Response {
        [$user, $unit] = $this->context($request);
        $filters = $request->validated();
        $data = $report->run($unit, $filters, $user->can('patients.view'));
        $html = view('reports.pdf', [
            ...$data,
            'filters' => $filters,
            'unit' => $unit->loadMissing('organization'),
        ])->render();
        $pdf = $renderer->render($html);
        $this->auditExport($audit, $request, $user, $unit, $filters, 'pdf', count($data['rows']));

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="relatorio-atendimentos-'.$filters['date_from'].'-'.$filters['date_to'].'.pdf"',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /** @return array{User, HealthUnit} */
    private function context(EncounterReportRequest $request): array
    {
        $user = $request->user();
        $unit = $request->attributes->get('active_health_unit');
        abort_unless($user instanceof User && $unit instanceof HealthUnit, 403);

        return [$user, $unit];
    }

    /** @param array<string, mixed> $filters */
    private function auditExport(
        RecordAuditEventAction $audit,
        EncounterReportRequest $request,
        User $user,
        HealthUnit $unit,
        array $filters,
        string $format,
        int $rowCount,
    ): void {
        $audit->execute(
            'report.exported',
            $request,
            $user,
            [
                'report' => 'encounters',
                'format' => $format,
                'date_from' => (string) $filters['date_from'],
                'date_to' => (string) $filters['date_to'],
                'row_count' => $rowCount,
            ],
            (int) $unit->getKey(),
        );
    }
}
