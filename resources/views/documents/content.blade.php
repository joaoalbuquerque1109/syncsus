<div class="document-content safe-wrap">
@switch($document->typeEnum())
    @case(\App\Modules\Documents\Domain\Enums\ClinicalDocumentType::Prescription)
        <p><strong>Tipo:</strong> {{ ($content['prescription_type'] ?? null) === 'hospital' ? 'Prescrição hospitalar' : 'Receita domiciliar' }}</p>
        <table class="items">
            <thead><tr><th>Medicamento</th><th>Apresentação</th><th>Posologia</th></tr></thead>
            <tbody>
                @foreach($content['items'] ?? [] as $item)
                    <tr>
                        <td><strong>{{ $item['medication_name'] }}</strong>@if(filled($item['concentration'] ?? null))<br>{{ $item['concentration'] }}@endif</td>
                        <td>{{ $item['presentation'] }}</td>
                        <td>{{ $item['dose'] }} {{ $item['dose_unit'] }} · {{ $item['route'] }} · {{ $item['frequency'] }}@if(filled($item['instructions'] ?? null))<br>{{ $item['instructions'] }}@endif</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        @if(filled($content['general_instructions'] ?? null))<div class="extra"><strong>Orientações gerais:</strong><br>{{ $content['general_instructions'] }}</div>@endif
        @break

    @case(\App\Modules\Documents\Domain\Enums\ClinicalDocumentType::ExamRequest)
        <p><strong>Prioridade:</strong> {{ $content['priority'] ?? 'routine' }}</p>
        <p><strong>Indicação clínica:</strong><br>{{ $content['clinical_indication'] ?? '' }}</p>
        <table class="items">
            <thead><tr><th>Exame solicitado</th><th>Grupo</th><th>Observações</th></tr></thead>
            <tbody>
                @foreach($content['items'] ?? [] as $item)
                    <tr><td><strong>{{ $item['exam_name'] }}</strong></td><td>{{ $item['group'] }}</td><td>{{ $item['justification'] ?? $item['preparation'] ?? '—' }}</td></tr>
                @endforeach
            </tbody>
        </table>
        @if(filled($content['notes'] ?? null))<div class="extra">{{ $content['notes'] }}</div>@endif
        @break

    @case(\App\Modules\Documents\Domain\Enums\ClinicalDocumentType::Referral)
        <table class="metadata">
            <tr><td>Destino</td><td>{{ $content['destination'] ?? '' }}</td></tr>
            @if(filled($content['specialty'] ?? null))<tr><td>Especialidade</td><td>{{ $content['specialty'] }}</td></tr>@endif
            @if(filled($content['recipient_professional'] ?? null))<tr><td>Profissional</td><td>{{ $content['recipient_professional'] }}</td></tr>@endif
            <tr><td>Prioridade</td><td>{{ $content['priority'] ?? 'routine' }}</td></tr>
        </table>
        <p><strong>Motivo:</strong><br>{{ $content['reason'] ?? '' }}</p>
        <p><strong>Resumo clínico:</strong><br>{{ $content['clinical_summary'] ?? '' }}</p>
        @if(filled($content['diagnostic_hypothesis'] ?? null))<p><strong>Hipótese diagnóstica:</strong><br>{{ $content['diagnostic_hypothesis'] }}</p>@endif
        @if(filled($content['guidance'] ?? null))<div class="extra"><strong>Orientações:</strong><br>{{ $content['guidance'] }}</div>@endif
        @break

    @default
        <div class="body">{{ $content['body'] ?? '' }}</div>
        @if(($content['include_cid'] ?? false) && filled($content['cid_text'] ?? null))
            <p><strong>CID informado mediante autorização:</strong> {{ $content['cid_text'] }}</p>
        @endif
        @if(filled($content['additional_information'] ?? null))
            <div class="extra">{{ $content['additional_information'] }}</div>
        @endif
@endswitch
</div>
