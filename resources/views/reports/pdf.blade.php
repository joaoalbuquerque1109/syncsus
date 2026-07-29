<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 18mm 12mm; }
        body { font-family: "DejaVu Sans", sans-serif; color: #172033; font-size: 8pt; }
        h1 { margin: 0; font-size: 16pt; color: #0b3558; }
        .subtitle { color: #53657b; margin: 4px 0 18px; }
        .metrics { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
        .metrics td { width: 25%; border: 1px solid #d9e2ec; padding: 8px; }
        .metrics strong { display: block; font-size: 14pt; }
        table.data { width: 100%; border-collapse: collapse; }
        .data th { background: #eaf3fb; text-align: left; padding: 6px; }
        .data td { border-bottom: 1px solid #d9e2ec; padding: 5px 6px; vertical-align: top; }
        .footer { position: fixed; bottom: -8mm; left: 0; right: 0; color: #53657b; border-top: 1px solid #d9e2ec; padding-top: 5px; }
    </style>
</head>
<body>
    <h1>SYNC SUS · Relatório de atendimentos</h1>
    <p class="subtitle">{{ $unit->organization->trade_name }} · {{ $unit->name }} · período {{ \Illuminate\Support\Carbon::parse($filters['date_from'])->format('d/m/Y') }} a {{ \Illuminate\Support\Carbon::parse($filters['date_to'])->format('d/m/Y') }}</p>
    <table class="metrics"><tr><td><strong>{{ $summary['total'] }}</strong>Total</td><td><strong>{{ $summary['average_triage_wait_minutes'] ?? '—' }}</strong>Espera triagem (min)</td><td><strong>{{ $summary['average_medical_wait_minutes'] ?? '—' }}</strong>Espera médica (min)</td><td><strong>{{ $summary['average_total_minutes'] ?? '—' }}</strong>Tempo total (min)</td></tr></table>
    <table class="data">
        <thead><tr><th>Atendimento</th><th>Paciente</th><th>Chegada</th><th>Risco</th><th>Status</th><th>Destino</th><th>Total</th></tr></thead>
        <tbody>
            @forelse($rows as $row)
                <tr><td>{{ $row['encounter'] }}</td><td>{{ $row['patient'] }}<br>{{ $row['medical_record'] }}</td><td>{{ $row['arrival_at'] }}</td><td>{{ $row['risk'] ?? '—' }}</td><td>{{ $row['status'] }}</td><td>{{ $row['destination'] ?? '—' }}</td><td>{{ $row['total_minutes'] }} min</td></tr>
            @empty
                <tr><td colspan="7">Nenhum atendimento encontrado.</td></tr>
            @endforelse
        </tbody>
    </table>
    <footer class="footer">Gerado em {{ now()->format('d/m/Y H:i') }} · máximo de 1.000 registros · exportação auditada</footer>
</body>
</html>
