<x-layout.app title="Configuração de filas e painéis">
    <div class="mb-6">
        <p class="text-sm font-semibold text-brand-700">Administração · {{ $activeHealthUnit->name }}</p>
        <h1 class="text-2xl font-extrabold text-slate-950">Filas e painéis</h1>
        <p class="mt-1 text-sm text-slate-600">Defina regras operacionais, pontos permitidos e privacidade do painel.</p>
    </div>

    @if($errors->any())<x-alert type="danger" class="mb-5">Revise os campos informados. Nenhuma configuração inválida foi gravada.</x-alert>@endif

    <div class="space-y-6">
        <section>
            <h2 class="mb-3 text-lg font-extrabold text-slate-900">Estrutura da unidade</h2>
            <div class="grid gap-5 xl:grid-cols-3">
                <x-card class="p-5">
                    <h3 class="font-extrabold">Novo setor</h3>
                    <form method="POST" action="{{ route('administration.flow.departments.store') }}" class="mt-4 space-y-3">
                        @csrf
                        <x-form.input name="code" label="Codigo" required />
                        <x-form.input name="name" label="Nome" required />
                        <x-form.select name="type" label="Tipo" required>
                            <option value="administrative">Administrativo</option>
                            <option value="triage">Triagem</option>
                            <option value="medical">Medico</option>
                            <option value="observation">Observacao</option>
                            <option value="diagnostic">Diagnostico</option>
                            <option value="procedure">Procedimento</option>
                        </x-form.select>
                        <x-form.input name="display_order" label="Ordem" type="number" min="0" value="100" />
                        <label class="flex gap-2 text-sm font-bold"><input type="checkbox" name="is_clinical" value="1"> Setor clinico</label>
                        <x-button.primary>Criar setor</x-button.primary>
                    </form>
                </x-card>
                <x-card class="p-5">
                    <h3 class="font-extrabold">Nova sala</h3>
                    <form method="POST" action="{{ route('administration.flow.rooms.store') }}" class="mt-4 space-y-3">
                        @csrf
                        <x-form.select name="department_id" label="Setor" required>
                            @foreach($departments as $department)<option value="{{ $department->id }}">{{ $department->name }}</option>@endforeach
                        </x-form.select>
                        <x-form.input name="code" label="Codigo" required />
                        <x-form.input name="name" label="Nome" required />
                        <x-form.input name="room_type" label="Tipo da sala" required value="clinical" />
                        <x-button.primary>Criar sala</x-button.primary>
                    </form>
                </x-card>
                <x-card class="p-5">
                    <h3 class="font-extrabold">Novo ponto de atendimento</h3>
                    <form method="POST" action="{{ route('administration.flow.service-points.store') }}" class="mt-4 space-y-3">
                        @csrf
                        <x-form.select name="room_id" label="Sala" required>
                            @foreach($rooms as $room)<option value="{{ $room->id }}">{{ $room->department->name }} / {{ $room->name }}</option>@endforeach
                        </x-form.select>
                        <x-form.input name="code" label="Codigo" required />
                        <x-form.input name="name" label="Nome" required />
                        <x-form.input name="type" label="Tipo" required value="clinical" />
                        <x-button.primary>Criar ponto</x-button.primary>
                    </form>
                </x-card>
            </div>
        </section>

        <section>
            <h2 class="mb-3 text-lg font-extrabold text-slate-900">Novos fluxos</h2>
            <div class="grid gap-5 xl:grid-cols-2">
                <x-card class="p-5">
                    <h3 class="font-extrabold">Nova fila</h3>
                    <form method="POST" action="{{ route('administration.flow.queues.store') }}" class="mt-4 space-y-3">
                        @csrf
                        <div class="grid gap-3 sm:grid-cols-2">
                            <x-form.select name="department_id" label="Setor" required>
                                @foreach($departments->where('is_clinical', true) as $department)<option value="{{ $department->id }}">{{ $department->name }}</option>@endforeach
                            </x-form.select>
                            <x-form.select name="specialty_id" label="Especialidade">
                                <option value="">Nao aplicavel</option>
                                @foreach($specialties as $specialty)<option value="{{ $specialty->id }}">{{ $specialty->name }}</option>@endforeach
                            </x-form.select>
                            <x-form.input name="code" label="Codigo" required />
                            <x-form.input name="name" label="Nome" required />
                            <x-form.input name="prefix" label="Prefixo" required maxlength="8" />
                            <x-form.input name="ticket_length" label="Digitos" type="number" min="1" max="8" value="3" required />
                            <x-form.select name="priority_strategy" label="Ordenacao" required>
                                <option value="priority_fifo">Prioridade e chegada</option>
                                <option value="fifo">Ordem de chegada</option>
                            </x-form.select>
                            <x-form.input name="minimum_calls_before_absent" label="Chamadas minimas" type="number" min="1" max="10" value="1" required />
                        </div>
                        <fieldset>
                            <legend class="field-label">Pontos permitidos</legend>
                            <div class="grid gap-2 sm:grid-cols-2">
                                @foreach($servicePoints as $point)
                                    <label class="flex gap-2 rounded-lg border p-2 text-sm"><input type="checkbox" name="service_points[]" value="{{ $point->id }}"> {{ $point->name }}</label>
                                @endforeach
                            </div>
                        </fieldset>
                        <x-button.primary>Criar fila</x-button.primary>
                    </form>
                </x-card>
                <x-card class="p-5">
                    <h3 class="font-extrabold">Novo painel</h3>
                    <form method="POST" action="{{ route('administration.flow.panels.store') }}" class="mt-4 space-y-3">
                        @csrf
                        <x-form.input name="name" label="Nome" required />
                        <input type="hidden" name="identification_mode" value="full_name">
                        <p class="rounded-lg bg-brand-50 px-3 py-2 text-sm font-semibold text-brand-800">Identificação pública: nome completo</p>
                        {{-- Seletor de identificação por nome parcial ou senha preservado para reativação futura.
                        <x-form.select name="identification_mode" label="Identificacao publica" required>
                            @foreach($identificationModes as $mode)<option value="{{ $mode->value }}">{{ $mode->label() }}</option>@endforeach
                        </x-form.select>
                        --}}
                        <fieldset>
                            <legend class="field-label">Filas exibidas</legend>
                            @foreach($queues as $queue)
                                <label class="mb-2 flex gap-2 rounded-lg border p-2 text-sm"><input type="checkbox" name="queues[]" value="{{ $queue->id }}"> {{ $queue->name }}</label>
                            @endforeach
                        </fieldset>
                        <x-button.primary>Criar painel</x-button.primary>
                    </form>
                </x-card>
            </div>
        </section>

        <section>
            <h2 class="mb-3 text-lg font-extrabold text-slate-900">Filas</h2>
            <div class="grid gap-5 xl:grid-cols-2">
                @foreach($queues as $queue)
                    <x-card class="p-5">
                        <form method="POST" action="{{ route('administration.flow.queues.update', $queue) }}" class="space-y-4">
                            @csrf @method('PUT')
                            <div>
                                <p class="text-xs font-bold uppercase text-slate-500">{{ $queue->code }} · {{ $queue->department->name }}</p>
                                <h3 class="mt-1 font-extrabold text-slate-900">{{ $queue->name }}</h3>
                            </div>
                            <div class="grid gap-4 sm:grid-cols-2">
                                <div class="sm:col-span-2"><x-form.input name="name" label="Nome" :value="$queue->name" required /></div>
                                <x-form.input name="prefix" label="Prefixo da senha" :value="$queue->prefix" required maxlength="8" />
                                <x-form.input name="ticket_length" label="Dígitos da senha" type="number" min="1" max="8" :value="$queue->ticket_length" required />
                                <x-form.select name="priority_strategy" label="Ordenação" required>
                                    <option value="fifo" @selected($queue->priority_strategy === 'fifo')>Ordem de chegada</option>
                                    <option value="priority_fifo" @selected($queue->priority_strategy === 'priority_fifo')>Prioridade e chegada</option>
                                </x-form.select>
                                <x-form.input name="minimum_calls_before_absent" label="Chamadas antes da ausência" type="number" min="1" max="10" :value="$queue->minimum_calls_before_absent" required />
                            </div>
                            <fieldset>
                                <legend class="field-label">Pontos de atendimento permitidos</legend>
                                <div class="grid gap-2 sm:grid-cols-2">
                                    @foreach($servicePoints as $point)
                                        <label class="flex items-center gap-2 rounded-lg border border-slate-200 p-3 text-sm">
                                            <input type="checkbox" name="service_points[]" value="{{ $point->id }}" @checked($queue->servicePoints->contains($point))>
                                            <span><strong class="block">{{ $point->name }}</strong><small>{{ $point->room->department->name }}</small></span>
                                        </label>
                                    @endforeach
                                </div>
                            </fieldset>
                            <div class="text-right"><x-button.primary>Salvar fila</x-button.primary></div>
                        </form>
                    </x-card>
                @endforeach
            </div>
        </section>

        <section>
            <h2 class="mb-3 text-lg font-extrabold text-slate-900">Painéis de chamada</h2>
            <div class="grid gap-5 xl:grid-cols-2">
                @foreach($panels as $panel)
                    <x-card class="p-5">
                        <form method="POST" action="{{ route('administration.flow.panels.update', $panel) }}" class="space-y-4">
                            @csrf @method('PUT')
                            <div class="flex items-start justify-between gap-3">
                                <div><p class="text-xs font-bold uppercase text-slate-500">Código técnico protegido</p><p class="mt-1 break-all font-mono text-xs">{{ $panel->public_code }}</p></div>
                                <a href="{{ route('panels.show', $panel) }}" target="_blank" rel="noopener" class="text-sm font-bold text-brand-700 underline">Visualizar</a>
                            </div>
                            <x-form.input name="name" label="Nome" :value="$panel->name" required />
                            <div class="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <input type="hidden" name="identification_mode" value="full_name">
                                    <span class="field-label">Identificação pública</span>
                                    <p class="field-control flex items-center bg-slate-50 font-semibold">Nome completo</p>
                                </div>
                                {{-- Seletor de identificação por nome parcial ou senha preservado para reativação futura.
                                <x-form.select name="identification_mode" label="Identificação pública" required>
                                    @foreach($identificationModes as $mode)<option value="{{ $mode->value }}" @selected($panel->identificationMode() === $mode)>{{ $mode->label() }}</option>@endforeach
                                </x-form.select>
                                --}}
                                <x-form.input name="previous_calls_count" label="Chamadas anteriores" type="number" min="1" max="20" :value="$panel->previous_calls_count" required />
                                <x-form.input name="suggested_volume" label="Volume sugerido (%)" type="number" min="0" max="100" :value="$panel->suggested_volume" required />
                                <label class="mt-8 flex items-center gap-2 text-sm font-bold"><input type="checkbox" name="sound_enabled" value="1" @checked($panel->sound_enabled)> Áudio habilitado</label>
                            </div>
                            <x-form.input name="institutional_message" label="Mensagem institucional" :value="$panel->institutional_message" />
                            <fieldset>
                                <legend class="field-label">Filas exibidas</legend>
                                <div class="grid gap-2 sm:grid-cols-2">
                                    @foreach($queues as $queue)
                                        <label class="flex items-center gap-2 rounded-lg border border-slate-200 p-3 text-sm">
                                            <input type="checkbox" name="queues[]" value="{{ $queue->id }}" @checked($panel->queues->contains($queue))> {{ $queue->name }}
                                        </label>
                                    @endforeach
                                </div>
                            </fieldset>
                            <div class="text-right"><x-button.primary>Salvar painel</x-button.primary></div>
                        </form>
                    </x-card>
                @endforeach
            </div>
        </section>
    </div>
</x-layout.app>
