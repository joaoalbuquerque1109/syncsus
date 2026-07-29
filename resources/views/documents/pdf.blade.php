<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 28mm 22mm 24mm; }
        body { font-family: "DejaVu Sans", sans-serif; color: #172033; font-size: 11pt; line-height: 1.55; }
        .header { border-bottom: 2px solid #1476d4; padding-bottom: 14px; margin-bottom: 28px; }
        .brand { color: #0b3558; font-size: 19pt; font-weight: bold; }
        .unit { color: #53657b; font-size: 9pt; }
        h1 { text-align: center; font-size: 16pt; text-transform: uppercase; margin: 25px 0; }
        .metadata { width: 100%; border-collapse: collapse; margin-bottom: 24px; }
        .metadata td { padding: 6px 8px; border-bottom: 1px solid #d9e2ec; }
        .metadata td:first-child { width: 30%; color: #53657b; font-weight: bold; }
        .body { white-space: pre-wrap; text-align: justify; min-height: 220px; }
        .extra { margin-top: 20px; padding: 12px; background: #f2f6fa; }
        .signature { margin: 60px auto 25px; width: 65%; text-align: center; border-top: 1px solid #172033; padding-top: 8px; }
        .footer { position: fixed; bottom: -10mm; left: 0; right: 0; border-top: 1px solid #d9e2ec; padding-top: 8px; font-size: 8pt; color: #53657b; }
        .code { font-family: "DejaVu Sans Mono", monospace; font-weight: bold; }
        .void { color: #a00; border: 2px solid #a00; padding: 10px; text-align: center; font-weight: bold; }
    </style>
</head>
<body>
    <header class="header">
        <div class="brand">SYNC SUS</div>
        <div class="unit">{{ $document->healthUnit->organization->trade_name }} · {{ $document->healthUnit->name }}</div>
        @if($document->healthUnit->cnes_code)<div class="unit">CNES {{ $document->healthUnit->cnes_code }}</div>@endif
    </header>

    @if($document->status === 'voided')
        <div class="void">DOCUMENTO ANULADO</div>
    @endif

    <h1>{{ $document->title }}</h1>

    <table class="metadata">
        <tr><td>Paciente</td><td>{{ $document->patient->displayName() }}</td></tr>
        <tr><td>Prontuário</td><td>{{ $document->patient->medical_record_number }}</td></tr>
        <tr><td>Atendimento</td><td>{{ $document->encounter->encounter_number }}</td></tr>
        <tr><td>Emissão</td><td>{{ now()->format('d/m/Y H:i') }}</td></tr>
        @if(filled($content['recipient_name'] ?? null))<tr><td>Destinatário</td><td>{{ $content['recipient_name'] }}</td></tr>@endif
        @if(filled($content['starts_at'] ?? null))<tr><td>Início</td><td>{{ \Illuminate\Support\Carbon::parse($content['starts_at'])->format('d/m/Y H:i') }}</td></tr>@endif
        @if(filled($content['duration_value'] ?? null))<tr><td>Período</td><td>{{ $content['duration_value'] }} {{ ($content['duration_unit'] ?? '') === 'hours' ? 'hora(s)' : 'dia(s)' }}</td></tr>@endif
    </table>

    <div class="body">{{ $content['body'] ?? '' }}</div>

    @if(($content['include_cid'] ?? false) && filled($content['cid_text'] ?? null))
        <p><strong>CID informado mediante autorização:</strong> {{ $content['cid_text'] }}</p>
    @endif
    @if(filled($content['additional_information'] ?? null))
        <div class="extra">{{ $content['additional_information'] }}</div>
    @endif

    <div class="signature">
        @php($profile = $document->consultation?->professional?->professionalProfile)
        <strong>{{ $profile?->displayName() ?? $document->consultation?->professional?->name ?? $document->creator->name }}</strong><br>
        @if($profile?->primaryRegistrationLabel())
            {{ $profile->primaryRegistrationLabel() }}
            @if($profile->specialties->first()?->pivot?->rqe_number) · RQE {{ $profile->specialties->first()->pivot->rqe_number }}@endif
            <br>
        @endif
        @if($profile?->cnes_code)CNES profissional {{ $profile->cnes_code }}<br>@endif
        Profissional emissor · identificação profissional conforme cadastro institucional
    </div>

    <footer class="footer">
        Documento {{ $document->public_id }} · versão {{ $versionNumber }}<br>
        Verificação local: <span class="code">{{ $document->verification_code }}</span> ·
        {{ route('documents.verify', $document->verification_code) }}
    </footer>
</body>
</html>
