<x-layout.app title="Novo paciente">
    <div class="mb-6">
        <p class="text-sm font-semibold text-brand-700">Pacientes</p>
        <h1 class="text-2xl font-extrabold text-slate-950">Novo cadastro</h1>
        <p class="mt-1 text-sm text-slate-600">Confira se o paciente já existe antes de criar outro prontuário.</p>
    </div>
    <form method="POST" action="{{ route('patients.store') }}">
        @csrf
        <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $idempotencyKey) }}">
        @if(request()->boolean('return_to_reception'))<input type="hidden" name="return_to_reception" value="1">@endif
        @include('patients._form')
    </form>
</x-layout.app>
