<x-layout.app title="Editar paciente">
    <div class="mb-6">
        <p class="text-sm font-semibold text-brand-700">{{ $patient->medical_record_number }}</p>
        <h1 class="text-2xl font-extrabold text-slate-950">Editar paciente</h1>
    </div>
    <form method="POST" action="{{ route('patients.update', $patient) }}">
        @csrf @method('PUT')
        @include('patients._form')
    </form>
</x-layout.app>
