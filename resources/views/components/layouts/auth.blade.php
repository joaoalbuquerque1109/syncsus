<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Acesso' }} · SYNC HOSP</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <main class="grid min-h-screen lg:grid-cols-[minmax(360px,0.9fr)_1.1fr]">
        <section class="flex items-center justify-center bg-white px-6 py-12">
            <div class="w-full max-w-md">
                <div class="mb-10 flex items-center gap-3">
                    <span class="grid size-11 place-items-center rounded-xl bg-brand-600 text-2xl font-black text-white shadow-lg shadow-brand-500/25" aria-hidden="true">+</span>
                    <div>
                        <p class="text-xl font-black tracking-wide text-navy-950">SYNC HOSP</p>
                        <p class="text-xs font-semibold tracking-[0.18em] text-slate-500 uppercase">Urgência e emergência</p>
                    </div>
                </div>

                {{ $slot }}

                <p class="mt-10 text-center text-xs leading-5 text-slate-500">
                    Acesso restrito a profissionais autorizados.<br>
                    As ações relevantes são registradas para auditoria.
                </p>
            </div>
        </section>

        <section class="relative hidden overflow-hidden bg-navy-950 p-14 text-white lg:flex lg:flex-col lg:justify-end">
            <div class="absolute -top-32 -right-32 size-96 rounded-full bg-brand-500/20 blur-3xl"></div>
            <div class="absolute top-1/3 left-1/4 size-72 rounded-full bg-cyan-400/10 blur-3xl"></div>
            <div class="relative max-w-xl">
                <span class="mb-6 inline-flex rounded-full border border-white/15 bg-white/8 px-4 py-2 text-xs font-bold tracking-widest uppercase">
                    Operação local e segura
                </span>
                <h1 class="text-4xl font-black leading-tight">Cuidado conectado do acolhimento à destinação.</h1>
                <p class="mt-5 max-w-lg text-base leading-7 text-slate-300">
                    Fluxos hospitalares claros, acesso por perfil e rastreabilidade para apoiar uma assistência segura.
                </p>
                <div class="mt-10 grid grid-cols-3 gap-4">
                    <div class="rounded-xl border border-white/10 bg-white/5 p-4">
                        <p class="text-2xl font-black text-cyan-300">Local</p>
                        <p class="mt-1 text-xs text-slate-400">Sem dependência de internet</p>
                    </div>
                    <div class="rounded-xl border border-white/10 bg-white/5 p-4">
                        <p class="text-2xl font-black text-cyan-300">LGPD</p>
                        <p class="mt-1 text-xs text-slate-400">Privacidade por padrão</p>
                    </div>
                    <div class="rounded-xl border border-white/10 bg-white/5 p-4">
                        <p class="text-2xl font-black text-cyan-300">24h</p>
                        <p class="mt-1 text-xs text-slate-400">Pronto para o plantão</p>
                    </div>
                </div>
            </div>
        </section>
    </main>
</body>
</html>
