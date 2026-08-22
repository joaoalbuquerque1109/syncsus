@php
    $initialPatient = $patient ? [
        'public_id' => $patient->public_id,
        'medical_record_number' => $patient->medical_record_number,
        'name' => $patient->displayName(),
        'birth_date' => $patient->birth_date?->format('d/m/Y'),
        'is_provisional' => $patient->is_provisional,
        'identifiers' => $patient->identifiers->map(fn ($identifier) => ['type' => $identifier->type->value, 'value' => $identifier->maskedValue()])->values(),
    ] : null;
    $restoredStep = max(1, min(3, (int) old('_reception_step', $patient ? 2 : 1)));
    $errorStep = $errors->hasAny(['patient_public_id']) ? 2 : ($errors->any() ? 3 : $restoredStep);
@endphp

<div
    class="mx-auto max-w-5xl"
    x-data="receptionWizard({
        patient: @js($initialPatient),
        searchUrl: @js(route('patients.search')),
        queues: @js($queues),
        arrivalMethods: @js($arrivalMethods),
        departmentId: @js(old('department_id', '')),
        queueId: @js(old('queue_id', '')),
        arrivalMethodId: @js(old('arrival_method_id', '')),
        step: {{ $errorStep }}
    })"
>
    <div class="mb-6">
        <p class="text-sm font-semibold text-brand-700">Recepção</p>
        <h1 class="text-2xl font-extrabold text-slate-950">Abrir atendimento</h1>
        <p class="mt-1 text-sm text-slate-600">Unidade: {{ $activeHealthUnit->name }}</p>
    </div>

    <ol class="mb-6 grid grid-cols-3 gap-2" aria-label="Etapas">
        @foreach([1 => 'Chegada', 2 => 'Paciente', 3 => 'Destino'] as $number => $label)
            <li class="rounded-lg border px-3 py-3 text-center text-sm font-bold" :class="step >= {{ $number }} ? 'border-brand-600 bg-brand-50 text-brand-700' : 'border-slate-200 bg-white text-slate-500'">
                <span class="mr-1">{{ $number }}.</span> {{ $label }}
            </li>
        @endforeach
    </ol>

    @if($errors->any())<x-alert type="danger" class="mb-5">Não foi possível abrir o atendimento. Revise os campos destacados.</x-alert>@endif

    <form method="POST" action="{{ route('reception.store') }}">
        @csrf
        <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $idempotencyKey) }}">
        <input type="hidden" name="patient_public_id" :value="patient?.public_id || ''">
        <input type="hidden" name="_reception_step" :value="step">

        <x-card class="p-5 lg:p-7" x-show="step === 1">
            <h2 class="text-lg font-extrabold text-slate-900">Dados da chegada</h2>
            <div class="mt-5 grid gap-4 md:grid-cols-2">
                <x-form.select name="entry_type_id" label="Tipo de entrada" required>
                    <option value="">Selecione</option>
                    @foreach($entryTypes as $type)<option value="{{ $type->id }}" @selected((string) old('entry_type_id') === (string) $type->id)>{{ $type->name }}</option>@endforeach
                </x-form.select>
                <x-form.select name="arrival_method_id" label="Forma de chegada" required x-model="arrivalMethodId">
                    <option value="">Selecione</option>
                    @foreach($arrivalMethods as $method)<option value="{{ $method->id }}" @selected((string) old('arrival_method_id') === (string) $method->id)>{{ $method->name }}</option>@endforeach
                </x-form.select>
                <x-form.input name="arrival_at" label="Data e hora da chegada" type="datetime-local" :value="old('arrival_at', now()->format('Y-m-d\\TH:i'))" />
                <x-form.input name="origin" label="Origem" :value="old('origin')" placeholder="Domicílio, UBS, via pública..." />
                <div class="md:col-span-2"><x-form.input name="entry_reason" label="Motivo informado" required :value="old('entry_reason')" /></div>
                <x-form.select name="administrative_priority" label="Prioridade administrativa" required>
                    @foreach($priorities as $priority)<option value="{{ $priority->value }}" @selected(old('administrative_priority', 'none') === $priority->value)>{{ $priority->label() }}</option>@endforeach
                </x-form.select>
                <div x-show="requiresVehicle" x-cloak><x-form.input name="vehicle_information" label="Identificação do veículo" :value="old('vehicle_information')" placeholder="Prefixo, placa ou equipe" /></div>
                <div class="md:col-span-2"><x-form.textarea name="reception_notes" label="Observações da recepção" rows="3">{{ old('reception_notes') }}</x-form.textarea></div>
            </div>
            <div class="mt-6 flex justify-end"><x-button.primary type="button" @click="next()">Continuar</x-button.primary></div>
        </x-card>

        <x-card class="p-5 lg:p-7" x-show="step === 2" x-cloak>
            <h2 class="text-lg font-extrabold text-slate-900">Identificação do paciente</h2>
            <div x-show="!patient" class="mt-5">
                <label class="field-label" for="patient-search">Nome, CPF, CNS ou prontuário</label>
                <div class="flex gap-2">
                    <input id="patient-search" class="field-control" type="search" x-model="query" @input.debounce.350ms="searchPatients()" placeholder="Digite ao menos 2 caracteres">
                    <span x-show="searching" class="self-center text-sm text-slate-500">Buscando...</span>
                </div>
                <div class="mt-3 divide-y divide-slate-100 rounded-lg border border-slate-200" x-show="results.length">
                    <template x-for="item in results" :key="item.public_id">
                        <button type="button" class="flex w-full items-center justify-between p-4 text-left hover:bg-brand-50" @click="selectPatient(item)">
                            <span><strong class="block text-slate-900" x-text="item.name"></strong><small class="text-slate-500" x-text="`${item.medical_record_number} · ${item.birth_date || 'Nascimento não informado'}`"></small></span>
                            <span class="text-sm font-bold text-brand-700">Selecionar</span>
                        </button>
                    </template>
                </div>
                <p class="mt-5 text-sm text-slate-600">Não encontrou?
                    <button type="submit" formmethod="POST" formaction="{{ route('reception.draft.patient') }}" formnovalidate class="font-bold text-brand-700 underline">Cadastrar paciente</button>
                    ou <button type="submit" formmethod="POST" formaction="{{ route('reception.draft.provisional') }}" formnovalidate class="font-bold text-amber-700 underline">criar identificação provisória</button>.
                </p>
            </div>
            <div x-show="patient" class="mt-5 rounded-xl border border-brand-200 bg-brand-50 p-5">
                <div class="flex items-start justify-between gap-3">
                    <div><p class="text-xs font-bold uppercase text-brand-700">Paciente selecionado</p><p class="mt-1 text-lg font-extrabold" x-text="patient?.name"></p><p class="text-sm text-slate-600" x-text="`${patient?.medical_record_number || ''} · ${patient?.birth_date || 'Nascimento não informado'}`"></p></div>
                    <button type="button" class="text-sm font-bold text-brand-700 underline" @click="patient = null">Trocar</button>
                </div>
            </div>
            @error('patient_public_id')<p class="mt-2 text-sm font-bold text-red-700">{{ $message }}</p>@enderror
            <div class="mt-6 flex justify-between"><button type="button" class="rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-bold" @click="previous()">Voltar</button><x-button.primary type="button" @click="next()" ::disabled="!patient">Continuar</x-button.primary></div>
        </x-card>

        <x-card class="p-5 lg:p-7" x-show="step === 3" x-cloak>
            <h2 class="text-lg font-extrabold text-slate-900">Destino e acompanhante</h2>
            <div class="mt-5 grid gap-4 md:grid-cols-2">
                <x-form.select name="department_id" label="Setor de destino" required x-model="departmentId">
                    <option value="">Selecione</option>
                    @foreach($departments->where('is_clinical', true) as $department)<option value="{{ $department->id }}" @selected((string) old('department_id') === (string) $department->id)>{{ $department->name }}</option>@endforeach
                </x-form.select>
                <x-form.select name="queue_id" label="Fila inicial" required x-model="queueId">
                    <option value="">Selecione o setor primeiro</option>
                    <template x-for="queue in filteredQueues" :key="queue.id"><option :value="queue.id" x-text="queue.name"></option></template>
                </x-form.select>
                <x-form.select name="specialty_id" label="Especialidade">
                    <option value="">Não definida</option>
                    @foreach($specialties as $specialty)<option value="{{ $specialty->id }}" @selected((string) old('specialty_id') === (string) $specialty->id)>{{ $specialty->name }}</option>@endforeach
                </x-form.select>
            </div>
            <div
                class="mt-6 rounded-xl border border-brand-200 bg-brand-50/40 p-5"
                x-data="{ requestExams: @js((bool) old('request_exams', $requestExamsByDefault ?? false)) }"
            >
                <label class="flex items-start gap-3">
                    <input type="checkbox" name="request_exams" value="1" x-model="requestExams" class="mt-1" @checked(old('request_exams', $requestExamsByDefault ?? false))>
                    <span><strong class="block text-slate-900">Registrar requisição de exames laboratoriais</strong><small class="text-slate-600">A requisição será vinculada a este atendimento e preparada para o Synclab.</small></span>
                </label>
                <div x-show="requestExams" x-cloak class="mt-5 space-y-4">
                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <x-form.select name="exam_requester_id" label="Médico solicitante (opcional)">
                                <option value="">Sem médico — registrar como recepcionista</option>
                                @foreach($examRequesters as $requester)
                                    <option value="{{ $requester->user_id }}" @selected((string) old('exam_requester_id') === (string) $requester->user_id)>{{ $requester->institutional_code }} · {{ $requester->displayName() }}{{ $requester->primaryRegistrationLabel() ? ' · '.$requester->primaryRegistrationLabel() : '' }}</option>
                                @endforeach
                            </x-form.select>
                            <p class="mt-1 text-xs text-slate-500">Deixe em branco para registrar você mesmo como solicitante.</p>
                        </div>
                        <x-form.select name="exam_priority" label="Prioridade da requisição" ::required="requestExams">
                            <option value="routine" @selected(old('exam_priority', 'routine') === 'routine')>Rotina</option>
                            <option value="urgent" @selected(old('exam_priority') === 'urgent')>Urgente</option>
                            <option value="emergency" @selected(old('exam_priority') === 'emergency')>Emergência</option>
                        </x-form.select>
                        <div class="md:col-span-2"><x-form.textarea name="exam_clinical_indication" label="Indicação clínica informada" rows="3" ::required="requestExams">{{ old('exam_clinical_indication') }}</x-form.textarea></div>
                        <div class="md:col-span-2"><x-form.textarea name="exam_notes" label="Observações da requisição" rows="2">{{ old('exam_notes') }}</x-form.textarea></div>
                    </div>
                    <div
                        class="relative"
                        x-data="laboratoryExamSelector({ searchUrl: @js(route('laboratory.exams.search')), initial: @js($selectedExams) })"
                    >
                        <label class="field-label" for="reception_exam_search">Exames *</label>
                        <input id="reception_exam_search" type="search" x-model="query" @input.debounce.250ms="search()" @keydown.escape="open = false" autocomplete="off" class="field-control" placeholder="Pesquise por código, sigla ou nome">
                        <p x-show="searching" class="mt-1 text-xs text-slate-500">Buscando na tabela de exames...</p>
                        <div x-show="open" @click.outside="open = false" class="absolute z-30 mt-1 max-h-72 w-full overflow-y-auto rounded-lg border border-slate-200 bg-white shadow-xl">
                            <template x-for="exam in results" :key="exam.id">
                                <button type="button" @click="add(exam)" class="block w-full border-b border-slate-100 px-3 py-3 text-left last:border-0 hover:bg-brand-50">
                                    <span class="block text-sm font-bold" x-text="exam.label"></span>
                                    <span class="mt-1 block text-xs text-slate-500" x-text="[exam.material, exam.container].filter(Boolean).join(' · ')"></span>
                                </button>
                            </template>
                            <p x-show="!searching && results.length === 0" class="p-3 text-sm text-slate-500">Nenhum exame encontrado nesta unidade.</p>
                        </div>
                        <div class="mt-3 space-y-2">
                            <template x-for="(exam, index) in selected" :key="exam.id">
                                <div class="flex items-center justify-between gap-3 rounded-lg border border-slate-200 bg-white px-3 py-2">
                                    <input type="hidden" name="exam_ids[]" :value="exam.id">
                                    <span class="min-w-0 safe-wrap text-sm font-bold" x-text="exam.label"></span>
                                    <button type="button" @click="remove(index)" class="grid size-8 shrink-0 place-items-center rounded-lg border border-red-200 text-red-700" aria-label="Remover exame"><x-icons.trash /></button>
                                </div>
                            </template>
                        </div>
                        <p x-show="selected.length === 0" class="mt-2 text-xs font-semibold text-amber-700">Selecione pelo menos um exame.</p>
                        @error('exam_ids')<p class="mt-2 text-sm font-bold text-red-700">{{ $message }}</p>@enderror
                        @error('exam_ids.*')<p class="mt-2 text-sm font-bold text-red-700">{{ $message }}</p>@enderror
                    </div>
                </div>
                @error('request_exams')<p class="mt-2 text-sm font-bold text-red-700">{{ $message }}</p>@enderror
            </div>
            <div class="mt-6 border-t border-slate-200 pt-5">
                <p class="mb-4 text-sm font-extrabold text-slate-700">Acompanhante (opcional)</p>
                <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
                    <x-form.input name="companion_name" label="Nome" :value="old('companion_name')" />
                    <x-form.input name="companion_cpf" label="CPF" :value="old('companion_cpf')" />
                    <x-form.input name="companion_phone" label="Telefone" :value="old('companion_phone')" />
                    <x-form.input name="companion_relationship" label="Vínculo" :value="old('companion_relationship')" />
                </div>
                <label class="mt-3 flex items-center gap-2 text-sm font-semibold"><input type="checkbox" name="companion_is_guardian" value="1" @checked(old('companion_is_guardian'))> É responsável legal</label>
            </div>
            <div class="mt-6 flex justify-between"><button type="button" class="rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-bold" @click="previous()">Voltar</button><x-button.primary>Confirmar e emitir senha</x-button.primary></div>
        </x-card>
    </form>
</div>
