@php
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

<x-layout.app title="Requisição de exames">
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div><a href="{{ route('laboratory.orders.index') }}" class="text-sm font-bold text-brand-700">← Requisições</a><h1 class="mt-2 text-2xl font-extrabold">Requisição #{{ $order->id }}</h1><p class="mt-1 text-sm text-slate-500">{{ $order->requested_at->format('d/m/Y H:i') }} · {{ $order->origin === 'reception' ? 'Recepção' : 'Atendimento médico' }}</p></div>
        <span @class(['rounded-lg px-3 py-2 text-sm font-bold', 'bg-yellow-100 text-yellow-800' => $order->status === 'pending', 'bg-red-100 text-red-800' => $order->status === 'cancelled'])>{{ $order->status === 'pending' ? 'Pendente' : 'Cancelada' }}</span>
    </div>

    <div class="grid gap-5 lg:grid-cols-3">
        <section class="app-card p-5 lg:col-span-2">
            <h2 class="text-lg font-extrabold">Exames solicitados</h2>
            <div class="mt-4 divide-y divide-slate-100 rounded-lg border border-slate-200">
                @foreach($order->items as $item)<div class="p-4"><div class="flex flex-wrap justify-between gap-2"><strong class="safe-wrap">{{ $item->external_exam_code }} · {{ $item->exam_name }}</strong><span class="text-xs font-bold uppercase text-slate-500">{{ $item->status }}</span></div>@if($item->preparation)<p class="mt-2 safe-wrap text-sm text-slate-600"><strong>Preparo:</strong> {{ $item->preparation }}</p>@endif</div>@endforeach
            </div>
            <dl class="mt-5 grid gap-4 md:grid-cols-2"><div><dt class="text-xs font-bold uppercase text-slate-500">Prioridade</dt><dd class="mt-1 font-semibold">{{ ucfirst($order->priority) }}</dd></div><div><dt class="text-xs font-bold uppercase text-slate-500">Criada por</dt><dd class="mt-1 font-semibold">{{ $order->createdBy?->name }}</dd></div><div class="md:col-span-2"><dt class="text-xs font-bold uppercase text-slate-500">Indicação clínica</dt><dd class="mt-1 safe-wrap whitespace-pre-line">{{ $order->clinical_indication }}</dd></div>@if($order->notes)<div class="md:col-span-2"><dt class="text-xs font-bold uppercase text-slate-500">Observações</dt><dd class="mt-1 safe-wrap whitespace-pre-line">{{ $order->notes }}</dd></div>@endif</dl>
        </section>
        <aside class="space-y-5">
            <section class="app-card p-5"><h2 class="font-extrabold">Paciente</h2><p class="mt-3 font-bold">{{ $order->encounter->patient->displayName() }}</p><p class="text-sm text-slate-500">{{ $order->encounter->patient->medical_record_number }}</p><p class="mt-2 text-sm text-slate-500">Atendimento {{ $order->encounter->encounter_number }}</p></section>
            <section class="app-card p-5"><h2 class="font-extrabold">Solicitante</h2><p class="mt-3 font-bold">{{ $order->requestedBy?->professionalProfile?->displayName() ?? $order->requestedBy?->name }}</p><p class="text-sm text-slate-500">{{ $order->requestedBy?->professionalProfile?->primaryRegistrationLabel() ?? 'Identificação institucional' }}</p></section>
            <section class="app-card p-5"><h2 class="font-extrabold">Integração Synclab</h2>@forelse($order->laboratoryTransmissions as $transmission)<p class="mt-3 text-sm"><strong>{{ $transmissionLabels[$transmission->status->value] ?? $transmission->status->value }}</strong><br><span class="safe-wrap text-xs text-slate-500">OS {{ $transmission->external_order_number ?? $order->id }}</span>@if($transmission->last_http_status)<br><span class="text-xs text-slate-500">HTTP {{ $transmission->last_http_status }}</span>@endif</p>@empty<p class="mt-3 text-sm text-slate-500">Ainda não preparada para integração.</p>@endforelse</section>
            @if($order->status === 'cancelled')<section class="rounded-xl border border-red-200 bg-red-50 p-5"><h2 class="font-extrabold text-red-800">Cancelamento</h2><p class="mt-2 safe-wrap text-sm text-red-800">{{ $order->cancellation_reason }}</p><p class="mt-2 text-xs text-red-700">{{ $order->cancelled_at?->format('d/m/Y H:i') }} · {{ $order->cancelledBy?->name }}</p></section>@endif
        </aside>
    </div>
</x-layout.app>
