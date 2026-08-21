<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Unidades e tenants · {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-100 text-slate-900 antialiased">
    <header class="bg-navy-950 px-6 py-5 text-white">
        <div class="mx-auto flex max-w-7xl items-center justify-between gap-4">
            <x-brand-logo />
            <div class="flex items-center gap-3">
                @if($organizations->isNotEmpty())
                    <a href="{{ route('dashboard') }}" class="rounded-lg border border-white/25 px-4 py-2 text-sm font-bold hover:bg-white/10">Voltar ao sistema</a>
                @endif
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button class="rounded-lg bg-white px-4 py-2 text-sm font-bold text-navy-950">Sair</button>
                </form>
            </div>
        </div>
    </header>

    <main class="mx-auto grid max-w-7xl gap-6 px-6 py-8 xl:grid-cols-[minmax(0,1fr)_24rem]">
        <section>
            <p class="text-sm font-bold text-brand-700">Administração global</p>
            <h1 class="mt-1 text-3xl font-black">Unidades e tenants</h1>
            <p class="mt-2 text-slate-600">Cada organização corresponde a uma unidade e é identificada pelo seu CNES.</p>

            @if(session('success'))
                <div class="mt-5 rounded-xl border border-emerald-300 bg-emerald-50 px-5 py-4 text-emerald-900">{{ session('success') }}</div>
            @endif
            @if($errors->any())
                <div class="mt-5 rounded-xl border border-red-300 bg-red-50 px-5 py-4 text-red-900">
                    Revise os campos destacados. {{ $errors->first() }}
                </div>
            @endif

            <div class="mt-6 space-y-4">
                @forelse($organizations as $organization)
                    @php($unit = $organization->healthUnits->first())
                    @php($manager = $unit?->users->first(fn ($user) => $user->roles->contains('name', 'manager')))
                    <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <div class="flex flex-wrap items-start justify-between gap-4">
                            <div>
                                <p class="text-xs font-extrabold uppercase tracking-wider text-brand-700">CNES {{ $organization->tenantIdentifier() ?? 'pendente' }}</p>
                                <h2 class="mt-1 text-xl font-black">{{ $organization->trade_name }}</h2>
                                <p class="mt-1 text-sm text-slate-500">{{ $organization->legal_name }}</p>
                            </div>
                            <div class="flex items-center gap-2">
                                <span @class([
                                    'rounded-full px-3 py-1 text-xs font-bold',
                                    'bg-emerald-100 text-emerald-800' => $unit?->is_active,
                                    'bg-slate-200 text-slate-600' => ! $unit?->is_active,
                                ])>{{ $unit === null ? 'Sem unidade' : ($unit->is_active ? 'Ativa' : 'Inativa') }}</span>
                                @if($unit !== null)
                                    <form method="POST" action="{{ route('administration.tenants.toggle-active', $unit) }}">
                                        @csrf
                                        @method('PUT')
                                        <button
                                            type="submit"
                                            class="rounded-full border border-slate-300 px-3 py-1 text-xs font-bold text-slate-700 hover:bg-slate-50"
                                        >{{ $unit->is_active ? 'Desativar' : 'Ativar' }}</button>
                                    </form>
                                @endif
                            </div>
                        </div>
                        <dl class="mt-4 grid gap-3 border-t border-slate-100 pt-4 text-sm sm:grid-cols-2">
                            <div><dt class="text-slate-500">Unidade principal</dt><dd class="font-bold">{{ $unit?->name ?? 'Não cadastrada' }}</dd></div>
                            <div><dt class="text-slate-500">Primeiro gestor</dt><dd class="font-bold">{{ $manager?->name ?? 'Pendente' }}</dd></div>
                        </dl>
                    </article>
                @empty
                    <div class="rounded-2xl border border-amber-300 bg-amber-50 p-6 text-amber-950">
                        Nenhuma unidade foi criada. Preencha o formulário para concluir a implantação inicial.
                    </div>
                @endforelse
            </div>
        </section>

        <aside class="h-fit rounded-2xl border border-slate-200 bg-white p-6 shadow-sm xl:sticky xl:top-6">
            <h2 class="text-xl font-black">Nova unidade</h2>
            <p class="mt-1 text-sm text-slate-500">O CNES será o identificador do tenant e o código usado no login.</p>
            <form method="POST" action="{{ route('administration.tenants.store') }}" class="mt-5 space-y-4">
                @csrf
                <x-form.input name="cnes_code" label="CNES" :value="old('cnes_code')" inputmode="numeric" maxlength="7" required />
                <x-form.input name="legal_name" label="Razão social" :value="old('legal_name')" required />
                <x-form.input name="trade_name" label="Nome da unidade" :value="old('trade_name')" required />
                <x-form.input name="document_number" label="CNPJ" :value="old('document_number')" />
                <div class="grid grid-cols-3 gap-3">
                    <div class="col-span-2"><x-form.input name="city" label="Cidade" :value="old('city')" /></div>
                    <x-form.input name="state" label="UF" :value="old('state')" maxlength="2" />
                </div>
                <x-form.input name="manager_name" label="Nome do primeiro gestor" :value="old('manager_name')" required />
                <x-form.input name="manager_email" label="E-mail do gestor" type="email" :value="old('manager_email')" required />
                <x-form.input name="manager_password" label="Senha temporária" type="password" required />
                <x-form.input name="manager_password_confirmation" label="Confirmar senha" type="password" required />
                <p class="text-xs leading-5 text-slate-500">Mínimo de 12 caracteres, com maiúscula, minúscula, número e símbolo. O gestor deverá alterá-la no primeiro acesso.</p>
                <button class="w-full rounded-xl bg-brand-600 px-5 py-3 font-extrabold text-white shadow-lg shadow-brand-900/15 hover:bg-brand-700">Criar unidade</button>
            </form>
        </aside>
    </main>
</body>
</html>
