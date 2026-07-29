@php($content = $document->currentVersion->structured_content)
<x-layout.app :title="$document->title">
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
            <p class="text-sm font-semibold text-brand-700">{{ $document->typeEnum()->label() }}</p>
            <h1 class="text-2xl font-extrabold text-slate-950">{{ $document->title }}</h1>
            <p class="mt-1 text-sm text-slate-500">{{ $document->patient->displayName() }} · {{ $document->encounter->encounter_number }}</p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('documents.pdf', $document) }}" class="rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-bold text-white">Baixar PDF</a>
            <a href="{{ route('documents.verify', $document->verification_code) }}" target="_blank" rel="noopener" class="rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold">Verificar</a>
        </div>
    </div>

    @if($document->status === 'voided')
        <div class="mb-5 rounded-lg border-2 border-red-300 bg-red-50 p-4 text-red-900">
            <strong>Documento anulado em {{ $document->voided_at->format('d/m/Y H:i') }}.</strong>
            <p class="mt-1 text-sm">{{ $document->void_reason }}</p>
        </div>
    @endif

    <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_360px]">
        <section class="app-card p-6">
            <div class="flex flex-wrap justify-between gap-3 border-b border-slate-200 pb-4">
                <div><p class="text-xs font-bold uppercase text-slate-500">Versão atual</p><p class="text-xl font-extrabold">{{ $document->currentVersion->version_number }}</p></div>
                <div class="text-right"><p class="text-xs font-bold uppercase text-slate-500">SHA-256</p><p class="break-all font-mono text-xs">{{ $document->currentVersion->file_hash }}</p></div>
            </div>
            <article class="prose mt-6 max-w-none">
                <h2 class="text-center text-lg font-extrabold uppercase">{{ $document->title }}</h2>
                <p class="mt-6 whitespace-pre-line leading-7">{{ $content['body'] }}</p>
                @if(($content['include_cid'] ?? false) && filled($content['cid_text'] ?? null))
                    <p class="mt-5"><strong>CID autorizado:</strong> {{ $content['cid_text'] }}</p>
                @endif
                @if(filled($content['additional_information'] ?? null))
                    <p class="mt-5 rounded-lg bg-slate-50 p-4">{{ $content['additional_information'] }}</p>
                @endif
            </article>
        </section>

        <aside class="space-y-5">
            <section class="app-card p-5">
                <h2 class="font-extrabold">Rastreabilidade</h2>
                <dl class="mt-4 space-y-3 text-sm">
                    <div><dt class="text-slate-500">Código de verificação</dt><dd class="font-mono font-bold">{{ $document->verification_code }}</dd></div>
                    <div><dt class="text-slate-500">Emitido por</dt><dd class="font-bold">{{ $document->creator->name }}</dd></div>
                    <div><dt class="text-slate-500">Arquivo privado</dt><dd>{{ number_format($document->currentVersion->size_bytes / 1024, 1, ',', '.') }} KB</dd></div>
                </dl>
                <h3 class="mt-5 text-sm font-extrabold">Histórico de versões</h3>
                <ol class="mt-2 space-y-2">
                    @foreach($document->versions as $version)
                        <li class="rounded-lg bg-slate-50 p-3 text-xs"><strong>v{{ $version->version_number }}</strong> · {{ $version->created_at->format('d/m/Y H:i') }}<br>{{ $version->reason ?: 'Sem motivo informado' }}</li>
                    @endforeach
                </ol>
            </section>

            @if($document->status === 'active')
                <section class="app-card p-5">
                    <h2 class="font-extrabold">Nova versão</h2>
                    <p class="mt-1 text-xs text-slate-500">A versão atual continuará preservada.</p>
                    <form method="POST" action="{{ route('documents.versions', $document) }}" class="mt-4 space-y-3">
                        @csrf
                        <div><label class="field-label" for="version_reason">Motivo *</label><input id="version_reason" name="reason" required class="field-control"></div>
                        <div><label class="field-label" for="version_body">Conteúdo *</label><textarea id="version_body" name="body" required rows="7" class="field-control">{{ $content['body'] }}</textarea></div>
                        <button class="w-full rounded-lg border border-brand-300 px-4 py-2.5 text-sm font-bold text-brand-700">Emitir nova versão</button>
                    </form>
                </section>
                <section class="app-card border-red-200 p-5">
                    <h2 class="font-extrabold text-red-800">Anular documento</h2>
                    <form method="POST" action="{{ route('documents.void', $document) }}" class="mt-3 space-y-3">
                        @csrf
                        <textarea name="reason" required rows="3" class="field-control" placeholder="Motivo detalhado da anulação"></textarea>
                        <button class="w-full rounded-lg bg-red-700 px-4 py-2.5 text-sm font-bold text-white" onclick="return confirm('Confirmar anulação? O histórico será preservado.')">Anular</button>
                    </form>
                </section>
            @endif
        </aside>
    </div>
</x-layout.app>
