@props([
    'name' => 'cid_code_id',
    'inputId' => 'cid_code_search',
    'placeholder' => 'Digite o código ou a descrição do CID',
])

<div
    x-data="cidSearch({ searchUrl: @js(route('medical.cid-codes.search')) })"
    class="relative"
    @click.outside="open = false"
>
    <input type="hidden" name="{{ $name }}" :value="selectedId">
    <div class="relative">
        <input
            id="{{ $inputId }}"
            x-model="query"
            @input.debounce.300ms="search"
            @focus="if (results.length) open = true"
            type="search"
            autocomplete="off"
            class="field-control pr-20"
            placeholder="{{ $placeholder }}"
            :aria-expanded="open"
        >
        <span x-show="searching" x-cloak class="absolute right-3 top-1/2 -translate-y-1/2 text-xs font-bold text-slate-500">Buscando...</span>
        <button
            x-show="selectedId && !searching"
            x-cloak
            type="button"
            class="absolute right-3 top-1/2 -translate-y-1/2 text-xs font-bold text-red-700"
            @click="clear"
        >Limpar</button>
    </div>

    <p x-show="selectedId" x-cloak class="mt-1 text-xs font-semibold text-emerald-700">CID selecionado do catálogo oficial.</p>
    <p x-show="!selectedId && query.length > 0 && query.length < 2" class="mt-1 text-xs text-slate-500">Digite ao menos 2 caracteres.</p>

    <div
        x-show="open"
        x-cloak
        class="absolute z-30 mt-1 max-h-72 w-full overflow-y-auto rounded-lg border border-slate-200 bg-white shadow-xl"
    >
        <template x-if="results.length === 0 && !searching">
            <p class="p-3 text-sm text-slate-500">Nenhum CID encontrado.</p>
        </template>
        <template x-for="item in results" :key="item.id">
            <button
                type="button"
                class="block w-full border-b border-slate-100 px-3 py-2.5 text-left text-sm last:border-0 hover:bg-brand-50"
                @click="select(item)"
            >
                <strong class="text-brand-800" x-text="item.code"></strong>
                <span class="text-slate-700" x-text="item.description"></span>
            </button>
        </template>
    </div>
</div>
