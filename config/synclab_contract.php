<?php

declare(strict_types=1);

return [
    'version' => 'outbound-orders-2026-08-03',

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
        'result_reception' => 'not_implemented',
        'result_partial_final_indicator' => 'not_applicable',
        'stable_result_identifiers' => 'not_applicable',
    ],

    'pending' => [],
];
