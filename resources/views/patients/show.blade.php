<x-layout.app :title="$patient->displayName()">
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
            <div class="flex flex-wrap items-center gap-2">
                <p class="font-mono text-sm font-bold text-brand-700">{{ $patient->medical_record_number }}</p>
                @if($patient->is_provisional)<span class="rounded-full bg-amber-100 px-2.5 py-1 text-xs font-bold text-amber-800">Identificação provisória</span>@endif
            </div>
            <h1 class="mt-1 text-2xl font-extrabold text-slate-950">{{ $patient->displayName() }}</h1>
            @if($patient->social_name)<p class="text-sm text-slate-500">Nome civil: {{ $patient->full_name }}</p>@endif
        </div>
        <div class="flex gap-3">
            @can('encounters.open')<a href="{{ route('reception.create', ['patient' => $patient->public_id]) }}" class="inline-flex items-center rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-bold text-white">Abrir atendimento</a>@endcan
            @can('patients.update')<a href="{{ route('patients.edit', $patient) }}" class="inline-flex items-center rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold">Editar cadastro</a>@endcan
        </div>
    </div>

    <div class="grid gap-5 lg:grid-cols-3">
        <x-card class="p-5 lg:col-span-2">
            <h2 class="font-extrabold text-slate-900">Dados pessoais</h2>
            <dl class="mt-4 grid gap-4 sm:grid-cols-2">
                <div><dt class="text-xs font-bold uppercase text-slate-500">Nascimento</dt><dd class="mt-1">{{ $patient->birth_date?->format('d/m/Y') ?? 'Não informado' }} @if($patient->ageYears() !== null)({{ $patient->ageYears() }} anos)@endif</dd></div>
                <div><dt class="text-xs font-bold uppercase text-slate-500">Sexo</dt><dd class="mt-1">{{ $patient->sex->label() }}</dd></div>
                <div><dt class="text-xs font-bold uppercase text-slate-500">Mãe</dt><dd class="mt-1">{{ $patient->mother_unknown ? 'Não informada' : ($patient->mother_name ?? 'Não informado') }}</dd></div>
                <div><dt class="text-xs font-bold uppercase text-slate-500">Naturalidade / IBGE</dt><dd class="mt-1">{{ $patient->birth_city ?? 'Não informada' }} @if($patient->birth_city_ibge_code)· {{ $patient->birth_city_ibge_code }}@endif</dd></div>
                <div class="sm:col-span-2"><dt class="text-xs font-bold uppercase text-slate-500">Documentos</dt><dd class="mt-1">@forelse($patient->identifiers as $identifier)<span class="mr-3 uppercase">{{ $identifier->typeValue() }} {{ $identifier->maskedValue() }}</span>@empty Não informado @endforelse</dd></div>
            </dl>
        </x-card>
        <x-card class="p-5">
            <h2 class="font-extrabold text-slate-900">Contato</h2>
            <div class="mt-4 space-y-2 text-sm">
                @forelse($patient->contacts as $contact)<p><span class="font-bold capitalize">{{ $contact->type }}:</span> {{ $contact->value }}</p>@empty<p class="text-slate-500">Nenhum contato informado.</p>@endforelse
            </div>
        </x-card>

        <x-card class="p-5 lg:col-span-3">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <h2 class="font-extrabold text-slate-900">Resumo clínico longitudinal</h2>
                    <p class="mt-1 text-sm text-slate-500">Alergias, condições, medicamentos contínuos e hábitos informados pelo paciente.</p>
                </div>
                <span class="rounded-full bg-red-50 px-3 py-1 text-xs font-bold text-red-700">{{ $patient->allergies->where('status', 'active')->count() }} alergia(s) ativa(s)</span>
            </div>

            <div class="mt-5 grid gap-5 xl:grid-cols-3">
                <section>
                    <h3 class="text-sm font-extrabold uppercase tracking-wide text-slate-600">Alergias</h3>
                    <div class="mt-3 space-y-2">
                        @forelse($patient->allergies as $allergy)
                            <div class="rounded-lg border border-slate-200 p-3 text-sm">
                                <p class="font-bold">{{ $allergy->substance }} <span class="font-normal text-slate-500">· {{ $allergy->status }}</span></p>
                                <p class="mt-1 text-slate-600">{{ $allergy->reaction ?: 'Reação não informada' }} @if($allergy->severity)· {{ $allergy->severity }}@endif</p>
                            </div>
                        @empty<p class="text-sm text-slate-500">Nenhuma alergia registrada.</p>@endforelse
                    </div>
                    @can('patients.clinical_history')
                        <form method="POST" action="{{ route('patients.clinical-history.allergies.store', $patient) }}" class="mt-4 space-y-3 rounded-lg bg-slate-50 p-3">
                            @csrf
                            <x-form.input name="substance" label="Substância" required />
                            <x-form.input name="reaction" label="Reação" />
                            <div class="grid grid-cols-2 gap-3">
                                <x-form.select name="severity" label="Gravidade"><option value="">Não informada</option><option value="mild">Leve</option><option value="moderate">Moderada</option><option value="severe">Grave</option><option value="unknown">Desconhecida</option></x-form.select>
                                <x-form.select name="status" label="Status"><option value="active">Ativa</option><option value="inactive">Inativa</option><option value="resolved">Resolvida</option></x-form.select>
                            </div>
                            <x-button.primary>Adicionar alergia</x-button.primary>
                        </form>
                    @endcan
                </section>

                <section>
                    <h3 class="text-sm font-extrabold uppercase tracking-wide text-slate-600">Condições de saúde</h3>
                    <div class="mt-3 space-y-2">
                        @forelse($patient->conditions as $condition)
                            <div class="rounded-lg border border-slate-200 p-3 text-sm">
                                <p class="font-bold">{{ $condition->description }} <span class="font-normal text-slate-500">· {{ $condition->status }}</span></p>
                                @if($condition->code || $condition->onset_date)<p class="mt-1 text-slate-600">{{ $condition->code }} @if($condition->onset_date)· desde {{ $condition->onset_date->format('d/m/Y') }}@endif</p>@endif
                            </div>
                        @empty<p class="text-sm text-slate-500">Nenhuma condição registrada.</p>@endforelse
                    </div>
                    @can('patients.clinical_history')
                        <form method="POST" action="{{ route('patients.clinical-history.conditions.store', $patient) }}" class="mt-4 space-y-3 rounded-lg bg-slate-50 p-3">
                            @csrf
                            <x-form.input name="description" label="Condição" required />
                            <div class="grid grid-cols-2 gap-3">
                                <x-form.input name="code" label="CID/CIAP" />
                                <x-form.input name="onset_date" label="Início" type="date" />
                            </div>
                            <x-form.select name="status" label="Status"><option value="active">Ativa</option><option value="inactive">Inativa</option><option value="resolved">Resolvida</option></x-form.select>
                            <x-form.textarea name="notes" label="Observação" rows="2"></x-form.textarea>
                            <x-button.primary>Adicionar condição</x-button.primary>
                        </form>
                    @endcan
                </section>

                <section>
                    <h3 class="text-sm font-extrabold uppercase tracking-wide text-slate-600">Medicamentos contínuos</h3>
                    <div class="mt-3 space-y-2">
                        @forelse($patient->medications as $medication)
                            <div class="rounded-lg border border-slate-200 p-3 text-sm">
                                <p class="font-bold">{{ $medication->medication_name }} <span class="font-normal text-slate-500">· {{ $medication->status }}</span></p>
                                <p class="mt-1 text-slate-600">{{ collect([$medication->dosage, $medication->frequency, $medication->route])->filter()->join(' · ') ?: 'Posologia não informada' }}</p>
                            </div>
                        @empty<p class="text-sm text-slate-500">Nenhum medicamento registrado.</p>@endforelse
                    </div>
                    @can('patients.clinical_history')
                        <form method="POST" action="{{ route('patients.clinical-history.medications.store', $patient) }}" class="mt-4 space-y-3 rounded-lg bg-slate-50 p-3">
                            @csrf
                            <x-form.input name="medication_name" label="Medicamento" required />
                            <div class="grid grid-cols-2 gap-3"><x-form.input name="dosage" label="Dose" /><x-form.input name="frequency" label="Frequência" /></div>
                            <div class="grid grid-cols-2 gap-3">
                                <x-form.input name="route" label="Via" />
                                <x-form.select name="status" label="Status"><option value="active">Ativo</option><option value="suspended">Suspenso</option><option value="completed">Concluído</option></x-form.select>
                            </div>
                            <x-button.primary>Adicionar medicamento</x-button.primary>
                        </form>
                    @endcan
                </section>
            </div>
        </x-card>

        <x-card class="p-5 lg:col-span-3">
            <h2 class="font-extrabold text-slate-900">Histórico social</h2>
            @can('patients.clinical_history')
                <form method="POST" action="{{ route('patients.clinical-history.social.update', $patient) }}" class="mt-4 grid gap-4 md:grid-cols-2 lg:grid-cols-4">
                    @csrf @method('PUT')
                    <x-form.select name="smoking_status" label="Tabagismo"><option value="">Não informado</option>@foreach(['never' => 'Nunca fumou', 'former' => 'Ex-fumante', 'current' => 'Fumante atual', 'unknown' => 'Desconhecido'] as $value => $label)<option value="{{ $value }}" @selected(old('smoking_status', $patient->socialHistory?->smoking_status) === $value)>{{ $label }}</option>@endforeach</x-form.select>
                    <x-form.select name="alcohol_use" label="Uso de álcool"><option value="">Não informado</option>@foreach(['none' => 'Não usa', 'occasional' => 'Ocasional', 'frequent' => 'Frequente', 'unknown' => 'Desconhecido'] as $value => $label)<option value="{{ $value }}" @selected(old('alcohol_use', $patient->socialHistory?->alcohol_use) === $value)>{{ $label }}</option>@endforeach</x-form.select>
                    <x-form.input name="other_substance_use" label="Outras substâncias" :value="old('other_substance_use', $patient->socialHistory?->other_substance_use)" />
                    <x-form.input name="notes" label="Observações" :value="old('notes', $patient->socialHistory?->notes)" />
                    <div class="lg:col-span-4"><x-button.primary>Salvar histórico social</x-button.primary></div>
                </form>
            @else
                <p class="mt-3 text-sm text-slate-600">{{ collect([$patient->socialHistory?->smoking_status, $patient->socialHistory?->alcohol_use, $patient->socialHistory?->other_substance_use])->filter()->join(' · ') ?: 'Não informado' }}</p>
            @endcan
        </x-card>

        <x-card class="p-5 lg:col-span-3">
            <h2 class="font-extrabold text-slate-900">Atendimentos recentes</h2>
            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="text-xs uppercase text-slate-500"><tr><th class="pb-3">Número</th><th class="pb-3">Chegada</th><th class="pb-3">Status</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($patient->encounters as $encounter)
                            <tr><td class="py-3 font-mono">{{ $encounter->encounter_number }}</td><td class="py-3">{{ $encounter->arrival_at->format('d/m/Y H:i') }}</td><td class="py-3">{{ str_replace('_', ' ', $encounter->current_status->value) }}</td></tr>
                        @empty<tr><td colspan="3" class="py-5 text-slate-500">Nenhum atendimento registrado.</td></tr>@endforelse
                    </tbody>
                </table>
            </div>
        </x-card>
    </div>
</x-layout.app>
