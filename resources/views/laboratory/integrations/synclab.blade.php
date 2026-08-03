@php
    $labels = [
        'awaiting_configuration' => 'Aguardando revisão',
        'pending' => 'Na fila',
        'sending' => 'Enviando',
        'retrying' => 'Nova tentativa',
        'accepted' => 'Aceita',
        'rejected' => 'Rejeitada',
        'manual_review' => 'Revisão manual',
        'cancelled' => 'Cancelada',
    ];
@endphp

<x-layout.app title="Integração Synclab">
    <x-page-header
        eyebrow="Administração · {{ $activeHealthUnit->name }}"
        title="Integração Synclab"
        description="Configuração segura e acompanhamento do envio de requisições laboratoriais desta unidade."
    />

    @if(! $globalEnabled)
        <div class="mb-5 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
            <strong>Envio global desativado.</strong>
            Defina <code>SYNC_SUS_SYNCLAB_ENABLED=true</code> no ambiente e limpe o cache de configuração.
        </div>
    @endif

    <div class="grid gap-5 xl:grid-cols-3">
        <section class="app-card p-5 xl:col-span-2">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-lg font-extrabold">Configuração da unidade</h2>
                    <p class="mt-1 text-sm text-slate-500">As credenciais são armazenadas criptografadas e nunca são exibidas novamente.</p>
                </div>
                <span @class([
                    'rounded-lg px-3 py-2 text-xs font-extrabold uppercase',
                    'bg-emerald-100 text-emerald-800' => $integration->connection_status === 'connected',
                    'bg-blue-100 text-blue-800' => in_array($integration->connection_status, ['configured', 'not_tested'], true),
                    'bg-red-100 text-red-800' => $integration->connection_status === 'error',
                    'bg-slate-100 text-slate-700' => ! in_array($integration->connection_status, ['connected', 'configured', 'not_tested', 'error'], true),
                ])>{{ $integration->connection_status ?? 'não configurada' }}</span>
            </div>

            <form method="POST" action="{{ route('administration.synclab.update') }}" class="mt-6 grid gap-4 md:grid-cols-2">
                @csrf
                @method('PUT')
                <div class="md:col-span-2">
                    <label class="field-label" for="base_url">URL HTTPS</label>
                    <input id="base_url" name="base_url" required class="field-control" value="{{ old('base_url', $integration->base_url ?: config('sync_sus.synclab.base_url')) }}">
                    @error('base_url')<p class="field-error">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="field-label" for="cnes_code">CNES da unidade</label>
                    <input id="cnes_code" name="cnes_code" required inputmode="numeric" maxlength="7" class="field-control" value="{{ old('cnes_code', $activeHealthUnit->cnes_code) }}">
                    @error('cnes_code')<p class="field-error">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="field-label">Credenciais atuais</label>
                    <div class="field-control flex items-center bg-slate-50 text-sm font-semibold">
                        {{ $integration->hasCredentials() ? 'Configuradas e criptografadas' : 'Não configuradas' }}
                    </div>
                </div>
                <div>
                    <label class="field-label" for="username">Novo usuário</label>
                    <input id="username" name="username" autocomplete="off" class="field-control" value="{{ old('username') }}" placeholder="Deixe vazio para manter o atual">
                    @error('username')<p class="field-error">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="field-label" for="password">Nova senha</label>
                    <input id="password" name="password" type="password" autocomplete="new-password" class="field-control" placeholder="Deixe vazio para manter a atual">
                    @error('password')<p class="field-error">{{ $message }}</p>@enderror
                </div>
                <label class="md:col-span-2 flex items-start gap-3 rounded-xl border border-slate-200 p-4">
                    <input type="checkbox" name="transmission_enabled" value="1" class="mt-1" @checked(old('transmission_enabled', $integration->transmission_enabled))>
                    <span><strong class="block">Habilitar envio de novas requisições</strong><span class="text-sm text-slate-500">Pedidos antigos não serão enviados automaticamente; revise e reenvie individualmente.</span></span>
                </label>
                <div class="md:col-span-2 flex justify-end">
                    <button class="rounded-lg bg-brand-600 px-5 py-3 text-sm font-extrabold text-white">Salvar configuração</button>
                </div>
            </form>
        </section>

        <aside class="space-y-5">
            <section class="app-card p-5">
                <h2 class="font-extrabold">Diagnóstico</h2>
                <dl class="mt-4 space-y-3 text-sm">
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Configuração global</dt><dd class="font-bold">{{ $globalEnabled ? 'Ativa' : 'Inativa' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Envio da unidade</dt><dd class="font-bold">{{ $integration->transmission_enabled ? 'Ativo' : 'Inativo' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Credenciais</dt><dd class="font-bold">{{ $integration->hasCredentials() ? 'Presentes' : 'Ausentes' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Exames ativos</dt><dd class="font-bold">{{ $catalogTotal }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Procedimentos SUS</dt><dd class="font-bold">{{ $catalogMapped }} / {{ $catalogTotal }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Último contato</dt><dd class="font-bold">{{ $integration->last_connection_test_at?->format('d/m/Y H:i') ?? 'Nunca' }}</dd></div>
                </dl>
                @if($integration->last_error)<p class="mt-4 safe-wrap rounded-lg bg-red-50 p-3 text-xs text-red-800">{{ $integration->last_error }}</p>@endif
            </section>
            <section class="app-card p-5">
                <h2 class="font-extrabold">Situação das transmissões</h2>
                <div class="mt-4 space-y-2 text-sm">
                    @forelse($statusCounts as $status => $total)
                        <div class="flex justify-between"><span>{{ $labels[$status] ?? $status }}</span><strong>{{ $total }}</strong></div>
                    @empty
                        <p class="text-slate-500">Nenhuma transmissão registrada.</p>
                    @endforelse
                </div>
            </section>
        </aside>
    </div>

    <section class="app-card mt-5 overflow-hidden">
        <div class="border-b border-slate-200 px-5 py-4"><h2 class="font-extrabold">Últimas requisições</h2></div>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[850px] text-left text-sm">
                <thead class="bg-slate-50 text-xs uppercase text-slate-500"><tr><th class="px-4 py-3">OS</th><th class="px-4 py-3">Paciente</th><th class="px-4 py-3">Status</th><th class="px-4 py-3">Tentativas</th><th class="px-4 py-3">Última atualização</th><th class="px-4 py-3"></th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($recentTransmissions as $transmission)
                        <tr><td class="px-4 py-3 font-bold">{{ $transmission->external_order_number ?? $transmission->exam_order_id }}</td><td class="safe-wrap px-4 py-3">{{ $transmission->order->encounter->patient->displayName() }}</td><td class="px-4 py-3">{{ $labels[$transmission->status->value] ?? $transmission->status->value }}</td><td class="px-4 py-3">{{ $transmission->attempt_count }}</td><td class="px-4 py-3">{{ $transmission->updated_at->format('d/m/Y H:i') }}</td><td class="px-4 py-3 text-right"><a class="font-bold text-brand-700" href="{{ route('laboratory.orders.show', $transmission->order) }}">Detalhes</a></td></tr>
                    @empty
                        <tr><td colspan="6" class="px-5 py-10 text-center text-slate-500">Nenhuma requisição registrada.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</x-layout.app>
