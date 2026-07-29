@php
    $isDraft = $triage->statusEnum() === \App\Modules\Triage\Domain\Enums\TriageAssessmentStatus::Draft;
    $patient = $triage->encounter->patient;
    $ticket = $triage->queueEntry->ticket_number;
@endphp

<x-layout.app :title="'Triagem · '.$ticket">
    <div x-data="{ tab: @js(request('tab', 'assessment')) }">
        <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <span class="rounded-lg bg-brand-100 px-3 py-1 text-sm font-black text-brand-800">Senha {{ $ticket }}</span>
                    <span class="rounded-lg bg-slate-200 px-3 py-1 text-sm font-bold text-slate-700">{{ $patient->ageYears() !== null ? $patient->ageYears().' anos' : 'Idade não informada' }}</span>
                    <span @class([
                        'rounded-lg px-3 py-1 text-sm font-bold',
                        'bg-amber-100 text-amber-800' => $isDraft,
                        'bg-emerald-100 text-emerald-800' => !$isDraft,
                    ])>{{ $triage->statusEnum()->label() }}</span>
                </div>
                <h1 class="mt-2 text-2xl font-extrabold text-slate-950">Triagem — {{ $patient->displayName() }}</h1>
                <p class="mt-1 text-sm text-slate-600">{{ $triage->professional->name }} · {{ $triage->servicePoint?->name ?? 'Ponto não informado' }} · iniciada em {{ $triage->started_at->format('d/m/Y H:i') }}</p>
            </div>
            @if(!$isDraft && $triage->riskLevel)
                <div class="rounded-xl border px-5 py-3 text-right" style="border-color: {{ match($triage->riskLevel->color_key) { 'red' => '#dc2626', 'orange' => '#ea580c', 'yellow' => '#ca8a04', 'green' => '#16a34a', default => '#2563eb' } }}">
                    <p class="text-xs font-bold uppercase text-slate-500">Classificação confirmada</p>
                    <p class="text-xl font-black uppercase">{{ $triage->riskLevel->name }}</p>
                    <p class="text-xs text-slate-500">Referência: {{ $triage->riskLevel->reference_minutes }} min</p>
                </div>
            @endif
        </div>

        @if($errors->any())
            <x-alert type="danger" class="mb-5"><strong>Não foi possível concluir a operação.</strong> Revise os campos destacados.</x-alert>
        @endif

        <div class="mb-5 grid gap-4 lg:grid-cols-4">
            <x-card class="p-4">
                <p class="text-xs font-bold uppercase text-slate-500">Paciente</p>
                <p class="mt-1 font-extrabold">{{ $patient->displayName() }}</p>
                <p class="text-sm text-slate-500">{{ $patient->sex->label() }} · {{ $patient->medical_record_number }}</p>
            </x-card>
            <x-card class="p-4">
                <p class="text-xs font-bold uppercase text-slate-500">Chegada</p>
                <p class="mt-1 font-extrabold">{{ $triage->encounter->arrival_at->format('d/m/Y H:i') }}</p>
                <p class="text-sm text-slate-500">{{ $triage->encounter->arrivalMethod->name }}</p>
            </x-card>
            <x-card class="p-4 lg:col-span-2">
                <p class="text-xs font-bold uppercase text-red-600">Alergias conhecidas</p>
                @forelse($patient->allergies->where('status', 'active') as $allergy)
                    <p class="mt-1 font-bold text-red-800">{{ $allergy->substance }}{{ $allergy->reaction ? ': '.$allergy->reaction : '' }}</p>
                @empty
                    <p class="mt-1 text-sm text-slate-500">Nenhuma alergia longitudinal registrada.</p>
                @endforelse
            </x-card>
        </div>

        <x-card class="overflow-hidden">
            <nav class="flex gap-1 overflow-x-auto border-b border-slate-200 px-4 pt-3" aria-label="Seções da triagem">
                @foreach(['assessment' => 'Avaliação', 'vitals' => 'Sinais vitais', 'history' => 'Histórico', 'classification' => 'Classificação e destino'] as $key => $label)
                    <button type="button" @click="tab = '{{ $key }}'" class="whitespace-nowrap border-b-3 px-4 py-3 text-sm font-bold" :class="tab === '{{ $key }}' ? 'border-brand-600 text-brand-700' : 'border-transparent text-slate-500'">{{ $label }}</button>
                @endforeach
            </nav>

            <section x-show="tab === 'assessment'" class="p-5 lg:p-7">
                @if($isDraft)
                    <form method="POST" action="{{ route('triage.draft', $triage) }}" class="space-y-5">
                        @csrf @method('PUT')
                        <input type="hidden" name="version" value="{{ $triage->version() }}">
                        <div class="grid gap-5 lg:grid-cols-2">
                            <x-form.textarea name="chief_complaint" label="Queixa principal" required rows="4">{{ old('chief_complaint', $triage->chief_complaint) }}</x-form.textarea>
                            <x-form.textarea name="brief_history" label="História resumida" required rows="4">{{ old('brief_history', $triage->brief_history) }}</x-form.textarea>
                            <x-form.input name="symptom_onset" label="Início dos sintomas" :value="old('symptom_onset', $triage->symptom_onset)" placeholder="Data, hora ou descrição informada" />
                            <x-form.input name="pain_scale" label="Escala de dor (0–10)" type="number" min="0" max="10" :value="old('pain_scale', $triage->pain_scale)" />
                            <x-form.select name="has_reported_allergies" label="Alergias relatadas" required>
                                <option value="">Selecione</option>
                                <option value="0" @selected((string) old('has_reported_allergies', $triage->has_reported_allergies === null ? '' : (int) $triage->has_reported_allergies) === '0')>Nega</option>
                                <option value="1" @selected((string) old('has_reported_allergies', $triage->has_reported_allergies === null ? '' : (int) $triage->has_reported_allergies) === '1')>Relata alergia</option>
                            </x-form.select>
                            <x-form.input name="reported_allergies" label="Descrição das alergias" :value="old('reported_allergies', $triage->reported_allergies)" />
                            <x-form.select name="uses_medications" label="Medicamentos em uso">
                                <option value="">Não avaliado</option>
                                <option value="0" @selected((string) old('uses_medications', $triage->uses_medications === null ? '' : (int) $triage->uses_medications) === '0')>Nega</option>
                                <option value="1" @selected((string) old('uses_medications', $triage->uses_medications === null ? '' : (int) $triage->uses_medications) === '1')>Sim</option>
                            </x-form.select>
                            <x-form.input name="current_medications" label="Quais medicamentos" :value="old('current_medications', $triage->current_medications)" />
                            <div class="lg:col-span-2"><x-form.textarea name="known_conditions" label="Condições e antecedentes conhecidos" rows="3">{{ old('known_conditions', $triage->known_conditions) }}</x-form.textarea></div>
                            <x-form.select name="pregnancy_status" label="Gestação/suspeita">
                                @foreach(['' => 'Não avaliado', 'not_applicable' => 'Não aplicável', 'denies' => 'Nega', 'confirmed' => 'Confirmada', 'suspected' => 'Suspeita', 'unknown' => 'Não sabe informar'] as $value => $label)
                                    <option value="{{ $value }}" @selected(old('pregnancy_status', $triage->pregnancy_status) === $value)>{{ $label }}</option>
                                @endforeach
                            </x-form.select>
                            <x-form.select name="fall_risk" label="Risco de queda">
                                @foreach(['' => 'Não avaliado', 'low' => 'Baixo', 'moderate' => 'Moderado', 'high' => 'Alto', 'not_assessed' => 'Não foi possível avaliar'] as $value => $label)
                                    <option value="{{ $value }}" @selected(old('fall_risk', $triage->fall_risk) === $value)>{{ $label }}</option>
                                @endforeach
                            </x-form.select>
                            <x-form.select name="requires_isolation" label="Necessidade de isolamento">
                                <option value="0" @selected(!old('requires_isolation', $triage->requires_isolation))>Não</option>
                                <option value="1" @selected(old('requires_isolation', $triage->requires_isolation))>Sim</option>
                            </x-form.select>
                            <x-form.input name="isolation_reason" label="Motivo do isolamento" :value="old('isolation_reason', $triage->isolation_reason)" />
                            <x-form.select name="violence_signs" label="Sinais de violência — campo protegido">
                                <option value="0" @selected(!old('violence_signs', $triage->violence_signs))>Não identificados</option>
                                <option value="1" @selected(old('violence_signs', $triage->violence_signs))>Identificados/suspeitos</option>
                            </x-form.select>
                            <x-form.input name="violence_notes" label="Registro protegido sobre violência" :value="old('violence_notes', $triage->violence_notes)" />
                            <x-form.textarea name="initial_exam" label="Exame inicial" rows="4">{{ old('initial_exam', $triage->initial_exam) }}</x-form.textarea>
                            <x-form.textarea name="observations" label="Observações" rows="4">{{ old('observations', $triage->observations) }}</x-form.textarea>
                        </div>
                        <div class="flex justify-end"><x-button.primary>Salvar rascunho</x-button.primary></div>
                    </form>
                @else
                    <dl class="grid gap-5 lg:grid-cols-2">
                        <div><dt class="text-xs font-bold uppercase text-slate-500">Queixa principal</dt><dd class="mt-1 whitespace-pre-line">{{ $triage->chief_complaint }}</dd></div>
                        <div><dt class="text-xs font-bold uppercase text-slate-500">História resumida</dt><dd class="mt-1 whitespace-pre-line">{{ $triage->brief_history }}</dd></div>
                        <div><dt class="text-xs font-bold uppercase text-slate-500">Alergias relatadas</dt><dd class="mt-1">{{ $triage->has_reported_allergies ? $triage->reported_allergies : 'Nega' }}</dd></div>
                        <div><dt class="text-xs font-bold uppercase text-slate-500">Condições conhecidas</dt><dd class="mt-1">{{ $triage->known_conditions ?: 'Não informado' }}</dd></div>
                        <div class="lg:col-span-2"><dt class="text-xs font-bold uppercase text-slate-500">Exame inicial e observações</dt><dd class="mt-1 whitespace-pre-line">{{ $triage->initial_exam }} {{ $triage->observations }}</dd></div>
                    </dl>
                @endif
            </section>

            <section id="vitals" x-show="tab === 'vitals'" x-cloak class="p-5 lg:p-7">
                @if($isDraft)
                    <form method="POST" action="{{ route('triage.vital-signs', $triage) }}" class="mb-7 rounded-xl border border-slate-200 bg-slate-50 p-5">
                        @csrf
                        <input type="hidden" name="version" value="{{ $triage->version() }}">
                        <input type="hidden" name="height_unit" value="cm">
                        <h2 class="font-extrabold text-slate-900">Nova aferição</h2>
                        <p class="mt-1 text-sm text-slate-500">Valores fora da faixa técnica exigem confirmação, mas não definem a classificação de risco.</p>
                        <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <x-form.input name="systolic_bp" label="Pressão sistólica (mmHg)" type="number" :value="old('systolic_bp')" />
                            <x-form.input name="diastolic_bp" label="Pressão diastólica (mmHg)" type="number" :value="old('diastolic_bp')" />
                            <x-form.input name="heart_rate" label="Frequência cardíaca (bpm)" type="number" :value="old('heart_rate')" />
                            <x-form.input name="respiratory_rate" label="Frequência respiratória (irpm)" type="number" :value="old('respiratory_rate')" />
                            <x-form.input name="temperature_c" label="Temperatura (°C)" type="number" step="0.1" :value="old('temperature_c')" />
                            <x-form.input name="oxygen_saturation" label="Saturação O₂ (%)" type="number" step="0.1" :value="old('oxygen_saturation')" />
                            <x-form.input name="blood_glucose" label="Glicemia (mg/dL)" type="number" :value="old('blood_glucose')" />
                            <x-form.input name="pain_scale" label="Dor (0–10)" type="number" min="0" max="10" :value="old('pain_scale')" />
                            <x-form.input name="weight_kg" label="Peso (kg)" type="number" step="0.01" :value="old('weight_kg')" />
                            <x-form.input name="height" label="Altura (cm)" type="number" step="0.1" :value="old('height')" />
                            <x-form.input name="glasgow_score" label="Glasgow (3–15)" type="number" min="3" max="15" :value="old('glasgow_score')" />
                            <x-form.input name="circumference_cm" label="Circunferência (cm)" type="number" step="0.1" :value="old('circumference_cm')" />
                        </div>
                        <fieldset class="mt-5">
                            <legend class="field-label">Alertas observados (não classificam automaticamente)</legend>
                            <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                @foreach(['known_hypertension' => 'Hipertensão conhecida', 'diabetes' => 'Diabetes', 'suspected_sepsis' => 'Sepse suspeita', 'dyspnea' => 'Dispneia', 'active_bleeding' => 'Sangramento ativo', 'arrhythmia' => 'Arritmia/taquicardia', 'medication_allergy' => 'Alergia medicamentosa', 'external_medication' => 'Medicação externa administrada', 'oxygen_in_use' => 'Oxigênio em uso', 'invasive_device' => 'Dispositivo invasivo'] as $value => $label)
                                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="clinical_alerts[]" value="{{ $value }}"> {{ $label }}</label>
                                @endforeach
                            </div>
                        </fieldset>
                        <div class="mt-4"><x-form.textarea name="notes" label="Observações da aferição" rows="2">{{ old('notes') }}</x-form.textarea></div>
                        <label class="mt-4 flex items-center gap-2 text-sm font-bold"><input type="checkbox" name="confirm_outside_ranges" value="1"> Confirmo que revisei valores eventualmente fora da faixa técnica usual</label>
                        <div class="mt-5 text-right"><x-button.primary>Registrar aferição</x-button.primary></div>
                    </form>
                @endif

                <h2 class="font-extrabold text-slate-900">Histórico de sinais vitais</h2>
                <div class="mt-3 space-y-4">
                    @forelse($triage->vitalSigns->sortByDesc('measured_at') as $vital)
                        <div class="rounded-xl border border-slate-200 p-5">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <p class="font-bold">{{ $vital->measured_at->format('d/m/Y H:i') }} · {{ $vital->recordedBy->name }}</p>
                                @if($vital->technical_alerts)<span class="rounded-full bg-amber-100 px-3 py-1 text-xs font-bold text-amber-800">Valor técnico confirmado</span>@endif
                            </div>
                            <dl class="mt-4 grid gap-4 sm:grid-cols-3 lg:grid-cols-6">
                                <div><dt class="text-xs text-slate-500">Pressão</dt><dd class="text-xl font-black">{{ $vital->systolic_bp ?? '—' }} / {{ $vital->diastolic_bp ?? '—' }}</dd></div>
                                <div><dt class="text-xs text-slate-500">FC</dt><dd class="text-xl font-black">{{ $vital->heart_rate ?? '—' }} <small>bpm</small></dd></div>
                                <div><dt class="text-xs text-slate-500">FR</dt><dd class="text-xl font-black">{{ $vital->respiratory_rate ?? '—' }} <small>irpm</small></dd></div>
                                <div><dt class="text-xs text-slate-500">Temperatura</dt><dd class="text-xl font-black">{{ $vital->temperature_c ?? '—' }} <small>°C</small></dd></div>
                                <div><dt class="text-xs text-slate-500">Sat. O₂</dt><dd class="text-xl font-black">{{ $vital->oxygen_saturation ?? '—' }} <small>%</small></dd></div>
                                <div><dt class="text-xs text-slate-500">Glicemia</dt><dd class="text-xl font-black">{{ $vital->blood_glucose ?? '—' }} <small>mg/dL</small></dd></div>
                                <div><dt class="text-xs text-slate-500">Peso</dt><dd class="text-xl font-black">{{ $vital->weight_kg ?? '—' }} <small>kg</small></dd></div>
                                <div><dt class="text-xs text-slate-500">Altura</dt><dd class="text-xl font-black">{{ $vital->height_cm ?? '—' }} <small>cm</small></dd></div>
                                <div><dt class="text-xs text-slate-500">IMC</dt><dd class="text-xl font-black">{{ $vital->bmi ?? '—' }}</dd></div>
                                <div><dt class="text-xs text-slate-500">Dor</dt><dd class="text-xl font-black">{{ $vital->pain_scale ?? '—' }}</dd></div>
                                <div><dt class="text-xs text-slate-500">Glasgow</dt><dd class="text-xl font-black">{{ $vital->glasgow_score ?? '—' }}</dd></div>
                            </dl>
                        </div>
                    @empty
                        <p class="rounded-lg bg-slate-50 p-5 text-sm text-slate-500">Nenhuma aferição registrada.</p>
                    @endforelse
                </div>
            </section>

            <section x-show="tab === 'history'" x-cloak class="p-5 lg:p-7">
                <div class="grid gap-5 lg:grid-cols-2">
                    <div><h2 class="font-extrabold">Atendimentos anteriores</h2><p class="mt-2 text-sm text-slate-500">O histórico clínico longitudinal será ampliado nas próximas fases.</p></div>
                    <div><h2 class="font-extrabold">Alergias longitudinais</h2>@forelse($patient->allergies as $allergy)<p class="mt-2">{{ $allergy->substance }} — {{ $allergy->reaction }}</p>@empty<p class="mt-2 text-sm text-slate-500">Sem registros anteriores.</p>@endforelse</div>
                </div>
            </section>

            <section x-show="tab === 'classification'" x-cloak class="p-5 lg:p-7">
                @if($isDraft)
                    <form method="POST" action="{{ route('triage.complete', $triage) }}" class="space-y-5">
                        @csrf
                        <input type="hidden" name="version" value="{{ $triage->version() }}">
                        <x-alert type="warning">A classificação final é decisão do profissional. Alertas técnicos e discriminadores não selecionam automaticamente o risco.</x-alert>
                        <div class="grid gap-4 lg:grid-cols-3">
                            <x-form.select name="triage_protocol_id" label="Protocolo utilizado" required>
                                <option value="">Selecione</option>
                                @foreach($protocols as $protocol)<option value="{{ $protocol->id }}">{{ $protocol->name }} · {{ $protocol->version }}</option>@endforeach
                            </x-form.select>
                            <x-form.select name="triage_flowchart_id" label="Fluxograma" required>
                                <option value="">Selecione</option>
                                @foreach($protocols as $protocol) @foreach($protocol->flowcharts as $flowchart)<option value="{{ $flowchart->id }}">{{ $flowchart->name }}</option>@endforeach @endforeach
                            </x-form.select>
                            <x-form.select name="triage_discriminator_id" label="Discriminador" required>
                                <option value="">Selecione</option>
                                @foreach($protocols as $protocol) @foreach($protocol->flowcharts as $flowchart) @foreach($flowchart->discriminators as $discriminator)<option value="{{ $discriminator->id }}">{{ $flowchart->name }} · {{ $discriminator->name }}</option>@endforeach @endforeach @endforeach
                            </x-form.select>
                        </div>
                        <fieldset>
                            <legend class="field-label">Nível de risco confirmado</legend>
                            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                                @foreach($riskLevels as $risk)
                                    <label class="cursor-pointer rounded-xl border-2 p-4 text-center has-checked:ring-3 has-checked:ring-brand-200" style="border-color: {{ match($risk->color_key) { 'red' => '#dc2626', 'orange' => '#ea580c', 'yellow' => '#ca8a04', 'green' => '#16a34a', default => '#2563eb' } }}">
                                        <input type="radio" name="risk_level_id" value="{{ $risk->id }}" required class="mb-2">
                                        <strong class="block uppercase">{{ $risk->name }}</strong>
                                        <small>{{ $risk->reference_minutes }} min</small>
                                    </label>
                                @endforeach
                            </div>
                        </fieldset>
                        <x-form.textarea name="risk_justification" label="Justificativa da classificação" required rows="4">{{ old('risk_justification') }}</x-form.textarea>
                        <div class="grid gap-4 lg:grid-cols-2">
                            <x-form.select name="destination_queue_id" label="Fila de destino" required>
                                <option value="">Selecione</option>
                                @foreach($destinationQueues as $queue)<option value="{{ $queue->id }}">{{ $queue->department->name }} · {{ $queue->name }}</option>@endforeach
                            </x-form.select>
                            <x-form.textarea name="routing_notes" label="Observação do encaminhamento" rows="2">{{ old('routing_notes') }}</x-form.textarea>
                        </div>
                        <label class="flex items-start gap-3 rounded-lg border border-brand-200 bg-brand-50 p-4 text-sm font-bold"><input type="checkbox" name="professional_confirmation" value="1" required class="mt-1"> Confirmo que revisei a avaliação e que o nível de risco foi escolhido por decisão profissional.</label>
                        <div class="text-right"><x-button.primary>Finalizar triagem e encaminhar</x-button.primary></div>
                    </form>
                @else
                    <dl class="grid gap-5 lg:grid-cols-2">
                        <div><dt class="text-xs font-bold uppercase text-slate-500">Protocolo</dt><dd class="mt-1 font-bold">{{ $triage->protocol?->name }} · {{ $triage->protocol_version }}</dd></div>
                        <div><dt class="text-xs font-bold uppercase text-slate-500">Fluxo e discriminador</dt><dd class="mt-1">{{ $triage->flowchart?->name }} · {{ $triage->discriminator?->name }}</dd></div>
                        <div><dt class="text-xs font-bold uppercase text-slate-500">Risco</dt><dd class="mt-1 text-xl font-black uppercase">{{ $triage->riskLevel?->name }}</dd></div>
                        <div><dt class="text-xs font-bold uppercase text-slate-500">Destino</dt><dd class="mt-1 font-bold">{{ $triage->destinationQueue?->name }}</dd></div>
                        <div class="lg:col-span-2"><dt class="text-xs font-bold uppercase text-slate-500">Justificativa</dt><dd class="mt-1 whitespace-pre-line">{{ $triage->risk_justification }}</dd></div>
                    </dl>

                    <div class="mt-8 border-t border-slate-200 pt-6">
                        <h2 class="font-extrabold">Adendos</h2>
                        <div class="mt-3 space-y-3">
                            @forelse($triage->addenda->sortByDesc('recorded_at') as $addendum)
                                <div class="rounded-lg border border-slate-200 p-4"><p class="text-xs font-bold text-slate-500">{{ $addendum->recorded_at->format('d/m/Y H:i') }} · {{ $addendum->author->name }} · {{ $addendum->reason }}</p><p class="mt-2 whitespace-pre-line">{{ $addendum->content }}</p></div>
                            @empty<p class="text-sm text-slate-500">Nenhum adendo registrado.</p>@endforelse
                        </div>
                        @can('triage.addendum')
                            <form method="POST" action="{{ route('triage.addendum', $triage) }}" class="mt-5 grid gap-4 rounded-xl bg-slate-50 p-5">
                                @csrf
                                <x-form.input name="reason" label="Motivo do adendo" required :value="old('reason')" />
                                <x-form.textarea name="content" label="Conteúdo do adendo" required rows="4">{{ old('content') }}</x-form.textarea>
                                <div class="text-right"><x-button.primary>Registrar adendo</x-button.primary></div>
                            </form>
                        @endcan
                    </div>
                @endif
            </section>
        </x-card>
    </div>
</x-layout.app>
