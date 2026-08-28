<x-layout.app title="Filas e chamadas">
    @if(!$selectedQueue)
        <x-card class="p-8 text-center">
            <h1 class="text-xl font-extrabold">Nenhuma fila ativa</h1>
            <p class="mt-2 text-slate-600">Configure uma fila para esta unidade antes de iniciar as chamadas.</p>
        </x-card>
    @else
        <div
            x-data="queueBoard({
                entriesUrl: @js(route('queues.entries', $selectedQueue)),
                pollMs: {{ max(2, (int) config('sync_sus.queue_poll_seconds', 5)) * 1000 }},
                defaultServicePoint: @js($selectedQueue->servicePoints->first()?->public_id),
                actions: {
                    call: @js(route('queue-entries.call', ['entry' => '__ENTRY__'])),
                    recall: @js(route('queue-entries.recall', ['entry' => '__ENTRY__'])),
                    start: @js(match($selectedQueue->department->type) {
                        'triage' => route('triage.start', ['entry' => '__ENTRY__']),
                        'medical' => route('medical.start', ['entry' => '__ENTRY__']),
                        default => route('queue-entries.start', ['entry' => '__ENTRY__']),
                    }),
                    absent: @js(route('queue-entries.absent', ['entry' => '__ENTRY__'])),
                    return: @js(route('queue-entries.return', ['entry' => '__ENTRY__'])),
                    transfer: @js(route('queue-entries.transfer', ['entry' => '__ENTRY__']))
                },
                encounterCancelUrl: @js(route('reception.cancel', ['encounter' => '__ENCOUNTER__'])),
                destinationQueues: @js($queues->reject(fn ($queue) => $queue->is($selectedQueue))->map(fn ($queue) => ['id' => $queue->public_id, 'name' => $queue->name])->values()),
                isAdmin: @js(auth()->user()->isPlatformAdministrator())
            })"
        >
            <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-sm font-semibold text-brand-700">{{ $activeHealthUnit->name }}</p>
                    <h1 class="text-2xl font-extrabold text-slate-950">Filas e chamadas</h1>
                    <p class="mt-1 text-sm text-slate-600">{{ $selectedQueue->department->name }} · atualização automática</p>
                    @if($restrictedOperationalView)
                        <p class="mt-1 text-xs font-semibold text-brand-700">Exibindo somente suas filas e pontos de atendimento autorizados.</p>
                    @endif
                </div>
                <div class="flex flex-wrap gap-2">
                    @foreach($panels as $panel)
                        <a href="{{ route('panels.show', $panel) }}" target="_blank" rel="noopener" class="inline-flex items-center rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold hover:bg-slate-100">
                            Abrir {{ $panel->name }}
                        </a>
                    @endforeach
                    <button type="button" @click="refresh()" class="inline-flex items-center rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-bold text-white">Atualizar</button>
                </div>
            </div>

            <div class="mb-5 flex gap-2 overflow-x-auto pb-1">
                @foreach($queues as $queue)
                    <a href="{{ route('queues.index', ['queue' => $queue->public_id]) }}" @class([
                        'whitespace-nowrap rounded-lg border px-4 py-2.5 text-sm font-bold',
                        'border-brand-600 bg-brand-600 text-white' => $queue->is($selectedQueue),
                        'border-slate-300 bg-white text-slate-700 hover:bg-slate-100' => !$queue->is($selectedQueue),
                    ])>{{ $queue->name }}</a>
                @endforeach
            </div>

            <x-card class="mb-5 p-5">
                <div class="grid gap-4 lg:grid-cols-4">
                    <div class="lg:col-span-2">
                        <label class="field-label" for="queue-search">Buscar na fila</label>
                        <input id="queue-search" class="field-control" x-model="query" @input.debounce.400ms="refresh()" placeholder="Senha, nome, prontuário, CPF ou CNS">
                    </div>
                    <div>
                        <label class="field-label" for="queue-status">Situação</label>
                        <select id="queue-status" class="field-control" x-model="status" @change="refresh()">
                            <option value="">Ativos e ausentes</option>
                            <option value="waiting">Aguardando</option>
                            <option value="called">Chamados</option>
                            <option value="in_service">Em atendimento</option>
                            <option value="absent">Não localizados</option>
                        </select>
                    </div>
                    <div>
                        <label class="field-label" for="service-point">Ponto de atendimento</label>
                        @if($selectedQueue->servicePoints->count() === 1)
                            @php($point = $selectedQueue->servicePoints->first())
                            <div id="service-point" class="field-control flex items-center bg-slate-50 font-semibold text-slate-700">{{ $point->name }} · {{ $point->room->name }}</div>
                        @else
                            <select id="service-point" class="field-control" x-model="servicePoint">
                                <option value="">Selecione</option>
                                @foreach($selectedQueue->servicePoints as $point)
                                    <option value="{{ $point->public_id }}">{{ $point->name }} · {{ $point->room->name }}</option>
                                @endforeach
                            </select>
                        @endif
                    </div>
                </div>
            </x-card>

            <div x-show="message" x-cloak class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-bold text-emerald-800" x-text="message"></div>
            <div
                x-show="error"
                x-cloak
                role="alert"
                data-minimum-visible-ms="5000"
                class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm font-bold text-red-800"
                x-text="error"
            ></div>

            <x-card class="overflow-hidden">
                <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4">
                    <h2 class="font-extrabold text-slate-900">{{ $selectedQueue->name }}</h2>
                    <div class="text-right text-xs text-slate-500">
                        <span class="font-bold" x-text="`${entries.length} registros`"></span>
                        <span class="ml-2" x-show="updatedAt" x-text="`Atualizada às ${updatedAt}`"></span>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[1050px] text-left text-sm">
                        <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-4 py-3">Senha</th>
                                <th class="px-4 py-3">Paciente</th>
                                <th class="px-4 py-3">Idade</th>
                                <th class="px-4 py-3">Chegada</th>
                                <th class="px-4 py-3">Espera</th>
                                <th class="px-4 py-3">Chamadas</th>
                                <th class="px-4 py-3">Situação</th>
                                <th class="px-4 py-3 text-right">Ações</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <template x-for="entry in entries" :key="entry.public_id">
                                <tr class="hover:bg-brand-50/30">
                                    <td class="relative py-4 pl-6 pr-4 text-lg font-black text-slate-950">
                                        <span
                                            class="absolute inset-y-0 left-0 w-2"
                                            :class="{
                                                'bg-red-600': entry.risk_color === 'red',
                                                'bg-orange-500': entry.risk_color === 'orange',
                                                'bg-yellow-400': entry.risk_color === 'yellow',
                                                'bg-green-600': entry.risk_color === 'green',
                                                'bg-blue-600': entry.risk_color === 'blue',
                                                'bg-slate-300': !entry.risk_color
                                            }"
                                            :title="entry.risk ? `Prioridade ${entry.risk}` : 'Prioridade ainda não classificada'"
                                            :aria-label="entry.risk ? `Prioridade ${entry.risk}` : 'Prioridade ainda não classificada'"
                                            role="img"
                                        ></span>
                                        <span x-text="entry.ticket"></span>
                                    </td>
                                    <td class="px-4 py-4">
                                        <strong class="block text-slate-900" x-text="entry.patient"></strong>
                                        <span class="text-xs text-slate-500" x-text="`${entry.medical_record_number} · ${entry.arrival_method}`"></span>
                                    </td>
                                    <td class="px-4 py-4" x-text="entry.age === null ? '—' : `${entry.age} anos`"></td>
                                    <td class="px-4 py-4" x-text="entry.entered_at"></td>
                                    <td class="px-4 py-4 font-semibold" x-text="`${entry.waiting_minutes} min`"></td>
                                    <td class="px-4 py-4" x-text="entry.call_count"></td>
                                    <td class="px-4 py-4">
                                        <span class="rounded-full px-2.5 py-1 text-xs font-bold" :class="{
                                            'bg-blue-100 text-blue-800': entry.status === 'waiting',
                                            'bg-amber-100 text-amber-800': entry.status === 'called',
                                            'bg-emerald-100 text-emerald-800': entry.status === 'in_service',
                                            'bg-slate-200 text-slate-700': entry.status === 'absent'
                                        }" x-text="entry.status_label"></span>
                                        <small class="mt-1 block text-slate-500" x-show="entry.service_point" x-text="entry.service_point"></small>
                                    </td>
                                    <td class="px-4 py-4">
                                        <div class="flex justify-end gap-2">
                                            <a x-show="entry.can_edit" x-cloak :href="entry.edit_url" class="rounded-lg bg-brand-600 px-3 py-2 text-xs font-bold text-white hover:bg-brand-700">Editar</a>
                                            @can('queues.call')
                                                <button type="button" x-show="entry.can_call" @click="call(entry)" :disabled="busyEntry === entry.public_id" class="rounded-lg border border-brand-300 px-3 py-2 text-xs font-bold text-brand-700 hover:bg-brand-50 disabled:opacity-50">Chamar</button>
                                                <button type="button" x-show="entry.can_recall" @click="call(entry, true)" :disabled="busyEntry === entry.public_id" class="rounded-lg border border-brand-300 px-3 py-2 text-xs font-bold text-brand-700 hover:bg-brand-50 disabled:opacity-50">Rechamar</button>
                                                <button type="button" x-show="entry.can_start" @click="perform(entry, 'start')" :disabled="busyEntry === entry.public_id" class="rounded-lg bg-emerald-600 px-3 py-2 text-xs font-bold text-white disabled:opacity-50">Iniciar</button>
                                                <button type="button" x-show="entry.can_absent" @click="markAbsent(entry)" :disabled="busyEntry === entry.public_id" class="rounded-lg border border-slate-300 px-3 py-2 text-xs font-bold disabled:opacity-50">Ausência</button>
                                                <button type="button" x-show="entry.can_return" @click="returnEntry(entry)" :disabled="busyEntry === entry.public_id" class="rounded-lg border border-slate-300 px-3 py-2 text-xs font-bold disabled:opacity-50">Retornar</button>
                                            @endcan
                                            <button type="button" x-show="entry.can_transfer || entry.can_cancel_encounter" @click="openActionModal(entry)" :disabled="busyEntry === entry.public_id" class="rounded-lg border border-slate-300 px-3 py-2 text-xs font-bold disabled:opacity-50">Transferir</button>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                            <tr x-show="!loading && entries.length === 0"><td colspan="8" class="px-5 py-12 text-center text-slate-500">Nenhuma entrada corresponde aos filtros.</td></tr>
                            <tr x-show="loading && entries.length === 0"><td colspan="8" class="px-5 py-12 text-center text-slate-500">Atualizando fila...</td></tr>
                        </tbody>
                    </table>
                </div>
            </x-card>
        </div>
    @endif
</x-layout.app>
