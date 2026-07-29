<x-layout.app title="Operação e continuidade">
    <div class="mb-6">
        <p class="text-sm font-semibold text-brand-700">Administração</p>
        <h1 class="text-2xl font-extrabold text-slate-950">Operação e continuidade</h1>
        <p class="mt-1 text-sm text-slate-500">Situação de backups, verificações, filas assíncronas e painéis.</p>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <section class="app-card p-5"><p class="text-sm text-slate-500">Último backup</p><p class="mt-2 text-lg font-extrabold">{{ $latestBackup?->status ?? 'Sem registro' }}</p><p class="text-xs text-slate-500">{{ $latestBackup?->finished_at?->format('d/m/Y H:i') ?? '—' }}</p></section>
        <section class="app-card p-5"><p class="text-sm text-slate-500">Última verificação</p><p class="mt-2 text-lg font-extrabold">{{ $latestVerification?->status ?? 'Sem registro' }}</p><p class="text-xs text-slate-500">{{ $latestVerification?->finished_at?->format('d/m/Y H:i') ?? '—' }}</p></section>
        <section class="app-card p-5"><p class="text-sm text-slate-500">Jobs</p><p class="mt-2 text-3xl font-black">{{ $pendingJobs }}</p><p class="text-xs text-red-700">{{ $failedJobs }} falhos</p></section>
        <section class="app-card p-5"><p class="text-sm text-slate-500">Painéis conectados</p><p class="mt-2 text-3xl font-black">{{ $connectedPanels }}</p><p class="text-xs text-slate-500">heartbeat no último minuto</p></section>
    </div>

    <section class="app-card mt-5 p-5">
        <h2 class="font-extrabold">Armazenamento privado</h2>
        <p class="mt-2 text-sm text-slate-600">{{ $freeStorageBytes === null ? 'Espaço indisponível' : number_format($freeStorageBytes / 1073741824, 2, ',', '.').' GB livres' }}</p>
        <p class="mt-3 text-xs text-slate-500">A verificação de conjunto é executada no servidor com <code>php artisan sync-sus:backup-verify /backups/CONJUNTO --actor=admin@instituicao.local</code>.</p>
    </section>

    <div class="mt-5 grid gap-5 xl:grid-cols-2">
        <section class="app-card overflow-hidden">
            <div class="border-b border-slate-200 px-5 py-4"><h2 class="font-extrabold">Execuções de backup</h2></div>
            <div class="overflow-x-auto"><table class="w-full min-w-[620px] text-left text-sm">
                <thead class="bg-slate-50 text-xs uppercase text-slate-500"><tr><th class="px-4 py-3">Início</th><th class="px-4 py-3">Status</th><th class="px-4 py-3">Tamanho</th><th class="px-4 py-3">Hash</th></tr></thead>
                <tbody class="divide-y divide-slate-100">@forelse($backups as $backup)<tr><td class="px-4 py-3">{{ $backup->started_at->format('d/m/Y H:i') }}</td><td class="px-4 py-3 font-bold">{{ $backup->status }}</td><td class="px-4 py-3">{{ $backup->size_bytes === null ? '—' : number_format($backup->size_bytes / 1048576, 1, ',', '.').' MB' }}</td><td class="max-w-44 truncate px-4 py-3 font-mono text-xs">{{ $backup->sha256 ?? '—' }}</td></tr>@empty<tr><td colspan="4" class="px-4 py-8 text-center text-slate-500">Sem execuções registradas.</td></tr>@endforelse</tbody>
            </table></div>
        </section>
        <section class="app-card overflow-hidden">
            <div class="border-b border-slate-200 px-5 py-4"><h2 class="font-extrabold">Verificações de integridade</h2></div>
            <div class="overflow-x-auto"><table class="w-full min-w-[620px] text-left text-sm">
                <thead class="bg-slate-50 text-xs uppercase text-slate-500"><tr><th class="px-4 py-3">Conjunto</th><th class="px-4 py-3">Status</th><th class="px-4 py-3">Responsável</th><th class="px-4 py-3">Conclusão</th></tr></thead>
                <tbody class="divide-y divide-slate-100">@forelse($verifications as $verification)<tr><td class="px-4 py-3 font-mono text-xs">{{ $verification->backup_set }}</td><td class="px-4 py-3 font-bold">{{ $verification->status }}</td><td class="px-4 py-3">{{ $verification->verifiedBy?->name ?? 'Rotina automática' }}</td><td class="px-4 py-3">{{ $verification->finished_at?->format('d/m/Y H:i') ?? 'Em andamento' }}</td></tr>@empty<tr><td colspan="4" class="px-4 py-8 text-center text-slate-500">Sem verificações registradas.</td></tr>@endforelse</tbody>
            </table></div>
        </section>
    </div>
</x-layout.app>
