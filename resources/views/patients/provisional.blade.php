<x-layout.app title="Identificação provisória">
    <div class="mx-auto max-w-3xl">
        <div class="mb-6">
            <p class="text-sm font-semibold text-amber-700">Exceção assistencial</p>
            <h1 class="text-2xl font-extrabold text-slate-950">Paciente não identificado</h1>
            <p class="mt-1 text-sm text-slate-600">Use somente quando a identificação segura não for possível no momento.</p>
        </div>
        <x-card class="p-6">
            <form method="POST" action="{{ route('patients.provisional.store') }}" class="space-y-5">
                @csrf
                <x-alert type="warning">O prontuário ficará marcado como provisório até a reconciliação dos dados.</x-alert>
                <x-form.input name="full_name" label="Nome informado, se houver" :value="old('full_name')" />
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-form.select name="sex" label="Sexo aparente/informado" required>
                        @foreach(['unknown' => 'Não informado', 'female' => 'Feminino', 'male' => 'Masculino', 'intersex' => 'Intersexo'] as $value => $label)
                            <option value="{{ $value }}" @selected(old('sex', 'unknown') === $value)>{{ $label }}</option>
                        @endforeach
                    </x-form.select>
                    <x-form.input name="estimated_age" label="Idade estimada" type="number" min="0" max="130" :value="old('estimated_age')" />
                </div>
                <x-form.textarea name="provisional_description" label="Características e contexto da identificação" required>{{ old('provisional_description') }}</x-form.textarea>
                <div class="flex justify-end gap-3">
                    <button type="submit" formmethod="POST" formaction="{{ route('reception.draft.resume') }}" class="inline-flex items-center rounded-lg border border-slate-300 px-4 text-sm font-bold">Cancelar</button>
                    <x-button.primary>Criar e continuar</x-button.primary>
                </div>
            </form>
        </x-card>
    </div>
</x-layout.app>
