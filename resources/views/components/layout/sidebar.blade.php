<aside
    class="fixed inset-y-0 left-0 z-40 flex w-64 -translate-x-full flex-col overflow-hidden bg-navy-950 px-4 py-6 text-slate-200 transition-transform lg:translate-x-0"
    :class="{ 'translate-x-0': sidebarOpen }"
    aria-label="Navegação principal"
>
    <div class="px-2">
        <x-brand-logo />
    </div>

    <nav class="sidebar-scroll mt-8 min-h-0 flex-1 space-y-1.5 overflow-y-auto overscroll-contain pr-1 pb-3" data-sidebar-scroll>
        <a href="{{ route('dashboard') }}" @class([
            'flex items-center gap-3 rounded-lg px-4 py-3 text-sm font-semibold transition',
            'bg-brand-600 text-white shadow-lg shadow-brand-900/20' => request()->routeIs('dashboard'),
            'hover:bg-white/8 hover:text-white' => !request()->routeIs('dashboard'),
        ])>
            <span aria-hidden="true">⌂</span>
            Início
        </a>

        @can('encounters.open')
            <a href="{{ route('reception.create') }}" @class([
                'flex items-center gap-3 rounded-lg px-4 py-3 text-sm font-semibold transition',
                'bg-brand-600 text-white' => request()->routeIs('reception.*'),
                'hover:bg-white/8 hover:text-white' => !request()->routeIs('reception.*'),
            ])><span aria-hidden="true">▣</span> Recepção</a>
        @endcan
        @can('queues.view')
            <a href="{{ route('queues.index') }}" @class([
                'flex items-center gap-3 rounded-lg px-4 py-3 text-sm font-semibold transition',
                'bg-brand-600 text-white' => request()->routeIs('queues.*', 'queue-entries.*', 'triage.*', 'medical.*'),
                'hover:bg-white/8 hover:text-white' => !request()->routeIs('queues.*', 'queue-entries.*', 'triage.*', 'medical.*'),
            ])><span aria-hidden="true">⌘</span> Filas e chamadas</a>
        @endcan
        @can('patients.view')
            <a href="{{ route('patients.index') }}" @class([
                'flex items-center gap-3 rounded-lg px-4 py-3 text-sm font-semibold transition',
                'bg-brand-600 text-white' => request()->routeIs('patients.*'),
                'hover:bg-white/8 hover:text-white' => !request()->routeIs('patients.*'),
            ])><span aria-hidden="true">♙</span> Pacientes</a>
        @endcan
        @if(auth()->user()->isPlatformAdministrator())
            <a href="{{ route('laboratory.orders.index') }}" @class([
                'flex items-center gap-3 rounded-lg px-4 py-3 text-sm font-semibold transition',
                'bg-brand-600 text-white' => request()->routeIs('laboratory.orders.*'),
                'hover:bg-white/8 hover:text-white' => !request()->routeIs('laboratory.orders.*'),
            ])><span aria-hidden="true">▥</span> Requisições de exames</a>
        @endif
        @can('reports.view')
            <a href="{{ route('reports.index') }}" @class([
                'flex items-center gap-3 rounded-lg px-4 py-3 text-sm font-semibold transition',
                'bg-brand-600 text-white' => request()->routeIs('reports.*'),
                'hover:bg-white/8 hover:text-white' => !request()->routeIs('reports.*'),
            ])><span aria-hidden="true">▤</span> Relatórios</a>
        @endcan
        @can('audit.view')
            <a href="{{ route('audit.index') }}" @class([
                'flex items-center gap-3 rounded-lg px-4 py-3 text-sm font-semibold transition',
                'bg-brand-600 text-white' => request()->routeIs('audit.*'),
                'hover:bg-white/8 hover:text-white' => !request()->routeIs('audit.*'),
            ])><span aria-hidden="true">◎</span> Auditoria</a>
        @endcan
        @can('administration.manage')
            <p class="px-4 pb-1 pt-3 text-[0.65rem] font-extrabold uppercase tracking-widest text-slate-500">Administração</p>
            @if(auth()->user()->isPlatformAdministrator())
                <a href="{{ route('administration.tenants.index') }}" @class([
                    'flex items-center gap-3 rounded-lg px-4 py-3 text-sm font-semibold transition',
                    'bg-brand-600 text-white' => request()->routeIs('administration.tenants.*'),
                    'hover:bg-white/8 hover:text-white' => !request()->routeIs('administration.tenants.*'),
                ])><span aria-hidden="true">O</span> Unidades e tenants</a>
            @endif
            <a href="{{ route('administration.users.index') }}" @class([
                'flex items-center gap-3 rounded-lg px-4 py-3 text-sm font-semibold transition',
                'bg-brand-600 text-white' => request()->routeIs('administration.users.*'),
                'hover:bg-white/8 hover:text-white' => !request()->routeIs('administration.users.*'),
            ])><span aria-hidden="true">U</span> Usuários e acessos</a>
            <a href="{{ route('administration.professionals.index') }}" @class([
                'flex items-center gap-3 rounded-lg px-4 py-3 text-sm font-semibold transition',
                'bg-brand-600 text-white' => request()->routeIs('administration.professionals.*'),
                'hover:bg-white/8 hover:text-white' => !request()->routeIs('administration.professionals.*'),
            ])><span aria-hidden="true">P</span> Profissionais</a>
            <a href="{{ route('administration.catalogs.index') }}" @class([
                'flex items-center gap-3 rounded-lg px-4 py-3 text-sm font-semibold transition',
                'bg-brand-600 text-white' => request()->routeIs('administration.catalogs.*'),
                'hover:bg-white/8 hover:text-white' => !request()->routeIs('administration.catalogs.*'),
            ])><span aria-hidden="true">C</span> Cadastros</a>
            <a href="{{ route('administration.exam-groups.index') }}" @class([
                'flex items-center gap-3 rounded-lg px-4 py-3 text-sm font-semibold transition',
                'bg-brand-600 text-white' => request()->routeIs('administration.exam-groups.*'),
                'hover:bg-white/8 hover:text-white' => !request()->routeIs('administration.exam-groups.*'),
            ])><span aria-hidden="true">G</span> Grupos de exames</a>
            <a href="{{ route('administration.flow.index') }}" @class([
                'flex items-center gap-3 rounded-lg px-4 py-3 text-sm font-semibold transition',
                'bg-brand-600 text-white' => request()->routeIs('administration.flow.*'),
                'hover:bg-white/8 hover:text-white' => !request()->routeIs('administration.flow.*'),
            ])><span aria-hidden="true">⚙</span> Administração</a>
            <a href="{{ route('administration.synclab.edit') }}" @class([
                'flex items-center gap-3 rounded-lg px-4 py-3 text-sm font-semibold transition',
                'bg-brand-600 text-white' => request()->routeIs('administration.synclab.*'),
                'hover:bg-white/8 hover:text-white' => !request()->routeIs('administration.synclab.*'),
            ])><span aria-hidden="true">S</span> Integração Synclab</a>
            @if(auth()->user()->isPlatformAdministrator())
            <a href="{{ route('administration.operations') }}" @class([
                'flex items-center gap-3 rounded-lg px-4 py-3 text-sm font-semibold transition',
                'bg-brand-600 text-white' => request()->routeIs('administration.operations'),
                'hover:bg-white/8 hover:text-white' => !request()->routeIs('administration.operations'),
            ])><span aria-hidden="true">◫</span> Operação</a>
            @endif
        @endcan
    </nav>

    <div class="mt-auto shrink-0 border-t border-white/10 px-2 pt-5">
        <div class="flex items-center gap-3">
            <span class="grid size-9 place-items-center rounded-full bg-slate-200 text-xs font-extrabold text-navy-900">
                {{ collect(explode(' ', auth()->user()->name))->take(2)->map(fn ($part) => mb_substr($part, 0, 1))->join('') }}
            </span>
            <div class="min-w-0">
                <p class="truncate text-sm font-semibold text-white">{{ auth()->user()->name }}</p>
                <p class="truncate text-xs text-slate-400">{{ auth()->user()->getRoleNames()->first() ?? 'Usuário' }}</p>
            </div>
        </div>
    </div>
</aside>
