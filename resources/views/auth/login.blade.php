<x-dynamic-component component="layouts.auth" title="Entrar">
    <div class="mb-8">
        <p class="text-sm font-bold text-brand-700">Bem-vindo</p>
        <h2 class="mt-1 text-3xl font-black tracking-tight text-slate-950">Acesse o seu plantão</h2>
        <p class="mt-2 text-sm leading-6 text-slate-500">Informe a unidade e suas credenciais institucionais.</p>
    </div>

    @if($errors->any())
        <x-alert type="error" class="mb-5">
            Não foi possível entrar. Verifique os dados e tente novamente.
        </x-alert>
    @endif

    <form method="POST" action="{{ route('login.store') }}" class="space-y-5">
        @csrf
        <x-form.input
            name="unit_code"
            label="Código da unidade"
            :value="old('unit_code')"
            autocomplete="organization"
            placeholder="Ex.: URGENCIA-CENTRAL"
            required
            autofocus
        />
        <p class="-mt-3 text-xs text-slate-500">Administradores globais usam o código administrativo fornecido na implantação.</p>
        <x-form.input
            name="email"
            label="E-mail institucional"
            type="email"
            :value="old('email')"
            autocomplete="username"
            placeholder="nome@instituicao.local"
            required
        />
        <x-form.input
            name="password"
            label="Senha"
            type="password"
            autocomplete="current-password"
            required
        />
        <x-button.primary class="w-full">Entrar no SYNC SUS</x-button.primary>
    </form>

    <p class="mt-6 rounded-lg bg-slate-50 px-4 py-3 text-xs leading-5 text-slate-600">
        Esqueceu a senha? Solicite a redefinição ao administrador da unidade.
    </p>
</x-dynamic-component>
