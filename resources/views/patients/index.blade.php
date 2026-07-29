<x-layout.app title="Pacientes">
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
                            <td class="px-5 py-4 text-right"><a class="font-bold text-brand-700 hover:underline" href="{{ route('patients.show', $patient) }}">Abrir</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-5 py-12 text-center text-slate-500">Nenhum paciente encontrado.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($patients->hasPages())<div class="border-t border-slate-200 p-4">{{ $patients->links() }}</div>@endif
    </x-card>
</x-layout.app>
