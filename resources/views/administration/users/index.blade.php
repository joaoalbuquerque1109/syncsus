<x-layout.app title="Usuários e acessos">
    @if(!$organizationHasManager)
        <x-alert type="warning" class="mb-5">
            Esta organização ainda não possui gestor ativo. O próximo usuário cadastrado deve receber o papel de gestor.
        </x-alert>
    @endif
    <x-slot:header>
        <x-page-header eyebrow="Administração" title="Usuários e acessos" description="Contas, perfis de permissão e unidades autorizadas." />
    </x-slot:header>

    @if($errors->any())<x-alert type="danger" class="mb-5">Revise os dados do usuário. {{ $errors->first() }}</x-alert>@endif

    <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_24rem]">
        <div>
            <x-card class="p-5">
                <form method="GET" class="flex gap-3">
                    <input name="q" class="field-control" value="{{ $term }}" placeholder="Nome ou e-mail">
                    <x-button.primary>Pesquisar</x-button.primary>
                </form>
            </x-card>

            <x-card class="mt-5 overflow-hidden">
                <div class="divide-y divide-slate-200">
                    @forelse($users as $managedUser)
                        <details class="group">
                            <summary class="flex cursor-pointer list-none items-center justify-between gap-4 px-5 py-4">
                                <div>
                                    <p class="font-bold text-slate-900">{{ $managedUser->name }}</p>
                                    <p class="text-sm text-slate-500">{{ $managedUser->email }} · {{ $managedUser->roles->pluck('name')->join(', ') }}</p>
                                </div>
                                <div class="text-right text-sm">
                                    <span class="{{ $managedUser->is_active ? 'text-emerald-700' : 'text-red-700' }}">{{ $managedUser->is_active ? 'Ativo' : 'Inativo' }}</span>
                                    <p class="text-xs text-slate-500">{{ $managedUser->professionalProfile ? 'Vinculado a profissional' : 'Sem perfil profissional' }}</p>
                                </div>
                            </summary>
                            <form method="POST" action="{{ route('administration.users.update', ['managedUser' => $managedUser->public_id]) }}" class="grid gap-4 border-t border-slate-200 bg-slate-50 p-5 md:grid-cols-2">
                                @csrf @method('PUT')
                                <x-form.input name="name" label="Nome" required :value="$managedUser->name" />
                                <x-form.input name="email" label="E-mail" type="email" required :value="$managedUser->email" />
                                <x-form.input name="password" label="Nova senha temporária" type="password" placeholder="Deixe vazio para manter" />
                                <x-form.select name="default_health_unit_id" label="Unidade padrão" required>
                                    @foreach($healthUnits as $unit)<option value="{{ $unit->getKey() }}" @selected($managedUser->default_health_unit_id === $unit->getKey())>{{ $unit->name }}</option>@endforeach
                                </x-form.select>
                                <fieldset><legend class="field-label">Perfis</legend><div class="space-y-2">@foreach($roles as $role)<label class="flex gap-2 text-sm"><input type="checkbox" name="roles[]" value="{{ $role->name }}" @checked($managedUser->hasRole($role->name))> {{ $role->name }}</label>@endforeach</div></fieldset>
                                <fieldset><legend class="field-label">Unidades autorizadas</legend><div class="space-y-2">@foreach($healthUnits as $unit)<label class="flex gap-2 text-sm"><input type="checkbox" name="health_unit_ids[]" value="{{ $unit->getKey() }}" @checked($managedUser->healthUnits->contains($unit))> {{ $unit->name }}</label>@endforeach</div></fieldset>
                                <label class="flex items-center gap-2 text-sm font-bold"><input type="checkbox" name="is_active" value="1" @checked($managedUser->is_active)> Usuário ativo</label>
                                <div class="flex justify-end"><x-button.primary>Salvar alterações</x-button.primary></div>
                            </form>
                        </details>
                    @empty<p class="p-8 text-center text-slate-500">Nenhum usuário encontrado.</p>@endforelse
                </div>
                <div class="border-t border-slate-200 p-4">{{ $users->links() }}</div>
            </x-card>
        </div>

        <x-card class="h-fit p-5">
            <h2 class="text-lg font-extrabold">Novo usuário</h2>
            <p class="mt-1 text-sm text-slate-500">A senha temporária deve ter ao menos 12 caracteres.</p>
            <form method="POST" action="{{ route('administration.users.store') }}" class="mt-4 space-y-4">
                @csrf
                <x-form.input name="name" label="Nome" required :value="old('name')" />
                <x-form.input name="email" label="E-mail" type="email" required :value="old('email')" />
                <x-form.input name="password" label="Senha temporária" type="password" required />
                <x-form.select name="default_health_unit_id" label="Unidade padrão" required>
                    <option value="">Selecione</option>
                    @foreach($healthUnits as $unit)<option value="{{ $unit->getKey() }}" @selected((string)old('default_health_unit_id') === (string)$unit->getKey())>{{ $unit->name }}</option>@endforeach
                </x-form.select>
                <fieldset><legend class="field-label">Perfis *</legend><div class="space-y-2">@foreach($roles as $role)<label class="flex gap-2 text-sm"><input type="checkbox" name="roles[]" value="{{ $role->name }}" @checked(in_array($role->name, old('roles', []), true))> {{ $role->name }}</label>@endforeach</div></fieldset>
                <fieldset><legend class="field-label">Unidades autorizadas *</legend><div class="space-y-2">@foreach($healthUnits as $unit)<label class="flex gap-2 text-sm"><input type="checkbox" name="health_unit_ids[]" value="{{ $unit->getKey() }}" @checked(in_array($unit->getKey(), array_map('intval', old('health_unit_ids', [])), true))> {{ $unit->name }}</label>@endforeach</div></fieldset>
                <label class="flex items-center gap-2 text-sm font-bold"><input type="checkbox" name="is_active" value="1" checked> Usuário ativo</label>
                <x-button.primary class="w-full justify-center">Criar usuário</x-button.primary>
            </form>
        </x-card>
    </div>
</x-layout.app>
