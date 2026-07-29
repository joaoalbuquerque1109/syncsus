@php
    $patient = $patient ?? null;
    $identifierRecord = fn (string $type) => $patient?->identifiers?->first(fn ($item) => $item->typeValue() === $type);
    $contact = fn (string $type) => $patient?->contacts?->firstWhere('type', $type)?->value;
    $address = $patient?->addresses?->first();
    $legalGuardian = $patient?->guardians?->firstWhere('guardian_type', 'legal') ?? $patient?->guardians?->first();
    $financialGuardian = $patient?->guardians?->firstWhere('guardian_type', 'financial');
@endphp

@if($errors->any())
    <x-alert type="danger" class="mb-5"><strong>Revise os campos destacados.</strong> Nenhuma alteração foi gravada.</x-alert>
@endif

<div class="space-y-5">
    <x-card class="p-5 lg:p-6">
        <h2 class="text-lg font-extrabold text-slate-900">Identificação e documentos</h2>
        <div class="mt-4 grid gap-4 md:grid-cols-2 lg:grid-cols-4">
            <div class="lg:col-span-2"><x-form.input name="full_name" label="Nome completo" required :value="old('full_name', $patient?->full_name)" /></div>
            <x-form.input name="social_name" label="Nome social" :value="old('social_name', $patient?->social_name)" />
            <x-form.input name="birth_date" label="Data de nascimento" type="date" required :value="old('birth_date', $patient?->birth_date?->format('Y-m-d'))" />

            <x-form.select name="sex" label="Sexo ao nascer" required>
                <option value="">Selecione</option>
                @foreach(['female' => 'Feminino', 'male' => 'Masculino', 'intersex' => 'Intersexo', 'unknown' => 'Não informado'] as $value => $label)
                    <option value="{{ $value }}" @selected(old('sex', $patient?->sex?->value) === $value)>{{ $label }}</option>
                @endforeach
            </x-form.select>
            <x-form.input name="gender_identity" label="Identidade de gênero" :value="old('gender_identity', $patient?->gender_identity)" />
            <x-form.input name="cpf" label="CPF" inputmode="numeric" placeholder="000.000.000-00" :value="old('cpf', $identifierRecord('cpf')?->normalized_value)" />
            <x-form.input name="cns" label="CNS" inputmode="numeric" placeholder="15 dígitos" :value="old('cns', $identifierRecord('cns')?->normalized_value)" />

            <x-form.input name="rg" label="RG" :value="old('rg', $identifierRecord('rg')?->display_value)" />
            <x-form.input name="rg_issuer" label="Órgão emissor do RG" :value="old('rg_issuer', $identifierRecord('rg')?->issuer)" />
            <x-form.input name="rg_issuer_state" label="UF do RG" maxlength="2" :value="old('rg_issuer_state', $identifierRecord('rg')?->issuer_state)" />
            <x-form.input name="rg_issued_at" label="Emissão do RG" type="date" :value="old('rg_issued_at', $identifierRecord('rg')?->issued_at?->format('Y-m-d'))" />

            <x-form.input name="passport" label="Passaporte" :value="old('passport', $identifierRecord('passport')?->display_value)" />
            <x-form.input name="passport_issuer" label="Emissor do passaporte" :value="old('passport_issuer', $identifierRecord('passport')?->issuer)" />
            <x-form.input name="passport_issuer_state" label="UF/país emissor" maxlength="2" :value="old('passport_issuer_state', $identifierRecord('passport')?->issuer_state)" />
            <x-form.input name="passport_issued_at" label="Emissão do passaporte" type="date" :value="old('passport_issued_at', $identifierRecord('passport')?->issued_at?->format('Y-m-d'))" />
        </div>
    </x-card>

    <x-card class="p-5 lg:p-6">
        <h2 class="text-lg font-extrabold text-slate-900">Dados demográficos e sociais</h2>
        <div class="mt-4 grid gap-4 md:grid-cols-2 lg:grid-cols-4">
            <x-form.select name="race_color" label="Raça/cor">
                <option value="">Não informado</option>
                @foreach(['white' => 'Branca', 'black' => 'Preta', 'brown' => 'Parda', 'yellow' => 'Amarela', 'indigenous' => 'Indígena'] as $value => $label)
                    <option value="{{ $value }}" @selected(old('race_color', $patient?->race_color) === $value)>{{ $label }}</option>
                @endforeach
            </x-form.select>
            <x-form.input name="ethnicity" label="Etnia" :value="old('ethnicity', $patient?->ethnicity)" />
            <x-form.input name="nationality" label="Nacionalidade" :value="old('nationality', $patient?->nationality ?? 'Brasileira')" />
            <x-form.input name="birth_city" label="Naturalidade" :value="old('birth_city', $patient?->birth_city)" />
            <x-form.input name="birth_city_ibge_code" label="Código IBGE da naturalidade" :value="old('birth_city_ibge_code', $patient?->birth_city_ibge_code)" />
            <x-form.select name="marital_status" label="Estado civil">
                <option value="">Não informado</option>
                @foreach(['single' => 'Solteiro(a)', 'married' => 'Casado(a)', 'stable_union' => 'União estável', 'divorced' => 'Divorciado(a)', 'widowed' => 'Viúvo(a)'] as $value => $label)
                    <option value="{{ $value }}" @selected(old('marital_status', $patient?->marital_status) === $value)>{{ $label }}</option>
                @endforeach
            </x-form.select>
            <x-form.input name="number_of_children" label="Número de filhos" type="number" min="0" max="30" :value="old('number_of_children', $patient?->number_of_children)" />
            <x-form.select name="blood_type" label="Tipo sanguíneo">
                <option value="">Não informado</option>
                @foreach(['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'] as $value)
                    <option value="{{ $value }}" @selected(old('blood_type', $patient?->blood_type) === $value)>{{ $value }}</option>
                @endforeach
            </x-form.select>
            <x-form.input name="education_level" label="Escolaridade" :value="old('education_level', $patient?->education_level)" />
            <x-form.input name="occupation" label="Ocupação" :value="old('occupation', $patient?->occupation)" />
            <label class="mt-8 flex items-center gap-2 text-sm font-semibold">
                <input type="checkbox" name="is_disabled" value="1" @checked(old('is_disabled', $patient?->is_disabled))>
                Pessoa com deficiência
            </label>
        </div>
    </x-card>

    <x-card class="p-5 lg:p-6">
        <h2 class="text-lg font-extrabold text-slate-900">Filiação e responsáveis</h2>
        <div class="mt-4 grid gap-4 md:grid-cols-2">
            <x-form.input name="mother_name" label="Nome da mãe" :value="old('mother_name', $patient?->mother_name)" />
            <label class="mt-8 flex items-center gap-2 text-sm font-semibold"><input type="checkbox" name="mother_unknown" value="1" @checked(old('mother_unknown', $patient?->mother_unknown))> Mãe não informada</label>
            <x-form.input name="father_name" label="Nome do pai" :value="old('father_name', $patient?->father_name)" />
            <label class="mt-8 flex items-center gap-2 text-sm font-semibold"><input type="checkbox" name="father_unknown" value="1" @checked(old('father_unknown', $patient?->father_unknown))> Pai não informado</label>
        </div>

        <div class="mt-5 border-t border-slate-200 pt-5">
            <p class="mb-4 text-sm font-bold text-slate-700">Responsável legal, quando aplicável</p>
            <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-5">
                <x-form.input name="guardian_name" label="Nome" :value="old('guardian_name', $legalGuardian?->full_name)" />
                <x-form.input name="guardian_cpf" label="CPF" :value="old('guardian_cpf', $legalGuardian?->cpf)" />
                <x-form.input name="guardian_relationship" label="Vínculo" :value="old('guardian_relationship', $legalGuardian?->relationship)" />
                <x-form.input name="guardian_phone" label="Telefone" :value="old('guardian_phone', $legalGuardian?->phone)" />
                <x-form.input name="guardian_reason" label="Motivo da responsabilidade" :value="old('guardian_reason', $legalGuardian?->responsibility_reason)" />
            </div>
        </div>

        <div class="mt-5 border-t border-slate-200 pt-5">
            <p class="mb-4 text-sm font-bold text-slate-700">Responsável financeiro, se diferente</p>
            <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-5">
                <x-form.input name="financial_guardian_name" label="Nome" :value="old('financial_guardian_name', $financialGuardian?->full_name)" />
                <x-form.input name="financial_guardian_cpf" label="CPF" :value="old('financial_guardian_cpf', $financialGuardian?->cpf)" />
                <x-form.input name="financial_guardian_relationship" label="Vínculo" :value="old('financial_guardian_relationship', $financialGuardian?->relationship)" />
                <x-form.input name="financial_guardian_phone" label="Telefone" :value="old('financial_guardian_phone', $financialGuardian?->phone)" />
                <x-form.input name="financial_guardian_reason" label="Observação" :value="old('financial_guardian_reason', $financialGuardian?->responsibility_reason)" />
            </div>
        </div>
    </x-card>

    <x-card class="p-5 lg:p-6">
        <h2 class="text-lg font-extrabold text-slate-900">Contato e endereço</h2>
        <div class="mt-4 grid gap-4 md:grid-cols-2 lg:grid-cols-4">
            <x-form.input name="mobile" label="Celular principal" :value="old('mobile', $contact('mobile'))" />
            <x-form.input name="phone" label="Telefone" :value="old('phone', $contact('phone'))" />
            <x-form.input name="phone2" label="Telefone 2" :value="old('phone2', $contact('phone2'))" />
            <x-form.input name="phone3" label="Telefone 3" :value="old('phone3', $contact('phone3'))" />
            <x-form.input name="email" label="E-mail" type="email" :value="old('email', $contact('email'))" />
            <x-form.input name="postal_code" label="CEP" :value="old('postal_code', $address?->postal_code)" />
            <x-form.input name="state" label="UF" maxlength="2" :value="old('state', $address?->state)" />
            <x-form.input name="city" label="Município" :value="old('city', $address?->city)" />
            <x-form.input name="city_ibge_code" label="Código IBGE do município" :value="old('city_ibge_code', $address?->city_ibge_code)" />
            <x-form.input name="district" label="Bairro" :value="old('district', $address?->district)" />
            <div class="lg:col-span-2"><x-form.input name="street" label="Logradouro" :value="old('street', $address?->street)" /></div>
            <x-form.input name="number" label="Número" :value="old('number', $address?->number)" />
            <x-form.input name="complement" label="Complemento" :value="old('complement', $address?->complement)" />
            <x-form.input name="reference" label="Referência" :value="old('reference', $address?->reference)" />
            <x-form.select name="area_type" label="Área">
                <option value="">Não informada</option>
                <option value="urban" @selected(old('area_type', $address?->area_type) === 'urban')>Urbana</option>
                <option value="rural" @selected(old('area_type', $address?->area_type) === 'rural')>Rural</option>
            </x-form.select>
        </div>
        <label class="mt-4 flex items-center gap-2 text-sm font-semibold"><input type="checkbox" name="address_unknown" value="1" @checked(old('address_unknown', $address?->is_unknown))> Endereço não informado</label>
    </x-card>

    <x-card class="p-5 lg:p-6">
        <x-form.textarea name="administrative_notes" label="Observações administrativas" rows="3">{{ old('administrative_notes', $patient?->administrative_notes) }}</x-form.textarea>
        <p class="mt-2 text-xs text-slate-500">Não registre informações clínicas neste campo. Use o histórico clínico da ficha do paciente.</p>
    </x-card>

    <div class="flex flex-wrap justify-end gap-3">
        <a href="{{ route('patients.index') }}" class="inline-flex min-h-10 items-center rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-bold hover:bg-slate-100">Cancelar</a>
        <x-button.primary>Salvar paciente</x-button.primary>
    </div>
</div>
