<x-layout.app title="Profissionais de saúde">
    <x-slot:header>
        <x-page-header eyebrow="Administração" title="Profissionais de saúde" description="Identificação institucional, conselhos, especialidades e unidades autorizadas.">
            <x-button.primary :href="route('administration.professionals.create')">Novo profissional</x-button.primary>
        </x-page-header>
    </x-slot:header>

    <x-card class="p-5">
        <form method="GET" class="flex gap-3">
            <input name="q" class="field-control" value="{{ $term }}" placeholder="Nome, código institucional ou CPF">
            <x-button.primary>Pesquisar</x-button.primary>
        </form>
    </x-card>

    <x-card class="mt-5 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-xs font-bold uppercase text-slate-500">
                    <tr><th class="px-5 py-3">Profissional</th><th class="px-5 py-3">Registro</th><th class="px-5 py-3">Especialidades</th><th class="px-5 py-3">Unidades</th><th class="px-5 py-3">Situação</th><th class="px-5 py-3"></th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($professionals as $professional)
                        <tr>
                            <td class="px-5 py-4"><strong>{{ $professional->displayName() }}</strong><br><span class="font-mono text-xs text-slate-500">{{ $professional->institutional_code }}</span></td>
                            <td class="px-5 py-4">{{ $professional->primaryRegistrationLabel() ?? 'Não informado' }}<br>@if($professional->cnes_code)<span class="text-xs text-slate-500">CNES {{ $professional->cnes_code }}</span>@endif</td>
                            <td class="px-5 py-4">{{ $professional->specialties->pluck('name')->join(', ') ?: 'Não informada' }}</td>
                            <td class="px-5 py-4">{{ $professional->healthUnits->pluck('name')->join(', ') }}</td>
                            <td class="px-5 py-4"><span @class(['rounded-full px-2.5 py-1 text-xs font-bold', 'bg-emerald-100 text-emerald-800' => $professional->is_active, 'bg-slate-100 text-slate-600' => ! $professional->is_active])>{{ $professional->is_active ? 'Ativo' : 'Inativo' }}</span></td>
                            <td class="px-5 py-4 text-right"><a class="font-bold text-brand-700 hover:underline" href="{{ route('administration.professionals.edit', $professional) }}">Editar</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-5 py-10 text-center text-slate-500">Nenhum profissional cadastrado.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-slate-200 p-4">{{ $professionals->links() }}</div>
    </x-card>
</x-layout.app>
