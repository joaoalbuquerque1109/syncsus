<x-dynamic-component component="layouts.auth" title="Alterar senha">
    <div class="mb-8">
        <p class="text-sm font-bold text-brand-700">{{ $isRequired ? 'Primeiro acesso' : 'Segurança da conta' }}</p>
        <h2 class="mt-1 text-3xl font-black tracking-tight text-slate-950">Crie uma nova senha</h2>
        <p class="mt-2 text-sm leading-6 text-slate-500">
            Use pelo menos 12 caracteres, com maiúscula, minúscula, número e símbolo.
        </p>
    </div>

    @if(session('warning'))
        <x-alert type="warning" class="mb-5">{{ session('warning') }}</x-alert>
    @endif
    @if($errors->any())
        <x-alert type="error" class="mb-5">Revise os campos indicados abaixo.</x-alert>
    @endif

    <form method="POST" action="{{ route('password.update') }}" class="space-y-5">
        @csrf
        @method('PUT')
        <x-form.input
            name="current_password"
            label="Senha atual"
            type="password"
            autocomplete="current-password"
            required
            autofocus
        />
        <x-form.input
            name="password"
            label="Nova senha"
            type="password"
            autocomplete="new-password"
            required
        />
        <x-form.input
            name="password_confirmation"
            label="Confirme a nova senha"
            type="password"
            autocomplete="new-password"
            required
        />
        <x-button.primary class="w-full">Salvar nova senha</x-button.primary>
    </form>

    @unless($isRequired)
        <a href="{{ route('dashboard') }}" class="mt-5 block text-center text-sm font-semibold text-slate-600 hover:text-brand-700">Voltar ao início</a>
    @endunless
</x-dynamic-component>
