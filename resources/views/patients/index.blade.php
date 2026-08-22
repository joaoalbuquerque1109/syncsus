<x-layout.app title="Pacientes">
    <div x-data="examRequestModal()">
        <template x-teleport="body">
            <div
                x-show="open"
                x-cloak
                class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-950/60 p-4 py-10"
                @keydown.escape.window="close()"
            >
                <div class="w-full max-w-3xl rounded-2xl bg-white shadow-2xl" @click.outside="close()">
                    <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4">
                        <h2 class="text-lg font-extrabold text-slate-900">Nova entrada com exames</h2>
                        <button type="button" class="grid size-9 place-items-center rounded-lg text-slate-500 hover:bg-slate-100" @click="close()" aria-label="Fechar">✕</button>
                    </div>
                    <div class="max-h-[80vh] overflow-y-auto p-5">
                        <p x-show="loading" class="py-10 text-center text-sm text-slate-500">Carregando...</p>
                        <div x-show="!loading" x-ref="body" x-html="html"></div>
                    </div>
                </div>
            </div>
        </template>

        <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="text-sm font-semibold text-brand-700">Cadastro longitudinal</p>
                <h1 class="text-2xl font-extrabold text-slate-950">Pacientes</h1>
                <p class="mt-1 text-sm text-slate-600">Localize por nome, prontuário, CPF ou CNS.</p>
            </div>
            @can('patients.create')
                <a href="{{ route('patients.create') }}" class="inline-flex min-h-10 items-center rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-bold text-white hover:bg-brand-700">Novo paciente</a>
            @endcan
        </div>

        <x-card class="p-5">
            <form method="GET" class="flex flex-col gap-3 sm:flex-row">
                <label class="sr-only" for="q">Buscar paciente</label>
                <input id="q" name="q" class="field-control" value="{{ $term }}" placeholder="Nome, prontuário, CPF ou CNS" autofocus>
                <x-button.primary type="submit">Buscar</x-button.primary>
            </form>
        </x-card>

        <x-card class="mt-5 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr><th class="px-5 py-3">Paciente</th><th class="px-5 py-3">Prontuário</th><th class="px-5 py-3">Nascimento</th><th class="px-5 py-3">Documento</th><th class="px-5 py-3"></th></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($patients as $patient)
                            <tr class="hover:bg-brand-50/40">
                                <td class="px-5 py-4">
                                    <p class="font-bold text-slate-900">{{ $patient->displayName() }}</p>
                                    @if($patient->is_provisional)<span class="text-xs font-semibold text-amber-700">Identificação provisória</span>@endif
                                </td>
                                <td class="px-5 py-4 font-mono">{{ $patient->medical_record_number }}</td>
                                <td class="px-5 py-4">{{ $patient->birth_date?->format('d/m/Y') ?? 'Não informada' }}</td>
                                <td class="px-5 py-4">{{ $patient->identifiers->first()?->maskedValue() ?? 'Sem documento' }}</td>
                                <td class="px-5 py-4 text-right">
                                    <div class="flex items-center justify-end gap-3">
                                        @can('encounters.open')
                                            <button
                                                type="button"
                                                class="font-bold text-brand-700 hover:underline"
                                                @click="openFor(@js($patient->public_id), @js(route('reception.create')))"
                                            >Nova entrada com exames</button>
                                        @endcan
                                        <a class="font-bold text-brand-700 hover:underline" href="{{ route('patients.show', $patient) }}">Abrir</a>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-5 py-12 text-center text-slate-500">Nenhum paciente encontrado.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($patients->hasPages())<div class="border-t border-slate-200 p-4">{{ $patients->links() }}</div>@endif
        </x-card>
    </div>
</x-layout.app>
