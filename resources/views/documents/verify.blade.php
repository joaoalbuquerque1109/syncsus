<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Verificação de documento · SYNC SUS</title>
    @vite(['resources/css/app.css'])
</head>
<body class="grid min-h-screen place-items-center bg-slate-100 p-5">
    <main class="w-full max-w-xl rounded-2xl border border-slate-200 bg-white p-7 shadow-xl">
        <div class="flex items-center gap-3"><x-brand-logo /></div>
        <div class="mt-7 rounded-xl border p-5 {{ $document->status === 'active' ? 'border-emerald-200 bg-emerald-50' : 'border-red-200 bg-red-50' }}">
            <p class="text-xs font-extrabold uppercase {{ $document->status === 'active' ? 'text-emerald-700' : 'text-red-700' }}">
                {{ $document->status === 'active' ? 'Documento válido no sistema' : 'Documento anulado' }}
            </p>
            <h1 class="mt-2 text-xl font-extrabold">{{ $document->title }}</h1>
        </div>
        <dl class="mt-6 divide-y divide-slate-100 text-sm">
            <div class="grid grid-cols-[150px_1fr] gap-3 py-3"><dt class="text-slate-500">Instituição</dt><dd class="font-bold">{{ $document->healthUnit->organization->trade_name }}</dd></div>
            <div class="grid grid-cols-[150px_1fr] gap-3 py-3"><dt class="text-slate-500">Unidade</dt><dd class="font-bold">{{ $document->healthUnit->name }}</dd></div>
            <div class="grid grid-cols-[150px_1fr] gap-3 py-3"><dt class="text-slate-500">Paciente</dt><dd class="font-bold">{{ str($document->patient->displayName())->explode(' ')->map(fn ($part) => mb_substr($part, 0, 1).'***')->join(' ') }}</dd></div>
            <div class="grid grid-cols-[150px_1fr] gap-3 py-3"><dt class="text-slate-500">Emissão</dt><dd class="font-bold">{{ $document->created_at->format('d/m/Y H:i') }}</dd></div>
            <div class="grid grid-cols-[150px_1fr] gap-3 py-3"><dt class="text-slate-500">Versão</dt><dd class="font-bold">{{ $document->currentVersion->version_number }}</dd></div>
            <div class="grid grid-cols-[150px_1fr] gap-3 py-3"><dt class="text-slate-500">Hash</dt><dd class="break-all font-mono text-xs">{{ $document->currentVersion->file_hash }}</dd></div>
            <div class="grid grid-cols-[150px_1fr] gap-3 py-3"><dt class="text-slate-500">Código</dt><dd class="font-mono font-bold">{{ $document->verification_code }}</dd></div>
        </dl>
        <p class="mt-6 text-xs leading-5 text-slate-500">Esta verificação confirma o registro e a versão atual no SYNC SUS. Em instalações locais, ela está disponível apenas na rede institucional.</p>
    </main>
</body>
</html>
