<x-layout.app title="Auditoria e acessos">
    <div class="mb-6">
        <p class="text-sm font-semibold text-brand-700">{{ $activeHealthUnit->name }}</p>
        <h1 class="text-2xl font-extrabold text-slate-950">Auditoria e acessos ao prontuário</h1>
        <p class="mt-1 text-sm text-slate-500">Consulta somente leitura, limitada à unidade ativa e sem conteúdo clínico nos eventos.</p>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach([
            ['Eventos', $summary['events']],
            ['Acessos a prontuários', $summary['record_accesses']],
            ['Falhas de login', $summary['login_failures']],
            ['Exportações', $summary['exports']],
        ] as [$label, $value])
            <section class="app-card p-5">
                <p class="text-sm text-slate-500">{{ $label }}</p>
                <p class="mt-2 text-3xl font-black text-slate-950">{{ number_format($value, 0, ',', '.') }}</p>
            </section>
        @endforeach
    </div>

    <section class="app-card mt-5 p-5">
        <form method="GET" action="{{ route('audit.index') }}" class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <div><label class="field-label" for="date_from">Data inicial</label><input id="date_from" name="date_from" type="date" value="{{ $filters['date_from'] }}" class="field-control"></div>
            <div><label class="field-label" for="date_to">Data final</label><input id="date_to" name="date_to" type="date" value="{{ $filters['date_to'] }}" class="field-control"></div>
            <div>
                <label class="field-label" for="user_id">Usuário</label>
                <select id="user_id" name="user_id" class="field-control">
                    <option value="">Todos</option>
                    @foreach($users as $user)<option value="{{ $user->getKey() }}" @selected((string) ($filters['user_id'] ?? '') === (string) $user->getKey())>{{ $user->name }}</option>@endforeach
                </select>
            </div>
            <div>
                <label class="field-label" for="action">Evento</label>
                <select id="action" name="action" class="field-control">
                    <option value="">Todos</option>
                    @foreach($actions as $action)<option value="{{ $action }}" @selected(($filters['action'] ?? '') === $action)>{{ $action }}</option>@endforeach
                </select>
            </div>
            <div>
                <label class="field-label" for="access_type">Tipo de acesso</label>
                <select id="access_type" name="access_type" class="field-control">
                    <option value="">Todos</option>
                    @foreach($accessTypes as $type)<option value="{{ $type }}" @selected(($filters['access_type'] ?? '') === $type)>{{ $type }}</option>@endforeach
                </select>
            </div>
            <div><label class="field-label" for="patient">Prontuário ou ID do paciente</label><input id="patient" name="patient" value="{{ $filters['patient'] ?? '' }}" class="field-control"></div>
            <div><label class="field-label" for="encounter">Atendimento ou ID</label><input id="encounter" name="encounter" value="{{ $filters['encounter'] ?? '' }}" class="field-control"></div>
            <div class="flex items-end gap-2">
                <button class="rounded-lg bg-brand-600 px-5 py-2.5 text-sm font-bold text-white">Filtrar</button>
                <a href="{{ route('audit.index') }}" class="rounded-lg border border-slate-300 px-5 py-2.5 text-sm font-bold">Limpar</a>
            </div>
        </form>
    </section>

    <section class="app-card mt-5 overflow-hidden">
        <div class="border-b border-slate-200 px-5 py-4">
            <h2 class="font-extrabold">Eventos do sistema</h2>
            <p class="text-xs text-slate-500">A trilha é append-only e não possui ações de alteração ou exclusão.</p>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[1000px] text-left text-sm">
                <thead class="bg-slate-50 text-xs uppercase text-slate-500"><tr><th class="px-4 py-3">Quando</th><th class="px-4 py-3">Ação</th><th class="px-4 py-3">Usuário</th><th class="px-4 py-3">Paciente</th><th class="px-4 py-3">Atendimento</th><th class="px-4 py-3">IP</th><th class="px-4 py-3">Contexto seguro</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($events as $event)
                        <tr>
                            <td class="whitespace-nowrap px-4 py-3">{{ $event->occurred_at->format('d/m/Y H:i:s') }}</td>
                            <td class="px-4 py-3 font-mono text-xs font-bold">{{ $event->action }}</td>
                            <td class="px-4 py-3">{{ $event->user?->name ?? 'Sistema' }}</td>
                            <td class="px-4 py-3 font-mono text-xs">{{ $event->patient?->medical_record_number ?? '—' }}</td>
                            <td class="px-4 py-3 font-mono text-xs">{{ $event->encounter?->encounter_number ?? '—' }}</td>
                            <td class="px-4 py-3 font-mono text-xs">{{ $event->ip_address ?? '—' }}</td>
                            <td class="max-w-sm px-4 py-3"><code class="break-all text-xs">{{ $event->context === null ? '—' : json_encode($event->context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</code></td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-5 py-10 text-center text-slate-500">Nenhum evento encontrado.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-slate-200 p-4">{{ $events->links() }}</div>
    </section>

    <section class="app-card mt-5 overflow-hidden">
        <div class="border-b border-slate-200 px-5 py-4">
            <h2 class="font-extrabold">Acessos a prontuários</h2>
            <p class="text-xs text-slate-500">Finalidade, usuário, rota, paciente e horário de cada visualização.</p>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[850px] text-left text-sm">
                <thead class="bg-slate-50 text-xs uppercase text-slate-500"><tr><th class="px-4 py-3">Quando</th><th class="px-4 py-3">Usuário</th><th class="px-4 py-3">Paciente</th><th class="px-4 py-3">Tipo</th><th class="px-4 py-3">Finalidade</th><th class="px-4 py-3">Rota</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($accesses as $access)
                        <tr>
                            <td class="whitespace-nowrap px-4 py-3">{{ $access->occurred_at->format('d/m/Y H:i:s') }}</td>
                            <td class="px-4 py-3">{{ $access->user->name }}</td>
                            <td class="px-4 py-3">{{ $access->patient->displayName() }}<br><span class="font-mono text-xs text-slate-500">{{ $access->patient->medical_record_number }}</span></td>
                            <td class="px-4 py-3 font-mono text-xs">{{ $access->access_type }}</td>
                            <td class="px-4 py-3">{{ $access->purpose ?? '—' }}</td>
                            <td class="px-4 py-3 font-mono text-xs">{{ $access->route_name }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-5 py-10 text-center text-slate-500">Nenhum acesso encontrado.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-slate-200 p-4">{{ $accesses->links() }}</div>
    </section>
</x-layout.app>
