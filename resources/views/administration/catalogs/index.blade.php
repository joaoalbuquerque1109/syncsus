<x-layout.app title="Cadastros administrativos">
    <x-slot:header>
        <x-page-header eyebrow="Administração" title="Cadastros administrativos" description="Unidades, especialidades e parâmetros usados no fluxo assistencial." />
    </x-slot:header>

    @if($errors->any())<x-alert type="danger" class="mb-5">{{ $errors->first() }}</x-alert>@endif

    <x-alert type="info" class="mb-5">
        Código desta unidade para acesso no login:
        <strong class="font-mono">{{ $activeHealthUnit->organization->code }}</strong>.
        Os locais abaixo pertencem exclusivamente a esta organização.
    </x-alert>

    <div x-data="{ tab: 'units' }">
        <div class="mb-5 flex flex-wrap gap-2">
            @foreach(['units' => 'Unidades', 'specialties' => 'Especialidades', 'arrivals' => 'Formas de chegada', 'entries' => 'Tipos de entrada'] as $tab => $label)
                <button type="button" @click="tab = '{{ $tab }}'" :class="tab === '{{ $tab }}' ? 'bg-brand-600 text-white' : 'bg-white text-slate-700'" class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-bold">{{ $label }}</button>
            @endforeach
        </div>

        <section x-show="tab === 'units'" class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_24rem]">
            <x-card class="overflow-hidden">
                <div class="divide-y divide-slate-200">
                    @forelse($healthUnits as $unit)
                        <details>
                            <summary class="flex cursor-pointer list-none justify-between gap-4 p-5"><span><strong>{{ $unit->name }}</strong><small class="ml-2 font-mono text-slate-500">{{ $unit->code }}</small><br><small class="text-slate-500">{{ $unit->city }}/{{ $unit->state }} · CNES {{ $unit->cnes_code ?: 'não informado' }}</small></span><span class="text-sm {{ $unit->is_active ? 'text-emerald-700' : 'text-red-700' }}">{{ $unit->is_active ? 'Ativa' : 'Inativa' }}</span></summary>
                            <form method="POST" action="{{ route('administration.catalogs.update', ['catalog' => 'health-units', 'record' => $unit->getKey()]) }}" class="grid gap-3 border-t bg-slate-50 p-5 md:grid-cols-3">
                                @csrf @method('PUT')
                                <x-form.select name="organization_id" label="Organização" required>@foreach($organizations as $organization)<option value="{{ $organization->getKey() }}" @selected($unit->organization_id === $organization->getKey())>{{ $organization->trade_name }}</option>@endforeach</x-form.select>
                                <x-form.input name="code" label="Código" required :value="$unit->code" /><x-form.input name="name" label="Nome" required :value="$unit->name" />
                                <x-form.input name="cnes_code" label="CNES" :value="$unit->cnes_code" /><x-form.input name="phone" label="Telefone" :value="$unit->phone" /><x-form.input name="postal_code" label="CEP" :value="$unit->postal_code" />
                                <x-form.input name="state" label="UF" maxlength="2" :value="$unit->state" /><x-form.input name="city" label="Município" :value="$unit->city" /><x-form.input name="district" label="Bairro" :value="$unit->district" />
                                <x-form.input name="street" label="Logradouro" :value="$unit->street" /><x-form.input name="street_number" label="Número" :value="$unit->street_number" /><x-form.input name="address_complement" label="Complemento" :value="$unit->address_complement" />
                                <label class="flex items-center gap-2 text-sm font-bold"><input type="checkbox" name="is_active" value="1" @checked($unit->is_active)> Unidade ativa</label><div class="md:col-span-2 text-right"><x-button.primary>Salvar unidade</x-button.primary></div>
                            </form>
                        </details>
                    @empty<p class="p-8 text-center text-slate-500">Nenhuma unidade cadastrada.</p>@endforelse
                </div>
            </x-card>
            <x-card class="h-fit p-5">
                <h2 class="font-extrabold">Nova unidade</h2>
                <form method="POST" action="{{ route('administration.catalogs.store', 'health-units') }}" class="mt-4 space-y-3">@csrf
                    <x-form.select name="organization_id" label="Organização" required><option value="">Selecione</option>@foreach($organizations as $organization)<option value="{{ $organization->getKey() }}">{{ $organization->trade_name }}</option>@endforeach</x-form.select>
                    <x-form.input name="code" label="Código" required /><x-form.input name="name" label="Nome" required /><x-form.input name="cnes_code" label="CNES" />
                    <div class="grid grid-cols-2 gap-3"><x-form.input name="state" label="UF" maxlength="2" /><x-form.input name="city" label="Município" /></div>
                    <x-form.input name="phone" label="Telefone" /><label class="flex gap-2 text-sm font-bold"><input type="checkbox" name="is_active" value="1" checked> Unidade ativa</label><x-button.primary>Cadastrar unidade</x-button.primary>
                </form>
            </x-card>
        </section>

        <section x-cloak x-show="tab === 'specialties'" class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_24rem]">
            <x-card class="overflow-hidden"><div class="divide-y divide-slate-200">
                @foreach($specialties as $specialty)<form method="POST" action="{{ route('administration.catalogs.update', ['catalog' => 'specialties', 'record' => $specialty->getKey()]) }}" class="grid items-end gap-3 p-4 md:grid-cols-[8rem_1fr_7rem_auto_auto]">@csrf @method('PUT')
                    <x-form.input name="code" label="Código" required :value="$specialty->code" /><x-form.input name="name" label="Nome" required :value="$specialty->name" /><x-form.input name="display_order" label="Ordem" type="number" :value="$specialty->display_order" /><label class="mb-3 flex gap-2 text-sm"><input type="checkbox" name="is_active" value="1" @checked($specialty->is_active)> Ativa</label><x-button.primary>Salvar</x-button.primary>
                </form>@endforeach
            </div></x-card>
            <x-card class="h-fit p-5"><h2 class="font-extrabold">Nova especialidade</h2><form method="POST" action="{{ route('administration.catalogs.store', 'specialties') }}" class="mt-4 space-y-3">@csrf<x-form.input name="code" label="Código" required /><x-form.input name="name" label="Nome" required /><x-form.input name="display_order" label="Ordem" type="number" value="0" /><label class="flex gap-2 text-sm font-bold"><input type="checkbox" name="is_active" value="1" checked> Ativa</label><x-button.primary>Cadastrar</x-button.primary></form></x-card>
        </section>

        <section x-cloak x-show="tab === 'arrivals'" class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_24rem]">
            <x-card class="overflow-hidden"><div class="divide-y divide-slate-200">
                @foreach($arrivalMethods as $method)<form method="POST" action="{{ route('administration.catalogs.update', ['catalog' => 'arrival-methods', 'record' => $method->getKey()]) }}" class="grid items-end gap-3 p-4 md:grid-cols-[8rem_1fr_7rem_auto_auto_auto]">@csrf @method('PUT')
                    <x-form.input name="code" label="Código" required :value="$method->code" /><x-form.input name="name" label="Nome" required :value="$method->name" /><x-form.input name="display_order" label="Ordem" type="number" :value="$method->display_order" /><label class="mb-3 flex gap-2 text-sm"><input type="checkbox" name="requires_vehicle_data" value="1" @checked($method->requires_vehicle_data)> Veículo</label><label class="mb-3 flex gap-2 text-sm"><input type="checkbox" name="is_active" value="1" @checked($method->is_active)> Ativa</label><x-button.primary>Salvar</x-button.primary>
                </form>@endforeach
            </div></x-card>
            <x-card class="h-fit p-5"><h2 class="font-extrabold">Nova forma de chegada</h2><form method="POST" action="{{ route('administration.catalogs.store', 'arrival-methods') }}" class="mt-4 space-y-3">@csrf<x-form.input name="code" label="Código" required /><x-form.input name="name" label="Nome" required /><x-form.input name="display_order" label="Ordem" type="number" value="0" /><label class="flex gap-2 text-sm"><input type="checkbox" name="requires_vehicle_data" value="1"> Exige dados do veículo</label><label class="flex gap-2 text-sm"><input type="checkbox" name="is_active" value="1" checked> Ativa</label><x-button.primary>Cadastrar</x-button.primary></form></x-card>
        </section>

        <section x-cloak x-show="tab === 'entries'" class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_24rem]">
            <x-card class="overflow-hidden"><div class="divide-y divide-slate-200">
                @foreach($entryTypes as $type)<details><summary class="flex cursor-pointer justify-between p-5"><strong>{{ $type->name }} <small class="font-mono text-slate-500">{{ $type->code }}</small></strong><span class="text-sm">{{ $type->is_active ? 'Ativo' : 'Inativo' }}</span></summary><form method="POST" action="{{ route('administration.catalogs.update', ['catalog' => 'entry-types', 'record' => $type->getKey()]) }}" class="grid items-end gap-3 border-t bg-slate-50 p-4 md:grid-cols-3">@csrf @method('PUT')
                    <x-form.input name="code" label="Código" required :value="$type->code" /><x-form.input name="name" label="Nome" required :value="$type->name" /><x-form.input name="display_order" label="Ordem" type="number" :value="$type->display_order" /><x-form.select name="default_queue_id" label="Fila padrão"><option value="">Sem fila</option>@foreach($queues as $queue)<option value="{{ $queue->getKey() }}" @selected($type->default_queue_id === $queue->getKey())>{{ $queue->name }}</option>@endforeach</x-form.select>
                    <label class="flex gap-2 text-sm"><input type="checkbox" name="requires_triage" value="1" @checked($type->requires_triage)> Exige triagem</label><label class="flex gap-2 text-sm"><input type="checkbox" name="allows_provisional_patient" value="1" @checked($type->allows_provisional_patient)> Aceita provisório</label><label class="flex gap-2 text-sm"><input type="checkbox" name="is_active" value="1" @checked($type->is_active)> Ativo</label><div class="md:col-span-2 text-right"><x-button.primary>Salvar</x-button.primary></div>
                </form></details>@endforeach
            </div></x-card>
            <x-card class="h-fit p-5"><h2 class="font-extrabold">Novo tipo de entrada</h2><form method="POST" action="{{ route('administration.catalogs.store', 'entry-types') }}" class="mt-4 space-y-3">@csrf<x-form.input name="code" label="Código" required /><x-form.input name="name" label="Nome" required /><x-form.input name="display_order" label="Ordem" type="number" value="0" /><x-form.select name="default_queue_id" label="Fila padrão"><option value="">Sem fila</option>@foreach($queues as $queue)<option value="{{ $queue->getKey() }}">{{ $queue->name }}</option>@endforeach</x-form.select><label class="flex gap-2 text-sm"><input type="checkbox" name="requires_triage" value="1" checked> Exige triagem</label><label class="flex gap-2 text-sm"><input type="checkbox" name="allows_provisional_patient" value="1" checked> Aceita provisório</label><label class="flex gap-2 text-sm"><input type="checkbox" name="is_active" value="1" checked> Ativo</label><x-button.primary>Cadastrar</x-button.primary></form></x-card>
        </section>
    </div>
</x-layout.app>
