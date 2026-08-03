@php
    $encounter = $consultation->encounter;
    $patient = $encounter->patient;
    $triage = $encounter->triageAssessment;
    $latestVitals = $triage?->vitalSigns?->sortByDesc('measured_at')->first();
    $editable = $consultation->statusEnum()->value === 'draft';
    $risk = $encounter->riskLevel;
    $riskColors = [
        'red' => 'border-red-300 bg-red-50 text-red-800',
        'orange' => 'border-orange-300 bg-orange-50 text-orange-800',
        'yellow' => 'border-yellow-300 bg-yellow-50 text-yellow-800',
        'green' => 'border-green-300 bg-green-50 text-green-800',
        'blue' => 'border-blue-300 bg-blue-50 text-blue-800',
    ];
    $tabLabels = [
        'summary' => 'Resumo',
        'anamnesis' => 'Anamnese',
        'exam' => 'Exame físico',
        'diagnosis' => 'Diagnóstico',
        'prescriptions' => 'Prescrição',
        'exams' => 'Exames',
        'corrections' => 'Correcoes',
        'evolution' => 'Evolução',
        'referrals' => 'Encaminhamentos',
        'certificates' => 'Atestados',
        'documents' => 'Documentos',
        'destination' => 'Destinação',
    ];
    $prescriptionEditorRecords = $consultation->prescriptions
        ->where('status', 'finalized')
        ->mapWithKeys(fn ($prescription) => [$prescription->public_id => [
            'prescription_type' => $prescription->prescription_type,
            'general_instructions' => $prescription->general_instructions,
            'items' => $prescription->items->map(fn ($item) => $item->only([
                'medication_name', 'presentation', 'concentration', 'dose', 'dose_unit',
                'route', 'frequency', 'instructions',
            ]))->values()->all(),
        ]])->all();
    $prescriptionFormConfig = [
        'initialItems' => old('form_context') === 'prescription' && is_array(old('items')) ? array_values(old('items')) : [[]],
        'records' => $prescriptionEditorRecords,
        'initialType' => old('form_context') === 'prescription' ? old('prescription_type', 'hospital') : 'hospital',
        'initialInstructions' => old('form_context') === 'prescription' ? old('general_instructions', '') : '',
        'editingId' => old('form_context') === 'prescription' ? old('replaces_prescription_id', '') : '',
        'replacementReason' => old('form_context') === 'prescription' ? old('replacement_reason', '') : '',
    ];
@endphp

<x-layout.app :title="'Atendimento médico · '.$consultation->queueEntry->ticket_number">
    <div x-data="{ tab: @js(request('tab', 'summary')), destination: @js(old('destination_type', 'discharge')), documentType: @js(old('document_type', 'attendance_declaration')) }">
        <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap items-center gap-2">
                @if($risk)
                    <span class="rounded-full border px-3 py-1 text-xs font-black uppercase {{ $riskColors[$risk->color_key] ?? 'border-slate-300 bg-slate-100 text-slate-700' }}">
                        {{ $risk->name }} · {{ $risk->reference_minutes }} min
                    </span>
                @endif
                <span class="rounded-full bg-slate-200 px-3 py-1 text-xs font-bold text-slate-700">
                    {{ $editable ? 'Em atendimento' : 'Finalizado' }}
                </span>
            </div>
            @if($editable)
                <button type="button" @click="tab = 'destination'" class="rounded-lg bg-emerald-600 px-5 py-2.5 text-sm font-extrabold text-white hover:bg-emerald-700">
                    Finalizar atendimento
                </button>
            @endif
        </div>

        @if($errors->any())
            <div class="mb-5 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800" role="alert">
                <p class="font-extrabold">Revise os campos informados:</p>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                </ul>
            </div>
        @endif

        <div class="grid gap-5 xl:grid-cols-[300px_minmax(0,1fr)]">
            <aside class="app-card self-start p-5 xl:sticky xl:top-24">
                <div class="text-center">
                    <span class="mx-auto grid size-20 place-items-center rounded-full bg-brand-600 text-2xl font-black text-white">
                        {{ collect(explode(' ', $patient->displayName()))->take(2)->map(fn ($part) => mb_substr($part, 0, 1))->join('') }}
                    </span>
                    <h1 class="mt-3 font-extrabold text-slate-950">{{ $patient->displayName() }}</h1>
                    <p class="text-sm text-slate-500">{{ $patient->ageYears() ?? '—' }} anos · {{ $patient->sex->label() }}</p>
                </div>
                <dl class="mt-5 divide-y divide-slate-100 border-y border-slate-200 text-sm">
                    <div class="flex justify-between gap-3 py-2.5"><dt class="text-slate-500">Prontuário</dt><dd class="font-bold">{{ $patient->medical_record_number }}</dd></div>
                    @foreach($patient->identifiers as $identifier)
                        <div class="flex justify-between gap-3 py-2.5"><dt class="uppercase text-slate-500">{{ $identifier->typeValue() }}</dt><dd class="font-bold">{{ $identifier->maskedValue() }}</dd></div>
                    @endforeach
                    <div class="flex justify-between gap-3 py-2.5"><dt class="text-slate-500">Atendimento</dt><dd class="font-bold">{{ $encounter->encounter_number }}</dd></div>
                    <div class="flex justify-between gap-3 py-2.5"><dt class="text-slate-500">Chegada</dt><dd class="font-bold">{{ $encounter->arrival_at->format('d/m/Y H:i') }}</dd></div>
                    <div class="flex justify-between gap-3 py-2.5"><dt class="text-slate-500">Fim da triagem</dt><dd class="font-bold">{{ $encounter->triage_finished_at?->format('H:i') ?? '—' }}</dd></div>
                    <div class="flex justify-between gap-3 py-2.5"><dt class="text-slate-500">Médico</dt><dd class="text-right font-bold">{{ $consultation->professional->professionalProfile?->displayName() ?? $consultation->professional->name }}@if($consultation->professional->professionalProfile?->primaryRegistrationLabel())<br><span class="text-xs font-medium text-slate-500">{{ $consultation->professional->professionalProfile->primaryRegistrationLabel() }}</span>@endif</dd></div>
                    <div class="flex justify-between gap-3 py-2.5"><dt class="text-slate-500">Especialidade</dt><dd class="font-bold">{{ $consultation->specialty?->name ?? $consultation->queueEntry->queue->department->name }}</dd></div>
                    <div class="flex justify-between gap-3 py-2.5"><dt class="text-slate-500">Consultório</dt><dd class="font-bold">{{ $consultation->room?->name ?? '—' }}</dd></div>
                </dl>
                <div class="mt-4 rounded-lg border border-red-200 bg-red-50 p-3">
                    <p class="text-xs font-extrabold uppercase text-red-700">Alergias</p>
                    <p class="mt-1 text-sm text-red-900">
                        {{ $patient->allergies->pluck('substance')->filter()->join(', ') ?: ($triage?->reported_allergies ?: 'Sem alergias registradas') }}
                    </p>
                </div>
                <p class="mt-4 text-center text-xs text-slate-500">Tempo total: {{ max(0, (int) $encounter->arrival_at->diffInMinutes(now())) }} min</p>
            </aside>

            <section class="app-card min-w-0 overflow-hidden">
                <div class="border-b border-slate-200 px-5 py-4">
                    <h2 class="font-extrabold text-slate-950">Atendimento médico — Senha {{ $consultation->queueEntry->ticket_number }}</h2>
                    <p class="mt-1 text-xs text-slate-500">Versão {{ $consultation->version() }} · iniciado em {{ $consultation->started_at->format('d/m/Y H:i') }}</p>
                </div>
                <nav class="flex gap-1 overflow-x-auto border-b border-slate-200 px-4 pt-3" aria-label="Seções do atendimento">
                    @foreach($tabLabels as $key => $label)
                        <button type="button" @click="tab = '{{ $key }}'" class="whitespace-nowrap border-b-3 px-3 py-3 text-sm font-bold" :class="tab === '{{ $key }}' ? 'border-brand-600 text-brand-700' : 'border-transparent text-slate-500'">{{ $label }}</button>
                    @endforeach
                </nav>

                <section x-show="tab === 'summary'" class="space-y-5 p-5 lg:p-7">
                    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                        <div class="rounded-lg bg-slate-50 p-4"><p class="text-xs font-bold uppercase text-slate-500">Queixa da triagem</p><p class="mt-1 text-sm">{{ $triage?->chief_complaint ?: 'Não informada' }}</p></div>
                        <div class="rounded-lg bg-slate-50 p-4"><p class="text-xs font-bold uppercase text-slate-500">Pressão arterial</p><p class="mt-1 text-lg font-extrabold">{{ $latestVitals?->systolic_bp && $latestVitals?->diastolic_bp ? $latestVitals->systolic_bp.'/'.$latestVitals->diastolic_bp.' mmHg' : '—' }}</p></div>
                        <div class="rounded-lg bg-slate-50 p-4"><p class="text-xs font-bold uppercase text-slate-500">Temperatura</p><p class="mt-1 text-lg font-extrabold">{{ $latestVitals?->temperature_c ? $latestVitals->temperature_c.' °C' : '—' }}</p></div>
                        <div class="rounded-lg bg-slate-50 p-4"><p class="text-xs font-bold uppercase text-slate-500">Saturação</p><p class="mt-1 text-lg font-extrabold">{{ $latestVitals?->oxygen_saturation ? $latestVitals->oxygen_saturation.'%' : '—' }}</p></div>
                    </div>
                    <div class="grid gap-5 lg:grid-cols-2">
                        <div>
                            <h3 class="font-extrabold">História e condições</h3>
                            <dl class="mt-3 space-y-3 text-sm">
                                <div><dt class="font-bold text-slate-500">História breve da triagem</dt><dd>{{ $triage?->brief_history ?: '—' }}</dd></div>
                                <div><dt class="font-bold text-slate-500">Condições conhecidas</dt><dd>{{ $triage?->known_conditions ?: $patient->conditions->pluck('description')->filter()->join(', ') ?: '—' }}</dd></div>
                                <div><dt class="font-bold text-slate-500">Medicamentos em uso</dt><dd>{{ $triage?->current_medications ?: '—' }}</dd></div>
                            </dl>
                        </div>
                        <div>
                            <h3 class="font-extrabold">Linha do tempo deste episódio</h3>
                            <ol class="mt-3 space-y-2 text-sm">
                                <li class="rounded-lg bg-slate-50 px-4 py-3"><strong>Chegada</strong> · {{ $encounter->arrival_at->format('d/m/Y H:i') }}</li>
                                <li class="rounded-lg bg-slate-50 px-4 py-3"><strong>Triagem finalizada</strong> · {{ $encounter->triage_finished_at?->format('d/m/Y H:i') ?? '—' }}</li>
                                <li class="rounded-lg bg-slate-50 px-4 py-3"><strong>Atendimento médico</strong> · {{ $consultation->started_at->format('d/m/Y H:i') }}</li>
                            </ol>
                        </div>
                    </div>
                </section>

                <section x-show="tab === 'anamnesis'" x-cloak class="p-5 lg:p-7">
                    @if($editable)
                        <form method="POST" action="{{ route('medical.draft', $consultation) }}" class="space-y-4">
                            @csrf @method('PUT')
                            <input type="hidden" name="version" value="{{ $consultation->version() }}">
                            <div class="grid gap-4 lg:grid-cols-2">
                                @foreach([
                                    'chief_complaint' => 'Queixa principal',
                                    'present_illness_history' => 'História da doença atual',
                                    'personal_history' => 'Antecedentes pessoais',
                                    'family_history' => 'Antecedentes familiares',
                                    'surgical_history' => 'Histórico cirúrgico',
                                    'current_medications' => 'Medicamentos em uso',
                                    'allergies_summary' => 'Alergias',
                                    'habits' => 'Hábitos',
                                    'gynecological_history' => 'Histórico gineco-obstétrico',
                                    'review_of_systems' => 'Revisão de sistemas',
                                    'additional_notes' => 'Observações adicionais',
                                    'conduct_summary' => 'Resumo da conduta',
                                    'procedures_summary' => 'Procedimentos',
                                    'guidance' => 'Orientações',
                                ] as $field => $label)
                                    <div @class(['lg:col-span-2' => in_array($field, ['present_illness_history', 'conduct_summary', 'guidance'], true)])>
                                        <label class="field-label" for="{{ $field }}">{{ $label }}</label>
                                        <textarea id="{{ $field }}" name="{{ $field }}" rows="3" class="field-control">{{ old($field, $consultation->{$field}) }}</textarea>
                                    </div>
                                @endforeach
                            </div>
                            <label class="flex items-center gap-2 text-sm font-bold"><input type="checkbox" name="requires_reassessment" value="1" @checked($consultation->requires_reassessment)> Reavaliação necessária</label>
                            <div class="text-right"><button class="rounded-lg bg-brand-600 px-5 py-2.5 text-sm font-bold text-white">Salvar rascunho</button></div>
                        </form>
                    @else
                        <div class="grid gap-5 lg:grid-cols-2">
                            @foreach(['chief_complaint' => 'Queixa principal', 'present_illness_history' => 'História da doença atual', 'personal_history' => 'Antecedentes pessoais', 'conduct_summary' => 'Conduta', 'guidance' => 'Orientações'] as $field => $label)
                                <div><h3 class="text-sm font-extrabold text-slate-500">{{ $label }}</h3><p class="mt-1 whitespace-pre-line">{{ $consultation->{$field} ?: '—' }}</p></div>
                            @endforeach
                        </div>
                    @endif
                </section>

                <section x-show="tab === 'exam'" x-cloak class="p-5 lg:p-7">
                    @if($editable)
                        <form method="POST" action="{{ route('medical.draft', $consultation) }}" class="space-y-4">
                            @csrf @method('PUT')
                            <input type="hidden" name="version" value="{{ $consultation->version() }}">
                            <div class="grid gap-4 lg:grid-cols-2">
                                @foreach([
                                    'general_state' => 'Estado geral', 'consciousness' => 'Nível de consciência',
                                    'skin_mucosa' => 'Pele e mucosas', 'head_neck' => 'Cabeça e pescoço',
                                    'respiratory' => 'Aparelho respiratório', 'cardiovascular' => 'Aparelho cardiovascular',
                                    'abdomen' => 'Abdome', 'neurological' => 'Sistema neurológico',
                                    'musculoskeletal' => 'Sistema musculoesquelético', 'extremities' => 'Extremidades',
                                    'specific_findings' => 'Achados específicos', 'free_text' => 'Texto livre',
                                ] as $field => $label)
                                    <div><label class="field-label" for="{{ $field }}">{{ $label }}</label><textarea id="{{ $field }}" name="{{ $field }}" rows="3" class="field-control">{{ old($field, $consultation->physicalExam?->{$field}) }}</textarea></div>
                                @endforeach
                                <div class="lg:col-span-2"><label class="field-label" for="physical_exam_justification">Justificativa caso o exame não seja realizado</label><textarea id="physical_exam_justification" name="physical_exam_justification" rows="2" class="field-control">{{ old('physical_exam_justification', $consultation->physical_exam_justification) }}</textarea></div>
                            </div>
                            <div class="text-right"><button class="rounded-lg bg-brand-600 px-5 py-2.5 text-sm font-bold text-white">Salvar exame físico</button></div>
                        </form>
                    @else
                        <div class="grid gap-5 lg:grid-cols-2">
                            @foreach(['general_state' => 'Estado geral', 'consciousness' => 'Consciência', 'respiratory' => 'Respiratório', 'cardiovascular' => 'Cardiovascular', 'abdomen' => 'Abdome', 'neurological' => 'Neurológico', 'musculoskeletal' => 'Musculoesquelético', 'free_text' => 'Texto livre'] as $field => $label)
                                <div><h3 class="text-sm font-extrabold text-slate-500">{{ $label }}</h3><p class="mt-1 whitespace-pre-line">{{ $consultation->physicalExam?->{$field} ?: '—' }}</p></div>
                            @endforeach
                        </div>
                    @endif
                </section>

                <section x-show="tab === 'diagnosis'" x-cloak class="space-y-5 p-5 lg:p-7">
                    @if($editable)
                        <form method="POST" action="{{ route('medical.diagnoses', $consultation) }}" class="rounded-xl border border-slate-200 p-4">
                            @csrf
                            <input type="hidden" name="version" value="{{ $consultation->version() }}">
                            <div class="grid gap-4 lg:grid-cols-2">
                                <div>
                                    <label class="field-label" for="diagnosis_code_search">CID do catálogo</label>
                                    <x-medical.cid-search name="diagnosis_code_id" input-id="diagnosis_code_search" />
                                    <p class="mt-1 text-xs text-slate-500">Opcional quando for registrada apenas uma hipótese descritiva.</p>
                                </div>
                                <div><label class="field-label" for="diagnosis_description">Descrição provisória</label><input id="diagnosis_description" name="description" class="field-control"></div>
                                <div><label class="field-label" for="diagnosis_type">Tipo</label><select id="diagnosis_type" name="diagnosis_type" class="field-control" required><option value="hypothesis">Hipótese</option><option value="confirmed">Confirmado</option><option value="ruled_out">Descartado</option></select></div>
                                <div><label class="field-label" for="diagnosis_notes">Observação</label><input id="diagnosis_notes" name="notes" class="field-control"></div>
                            </div>
                            <label class="mt-4 flex items-center gap-2 text-sm font-bold"><input type="checkbox" name="is_primary" value="1"> Diagnóstico principal</label>
                            <div class="mt-4 text-right"><button class="rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-bold text-white">Registrar diagnóstico</button></div>
                        </form>
                    @endif
                    <div class="space-y-3">
                        @forelse($consultation->diagnoses->sortByDesc('diagnosed_at') as $diagnosis)
                            <article class="rounded-lg border border-slate-200 p-4"><div class="flex flex-wrap justify-between gap-2"><strong>{{ $diagnosis->code ? $diagnosis->code.' · ' : '' }}{{ $diagnosis->description }}</strong><span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-bold">{{ $diagnosis->is_primary ? 'Principal' : 'Secundário' }} · {{ $diagnosis->diagnosis_type }}</span></div><p class="mt-2 text-sm text-slate-600">{{ $diagnosis->notes ?: 'Sem observação.' }}</p></article>
                        @empty
                            <p class="rounded-lg bg-slate-50 p-5 text-sm text-slate-500">Nenhum diagnóstico registrado.</p>
                        @endforelse
                    </div>
                </section>

                <section x-show="tab === 'prescriptions'" x-cloak x-data="prescriptionItems(@js($prescriptionFormConfig))" class="space-y-5 p-5 lg:p-7">
                    @if($editable)
                        <form id="prescription_form" method="POST" action="{{ route('medical.prescriptions', $consultation) }}" class="scroll-mt-24 rounded-xl border border-slate-200 p-4">
                            @csrf
                            <input type="hidden" name="form_context" value="prescription">
                            <input type="hidden" name="replaces_prescription_id" x-model="editingId">
                            <input type="hidden" name="version" value="{{ $consultation->version() }}">
                            <div x-show="editingId" x-cloak class="mb-4 rounded-xl border border-amber-200 bg-amber-50 p-4">
                                <div class="flex flex-wrap items-center justify-between gap-3"><p class="font-extrabold text-amber-900">Editando uma prescrição vigente</p><button type="button" @click="cancelEditing()" class="text-sm font-bold text-amber-800 underline">Cancelar edição</button></div>
                                <p class="mt-1 text-xs text-amber-800">O registro original será preservado e marcado como substituído.</p>
                                <label class="field-label mt-3" for="replacement_reason">Motivo da alteração *</label>
                                <textarea id="replacement_reason" name="replacement_reason" x-model="replacementReason" :required="Boolean(editingId)" minlength="10" rows="2" class="field-control"></textarea>
                            </div>
                            <div class="mb-4 max-w-sm"><label class="field-label" for="prescription_type">Tipo</label><select id="prescription_type" name="prescription_type" x-model="prescriptionType" class="field-control"><option value="hospital">Hospitalar</option><option value="home">Receita domiciliar</option></select></div>
                            <div class="space-y-4">
                                <template x-for="(item, index) in items" :key="item._key">
                                    <fieldset class="rounded-xl border border-slate-200 bg-slate-50/50 p-4">
                                        <legend class="sr-only">Dados do medicamento</legend>
                                        <div class="mb-4 flex items-center justify-between gap-3">
                                            <p class="font-extrabold text-slate-800">Medicamento <span x-text="index + 1"></span></p>
                                            <button x-show="items.length > 1" type="button" @click="removeItem(index)" class="grid size-9 place-items-center rounded-lg border border-red-200 bg-white text-red-700 hover:bg-red-50" aria-label="Remover medicamento" title="Remover medicamento"><x-icons.trash /></button>
                                        </div>
                                        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                                            <div><label class="field-label" :for="`medication_name_${index}`">Medicamento/produto *</label><input :id="`medication_name_${index}`" :name="`items[${index}][medication_name]`" x-model="item.medication_name" required class="field-control"></div>
                                            <div><label class="field-label" :for="`presentation_${index}`">Apresentação *</label><input :id="`presentation_${index}`" :name="`items[${index}][presentation]`" x-model="item.presentation" required class="field-control"></div>
                                            <div><label class="field-label" :for="`concentration_${index}`">Concentração</label><input :id="`concentration_${index}`" :name="`items[${index}][concentration]`" x-model="item.concentration" class="field-control"></div>
                                            <div><label class="field-label" :for="`dose_${index}`">Dose *</label><input :id="`dose_${index}`" :name="`items[${index}][dose]`" x-model="item.dose" type="number" min="0.001" step="0.001" required class="field-control"></div>
                                            <div><label class="field-label" :for="`dose_unit_${index}`">Unidade *</label><input :id="`dose_unit_${index}`" :name="`items[${index}][dose_unit]`" x-model="item.dose_unit" placeholder="mg, mL, UI" required class="field-control"></div>
                                            <div><label class="field-label" :for="`route_${index}`">Via *</label><input :id="`route_${index}`" :name="`items[${index}][route]`" x-model="item.route" placeholder="Oral, IV, IM" required class="field-control"></div>
                                            <div><label class="field-label" :for="`frequency_${index}`">Frequência *</label><input :id="`frequency_${index}`" :name="`items[${index}][frequency]`" x-model="item.frequency" placeholder="A cada 8 horas" required class="field-control"></div>
                                            <div class="md:col-span-2 xl:col-span-4"><label class="field-label" :for="`item_instructions_${index}`">Posologia e orientações</label><textarea :id="`item_instructions_${index}`" :name="`items[${index}][instructions]`" x-model="item.instructions" rows="2" class="field-control"></textarea></div>
                                        </div>
                                    </fieldset>
                                </template>
                            </div>
                            <button type="button" @click="addItem()" :disabled="items.length >= 30" class="mt-4 inline-flex items-center gap-2 rounded-lg border border-brand-300 bg-white px-4 py-2.5 text-sm font-bold text-brand-700 disabled:cursor-not-allowed disabled:opacity-50"><x-icons.plus /> Adicionar medicamento</button>
                            <div class="mt-4"><label class="field-label" for="general_instructions">Observações gerais</label><textarea id="general_instructions" name="general_instructions" x-model="generalInstructions" rows="2" class="field-control"></textarea></div>
                            <div class="mt-4 text-right"><button class="rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-bold text-white"><span x-text="editingId ? 'Salvar nova versão' : 'Finalizar prescrição'"></span> (<span x-text="items.length"></span> medicamento<span x-show="items.length !== 1">s</span>)</button></div>
                        </form>
                    @endif
                    @forelse($consultation->prescriptions->sortByDesc('finalized_at') as $prescription)
                        @php
                            $prescriptionStatus = match ($prescription->status) {
                                'finalized' => ['Vigente', 'border-emerald-200 bg-emerald-50/40', 'bg-emerald-100 text-emerald-800'],
                                'replaced' => ['Substituída', 'border-amber-200 bg-amber-50/40', 'bg-amber-100 text-amber-800'],
                                'cancelled' => ['Cancelada', 'border-red-200 bg-red-50/40', 'bg-red-100 text-red-800'],
                                default => [ucfirst($prescription->status), 'border-slate-200 bg-slate-50', 'bg-slate-200 text-slate-700'],
                            };
                            $canManagePrescription = auth()->user()?->canManageClinicalRecordOwnedBy($prescription->professional_id) ?? false;
                        @endphp
                        <article x-data="{ cancelling: false }" class="safe-wrap rounded-xl border p-4 {{ $prescriptionStatus[1] }}">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <div class="flex flex-wrap items-center gap-2"><strong>{{ $prescription->prescription_type === 'hospital' ? 'Prescrição hospitalar' : 'Receita domiciliar' }}</strong><span class="rounded-full px-2.5 py-1 text-[11px] font-extrabold uppercase {{ $prescriptionStatus[2] }}">{{ $prescriptionStatus[0] }}</span></div>
                                    <p class="mt-1 text-xs text-slate-500">Versão {{ $prescription->version }} · {{ $prescription->items->count() }} medicamento(s) · {{ $prescription->finalized_at?->format('d/m/Y H:i') }}</p>
                                </div>
                                <div class="flex flex-wrap items-center gap-2">
                                    @can('medical.issue_documents')
                                        @if($prescription->document)
                                            <a href="{{ route('documents.pdf', $prescription->document) }}" class="rounded-lg border border-brand-300 bg-white px-3 py-2 text-xs font-bold text-brand-700">Baixar PDF</a>
                                        @elseif($prescription->status === 'finalized')
                                            <form method="POST" action="{{ route('medical.prescriptions.document', [$consultation, $prescription]) }}">@csrf<button class="rounded-lg bg-brand-600 px-3 py-2 text-xs font-bold text-white">Gerar PDF</button></form>
                                        @endif
                                    @endcan
                                    @if($canManagePrescription && $prescription->status === 'finalized')
                                        @if($editable)
                                            <button type="button" @click="startEditing('{{ $prescription->public_id }}')" class="grid size-9 place-items-center rounded-lg border border-amber-300 bg-white text-amber-700 hover:bg-amber-50" aria-label="Editar prescrição" title="Editar prescrição"><x-icons.pencil /></button>
                                        @endif
                                        <button type="button" @click="cancelling = !cancelling" class="grid size-9 place-items-center rounded-lg border border-red-300 bg-white text-red-700 hover:bg-red-50" aria-label="Cancelar prescrição" title="Cancelar prescrição"><x-icons.trash /></button>
                                    @endif
                                </div>
                            </div>
                            <div class="mt-4 grid gap-2 md:grid-cols-2">
                                @foreach($prescription->items as $item)
                                    <div class="rounded-lg border border-white/70 bg-white/80 p-3 text-sm"><strong>{{ $item->medication_name }}</strong><p class="mt-1 text-xs text-slate-600">{{ $item->presentation }}@if($item->concentration) · {{ $item->concentration }}@endif<br>{{ $item->dose }} {{ $item->dose_unit }} · {{ $item->route }} · {{ $item->frequency }}</p></div>
                                @endforeach
                            </div>
                            @if($prescription->status === 'replaced' && $prescription->replacement_reason)<p class="mt-3 rounded-lg bg-amber-100/70 p-3 text-xs text-amber-900"><strong>Motivo da substituição:</strong> {{ $prescription->replacement_reason }}</p>@endif
                            @if($prescription->status === 'cancelled' && $prescription->cancellation_reason)<p class="mt-3 rounded-lg bg-red-100/70 p-3 text-xs text-red-900"><strong>Motivo do cancelamento:</strong> {{ $prescription->cancellation_reason }}</p>@endif
                            @if($canManagePrescription && $prescription->status === 'finalized')
                                <form x-show="cancelling" x-cloak method="POST" action="{{ route('medical.prescriptions.cancel', [$consultation, $prescription]) }}" class="mt-4 rounded-lg border border-red-200 bg-white p-4">
                                    @csrf
                                    <input type="hidden" name="confirmation" value="1">
                                    <label class="field-label" for="cancel_prescription_{{ $prescription->public_id }}">Motivo do cancelamento *</label>
                                    <textarea id="cancel_prescription_{{ $prescription->public_id }}" name="reason" required minlength="10" rows="2" class="field-control"></textarea>
                                    <div class="mt-3 flex justify-end gap-2"><button type="button" @click="cancelling = false" class="rounded-lg border border-slate-300 px-3 py-2 text-xs font-bold">Voltar</button><button class="inline-flex items-center gap-2 rounded-lg bg-red-700 px-3 py-2 text-xs font-bold text-white"><x-icons.trash /> Confirmar cancelamento</button></div>
                                </form>
                            @endif
                        </article>
                    @empty
                        <p class="rounded-lg bg-slate-50 p-5 text-sm text-slate-500">Nenhuma prescrição registrada.</p>
                    @endforelse
                </section>

                <section x-show="tab === 'exams'" x-cloak class="space-y-5 p-5 lg:p-7">
                    @if($editable)
                        <form method="POST" action="{{ route('medical.exam-orders', $consultation) }}" x-data="examOrderItems(@js(old('form_context') === 'exam_order' && is_array(old('items')) ? array_values(old('items')) : [[]]), { searchUrl: @js(route('medical.laboratory-exams.search')) })" class="rounded-xl border border-slate-200 p-4">
                            @csrf
                            <input type="hidden" name="form_context" value="exam_order">
                            <input type="hidden" name="version" value="{{ $consultation->version() }}">
                            <div class="mb-4 grid gap-4 lg:grid-cols-2">
                                <div><label class="field-label" for="exam_priority">Prioridade do pedido *</label><select id="exam_priority" name="priority" class="field-control"><option value="routine" @selected(old('form_context') !== 'exam_order' || old('priority') === 'routine')>Rotina</option><option value="urgent" @selected(old('form_context') === 'exam_order' && old('priority') === 'urgent')>Urgente</option><option value="emergency" @selected(old('form_context') === 'exam_order' && old('priority') === 'emergency')>Emergência</option></select></div>
                                <div><label class="field-label" for="clinical_indication">Indicação clínica *</label><textarea id="clinical_indication" name="clinical_indication" required rows="3" class="field-control">{{ old('form_context') === 'exam_order' ? old('clinical_indication') : '' }}</textarea></div>
                            </div>
                            <div class="space-y-4">
                                <template x-for="(item, index) in items" :key="item._key">
                                    <fieldset class="rounded-xl border border-slate-200 bg-slate-50/50 p-4">
                                        <legend class="sr-only">Dados do exame</legend>
                                        <div class="mb-4 flex items-center justify-between gap-3"><p class="font-extrabold text-slate-800">Exame <span x-text="index + 1"></span></p><button x-show="items.length > 1" type="button" @click="removeItem(index)" class="grid size-9 place-items-center rounded-lg border border-red-200 bg-white text-red-700 hover:bg-red-50" aria-label="Remover exame" title="Remover exame"><x-icons.trash /></button></div>
                                        <div class="grid gap-4 lg:grid-cols-2 xl:grid-cols-3">
                                            <div class="relative">
                                                <label class="field-label" :for="`exam_name_${index}`">Exame *</label>
                                                <input type="hidden" :name="`items[${index}][laboratory_exam_id]`" :value="item.laboratory_exam_id">
                                                <input :id="`exam_name_${index}`" :name="`items[${index}][exam_name]`" x-model="item.exam_name" @input.debounce.250ms="searchCatalog(index)" @focus="item.catalogOpen = item.catalogResults.length > 0" @keydown.escape="item.catalogOpen = false" autocomplete="off" required class="field-control">
                                                <p x-show="item.group === 'laboratory' && item.catalogSearching" class="mt-1 text-xs text-slate-500">Buscando no catálogo do laboratório...</p>
                                                <p x-show="item.group === 'laboratory' && item.laboratory_exam_id" class="mt-1 text-xs font-bold text-emerald-700">Exame vinculado à tabela de exames da unidade.</p>
                                                <div x-show="item.catalogOpen" @click.outside="item.catalogOpen = false" class="absolute z-30 mt-1 max-h-72 w-full overflow-y-auto rounded-lg border border-slate-200 bg-white shadow-xl">
                                                    <template x-for="exam in item.catalogResults" :key="exam.id">
                                                        <button type="button" @click="selectCatalogExam(item, exam)" class="block w-full border-b border-slate-100 px-3 py-3 text-left last:border-0 hover:bg-brand-50">
                                                            <span class="block text-sm font-bold" x-text="exam.label"></span>
                                                            <span class="mt-1 block text-xs text-slate-500" x-text="[exam.material, exam.container, exam.cap_color].filter(Boolean).join(' · ')"></span>
                                                        </button>
                                                    </template>
                                                    <p x-show="!item.catalogSearching && item.catalogResults.length === 0" class="p-3 text-sm text-slate-500">Nenhum exame encontrado no catálogo desta unidade.</p>
                                                </div>
                                            </div>
                                            <div><label class="field-label" :for="`exam_group_${index}`">Grupo *</label><select :id="`exam_group_${index}`" :name="`items[${index}][group]`" x-model="item.group" @change="groupChanged(item)" class="field-control"><option value="laboratory">Laboratório</option><option value="imaging">Imagem</option><option value="cardiology">Cardiologia</option><option value="other">Outro</option></select></div>
                                            <div><label class="field-label" :for="`internal_code_${index}`">Código interno</label><input :id="`internal_code_${index}`" :name="`items[${index}][internal_code]`" x-model="item.internal_code" class="field-control"></div>
                                            <div><label class="field-label" :for="`laterality_${index}`">Lateralidade</label><select :id="`laterality_${index}`" :name="`items[${index}][laterality]`" x-model="item.laterality" class="field-control"><option value="not_applicable">Não se aplica</option><option value="right">Direita</option><option value="left">Esquerda</option><option value="bilateral">Bilateral</option></select></div>
                                            <div><label class="field-label" :for="`preparation_${index}`">Preparo</label><textarea :id="`preparation_${index}`" :name="`items[${index}][preparation]`" x-model="item.preparation" rows="2" class="field-control"></textarea></div>
                                            <div><label class="field-label" :for="`justification_${index}`">Justificativa específica</label><textarea :id="`justification_${index}`" :name="`items[${index}][justification]`" x-model="item.justification" rows="2" class="field-control"></textarea></div>
                                        </div>
                                    </fieldset>
                                </template>
                            </div>
                            <button type="button" @click="addItem()" :disabled="items.length >= 30" class="mt-4 inline-flex items-center gap-2 rounded-lg border border-brand-300 bg-white px-4 py-2.5 text-sm font-bold text-brand-700 disabled:cursor-not-allowed disabled:opacity-50"><x-icons.plus /> Adicionar exame</button>
                            <div class="mt-4 text-right"><button class="rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-bold text-white">Solicitar <span x-text="items.length"></span> exame<span x-show="items.length !== 1">s</span></button></div>
                        </form>
                    @endif
                    @forelse($consultation->examOrders->sortByDesc('requested_at') as $order)
                        <article class="safe-wrap rounded-lg border border-slate-200 p-4">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <p class="text-sm"><strong>Indicação:</strong> {{ $order->clinical_indication }}</p>
                                @can('medical.issue_documents')
                                    @if($order->document)
                                        <a href="{{ route('documents.pdf', $order->document) }}" class="rounded-lg border border-brand-300 px-3 py-2 text-xs font-bold text-brand-700">Baixar PDF</a>
                                    @else
                                        <form method="POST" action="{{ route('medical.exam-orders.document', [$consultation, $order]) }}">@csrf<button class="rounded-lg bg-brand-600 px-3 py-2 text-xs font-bold text-white">Gerar PDF</button></form>
                                    @endif
                                @endcan
                            </div>
                            @foreach($order->items as $item)
                                <div class="mt-3 rounded-lg bg-slate-50 p-4">
                                    <div class="flex flex-wrap justify-between gap-2"><strong>{{ $item->exam_name }}</strong><span class="text-xs font-bold uppercase">{{ $item->status }}</span></div>
                                    <p class="mt-2 text-xs text-slate-500">
                                        Resultados não são registrados manualmente no SYNC SUS nesta versão.
                                    </p>
                                </div>
                            @endforeach
                        </article>
                    @empty
                        <p class="rounded-lg bg-slate-50 p-5 text-sm text-slate-500">Nenhum exame solicitado.</p>
                    @endforelse
                </section>

                <section x-show="tab === 'evolution'" x-cloak class="space-y-5 p-5 lg:p-7">
                    @if($editable)
                        <form method="POST" action="{{ route('medical.clinical-notes', $consultation) }}" class="rounded-xl border border-slate-200 p-4">
                            @csrf
                            <input type="hidden" name="version" value="{{ $consultation->version() }}">
                            <div class="grid gap-4 lg:grid-cols-3">
                                <div><label class="field-label" for="note_type">Tipo</label><select id="note_type" name="note_type" class="field-control"><option value="medical_evolution">Evolução médica</option><option value="reassessment">Reavaliação</option><option value="intercurrence">Intercorrência</option></select></div>
                                <div class="lg:col-span-2"><label class="field-label" for="note_content">Registro clínico *</label><textarea id="note_content" name="content" required rows="4" class="field-control"></textarea></div>
                            </div>
                            <div class="mt-4 text-right"><button class="rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-bold text-white">Finalizar evolução</button></div>
                        </form>
                    @endif
                    @forelse($consultation->clinicalNotes->sortByDesc('clinical_at') as $note)
                        <article class="rounded-lg border border-slate-200 p-4"><div class="flex justify-between gap-3"><strong>{{ $note->note_type }}</strong><span class="text-xs text-slate-500">{{ $note->clinical_at->format('d/m/Y H:i') }} · {{ $note->author->name }}</span></div><p class="mt-3 whitespace-pre-line text-sm">{{ $note->content }}</p></article>
                    @empty
                        <p class="rounded-lg bg-slate-50 p-5 text-sm text-slate-500">Nenhuma evolução registrada.</p>
                    @endforelse
                </section>

                <section x-show="tab === 'corrections'" x-cloak class="space-y-5 p-5 lg:p-7">
                    <x-alert type="warning">Anulações preservam o conteúdo original e exigem justificativa. A opção “apagar documento” invalida o PDF e sua verificação, mas mantém todas as versões para auditoria.</x-alert>
                    @php($canCorrectDocuments = auth()->user()?->can('medical.issue_documents') && auth()->user()?->canManageClinicalRecordOwnedBy($consultation->professional_id))
                    <div>
                        <h2 class="text-lg font-extrabold text-slate-950">Documentos gerados</h2>
                        <p class="mt-1 text-sm text-slate-500">A lista inclui documentos emitidos antes e depois desta atualização.</p>
                        <div class="mt-4 grid gap-4 lg:grid-cols-2">
                            @forelse($consultation->documents->sortByDesc('created_at') as $document)
                                <article x-data="{ deleting: false }" class="safe-wrap rounded-xl border p-4 {{ $document->status === 'active' ? 'border-slate-200 bg-white' : 'border-red-200 bg-red-50/60' }}">
                                    <div class="flex flex-wrap items-start justify-between gap-3">
                                        <div>
                                            <p class="text-xs font-extrabold uppercase text-slate-500">{{ $document->typeEnum()->label() }}</p>
                                            <h3 class="mt-1 font-extrabold text-slate-950">{{ $document->title }}</h3>
                                            <p class="mt-1 text-xs text-slate-500">{{ $document->created_at->format('d/m/Y H:i') }} · v{{ $document->currentVersion?->version_number ?? '—' }} · {{ $document->public_id }}</p>
                                        </div>
                                        <span class="rounded-full px-2.5 py-1 text-[11px] font-extrabold uppercase {{ $document->status === 'active' ? 'bg-emerald-100 text-emerald-800' : 'bg-red-100 text-red-800' }}">{{ $document->status === 'active' ? 'Válido' : 'Apagado' }}</span>
                                    </div>
                                    <div class="mt-4 flex flex-wrap items-center gap-2">
                                        <a href="{{ route('documents.show', $document) }}" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700">Detalhes</a>
                                        <a href="{{ route('documents.pdf', $document) }}" class="rounded-lg border border-brand-300 bg-white px-3 py-2 text-xs font-bold text-brand-700">Baixar PDF</a>
                                        @if($document->status === 'active' && $canCorrectDocuments)
                                            <button type="button" @click="deleting = !deleting" class="ml-auto inline-flex items-center gap-2 rounded-lg border border-red-300 bg-white px-3 py-2 text-xs font-bold text-red-700 hover:bg-red-50" aria-label="Apagar documento {{ $document->title }}"><x-icons.trash /> Apagar documento</button>
                                        @endif
                                    </div>
                                    @if($document->status === 'voided')
                                        <div class="mt-4 rounded-lg bg-red-100/70 p-3 text-xs text-red-900">
                                            <strong>Apagado em {{ $document->voided_at?->format('d/m/Y H:i') }}:</strong> {{ $document->void_reason }}
                                        </div>
                                    @elseif($canCorrectDocuments)
                                        <form x-show="deleting" x-cloak method="POST" action="{{ route('documents.void', $document) }}" class="mt-4 rounded-lg border border-red-200 bg-red-50 p-4" id="delete_document_{{ $document->public_id }}">
                                            @csrf
                                            <label class="field-label" for="delete_document_reason_{{ $document->public_id }}">Motivo para apagar *</label>
                                            <textarea id="delete_document_reason_{{ $document->public_id }}" name="reason" required minlength="10" maxlength="1000" rows="2" class="field-control" placeholder="Explique por que este documento deve ser invalidado"></textarea>
                                            <label class="mt-3 flex items-start gap-2 text-sm text-red-900"><input type="checkbox" name="confirmation" value="1" required class="mt-1"> Confirmo que o PDF será invalidado e que o histórico será preservado.</label>
                                            <div class="mt-3 flex justify-end gap-2"><button type="button" @click="deleting = false" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-bold">Voltar</button><button class="inline-flex items-center gap-2 rounded-lg bg-red-700 px-3 py-2 text-xs font-bold text-white"><x-icons.trash /> Confirmar</button></div>
                                        </form>
                                    @endif
                                </article>
                            @empty
                                <p class="rounded-lg bg-slate-50 p-5 text-sm text-slate-500 lg:col-span-2">Nenhum documento foi gerado neste atendimento.</p>
                            @endforelse
                        </div>
                    </div>
                    <div class="border-t border-slate-200 pt-5">
                        <h2 class="text-lg font-extrabold text-slate-950">Registros clínicos</h2>
                        <p class="mt-1 text-sm text-slate-500">Anule registros incorretos sem remover seu conteúdo original.</p>
                    </div>
                    @foreach($consultation->diagnoses->where('status', '!=', 'voided') as $diagnosis)
                        <form method="POST" action="{{ route('medical.diagnoses.void', [$consultation, $diagnosis]) }}" class="rounded-lg border p-4">
                            @csrf
                            <strong>Diagnostico: {{ $diagnosis->code }} {{ $diagnosis->description }}</strong>
                            <textarea name="reason" required minlength="10" rows="2" class="field-control mt-3" placeholder="Motivo detalhado da anulacao"></textarea>
                            <label class="mt-2 flex gap-2 text-sm"><input type="checkbox" name="confirmation" value="1" required> Confirmo a anulacao</label>
                            <button class="mt-3 rounded-lg bg-red-700 px-3 py-2 text-sm font-bold text-white">Anular diagnostico</button>
                        </form>
                    @endforeach
                    @foreach($consultation->prescriptions->where('status', '!=', 'cancelled') as $prescription)
                        <form method="POST" action="{{ route('medical.prescriptions.cancel', [$consultation, $prescription]) }}" class="rounded-lg border p-4">
                            @csrf
                            <strong>Prescricao {{ $prescription->public_id }}</strong>
                            <textarea name="reason" required minlength="10" rows="2" class="field-control mt-3" placeholder="Motivo detalhado do cancelamento"></textarea>
                            <label class="mt-2 flex gap-2 text-sm"><input type="checkbox" name="confirmation" value="1" required> Confirmo o cancelamento</label>
                            <button class="mt-3 rounded-lg bg-red-700 px-3 py-2 text-sm font-bold text-white">Cancelar prescricao</button>
                        </form>
                    @endforeach
                    @foreach($consultation->clinicalNotes->where('status', '!=', 'voided') as $note)
                        <form method="POST" action="{{ route('medical.clinical-notes.void', [$consultation, $note]) }}" class="rounded-lg border p-4">
                            @csrf
                            <strong>Evolucao de {{ $note->clinical_at->format('d/m/Y H:i') }}</strong>
                            <textarea name="reason" required minlength="10" rows="2" class="field-control mt-3" placeholder="Motivo detalhado da anulacao"></textarea>
                            <label class="mt-2 flex gap-2 text-sm"><input type="checkbox" name="confirmation" value="1" required> Confirmo a anulacao</label>
                            <button class="mt-3 rounded-lg bg-red-700 px-3 py-2 text-sm font-bold text-white">Anular evolucao</button>
                        </form>
                    @endforeach
                </section>

                <section x-show="tab === 'referrals'" x-cloak class="space-y-5 p-5 lg:p-7">
                    @if($editable)
                        <form method="POST" action="{{ route('medical.referrals', $consultation) }}" class="rounded-xl border border-slate-200 p-4">
                            @csrf
                            <input type="hidden" name="version" value="{{ $consultation->version() }}">
                            <div class="grid gap-4 lg:grid-cols-2">
                                <div><label class="field-label" for="referral_type">Tipo</label><select id="referral_type" name="referral_type" class="field-control"><option value="internal">Interno</option><option value="external">Externo</option></select></div>
                                <div><label class="field-label" for="referral_priority">Prioridade</label><select id="referral_priority" name="priority" class="field-control"><option value="routine">Rotina</option><option value="urgent">Urgente</option><option value="emergency">Emergência</option></select></div>
                                <div><label class="field-label" for="referral_destination">Destino *</label><input id="referral_destination" name="destination" required class="field-control"></div>
                                <div><label class="field-label" for="specialty_id">Especialidade</label><select id="specialty_id" name="specialty_id" class="field-control"><option value="">Não definida</option>@foreach($specialties as $specialty)<option value="{{ $specialty->getKey() }}">{{ $specialty->name }}</option>@endforeach</select></div>
                                <div><label class="field-label" for="referral_reason">Motivo *</label><textarea id="referral_reason" name="reason" required rows="3" class="field-control"></textarea></div>
                                <div><label class="field-label" for="referral_summary">Resumo clínico *</label><textarea id="referral_summary" name="clinical_summary" required rows="3" class="field-control"></textarea></div>
                            </div>
                            <div class="mt-4 text-right"><button class="rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-bold text-white">Emitir encaminhamento</button></div>
                        </form>
                    @endif
                    @forelse($consultation->referrals->sortByDesc('issued_at') as $referral)
                        <article class="safe-wrap rounded-lg border border-slate-200 p-4">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div><strong>{{ $referral->destination }}</strong><span class="ml-2 text-xs font-bold uppercase">{{ $referral->status }}</span></div>
                                @can('medical.issue_documents')
                                    @if($referral->document)
                                        <a href="{{ route('documents.pdf', $referral->document) }}" class="rounded-lg border border-brand-300 px-3 py-2 text-xs font-bold text-brand-700">Baixar PDF</a>
                                    @else
                                        <form method="POST" action="{{ route('medical.referrals.document', [$consultation, $referral]) }}">@csrf<button class="rounded-lg bg-brand-600 px-3 py-2 text-xs font-bold text-white">Gerar PDF</button></form>
                                    @endif
                                @endcan
                            </div>
                            <p class="mt-2 text-sm">{{ $referral->reason }}</p><p class="mt-2 text-sm text-slate-600">{{ $referral->clinical_summary }}</p>
                        </article>
                    @empty
                        <p class="rounded-lg bg-slate-50 p-5 text-sm text-slate-500">Nenhum encaminhamento emitido.</p>
                    @endforelse
                </section>

                <section x-show="tab === 'certificates'" x-cloak class="space-y-5 p-5 lg:p-7">
                    @can('medical.issue_documents')
                        <form method="POST" action="{{ route('medical.medical-certificates.store', $consultation) }}" class="rounded-xl border border-slate-200 p-4">
                            @csrf
                            <div class="grid gap-4 lg:grid-cols-2">
                                <div><label class="field-label" for="certificate_starts_at">Início do afastamento *</label><input id="certificate_starts_at" name="starts_at" type="datetime-local" value="{{ old('starts_at', now()->format('Y-m-d\TH:i')) }}" required class="field-control"></div>
                                <div class="grid grid-cols-2 gap-3">
                                    <div><label class="field-label" for="certificate_duration_value">Duração *</label><input id="certificate_duration_value" name="duration_value" type="number" min="1" max="365" value="{{ old('duration_value') }}" required class="field-control"></div>
                                    <div><label class="field-label" for="certificate_duration_unit">Unidade *</label><select id="certificate_duration_unit" name="duration_unit" required class="field-control"><option value="days">Dias</option><option value="hours">Horas</option></select></div>
                                </div>
                                <div class="lg:col-span-2"><label class="field-label" for="certificate_statement">Declaração clínica *</label><textarea id="certificate_statement" name="statement" required minlength="10" rows="5" class="field-control">{{ old('statement') }}</textarea></div>
                                <div class="lg:col-span-2"><label class="field-label" for="certificate_additional_information">Informações adicionais</label><textarea id="certificate_additional_information" name="additional_information" rows="3" class="field-control">{{ old('additional_information') }}</textarea></div>
                            </div>
                            <div x-data="{ includeCid: @js(old('include_cid', false)) }" class="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-4">
                                <label class="flex items-center gap-2 text-sm font-bold"><input x-model="includeCid" type="checkbox" name="include_cid" value="1"> Incluir CID mediante autorização expressa</label>
                                <div x-show="includeCid" x-cloak class="mt-3 grid gap-3 lg:grid-cols-2">
                                    <div><label class="field-label" for="certificate_cid_search">CID *</label><x-medical.cid-search name="cid_code_id" input-id="certificate_cid_search" /></div>
                                    <label class="flex items-center gap-2 self-end pb-3 text-sm"><input type="checkbox" name="cid_authorization" value="1"> Confirmo a autorização para inclusão do CID</label>
                                </div>
                            </div>
                            <div class="mt-4 text-right"><button class="rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-bold text-white">Emitir atestado e gerar PDF</button></div>
                        </form>
                    @endcan
                    <div class="space-y-3">
                        @forelse($consultation->medicalCertificates->sortByDesc('issued_at') as $certificate)
                            <article class="rounded-lg border border-slate-200 p-4">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div><strong>Atestado médico</strong><p class="mt-1 text-xs text-slate-500">{{ $certificate->issued_at->format('d/m/Y H:i') }} · {{ $certificate->duration_value }} {{ $certificate->duration_unit === 'hours' ? 'hora(s)' : 'dia(s)' }}</p></div>
                                    @if($certificate->document)
                                        <div class="flex gap-2"><a href="{{ route('documents.show', $certificate->document) }}" class="rounded-lg border border-slate-300 px-3 py-2 text-xs font-bold">Detalhes</a><a href="{{ route('documents.pdf', $certificate->document) }}" class="rounded-lg bg-brand-600 px-3 py-2 text-xs font-bold text-white">Baixar PDF</a></div>
                                    @endif
                                </div>
                                <p class="mt-3 whitespace-pre-line text-sm">{{ $certificate->statement }}</p>
                                @if($certificate->include_cid && $certificate->diagnosisCode)<p class="mt-2 text-xs font-bold text-amber-800">CID autorizado: {{ $certificate->diagnosisCode->code }} · {{ $certificate->diagnosisCode->description }}</p>@endif
                            </article>
                        @empty
                            <p class="rounded-lg bg-slate-50 p-5 text-sm text-slate-500">Nenhum atestado emitido neste atendimento.</p>
                        @endforelse
                    </div>
                </section>

                <section x-show="tab === 'documents'" x-cloak class="space-y-5 p-5 lg:p-7">
                    @can('medical.issue_documents')
                        <form method="POST" action="{{ route('medical.documents', $consultation) }}" class="rounded-xl border border-slate-200 p-4">
                            @csrf
                            <div class="grid gap-4 lg:grid-cols-2">
                                <div class="lg:col-span-2"><label class="field-label" for="document_type">Tipo de documento *</label><select id="document_type" name="document_type" x-model="documentType" required class="field-control"><option value="attendance_declaration">Declaração de comparecimento</option><option value="companion_declaration">Declaração de acompanhante</option><option value="medical_report">Relatório médico</option><option value="discharge_guidance">Orientações de alta</option><option value="encounter_summary">Resumo do atendimento</option></select></div>
                                <div x-show="['companion_declaration','medical_report'].includes(documentType)"><label class="field-label" for="recipient_name" x-text="documentType === 'companion_declaration' ? 'Nome do acompanhante *' : 'Destinatário'"></label><input id="recipient_name" name="recipient_name" class="field-control"></div>
                                <div x-show="['attendance_declaration','companion_declaration'].includes(documentType)"><label class="field-label" for="starts_at">Data e hora inicial *</label><input id="starts_at" name="starts_at" type="datetime-local" class="field-control"></div>
                                <div x-show="['attendance_declaration','companion_declaration'].includes(documentType)"><label class="field-label" for="duration_value">Duração *</label><input id="duration_value" name="duration_value" type="number" min="1" max="365" class="field-control"></div>
                                <div x-show="['attendance_declaration','companion_declaration'].includes(documentType)"><label class="field-label" for="duration_unit">Unidade da duração *</label><select id="duration_unit" name="duration_unit" class="field-control"><option value="hours">Horas</option><option value="days">Dias</option></select></div>
                                <div class="lg:col-span-2"><label class="field-label" for="document_body" x-text="({ attendance_declaration: 'Finalidade ou observações *', companion_declaration: 'Finalidade ou observações *', medical_report: 'Relatório clínico *', discharge_guidance: 'Orientações de alta *', encounter_summary: 'Resumo do atendimento *' })[documentType]"></label><textarea id="document_body" name="body" required rows="8" class="field-control">{{ old('body') }}</textarea></div>
                                <div class="lg:col-span-2"><label class="field-label" for="additional_information">Informações adicionais</label><textarea id="additional_information" name="additional_information" rows="3" class="field-control">{{ old('additional_information') }}</textarea></div>
                            </div>
                            <div x-show="documentType === 'medical_report'" x-data="{ includeCid: @js(old('include_cid', false)) }" class="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-4">
                                <label class="flex items-center gap-2 text-sm font-bold"><input x-model="includeCid" type="checkbox" name="include_cid" value="1"> Incluir CID mediante autorização expressa</label>
                                <div x-show="includeCid" x-cloak class="mt-3 grid gap-3 lg:grid-cols-2">
                                    <div>
                                        <label class="field-label" for="report_cid_search">CID *</label>
                                        <x-medical.cid-search name="cid_code_id" input-id="report_cid_search" />
                                    </div>
                                    <label class="flex items-center gap-2 self-end pb-3 text-sm"><input type="checkbox" name="cid_authorization" value="1"> Confirmo a autorização para inclusão do CID</label>
                                </div>
                            </div>
                            <div class="mt-4 text-right"><button class="rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-bold text-white">Emitir documento e PDF</button></div>
                        </form>
                    @endcan
                    <div class="grid gap-3 lg:grid-cols-2">
                        @forelse($consultation->documents->reject(fn ($item) => $item->typeEnum()->isSourceManaged())->sortByDesc('created_at') as $document)
                            <a href="{{ route('documents.show', $document) }}" class="rounded-lg border border-slate-200 p-4 transition hover:border-brand-300 hover:bg-brand-50/30">
                                <div class="flex justify-between gap-2"><strong>{{ $document->title }}</strong><span class="text-xs font-bold uppercase {{ $document->status === 'active' ? 'text-emerald-700' : 'text-red-700' }}">{{ $document->status }}</span></div>
                                <p class="mt-2 text-xs text-slate-500">v{{ $document->currentVersion->version_number }} · {{ $document->created_at->format('d/m/Y H:i') }}</p>
                            </a>
                        @empty
                            <p class="rounded-lg bg-slate-50 p-5 text-sm text-slate-500 lg:col-span-2">Nenhum documento emitido neste atendimento.</p>
                        @endforelse
                    </div>
                </section>

                <section x-show="tab === 'destination'" x-cloak class="space-y-5 p-5 lg:p-7">
                    @if($editable)
                        <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                            A finalização exige resumo clínico ou anamnese, exame físico ou justificativa, conduta e exatamente um diagnóstico principal.
                        </div>
                        <form method="POST" action="{{ route('medical.complete', $consultation) }}" class="space-y-4">
                            @csrf
                            <input type="hidden" name="version" value="{{ $consultation->version() }}">
                            <div><label class="field-label" for="destination_type">Destinação *</label><select id="destination_type" name="destination_type" x-model="destination" class="field-control" required><option value="discharge">Alta</option><option value="observation">Observação</option><option value="admission_request">Solicitação de internação</option><option value="transfer">Transferência</option><option value="evasion">Evasão</option><option value="death">Óbito</option></select></div>
                            <div class="grid gap-4 lg:grid-cols-2">
                                <div><label class="field-label" for="destination_reason">Motivo *</label><textarea id="destination_reason" name="reason" required rows="3" class="field-control"></textarea></div>
                                <div><label class="field-label" for="clinical_condition">Condição clínica</label><textarea id="clinical_condition" name="clinical_condition" rows="3" class="field-control"></textarea></div>
                                <div><label class="field-label" for="destination_summary">Resumo clínico</label><textarea id="destination_summary" name="clinical_summary" rows="3" class="field-control"></textarea></div>
                                <div x-show="destination === 'discharge'"><label class="field-label" for="instructions">Orientações de alta *</label><textarea id="instructions" name="instructions" rows="3" class="field-control"></textarea></div>
                                <div x-show="destination === 'discharge'"><label class="field-label" for="warning_signs">Sinais de alerta *</label><textarea id="warning_signs" name="warning_signs" rows="3" class="field-control"></textarea></div>
                                <div x-show="['observation','admission_request','transfer'].includes(destination)"><label class="field-label" for="destination_department">Setor de destino *</label><input id="destination_department" name="destination_department" class="field-control"></div>
                                <div x-show="destination === 'admission_request'"><label class="field-label" for="bed_type">Tipo de leito *</label><input id="bed_type" name="bed_type" class="field-control"></div>
                                <div x-show="destination === 'transfer'"><label class="field-label" for="destination_institution">Instituição de destino *</label><input id="destination_institution" name="destination_institution" class="field-control"></div>
                                <div x-show="destination === 'transfer'"><label class="field-label" for="destination_city">Cidade *</label><input id="destination_city" name="destination_city" class="field-control"></div>
                                <div x-show="destination === 'transfer'"><label class="field-label" for="transport_method">Transporte *</label><input id="transport_method" name="transport_method" class="field-control"></div>
                                <div x-show="destination === 'evasion'"><label class="field-label" for="last_known_location">Última localização *</label><input id="last_known_location" name="last_known_location" class="field-control"></div>
                                <div x-show="destination === 'evasion'"><label class="field-label" for="contact_attempts">Tentativas de chamada/contato *</label><textarea id="contact_attempts" name="contact_attempts" rows="3" class="field-control"></textarea></div>
                                <div x-show="destination === 'death'"><label class="field-label" for="death_cause">Diagnóstico ou causa informada *</label><textarea id="death_cause" name="death_cause" rows="3" class="field-control"></textarea></div>
                            </div>
                            <label class="flex items-start gap-3 rounded-lg border border-brand-200 bg-brand-50 p-4 text-sm font-bold"><input type="checkbox" name="professional_confirmation" value="1" required class="mt-1"> Confirmo que revisei o atendimento, os registros pendentes e a destinação selecionada.</label>
                            <div class="text-right"><button class="rounded-lg bg-emerald-600 px-5 py-2.5 text-sm font-extrabold text-white">Finalizar atendimento</button></div>
                        </form>
                    @else
                        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-5">
                            <p class="text-xs font-extrabold uppercase text-emerald-700">Destinação final</p>
                            <h3 class="mt-1 text-xl font-extrabold">{{ $consultation->destination->destination_type->label() }}</h3>
                            <p class="mt-3 whitespace-pre-line">{{ $consultation->destination->reason }}</p>
                            <p class="mt-2 text-sm text-slate-600">Registrada por {{ $consultation->destination->recordedBy->name }} em {{ $consultation->destination->occurred_at->format('d/m/Y H:i') }}</p>
                            <p class="mt-3 break-all text-xs text-slate-500">Integridade: {{ $consultation->content_hash }}</p>
                        </div>
                        <div>
                            <h3 class="font-extrabold">Adendos</h3>
                            <div class="mt-3 space-y-3">@forelse($consultation->addenda->sortByDesc('recorded_at') as $addendum)<article class="rounded-lg border border-slate-200 p-4"><div class="flex justify-between gap-3"><strong>{{ $addendum->reason }}</strong><span class="text-xs text-slate-500">{{ $addendum->recorded_at->format('d/m/Y H:i') }} · {{ $addendum->author->name }}</span></div><p class="mt-2 whitespace-pre-line text-sm">{{ $addendum->content }}</p></article>@empty<p class="text-sm text-slate-500">Nenhum adendo.</p>@endforelse</div>
                            <form method="POST" action="{{ route('medical.addendum', $consultation) }}" class="mt-5 rounded-xl border border-slate-200 p-4">
                                @csrf
                                <div class="grid gap-4 lg:grid-cols-2"><div><label class="field-label" for="addendum_reason">Motivo *</label><input id="addendum_reason" name="reason" required class="field-control"></div><div><label class="field-label" for="addendum_content">Conteúdo do adendo *</label><textarea id="addendum_content" name="content" required rows="3" class="field-control"></textarea></div></div>
                                <div class="mt-4 text-right"><button class="rounded-lg border border-brand-300 px-4 py-2.5 text-sm font-bold text-brand-700">Registrar adendo</button></div>
                            </form>
                        </div>
                    @endif
                </section>
            </section>
        </div>
    </div>
</x-layout.app>
