<x-layout.app title="Grupos de exames">
    <x-slot:header>
        <x-page-header
            eyebrow="Administração"
            title="Grupos de exames"
            description="Monte conjuntos reutilizáveis a partir do catálogo canônico de exames da organização."
        />
    </x-slot:header>

    @if($errors->any())
        <x-alert type="danger" class="mb-5">{{ $errors->first() }}</x-alert>
    @endif

    <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_26rem]">
        <x-card class="overflow-hidden">
            <div class="border-b border-slate-200 px-5 py-4">
                <h2 class="font-extrabold text-navy-950">Grupos cadastrados</h2>
                <p class="mt-1 text-sm text-slate-500">{{ $groups->total() }} grupo(s) nesta organização.</p>
            </div>

            <div class="divide-y divide-slate-200">
                @forelse($groups as $group)
                    <article class="p-5">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="font-bold text-navy-950">{{ $group->name }}</h3>
                                    <span @class([
                                        'rounded-full px-2.5 py-1 text-xs font-bold',
                                        'bg-emerald-50 text-emerald-700' => $group->is_active,
                                        'bg-slate-100 text-slate-600' => !$group->is_active,
                                    ])>{{ $group->is_active ? 'Ativo' : 'Inativo' }}</span>
                                </div>
                                <p class="mt-1 text-sm text-slate-500">{{ $group->items->count() }} exame(s)</p>
                            </div>
                            <a
                                href="{{ route('administration.exam-groups.index', ['edit' => $group->public_id]) }}"
                                class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-bold text-slate-700 transition hover:border-brand-500 hover:text-brand-700"
                            >Editar</a>
                        </div>

                        <ul class="mt-4 grid gap-2 text-sm text-slate-700 md:grid-cols-2">
                            @foreach($group->items as $item)
                                <li class="rounded-lg bg-slate-50 px-3 py-2">
                                    {{ $item->exam->name }}
                                    @if($item->exam->sus_procedure_code)
                                        <small class="block font-mono text-slate-500">SUS {{ $item->exam->sus_procedure_code }}</small>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </article>
                @empty
                    <p class="p-10 text-center text-slate-500">Nenhum grupo de exames cadastrado.</p>
                @endforelse
            </div>

            @if($groups->hasPages())
                <div class="border-t border-slate-200 p-5">{{ $groups->links() }}</div>
            @endif
        </x-card>

        <x-card class="h-fit p-5">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h2 class="font-extrabold text-navy-950">{{ $editingGroup ? 'Editar grupo' : 'Novo grupo' }}</h2>
                    <p class="mt-1 text-sm text-slate-500">Inclua ao menos um exame. A ordem escolhida será preservada.</p>
                </div>
                @if($editingGroup)
                    <a href="{{ route('administration.exam-groups.index') }}" class="text-sm font-bold text-brand-700">Cancelar</a>
                @endif
            </div>

            <form
                method="POST"
                action="{{ $editingGroup ? route('administration.exam-groups.update', $editingGroup) : route('administration.exam-groups.store') }}"
                class="mt-5 space-y-5"
                x-data="examGroupItems({ initialItems: @js($formItems), searchUrl: @js(route('administration.exam-groups.search-exams')), maxItems: 50 })"
                @click.outside="resultsOpen = false"
            >
                @csrf
                @if($editingGroup) @method('PUT') @endif

                <x-form.input
                    name="name"
                    label="Nome do grupo"
                    required
                    maxlength="255"
                    :value="old('name', $editingGroup?->name)"
                    placeholder="Ex.: Pré-operatório"
                />

                <div class="relative">
                    <label for="exam-group-search" class="mb-1.5 block text-sm font-bold text-slate-700">Adicionar exames</label>
                    <div class="relative">
                        <input
                            id="exam-group-search"
                            type="search"
                            x-model="query"
                            @input.debounce.300ms="searchExams()"
                            @focus="resultsOpen = results.length > 0"
                            autocomplete="off"
                            placeholder="Busque por nome, código ou descrição SUS"
                            class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 pr-10 text-sm outline-none transition focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
                        >
                        <span x-show="searching" class="absolute right-3 top-2.5 text-sm text-slate-400">Buscando…</span>
                    </div>

                    <div
                        x-cloak
                        x-show="resultsOpen"
                        class="absolute z-20 mt-1 max-h-64 w-full overflow-y-auto rounded-lg border border-slate-200 bg-white p-1 shadow-xl"
                    >
                        <template x-for="exam in results" :key="exam.id">
                            <button
                                type="button"
                                @click="addExam(exam)"
                                class="block w-full rounded-md px-3 py-2 text-left text-sm text-slate-700 hover:bg-brand-50"
                                x-text="exam.label"
                            ></button>
                        </template>
                        <p x-show="!searching && results.length === 0" class="px-3 py-3 text-sm text-slate-500">Nenhum exame encontrado.</p>
                    </div>
                </div>

                <div>
                    <div class="mb-2 flex items-center justify-between gap-3">
                        <span class="text-sm font-bold text-slate-700">Exames selecionados</span>
                        <small class="text-slate-500" x-text="`${items.length}/50`"></small>
                    </div>
                    <div class="space-y-2">
                        <template x-for="(item, index) in items" :key="item.key">
                            <div class="flex items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 p-2.5">
                                <input type="hidden" :name="`items[${index}][exam_id]`" :value="item.id">
                                <span class="min-w-0 flex-1 text-sm text-slate-700" x-text="item.label"></span>
                                <button type="button" @click="removeExam(index)" class="rounded-md px-2 py-1 text-xs font-bold text-red-700 hover:bg-red-50">Remover</button>
                            </div>
                        </template>
                        <p x-show="items.length === 0" class="rounded-lg border border-dashed border-slate-300 px-3 py-5 text-center text-sm text-slate-500">
                            Nenhum exame selecionado.
                        </p>
                    </div>
                </div>

                <input type="hidden" name="is_active" value="0">
                <label class="flex items-center gap-2 text-sm font-bold text-slate-700">
                    <input
                        type="checkbox"
                        name="is_active"
                        value="1"
                        @checked((bool) old('is_active', $editingGroup?->is_active ?? true))
                        class="rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                    >
                    Grupo ativo
                </label>

                <x-button.primary class="w-full justify-center">
                    {{ $editingGroup ? 'Salvar alterações' : 'Cadastrar grupo' }}
                </x-button.primary>
            </form>
        </x-card>
    </div>
</x-layout.app>
