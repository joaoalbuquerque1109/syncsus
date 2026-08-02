<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex,nofollow">
    <title>{{ $panel->name }} · SYNC SUS</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-navy-950 text-white">
    <main
        class="flex min-h-screen flex-col p-6 lg:p-10"
        x-data="publicPanel({
            code: @js($panel->public_code),
            stateUrl: @js(route('panels.state', $panel)),
            heartbeatUrl: @js(route('panels.heartbeat', $panel)),
            previousCalls: {{ $panel->previous_calls_count }},
            soundEnabled: @js($panel->sound_enabled),
            volume: {{ $panel->suggested_volume }},
            pollMs: {{ max(1, (int) config('sync_sus.panel_poll_seconds', 2)) * 1000 }},
            heartbeatMs: {{ max(5, (int) config('sync_sus.panel_heartbeat_seconds', 15)) * 1000 }}
        })"
    >
        <header class="flex items-center justify-between gap-6 border-b border-white/15 pb-6">
            <div class="flex items-center gap-4">
                <x-brand-logo />
                <div class="hidden border-l border-white/20 pl-4 sm:block">
                    <p class="text-sm text-slate-300">{{ $panel->healthUnit->name }}</p>
                    <h1 class="text-xl font-extrabold">{{ $panel->name }}</h1>
                </div>
            </div>
            <div class="flex items-center gap-4">
                <button type="button" x-show="!audioEnabled && {{ $panel->sound_enabled ? 'true' : 'false' }}" @click="enableAudio()" class="rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-bold">Ativar áudio</button>
                <span class="flex items-center gap-2 text-sm font-bold" :class="connected ? 'text-emerald-300' : 'text-red-300'">
                    <span class="size-2.5 rounded-full" :class="connected ? 'bg-emerald-400' : 'bg-red-400'"></span>
                    <span x-text="connected ? 'Conectado' : 'Reconectando'"></span>
                </span>
                <time class="text-3xl font-black tabular-nums" x-text="clock"></time>
            </div>
        </header>

        <section class="grid flex-1 gap-6 py-8 lg:grid-cols-[1.6fr_1fr]">
            <div class="grid place-items-center rounded-3xl bg-white p-8 text-center text-navy-950 shadow-2xl">
                <div x-show="current">
                    <p class="text-xl font-bold uppercase tracking-[0.25em] text-brand-700" x-text="current?.is_recall ? 'Paciente rechamado' : 'Paciente chamado'"></p>
                    <p class="mt-6 max-w-[18ch] break-words text-[clamp(2.8rem,7vw,7rem)] font-black leading-tight tracking-tight text-navy-950" x-text="current?.person_label"></p>
                    {{--
                        Fluxo visual por senha preservado para uso futuro:
                        <p class="text-xl font-bold uppercase tracking-[0.25em] text-brand-700" x-text="current?.is_recall ? 'Rechamada' : 'Senha chamada'"></p>
                        <p class="mt-4 text-[clamp(5rem,16vw,13rem)] font-black leading-none tracking-tight text-navy-950" x-text="current?.ticket"></p>
                    --}}
                    <div class="mx-auto mt-8 max-w-2xl rounded-2xl bg-brand-600 px-8 py-6 text-white">
                        <p class="text-lg font-semibold uppercase tracking-wider text-brand-100">Dirija-se a</p>
                        <p class="mt-1 text-4xl font-black" x-text="current?.destination"></p>
                    </div>
                </div>
                <div x-show="!current" class="text-slate-500">
                    <p class="text-4xl font-black">Aguardando chamadas</p>
                    <p class="mt-3 text-xl">Aguarde a chamada pelo seu nome.</p>
                </div>
            </div>

            <aside class="rounded-3xl border border-white/10 bg-white/8 p-6">
                <h2 class="text-xl font-extrabold">Últimas chamadas</h2>
                <div class="mt-5 space-y-3">
                    <template x-for="call in calls.slice(0, -1).reverse()" :key="call.event">
                        <div class="flex items-center justify-between gap-4 rounded-xl bg-white/10 px-5 py-4">
                            <div>
                                <strong class="block break-words text-2xl font-black" x-text="call.person_label || 'Paciente'"></strong>
                                {{-- Fluxo anterior por senha: <strong class="block text-3xl font-black" x-text="call.ticket"></strong> --}}
                            </div>
                            <span class="text-right text-lg font-bold text-brand-100" x-text="call.destination"></span>
                        </div>
                    </template>
                    <p x-show="calls.length <= 1" class="py-8 text-center text-slate-400">Ainda não há chamadas anteriores.</p>
                </div>
            </aside>
        </section>

        <footer class="flex items-center justify-between gap-4 border-t border-white/15 pt-5 text-lg">
            <p>{{ $panel->institutional_message }}</p>
            <p class="whitespace-nowrap text-sm text-slate-400">SYNC SUS · operação local</p>
        </footer>
    </main>
</body>
</html>
