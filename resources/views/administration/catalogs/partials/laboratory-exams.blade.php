<section x-cloak x-show="tab === 'exams'" class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_25rem]">
    <div class="min-w-0 space-y-4">
        <x-card class="p-5">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h2 class="text-lg font-extrabold text-slate-950">Exames laboratoriais</h2>
                    <p class="mt-1 text-sm text-slate-500">
                        Catálogo enviado ao Synclab pela unidade {{ $activeHealthUnit->name }}.
                    </p>
                </div>
                <form method="GET" action="{{ route('administration.catalogs.index') }}" class="flex w-full gap-2 sm:w-auto">
                    <input type="hidden" name="tab" value="exams">
                    <label class="sr-only" for="exam_q">Buscar exame</label>
                    <input
                        id="exam_q"
                        name="exam_q"
                        value="{{ $examSearch }}"
                        class="field-control min-w-0 sm:w-72"
                        maxlength="80"
                        placeholder="Código, nome, sigla ou SIGTAP"
                    >
                    <x-button.primary>Buscar</x-button.primary>
                    @if($examSearch !== '')
                        <a href="{{ route('administration.catalogs.index', ['tab' => 'exams']) }}" class="inline-flex min-h-10 items-center rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-bold text-slate-700 hover:bg-slate-50">Limpar</a>
                    @endif
                </form>
            </div>
        </x-card>

        <x-card class="overflow-hidden">
            <div class="border-b border-slate-200 px-5 py-4">
                <p class="text-sm font-semibold text-slate-600">
                    {{ $laboratoryExams->total() }} exame(s) encontrado(s)
                </p>
            </div>
            <div class="divide-y divide-slate-200">
                @forelse($laboratoryExams as $exam)
                    <details>
                        <summary class="flex cursor-pointer list-none flex-wrap items-center justify-between gap-3 p-5 hover:bg-slate-50">
                            <span class="min-w-0">
                                <span class="font-extrabold text-slate-950">{{ $exam->name }}</span>
                                <span class="ml-2 font-mono text-sm font-bold text-brand-700">{{ $exam->external_code }}</span>
                                <span class="mt-1 block text-xs text-slate-500">
                                    {{ $exam->acronym ?: 'Sem sigla' }}
                                    @if($exam->group_name) · {{ $exam->group_name }} @endif
                                    @if($exam->sus_procedure_code) · SIGTAP {{ $exam->sus_procedure_code }} @endif
                                </span>
                            </span>
                            <span class="flex items-center gap-2">
                                <span class="rounded-full px-2.5 py-1 text-xs font-bold {{ $exam->source_version ? 'bg-slate-100 text-slate-600' : 'bg-violet-100 text-violet-800' }}">
                                    {{ $exam->source_version ? 'Importado' : 'Manual' }}
                                </span>
                                <span class="rounded-full px-2.5 py-1 text-xs font-bold {{ $exam->is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-red-100 text-red-800' }}">
                                    {{ $exam->is_active ? 'Ativo' : 'Inativo' }}
                                </span>
                            </span>
                        </summary>

                        <form method="POST" action="{{ route('administration.catalogs.update', ['catalog' => 'laboratory-exams', 'record' => $exam->getKey()]) }}" class="grid gap-4 border-t border-slate-200 bg-slate-50 p-5 md:grid-cols-2">
                            @csrf
                            @method('PUT')
                            <x-form.input :id="'exam_'.$exam->getKey().'_code'" name="external_code" label="Código Synclab" required maxlength="128" :value="$exam->external_code" />
                            <x-form.input :id="'exam_'.$exam->getKey().'_name'" name="name" label="Nome do exame" required maxlength="255" :value="$exam->name" />
                            <x-form.input :id="'exam_'.$exam->getKey().'_short_name'" name="short_name" label="Nome reduzido" maxlength="255" :value="$exam->short_name" />
                            <x-form.input :id="'exam_'.$exam->getKey().'_acronym'" name="acronym" label="Sigla" maxlength="64" :value="$exam->acronym" />
                            <x-form.input :id="'exam_'.$exam->getKey().'_integration_acronym'" name="integration_acronym" label="Sigla de integração" maxlength="128" :value="$exam->integration_acronym" />
                            <x-form.input :id="'exam_'.$exam->getKey().'_sus'" name="sus_procedure_code" label="Código SIGTAP/SUS" maxlength="10" inputmode="numeric" :value="$exam->sus_procedure_code" />
                            <x-form.input :id="'exam_'.$exam->getKey().'_group'" name="group_name" label="Grupo" maxlength="255" :value="$exam->group_name" />
                            <x-form.input :id="'exam_'.$exam->getKey().'_turnaround'" name="turnaround_minutes" label="Prazo em minutos" type="number" min="0" max="525600" :value="$exam->turnaround_minutes" />
                            <x-form.textarea :id="'exam_'.$exam->getKey().'_synonyms'" name="synonyms_text" label="Sinônimos" rows="3">{{ implode(PHP_EOL, $exam->synonyms ?? []) }}</x-form.textarea>
                            <x-form.textarea :id="'exam_'.$exam->getKey().'_instructions'" name="collection_instructions" label="Instruções de coleta" rows="3">{{ $exam->collection_instructions }}</x-form.textarea>
                            <label class="flex items-center gap-2 text-sm font-bold text-slate-700">
                                <input type="checkbox" name="is_active" value="1" @checked($exam->is_active)>
                                Disponível para novas requisições
                            </label>
                            <div class="text-right md:col-span-2">
                                <x-button.primary>Salvar exame</x-button.primary>
                            </div>
                        </form>
                    </details>
                @empty
                    <p class="p-10 text-center text-sm text-slate-500">Nenhum exame encontrado nesta unidade.</p>
                @endforelse
            </div>
            @if($laboratoryExams->hasPages())
                <div class="border-t border-slate-200 px-5 py-4">
                    {{ $laboratoryExams->links() }}
                </div>
            @endif
        </x-card>
    </div>

    <x-card class="h-fit p-5 xl:sticky xl:top-24">
        <h2 class="text-lg font-extrabold text-slate-950">Novo exame</h2>
        <p class="mt-1 text-sm text-slate-500">
            Use o código reconhecido pelo Synclab. O código SIGTAP é complementar.
        </p>
        <form method="POST" action="{{ route('administration.catalogs.store', 'laboratory-exams') }}" class="mt-5 space-y-4">
            @csrf
            <x-form.input id="new_exam_code" name="external_code" label="Código Synclab" required maxlength="128" :value="old('external_code')" />
            <x-form.input id="new_exam_name" name="name" label="Nome do exame" required maxlength="255" :value="old('name')" />
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-1">
                <x-form.input id="new_exam_acronym" name="acronym" label="Sigla" maxlength="64" :value="old('acronym')" />
                <x-form.input id="new_exam_integration_acronym" name="integration_acronym" label="Sigla de integração" maxlength="128" :value="old('integration_acronym')" />
                <x-form.input id="new_exam_sus" name="sus_procedure_code" label="Código SIGTAP/SUS" maxlength="10" inputmode="numeric" :value="old('sus_procedure_code')" />
                <x-form.input id="new_exam_group" name="group_name" label="Grupo" maxlength="255" :value="old('group_name')" />
            </div>
            <x-form.input id="new_exam_short_name" name="short_name" label="Nome reduzido" maxlength="255" :value="old('short_name')" />
            <x-form.input id="new_exam_turnaround" name="turnaround_minutes" label="Prazo em minutos" type="number" min="0" max="525600" :value="old('turnaround_minutes')" />
            <x-form.textarea id="new_exam_synonyms" name="synonyms_text" label="Sinônimos" rows="3">{{ old('synonyms_text') }}</x-form.textarea>
            <x-form.textarea id="new_exam_instructions" name="collection_instructions" label="Instruções de coleta" rows="3">{{ old('collection_instructions') }}</x-form.textarea>
            <label class="flex items-center gap-2 text-sm font-bold text-slate-700">
                <input type="checkbox" name="is_active" value="1" @checked(old('is_active', '1'))>
                Disponível para novas requisições
            </label>
            <x-button.primary class="w-full">Cadastrar exame</x-button.primary>
        </form>
    </x-card>
</section>
