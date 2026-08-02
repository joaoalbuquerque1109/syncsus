<x-layout.app title="Visão geral do plantão">
    <div x-data="dashboard({
        metrics: @js($metrics),
        encounters: @js($activeEncounters),
        stateUrl: @js(route('dashboard.state')),
        pollMs: {{ max(5, (int) config('sync_sus.dashboard_poll_seconds', 15)) * 1000 }}
    })">
        <div class="mb-6 flex flex-wrap items-end justify-between gap-3">
            <div>
                <p class="text-sm font-semibold text-brand-700">{{ $activeHealthUnit->name }}</p>
                <h1 class="text-2xl font-extrabold text-slate-950">Visão geral do plantão</h1>
                <p class="mt-1 text-sm text-slate-500">Indicadores operacionais atualizados automaticamente.</p>
            </div>
            <div class="text-right text-xs text-slate-500">
                <span x-show="disconnected" x-cloak class="mr-2 font-bold text-red-700">Sem conexão</span>
                Atualizado às <span class="font-bold" x-text="updatedAt"></span>
            </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @foreach([
                ['waiting_triage', 'Aguardando triagem', '▣'],
                ['in_triage', 'Em triagem', '✓'],
                ['waiting_medical', 'Aguardando médico', '♙'],
                ['in_medical_care', 'Em atendimento', '+'],
                ['under_observation', 'Em observação', '◉'],
                ['awaiting_admission', 'Aguardando internação', '⌂'],
                ['transfers_today', 'Transferências hoje', '⇄'],
                ['discharges_today', 'Altas hoje', '✓'],
            ] as [$key, $label, $icon])
                <section class="app-card p-5">
                    <div class="flex items-start justify-between">
                        <div><p class="text-sm text-slate-500">{{ $label }}</p><p class="mt-2 text-3xl font-black text-slate-950" x-text="String(metrics.{{ $key }} ?? 0).padStart(2, '0')"></p></div>
                        <span class="grid size-10 place-items-center rounded-lg bg-brand-50 font-black text-brand-700">{{ $icon }}</span>
                    </div>
                    <p class="mt-3 text-xs text-slate-400">Atualização operacional</p>
                </section>
            @endforeach
        </div>

        <section class="app-card mt-5 p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="font-extrabold">Médicos habilitados na unidade</h2>
                    <p class="text-xs text-slate-500">Profissionais ativos, vinculados à unidade e com especialidade cadastrada.</p>
                </div>
                <span class="rounded-full bg-emerald-100 px-3 py-1 text-sm font-extrabold text-emerald-800">
                    {{ $availableDoctors->count() }} disponíveis
                </span>
            </div>
            <div class="mt-4 flex flex-wrap gap-2">
                @forelse($availableDoctors as $doctor)
                    <span class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm">
                        <strong>{{ $doctor->professionalProfile?->displayName() ?? $doctor->name }}</strong>
                        <small class="ml-1 text-slate-500">{{ $doctor->professionalProfile?->specialties?->pluck('name')->join(', ') }}</small>
                    </span>
                @empty
                    <p class="text-sm text-amber-800">Nenhum médico habilitado nesta unidade.</p>
                @endforelse
            </div>
        </section>

        <section class="app-card mt-5 overflow-hidden">
            <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4">
                <div><h2 class="font-extrabold">Atendimentos em andamento</h2><p class="text-xs text-slate-500">Máximo de 20 episódios por ordem de chegada</p></div>
                @can('reports.view')<a href="{{ route('reports.index') }}" class="rounded-lg bg-slate-100 px-4 py-2 text-sm font-bold">Ver relatórios</a>@endcan
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[850px] text-left text-sm">
                    <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase text-slate-500"><tr><th class="px-4 py-3">Senha</th><th class="px-4 py-3">Paciente</th><th class="px-4 py-3">Etapa</th><th class="px-4 py-3">Risco</th><th class="px-4 py-3">Tempo</th><th class="px-4 py-3">Local</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        <template x-for="encounter in encounters" :key="encounter.encounter">
                            <tr>
                                <td class="px-4 py-4 font-black" x-text="encounter.ticket"></td>
                                <td class="px-4 py-4 font-semibold" x-text="encounter.patient"></td>
                                <td class="px-4 py-4" x-text="encounter.stage"></td>
                                <td class="px-4 py-4"><span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-bold uppercase" x-text="encounter.risk || '—'"></span></td>
                                <td class="px-4 py-4" x-text="encounter.waiting_minutes === null ? '—' : `${encounter.waiting_minutes} min`"></td>
                                <td class="px-4 py-4" x-text="encounter.location || '—'"></td>
                            </tr>
                        </template>
                        <tr x-show="encounters.length === 0"><td colspan="6" class="px-5 py-10 text-center text-slate-500">Nenhum atendimento ativo.</td></tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</x-layout.app>
