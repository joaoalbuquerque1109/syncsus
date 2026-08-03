@php
    $statusLabels = ['pending' => 'Pendente', 'cancelled' => 'Cancelada'];
    $originLabels = ['reception' => 'Recepção', 'medical' => 'Atendimento médico'];
    $transmissionLabels = [
        'awaiting_configuration' => 'Aguardando configuração',
        'pending' => 'Na fila de envio',
        'sending' => 'Enviando',
        'retrying' => 'Nova tentativa agendada',
        'accepted' => 'Aceita pelo Synclab',
        'rejected' => 'Rejeitada pelo Synclab',
        'manual_review' => 'Requer revisão',
        'cancelled' => 'Cancelada',
    ];
@endphp

<x-layout.app title="Requisições de exames">
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="text-sm font-semibold text-brand-700">{{ $activeHealthUnit->name }}</p>
            <h1 class="text-2xl font-extrabold text-slate-950">Requisições de exames</h1>
            <p class="mt-1 text-sm text-slate-600">Pedidos laboratoriais registrados na recepção e no atendimento médico.</p>
        </div>
        @can('encounters.open')<a href="{{ route('reception.create') }}" class="rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-bold text-white">Nova entrada com exames</a>@endcan
    </div>

    <section class="app-card mb-5 p-5">
        <form method="GET" class="grid gap-4 md:grid-cols-4">
            <div class="md:col-span-2"><label class="field-label" for="q">Buscar</label><input id="q" name="q" value="{{ $filters['q'] ?? '' }}" class="field-control" placeholder="ID, paciente, prontuário ou solicitante"></div>
            <div><label class="field-label" for="status">Status</label><select id="status" name="status" class="field-control"><option value="">Todos</option>@foreach($statusLabels as $value => $label)<option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></div>
            <div><label class="field-label" for="origin">Origem</label><select id="origin" name="origin" class="field-control"><option value="">Todas</option>@foreach($originLabels as $value => $label)<option value="{{ $value }}" @selected(($filters['origin'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></div>
            <div class="md:col-span-4 flex justify-end gap-2"><a href="{{ route('laboratory.orders.index') }}" class="rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-bold">Limpar</a><button class="rounded-lg bg-slate-900 px-4 py-2.5 text-sm font-bold text-white">Filtrar</button></div>
        </form>
    </section>

    <section class="app-card overflow-hidden">
        <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4"><h2 class="font-extrabold">Pedidos encontrados</h2><span class="text-xs font-bold text-slate-500">{{ $orders->total() }} registro(s)</span></div>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[1100px] text-left text-sm">
                <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase text-slate-500"><tr><th class="px-4 py-3">ID</th><th class="px-4 py-3">Data</th><th class="px-4 py-3">Paciente</th><th class="px-4 py-3">Solicitante</th><th class="px-4 py-3">Tipo de exame</th><th class="px-4 py-3">Total</th><th class="px-4 py-3">Status</th><th class="px-4 py-3 text-right">Ações</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($orders as $order)
                        @php
                            $profile = $order->requestedBy?->professionalProfile;
                            $transmission = $order->laboratoryTransmissions->first();
                            $transmissionStatus = $transmission?->status?->value;
                        @endphp
                        <tr class="align-top hover:bg-slate-50/70">
                            <td class="px-4 py-4 font-bold">{{ $order->id }}</td>
                            <td class="px-4 py-4 whitespace-nowrap">{{ $order->requested_at->format('d/m/Y') }}<br><span class="text-slate-500">{{ $order->requested_at->format('H:i') }}</span></td>
                            <td class="safe-wrap px-4 py-4"><strong>{{ $order->encounter->patient->medical_record_number }} · {{ $order->encounter->patient->displayName() }}</strong><br><span class="text-xs text-amber-700">{{ $originLabels[$order->origin] ?? $order->origin }}</span></td>
                            <td class="safe-wrap px-4 py-4">{{ $profile?->institutional_code ?? $order->requestedBy?->getKey() }} · {{ $profile?->displayName() ?? $order->requestedBy?->name }}</td>
                            <td class="px-4 py-4"><span class="rounded-md bg-blue-50 px-2 py-1 text-xs font-bold text-blue-700">Laboratorial</span></td>
                            <td class="px-4 py-4 font-bold">{{ $order->items->count() }}</td>
                            <td class="px-4 py-4">
                                <span @class(['rounded-md px-2 py-1 text-xs font-bold', 'bg-yellow-100 text-yellow-800' => $order->status === 'pending', 'bg-red-100 text-red-800' => $order->status === 'cancelled'])>{{ $statusLabels[$order->status] ?? $order->status }}</span>
                                @if($transmissionStatus)<p class="mt-2 text-[0.68rem] text-slate-500">Integração: {{ $transmissionLabels[$transmissionStatus] ?? $transmissionStatus }}</p>@endif
                            </td>
                            <td class="px-4 py-4 text-right" x-data="{ cancelling: false }">
                                <div class="flex justify-end gap-1"><a href="{{ route('laboratory.orders.show', $order) }}" class="rounded-l-lg bg-brand-600 px-3 py-2 text-xs font-bold text-white" title="Visualizar">Visualizar</a>@can('laboratory.orders.cancel')@if($order->status === 'pending')<button type="button" @click="cancelling = !cancelling" class="grid size-9 place-items-center rounded-r-lg bg-red-600 text-white" title="Cancelar requisição" aria-label="Cancelar requisição"><x-icons.trash /></button>@endif @endcan</div>
                                <form x-show="cancelling" x-cloak method="POST" action="{{ route('laboratory.orders.cancel', $order) }}" class="mt-3 w-72 rounded-lg border border-red-200 bg-white p-3 text-left shadow-lg">@csrf<textarea name="reason" required minlength="10" rows="2" class="field-control" placeholder="Motivo do cancelamento"></textarea><label class="mt-2 flex gap-2 text-xs"><input type="checkbox" name="confirmation" value="1" required> Confirmo o cancelamento</label><button class="mt-2 w-full rounded-lg bg-red-700 px-3 py-2 text-xs font-bold text-white">Confirmar</button></form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-5 py-12 text-center text-slate-500">Nenhuma requisição corresponde aos filtros.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($orders->hasPages())<div class="border-t border-slate-200 px-5 py-4">{{ $orders->links() }}</div>@endif
    </section>
</x-layout.app>
