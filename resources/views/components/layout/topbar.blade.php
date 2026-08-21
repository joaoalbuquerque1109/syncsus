<header class="sticky top-0 z-30 flex min-h-18 items-center justify-between border-b border-slate-200 bg-white px-5 lg:px-8">
    @php($isPlatformAdministrator = auth()->user()->isPlatformAdministrator())

    <div class="flex min-w-0 items-center gap-3">
        @if($isPlatformAdministrator)
            <span class="hidden rounded-full bg-violet-100 px-3 py-2 text-xs font-extrabold text-violet-800 md:inline">
                Administrador global
            </span>
        @endif
        <button
            type="button"
            class="grid size-10 place-items-center rounded-lg text-slate-600 hover:bg-slate-100 lg:hidden"
            @click="sidebarOpen = !sidebarOpen"
            aria-label="Abrir menu"
        >☰</button>
        <div class="min-w-0">
            <p class="truncate text-xs font-medium tracking-wide text-slate-500">
                {{ $activeHealthUnit->organization->code }} · {{ $activeHealthUnit->code }}
            </p>
            <h1 class="truncate text-lg font-extrabold text-slate-900">{{ $title ?? 'SYNC HOSP' }}</h1>
        </div>
    </div>

    <div class="flex min-w-0 items-center gap-3">
        @if($isPlatformAdministrator || $availableHealthUnits->count() > 1)
            <form method="POST" action="{{ route('active-health-unit.update') }}" class="flex min-w-0 items-center gap-2">
                @csrf
                @method('PUT')
                <label class="hidden whitespace-nowrap text-xs font-bold uppercase tracking-wide text-slate-500 xl:block" for="health-unit">
                    {{ $isPlatformAdministrator ? 'Visualizar unidade' : 'Unidade ativa' }}
                </label>
                @if($isPlatformAdministrator)
                    <input
                        id="health-unit"
                        name="health_unit"
                        list="health-unit-options"
                        class="field-control min-w-0 max-w-64 flex-1 truncate py-2 text-sm font-bold"
                        aria-label="Visualizar unidade"
                        placeholder="CNES atual: {{ $activeHealthUnit->cnes_code ?: $activeHealthUnit->organization->cnes_code }}"
                        autocomplete="off"
                        onfocus="this.select()"
                    >
                    <datalist id="health-unit-options">
                        @foreach($availableHealthUnits as $unit)
                            <option value="{{ $unit->cnes_code ?: $unit->organization->cnes_code }}">
                                {{ $unit->organization->trade_name }} · {{ $unit->name }}
                            </option>
                        @endforeach
                    </datalist>
                    <x-button.primary class="min-h-0 shrink-0 px-3 py-2 text-sm">Ir</x-button.primary>
                @else
                    <select
                        id="health-unit"
                        name="health_unit"
                        class="field-control min-w-0 max-w-64 truncate py-2 text-sm font-bold"
                        aria-label="Unidade ativa"
                        onchange="this.form.submit()"
                    >
                        @foreach($availableHealthUnits as $unit)
                            <option value="{{ $unit->public_id }}" @selected($unit->is($activeHealthUnit))>
                                {{ $unit->name }}
                            </option>
                        @endforeach
                    </select>
                @endif
            </form>
        @else
            <span class="hidden rounded-full border border-slate-200 bg-slate-50 px-4 py-2 text-sm font-bold text-slate-700 sm:inline">
                {{ $activeHealthUnit->name }}
            </span>
        @endif

        <details class="relative">
            <summary class="grid size-10 cursor-pointer list-none place-items-center rounded-full bg-brand-50 text-sm font-extrabold text-brand-700">
                {{ mb_substr(auth()->user()->name, 0, 1) }}
            </summary>
            <div class="absolute right-0 mt-2 w-52 rounded-lg border border-slate-200 bg-white p-2 shadow-xl">
                <a href="{{ route('password.edit') }}" class="block rounded-md px-3 py-2 text-sm hover:bg-slate-50">Alterar senha</a>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button class="w-full rounded-md px-3 py-2 text-left text-sm text-red-700 hover:bg-red-50" type="submit">Sair</button>
                </form>
            </div>
        </details>
    </div>
</header>
