@php
    $statuses = [
        'waiting_triage' => 'Aguardando triagem', 'in_triage' => 'Em triagem',
        'waiting_medical' => 'Aguardando médico', 'in_medical_care' => 'Em atendimento',
        'under_observation' => 'Em observação', 'awaiting_admission' => 'Aguardando internação',
        'discharged' => 'Alta', 'transferred' => 'Transferido',
        'left_without_notice' => 'Evasão', 'deceased' => 'Óbito', 'cancelled' => 'Cancelado',
    ];
    $destinations = [
        'discharge' => 'Alta', 'observation' => 'Observação',
        'admission_request' => 'Internação solicitada', 'transfer' => 'Transferência',
        'evasion' => 'Evasão', 'death' => 'Óbito',
    ];
@endphp

<x-layout.app title="Relatórios">
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div><p class="text-sm font-semibold text-brand-700">{{ $activeHealthUnit->name }}</p><h1 class="text-2xl font-extrabold">Relatório de atendimentos</h1><p class="mt-1 text-sm text-slate-500">Filtros, tempos assistenciais e destinações com limite de 1.000 registros.</p></div>
        <div class="flex gap-2"><a href="{{ route('reports.csv', $filters) }}" class="rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold">Exportar CSV</a><a href="{{ route('reports.pdf', $filters) }}" class="rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-bold text-white">Exportar PDF</a></div>
    </div>

    <section class="app-card mb-5 p-5">
        <form method="GET" action="{{ route('reports.index') }}">
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <div><label class="field-label" for="date_from">Data inicial *</label><input id="date_from" name="date_from" type="date" required value="{{ $filters['date_from'] }}" class="field-control"></div>
                <div><label class="field-label" for="date_to">Data final *</label><input id="date_to" name="date_to" type="date" required value="{{ $filters['date_to'] }}" class="field-control"></div>
                <div><label class="field-label" for="status">Status</label><select id="status" name="status" class="field-control"><option value="">Todos</option>@foreach($statuses as $value => $label)<option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></div>
                <div><label class="field-label" for="destination_type">Destinação</label><select id="destination_type" name="destination_type" class="field-control"><option value="">Todas</option>@foreach($destinations as $value => $label)<option value="{{ $value }}" @selected(($filters['destination_type'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></div>
                <div><label class="field-label" for="risk_level_id">Risco</label><select id="risk_level_id" name="risk_level_id" class="field-control"><option value="">Todos</option>@foreach($riskLevels as $risk)<option value="{{ $risk->getKey() }}" @selected((string)($filters['risk_level_id'] ?? '') === (string)$risk->getKey())>{{ $risk->name }}</option>@endforeach</select></div>
                <div><label class="field-label" for="specialty_id">Especialidade</label><select id="specialty_id" name="specialty_id" class="field-control"><option value="">Todas</option>@foreach($specialties as $specialty)<option value="{{ $specialty->getKey() }}" @selected((string)($filters['specialty_id'] ?? '') === (string)$specialty->getKey())>{{ $specialty->name }}</option>@endforeach</select></div>
                <div><label class="field-label" for="professional_id">Profissional</label><select id="professional_id" name="professional_id" class="field-control"><option value="">Todos</option>@foreach($professionals as $professional)<option value="{{ $professional->getKey() }}" @selected((string)($filters['professional_id'] ?? '') === (string)$professional->getKey())>{{ $professional->name }}</option>@endforeach</select></div>
                <div class="flex items-end"><button class="w-full rounded-lg bg-slate-900 px-4 py-2.5 text-sm font-bold text-white">Aplicar filtros</button></div>
            </div>
        </form>
    </section>

    <div class="mb-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach([
            ['Total', $summary['total']],
            ['Espera média até triagem', $summary['average_triage_wait_minutes'] === null ? '—' : $summary['average_triage_wait_minutes'].' min'],
            ['Espera média médica', $summary['average_medical_wait_minutes'] === null ? '—' : $summary['average_medical_wait_minutes'].' min'],
            ['Tempo total médio', $summary['average_total_minutes'] === null ? '—' : $summary['average_total_minutes'].' min'],
        ] as [$label, $value])
            <section class="app-card p-5"><p class="text-sm text-slate-500">{{ $label }}</p><p class="mt-2 text-2xl font-black">{{ $value }}</p></section>
        @endforeach
    </div>

    <div class="mb-5 grid gap-5 lg:grid-cols-3">
        @foreach([['Por status', $summary['by_status']], ['Por risco', $summary['by_risk']], ['Por destinação', $summary['by_destination']]] as [$title, $items])
            <section class="app-card p-5"><h2 class="font-extrabold">{{ $title }}</h2><dl class="mt-3 space-y-2 text-sm">@forelse($items as $label => $count)<div class="flex justify-between gap-3 border-b border-slate-100 pb-2"><dt>{{ $statuses[$label] ?? $destinations[$label] ?? $label }}</dt><dd class="font-black">{{ $count }}</dd></div>@empty<p class="text-slate-500">Sem dados.</p>@endforelse</dl></section>
        @endforeach
    </div>

    <section class="app-card overflow-hidden">
        <div class="border-b border-slate-200 px-5 py-4"><h2 class="font-extrabold">Atendimentos encontrados</h2><p class="text-xs text-slate-500">Identificação exibida conforme as permissões do perfil.</p></div>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[1250px] text-left text-xs">
                <thead class="border-b border-slate-200 bg-slate-50 uppercase text-slate-500"><tr><th class="px-3 py-3">Atendimento</th><th class="px-3 py-3">Paciente</th><th class="px-3 py-3">Chegada</th><th class="px-3 py-3">Risco</th><th class="px-3 py-3">Especialidade</th><th class="px-3 py-3">Profissional</th><th class="px-3 py-3">Status</th><th class="px-3 py-3">Destinação</th><th class="px-3 py-3">Triagem</th><th class="px-3 py-3">Médico</th><th class="px-3 py-3">Total</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($rows as $row)
                        <tr><td class="px-3 py-3 font-bold">{{ $row['encounter'] }}</td><td class="px-3 py-3"><strong>{{ $row['patient'] }}</strong><br><span class="text-slate-500">{{ $row['medical_record'] }}</span></td><td class="px-3 py-3">{{ $row['arrival_at'] }}</td><td class="px-3 py-3">{{ $row['risk'] ?? '—' }}</td><td class="px-3 py-3">{{ $row['specialty'] ?? '—' }}</td><td class="px-3 py-3">{{ $row['professional'] ?? '—' }}</td><td class="px-3 py-3">{{ $statuses[$row['status']] ?? $row['status'] }}</td><td class="px-3 py-3">{{ $row['destination'] ?? '—' }}</td><td class="px-3 py-3">{{ $row['triage_duration_minutes'] === null ? '—' : $row['triage_duration_minutes'].' min' }}</td><td class="px-3 py-3">{{ $row['medical_duration_minutes'] === null ? '—' : $row['medical_duration_minutes'].' min' }}</td><td class="px-3 py-3 font-bold">{{ $row['total_minutes'] }} min</td></tr>
                    @empty
                        <tr><td colspan="11" class="px-5 py-12 text-center text-slate-500">Nenhum atendimento corresponde aos filtros.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</x-layout.app>
