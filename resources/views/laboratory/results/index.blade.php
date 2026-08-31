@php
    $itemStatusLabels = ['requested' => 'Pendente', 'resulted' => 'Pronto', 'cancelled' => 'Cancelado'];
    $itemStatusClasses = [
        'requested' => 'bg-yellow-100 text-yellow-800',
        'resulted' => 'bg-emerald-100 text-emerald-800',
        'cancelled' => 'bg-red-100 text-red-800',
    ];
@endphp

<x-layout.app title="Resultados de exames">
    <div class="mb-6">
        <p class="text-sm font-semibold text-brand-700">{{ $activeHealthUnit->name }}</p>
        <h1 class="text-2xl font-extrabold text-slate-950">Resultados de exames</h1>
        <p class="mt-1 text-sm text-slate-600">Busque por paciente para acompanhar o andamento e abrir os laudos já prontos.</p>
    </div>

    <section class="app-card mb-5 p-5">
        <form method="GET" class="grid gap-4 md:grid-cols-4">
            <div class="md:col-span-2">
                <label class="field-label" for="patient">Paciente</label>
                <input id="patient" name="patient" value="{{ $filters['patient'] ?? '' }}" class="field-control" placeholder="Nome ou CPF">
            </div>
            <div><label class="field-label" for="date_from">Data inicial</label><input type="date" id="date_from" name="date_from" value="{{ $filters['date_from'] ?? '' }}" class="field-control"></div>
            <div><label class="field-label" for="date_to">Data final</label><input type="date" id="date_to" name="date_to" value="{{ $filters['date_to'] ?? '' }}" class="field-control"></div>
            <div class="md:col-span-4 flex justify-end gap-2">
                <a href="{{ route('laboratory.results.index') }}" class="rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-bold">Limpar</a>
                <button class="rounded-lg bg-slate-900 px-4 py-2.5 text-sm font-bold text-white">Filtrar</button>
            </div>
        </form>
    </section>

    <section class="app-card overflow-hidden">
        <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4">
            <h2 class="font-extrabold">Pedidos encontrados</h2>
            <span class="text-xs font-bold text-slate-500">{{ $orders->total() }} registro(s)</span>
        </div>
        <div class="divide-y divide-slate-100">
            @forelse($orders as $order)
                @php $profile = $order->requestedBy?->professionalProfile; @endphp
                <article class="p-5">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <strong class="text-slate-900">{{ $order->encounter->patient?->medical_record_number }} · {{ $order->encounter->patient?->displayName() }}</strong>
                            <p class="mt-1 text-xs text-slate-500">Solicitado por {{ $profile?->displayName() ?? $order->requestedBy?->name }} em {{ $order->requested_at->format('d/m/Y H:i') }}</p>
                        </div>
                        <a href="{{ route('laboratory.orders.show', $order) }}" class="rounded-lg border border-slate-300 px-3 py-2 text-xs font-bold text-slate-700">Ver pedido</a>
                    </div>
                    <div class="mt-4 grid gap-2">
                        @foreach($order->items as $item)
                            <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg bg-slate-50 px-4 py-3">
                                <span class="font-semibold text-slate-800">{{ $item->exam_name }}</span>
                                <div class="flex items-center gap-3">
                                    <span @class(['inline-flex whitespace-nowrap rounded-md px-2 py-1 text-xs font-bold', $itemStatusClasses[$item->status] ?? 'bg-slate-200 text-slate-700'])>{{ $itemStatusLabels[$item->status] ?? $item->status }}</span>
                                    @if($item->result?->result_pdf_path)
                                        <a href="{{ route('laboratory.results.print', $item->result) }}" target="_blank" rel="noopener" class="rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-bold text-white">Imprimir</a>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </article>
            @empty
                <p class="px-5 py-12 text-center text-slate-500">Nenhum resultado corresponde aos filtros.</p>
            @endforelse
        </div>
        @if($orders->hasPages())<div class="border-t border-slate-200 px-5 py-4">{{ $orders->links() }}</div>@endif
    </section>
</x-layout.app>
