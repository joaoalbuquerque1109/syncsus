@php
    $editing = $professional !== null;
    $selectedUnits = old('health_unit_ids', $professional?->healthUnits?->modelKeys() ?? []);
    $selectedSpecialties = old('specialty_ids', $professional?->specialties?->modelKeys() ?? []);
    $selectedQueues = old('queue_ids', $professional?->queues?->modelKeys() ?? []);
    $selectedServicePoints = old('service_point_ids', $professional?->servicePoints?->modelKeys() ?? []);
    $primarySpecialty = old('primary_specialty_id', $professional?->specialties?->firstWhere('pivot.is_primary', true)?->getKey());
    $existingRegistrations = $professional?->registrations?->values() ?? collect();
@endphp

<x-layout.app :title="$editing ? 'Editar profissional' : 'Novo profissional'">
    <x-slot:header>
        <x-page-header eyebrow="Administração · Profissionais" :title="$editing ? 'Editar profissional' : 'Novo profissional'" description="Os registros profissionais serão usados no prontuário, documentos e auditoria." />
    </x-slot:header>

    @if($errors->any())
        <x-alert type="danger" class="mb-5"><strong>Revise os campos informados.</strong></x-alert>
    @endif

    <form method="POST" action="{{ $editing ? route('administration.professionals.update', $professional) : route('administration.professionals.store') }}" class="space-y-5">
        @csrf
        @if($editing) @method('PUT') @endif

        <x-card class="p-5 lg:p-6">
            <h2 class="text-lg font-extrabold">Identificação profissional</h2>
            <div class="mt-4 grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                <x-form.input name="institutional_code" label="Código institucional" required :value="old('institutional_code', $professional?->institutional_code)" />
                <x-form.select name="profession_type" label="Categoria profissional" required>
                    @foreach(['doctor' => 'Médico', 'nurse' => 'Enfermeiro', 'technician' => 'Técnico', 'physiotherapist' => 'Fisioterapeuta', 'psychologist' => 'Psicólogo', 'social_worker' => 'Assistente social', 'other' => 'Outro'] as $value => $label)
                        <option value="{{ $value }}" @selected(old('profession_type', $professional?->profession_type) === $value)>{{ $label }}</option>
                    @endforeach
                </x-form.select>
                <x-form.select name="user_id" label="Usuário de acesso">
                    <option value="">Sem acesso ao sistema</option>
                    @foreach($users as $user)<option value="{{ $user->getKey() }}" @selected((string)old('user_id', $professional?->user_id) === (string)$user->getKey())>{{ $user->name }} · {{ $user->email }}</option>@endforeach
                </x-form.select>
                <x-form.input name="treatment_name" label="Tratamento" placeholder="Dra., Dr., Enf." :value="old('treatment_name', $professional?->treatment_name)" />
                <div class="lg:col-span-2"><x-form.input name="full_name" label="Nome completo" required :value="old('full_name', $professional?->full_name)" /></div>
                <x-form.input name="social_name" label="Nome social" :value="old('social_name', $professional?->social_name)" />
                <x-form.input name="cpf" label="CPF" :value="old('cpf', $professional?->cpf)" />
                <x-form.input name="birth_date" label="Data de nascimento" type="date" :value="old('birth_date', $professional?->birth_date?->format('Y-m-d'))" />
                <x-form.select name="sex" label="Sexo">
                    <option value="">Não informado</option>
                    @foreach(['female' => 'Feminino', 'male' => 'Masculino', 'intersex' => 'Intersexo', 'unknown' => 'Não informado'] as $value => $label)<option value="{{ $value }}" @selected(old('sex', $professional?->sex) === $value)>{{ $label }}</option>@endforeach
                </x-form.select>
                <x-form.input name="identity_number" label="Documento de identidade" :value="old('identity_number', $professional?->identity_number)" />
                <x-form.input name="identity_issuer" label="Órgão emissor" :value="old('identity_issuer', $professional?->identity_issuer)" />
                <x-form.input name="identity_state" label="UF do documento" maxlength="2" :value="old('identity_state', $professional?->identity_state)" />
                <x-form.input name="cnes_code" label="Código CNES do profissional" :value="old('cnes_code', $professional?->cnes_code)" />
                <label class="mt-8 flex items-center gap-2 text-sm font-semibold"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $professional?->is_active ?? true))> Profissional ativo</label>
            </div>
        </x-card>

        <x-card class="p-5 lg:p-6">
            <h2 class="text-lg font-extrabold">Filas e pontos de atendimento</h2>
            <p class="mt-1 text-sm text-slate-500">Defina exatamente quais filas o profissional visualiza e em quais salas ou consultórios ele pode chamar pacientes. Para separar Triagem 1 e Triagem 2, mantenha filas distintas e atribua apenas a correspondente.</p>
            <div class="mt-4 grid gap-6 lg:grid-cols-2">
                <div>
                    <p class="font-bold">Filas autorizadas</p>
                    <div class="mt-3 space-y-2">
                        @forelse($queues as $queue)
                            <label class="flex items-start gap-3 rounded-lg border border-slate-200 p-3">
                                <input class="mt-1" type="checkbox" name="queue_ids[]" value="{{ $queue->getKey() }}" @checked(in_array($queue->getKey(), array_map('intval', $selectedQueues), true))>
                                <span class="min-w-0"><strong class="block break-words text-sm">{{ $queue->name }}</strong><span class="block text-xs text-slate-500">{{ $queue->healthUnit->name }} · {{ $queue->department->name }}@if($queue->specialty) · {{ $queue->specialty->name }}@endif</span></span>
                            </label>
                        @empty
                            <p class="text-sm text-slate-500">Nenhuma fila ativa cadastrada.</p>
                        @endforelse
                    </div>
                </div>
                <div>
                    <p class="font-bold">Pontos autorizados</p>
                    <div class="mt-3 space-y-2">
                        @forelse($servicePoints as $point)
                            <label class="flex items-start gap-3 rounded-lg border border-slate-200 p-3">
                                <input class="mt-1" type="checkbox" name="service_point_ids[]" value="{{ $point->getKey() }}" @checked(in_array($point->getKey(), array_map('intval', $selectedServicePoints), true))>
                                <span class="min-w-0"><strong class="block break-words text-sm">{{ $point->name }}</strong><span class="block text-xs text-slate-500">{{ $point->room->name }} · {{ $point->queues->pluck('name')->join(', ') }}</span></span>
                            </label>
                        @empty
                            <p class="text-sm text-slate-500">Nenhum ponto ativo vinculado às filas.</p>
                        @endforelse
                    </div>
                </div>
            </div>
        </x-card>

        <x-card class="p-5 lg:p-6">
            <h2 class="text-lg font-extrabold">Conselhos profissionais</h2>
            <p class="mt-1 text-sm text-slate-500">O primeiro registro será utilizado como identificação principal nos documentos.</p>
            <div class="mt-4 space-y-4">
                @for($index = 0; $index < 2; $index++)
                    @php($registration = $existingRegistrations->get($index))
                    <div class="grid gap-4 rounded-lg border border-slate-200 p-4 md:grid-cols-3 lg:grid-cols-5">
                        <x-form.input :name="'registrations['.$index.'][council_type]'" label="Conselho" placeholder="CRM, COREN" :required="$index === 0" :value="old('registrations.'.$index.'.council_type', $registration?->council_type)" />
                        <x-form.input :name="'registrations['.$index.'][registration_number]'" label="Número" :required="$index === 0" :value="old('registrations.'.$index.'.registration_number', $registration?->registration_number)" />
                        <x-form.input :name="'registrations['.$index.'][state]'" label="UF" maxlength="2" :required="$index === 0" :value="old('registrations.'.$index.'.state', $registration?->state)" />
                        <x-form.input :name="'registrations['.$index.'][issued_at]'" label="Emissão" type="date" :value="old('registrations.'.$index.'.issued_at', $registration?->issued_at?->format('Y-m-d'))" />
                        <x-form.input :name="'registrations['.$index.'][expires_at]'" label="Validade" type="date" :value="old('registrations.'.$index.'.expires_at', $registration?->expires_at?->format('Y-m-d'))" />
                        @if($index === 0)<input type="hidden" name="registrations[0][is_primary]" value="1">@endif
                    </div>
                @endfor
            </div>
        </x-card>

        <x-card class="p-5 lg:p-6">
            <h2 class="text-lg font-extrabold">Especialidades e unidades</h2>
            <div class="mt-4 grid gap-6 lg:grid-cols-2">
                <div class="space-y-3">
                    <p class="font-bold">Especialidades</p>
                    @foreach($specialties as $specialty)
                        @php($pivot = $professional?->specialties?->firstWhere('id', $specialty->getKey())?->pivot)
                        <div class="grid grid-cols-[auto_1fr_8rem] items-end gap-3 rounded-lg border border-slate-200 p-3">
                            <input type="checkbox" name="specialty_ids[]" value="{{ $specialty->getKey() }}" @checked(in_array($specialty->getKey(), array_map('intval', $selectedSpecialties), true))>
                            <div><strong class="block text-sm">{{ $specialty->name }}</strong><label class="text-xs text-slate-500">RQE <input class="field-control mt-1" name="specialty_rqe[{{ $specialty->getKey() }}]" value="{{ old('specialty_rqe.'.$specialty->getKey(), $pivot?->rqe_number) }}"></label></div>
                            <label class="text-xs font-bold"><input type="radio" name="primary_specialty_id" value="{{ $specialty->getKey() }}" @checked((int)$primarySpecialty === (int)$specialty->getKey())> Principal</label>
                            <input type="date" class="field-control col-start-2" name="specialty_registered_at[{{ $specialty->getKey() }}]" value="{{ old('specialty_registered_at.'.$specialty->getKey(), $pivot?->registered_at) }}">
                        </div>
                    @endforeach
                </div>
                <div>
                    <p class="font-bold">Unidades autorizadas *</p>
                    <div class="mt-3 space-y-2">@foreach($healthUnits as $unit)<label class="flex items-center gap-3 rounded-lg border border-slate-200 p-3"><input type="checkbox" name="health_unit_ids[]" value="{{ $unit->getKey() }}" @checked(in_array($unit->getKey(), array_map('intval', $selectedUnits), true))> {{ $unit->name }}</label>@endforeach</div>
                </div>
            </div>
        </x-card>

        <x-card class="p-5 lg:p-6">
            <h2 class="text-lg font-extrabold">Contato e endereço</h2>
            <div class="mt-4 grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                @foreach(['phone' => 'Telefone', 'mobile' => 'Celular', 'secondary_phone' => 'Telefone alternativo', 'email' => 'E-mail profissional', 'secondary_email' => 'E-mail alternativo', 'postal_code' => 'CEP', 'state' => 'UF', 'city' => 'Município', 'city_ibge_code' => 'Código IBGE', 'district' => 'Bairro', 'street' => 'Logradouro', 'street_number' => 'Número', 'address_complement' => 'Complemento'] as $field => $label)
                    <x-form.input :name="$field" :label="$label" :type="str_contains($field, 'email') ? 'email' : 'text'" :value="old($field, $professional?->{$field})" />
                @endforeach
                <div class="md:col-span-2 lg:col-span-3"><x-form.textarea name="notes" label="Observações administrativas">{{ old('notes', $professional?->notes) }}</x-form.textarea></div>
            </div>
        </x-card>

        <div class="flex justify-end gap-3"><a href="{{ route('administration.professionals.index') }}" class="rounded-lg border border-slate-300 px-4 py-2.5 font-bold">Cancelar</a><x-button.primary>Salvar profissional</x-button.primary></div>
    </form>
</x-layout.app>
