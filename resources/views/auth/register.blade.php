<x-dynamic-component component="layouts.auth" title="Cadastro">
    <div class="mb-8">
        <p class="text-sm font-bold text-brand-700">Novo acesso</p>
        <h2 class="mt-1 text-3xl font-black tracking-tight text-slate-950">Cadastro de funcionário</h2>
        <p class="mt-2 text-sm leading-6 text-slate-500">Informe seus dados e os dados da unidade onde você atua.</p>
    </div>

    @if($errors->any())
        <x-alert type="error" class="mb-5">
            Não foi possível concluir o cadastro. Verifique os dados e tente novamente.
        </x-alert>
    @endif

    <form
        method="POST"
        action="{{ route('register.store') }}"
        class="space-y-5"
        x-data="{ role: @js(old('role', '')) }"
    >
        @csrf

        <x-form.input
            name="cnes_code"
            label="CNES da unidade"
            :value="old('cnes_code')"
            placeholder="Ex.: 6612547"
            required
            autofocus
        />
        <x-form.input
            name="name"
            label="Nome completo"
            :value="old('name')"
            autocomplete="name"
            required
        />
        <x-form.input
            name="cpf"
            label="CPF"
            :value="old('cpf')"
            placeholder="Somente números"
            required
        />
        <x-form.input
            name="birth_date"
            label="Data de nascimento"
            type="date"
            :value="old('birth_date')"
            required
        />
        <x-form.input
            name="email"
            label="E-mail institucional"
            type="email"
            :value="old('email')"
            autocomplete="email"
            placeholder="nome@instituicao.local"
            required
        />

        <x-form.select name="role" label="Função" x-model="role" required>
            <option value="">Selecione</option>
            @foreach($roles as $roleOption)
                <option value="{{ $roleOption->name }}" @selected(old('role') === $roleOption->name)>
                    {{ match($roleOption->name) {
                        'receptionist' => 'Recepcionista',
                        'triage_professional' => 'Profissional de triagem',
                        'doctor' => 'Médico',
                        default => $roleOption->name,
                    } }}
                </option>
            @endforeach
        </x-form.select>

        <div x-show="role === 'doctor' || role === 'triage_professional'" x-cloak class="space-y-5 rounded-lg border border-slate-200 bg-slate-50 p-4">
            <p class="text-xs font-bold tracking-wide text-slate-500 uppercase">Registro profissional</p>
            <x-form.input
                name="council_type"
                label="Conselho (ex.: CRM, COREN)"
                :value="old('council_type')"
            />
            <x-form.input
                name="registration_number"
                label="Número de inscrição"
                :value="old('registration_number')"
            />
            <x-form.input
                name="registration_state"
                label="UF do registro"
                :value="old('registration_state')"
                maxlength="2"
                placeholder="Ex.: SP"
            />
        </div>

        <x-form.input
            name="password"
            label="Senha"
            type="password"
            autocomplete="new-password"
            required
        />
        <x-form.input
            name="password_confirmation"
            label="Confirmar senha"
            type="password"
            autocomplete="new-password"
            required
        />

        <x-button.primary class="w-full">Finalizar cadastro</x-button.primary>
    </form>

    <p class="mt-6 text-center text-sm">
        <a href="{{ route('login') }}" class="font-bold text-brand-700 hover:text-brand-800">Voltar para o login</a>
    </p>
</x-dynamic-component>
