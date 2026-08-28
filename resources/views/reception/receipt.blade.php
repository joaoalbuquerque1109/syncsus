@php($queueEntry = $encounter->queueEntries->first())
<x-layout.app title="Comprovante de atendimento">
    <div class="mx-auto max-w-3xl">
        <div class="mb-5 flex flex-wrap items-center justify-between gap-3 print:hidden">
            <div><p class="text-sm font-semibold text-brand-700">Recepção concluída</p><h1 class="text-2xl font-extrabold text-slate-950">Comprovante</h1></div>
            <div class="flex gap-3"><button type="button" onclick="window.print()" class="rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold">Imprimir</button><a href="{{ route('reception.create') }}" class="rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-bold text-white">Nova recepção</a></div>
        </div>
        <x-card class="overflow-hidden">
            <div class="bg-navy-950 p-6 text-white">
                <p class="text-sm font-bold uppercase tracking-widest text-brand-100">{{ $encounter->healthUnit->name }}</p>
                <div class="mt-4 flex items-end justify-between gap-4">
                    <div><p class="text-sm text-slate-300">Senha de atendimento</p><p class="text-5xl font-black tracking-wider">{{ $queueEntry?->ticket_number }}</p></div>
                    <p class="font-mono text-sm">{{ $encounter->encounter_number }}</p>
                </div>
            </div>
            <div class="p-6">
                <dl class="grid gap-5 sm:grid-cols-2">
                    <div class="sm:col-span-2"><dt class="text-xs font-bold uppercase text-slate-500">Paciente</dt><dd class="mt-1 text-xl font-extrabold">{{ $encounter->patient->displayName() }}</dd><dd class="text-sm text-slate-500">{{ $encounter->patient->medical_record_number }}</dd></div>
                    <div><dt class="text-xs font-bold uppercase text-slate-500">Chegada</dt><dd class="mt-1 font-semibold">{{ $encounter->arrival_at->format('d/m/Y H:i') }}</dd></div>
                    <div><dt class="text-xs font-bold uppercase text-slate-500">Destino</dt><dd class="mt-1 font-semibold">{{ $encounter->currentDepartment?->name }}</dd></div>
                    <div><dt class="text-xs font-bold uppercase text-slate-500">Fila</dt><dd class="mt-1 font-semibold">{{ $queueEntry?->queue?->name }}</dd></div>
                    <div><dt class="text-xs font-bold uppercase text-slate-500">Forma de chegada</dt><dd class="mt-1 font-semibold">{{ $encounter->arrivalMethod->name }}</dd></div>
                </dl>
                <div class="mt-6 rounded-lg bg-amber-50 p-4 text-sm text-amber-900"><strong>Atenção:</strong> aguarde a chamada pelo painel e mantenha este comprovante em mãos.</div>
            </div>
        </x-card>
        @if(!$encounter->currentStatusEnum()->isFinal() && $canCancelEncounter)
            <x-card class="mt-5 border-red-200 p-5 print:hidden">
                <h2 class="font-extrabold text-red-800">{{ $isPlatformAdministrator ? 'Encerrar atendimento' : 'Cancelar atendimento' }}</h2>
                <p class="mt-1 text-sm text-slate-600">O paciente sai das filas ativas e o motivo fica registrado na auditoria. Se ele não foi localizado, use a opção "Paciente não encontrado".</p>
                <button
                    type="button"
                    class="mt-4 rounded-lg bg-red-700 px-4 py-2.5 text-sm font-bold text-white"
                    @click="$store.encounterActionModal.openFor({
                        title: @js('Atendimento '.$encounter->encounter_number),
                        canTransfer: false,
                        canCancel: true,
                        isAdmin: @js($isPlatformAdministrator),
                        cancelUrl: @js(route('reception.cancel', $encounter)),
                        encounterVersion: {{ $encounter->lock_version }},
                    })"
                >{{ $isPlatformAdministrator ? 'Encerrar atendimento' : 'Cancelar atendimento' }}</button>
            </x-card>
        @elseif($encounter->currentStatusEnum() === \App\Modules\Reception\Domain\Enums\EncounterStatus::Cancelled)
            <x-alert type="danger" class="mt-5">Atendimento cancelado: {{ $encounter->cancellation_reason }}</x-alert>
        @elseif($encounter->currentStatusEnum() === \App\Modules\Reception\Domain\Enums\EncounterStatus::LeftWithoutNotice)
            <x-alert type="danger" class="mt-5">Paciente não encontrado: {{ $encounter->cancellation_reason }}</x-alert>
        @endif
    </div>
</x-layout.app>
