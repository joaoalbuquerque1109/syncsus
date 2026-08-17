<?php

declare(strict_types=1);

return [
    // The legacy version remains active until the provider explicitly approves
    // the additive public identifier fields and the feature flag is enabled.
    'version' => (bool) env('SYNC_SUS_SYNCLAB_PUBLIC_IDENTIFIERS_ENABLED', false)
        ? 'outbound-orders-2026-08-08-public-identifiers'
        : 'outbound-orders-2026-08-03',

    'versions' => [
        'legacy' => 'outbound-orders-2026-08-03',
        'public_identifiers' => 'outbound-orders-2026-08-08-public-identifiers',
        'result_reception' => 'inbound-results-2026-08-08',
    ],

    'confirmed' => [
        // Synclab currently exposes no separate acknowledgement mechanism.
        'accepted_http_statuses' => [200],
        // A patient is identified by name plus at least one official identifier.
        'patient_identification' => 'name_and_cpf_or_cns',
        // Samples and barcodes are assigned later inside Synclab.
        'sample_identification' => 'synclab_after_request',
        // The local laboratory_exams table is the only orderable exam source.
        'catalog_source' => 'sync_sus_laboratory_exams',
        // The Synclab route is scoped by the seven-digit CNES of the unit.
        'endpoint_scope' => 'health_unit_cnes',
        // The numeric exam_orders.id is stable and is the service order shown in grids.
        'external_order_number' => 'exam_orders_id',
        'success_response' => 'http_200_only',
    ],

    // These capabilities are intentionally parked for later releases. Keeping
    // them visible prevents accidental implementation inside today's send-only scope.
    'standby' => [
        'sample_identification' => 'not_implemented',
        'barcode_generation' => 'not_implemented',
        'result_reception' => 'feature_gated_webhook',
        'result_partial_final_indicator' => 'not_applicable',
        'stable_result_identifiers' => 'not_applicable',
        // Phase 5 cannot consume Synclab's authenticated administration routes.
        // It remains parked until the provider publishes a supported catalog API.
        'incremental_catalog_sync' => 'blocked_provider_api_unavailable',
    ],

    'transitions' => [
        'public_identifiers' => [
            'status' => 'feature_gated_awaiting_provider_approval',
            'legacy_fields_preserved' => true,
            'order_field' => 'pedido.identificador_externo',
            'patient_field' => 'paciente.identificador_externo',
        ],
        'result_reception' => [
            'status' => 'feature_gated_awaiting_provider_configuration',
            'version' => 'inbound-results-2026-08-08',
            'endpoint' => '/api/v1/laboratory/synclab/results',
            'authentication_header' => 'X-Synclab-Result-Token',
        ],
        'incremental_catalog_sync' => [
            'status' => 'blocked_provider_api_unavailable',
            'endpoint' => null,
            'authentication' => null,
            'request_schema' => null,
            'response_schema' => null,
            'cursor_semantics' => null,
            'fallback_source' => 'versioned_csv',
        ],
    ],

    'pending' => [],
];
