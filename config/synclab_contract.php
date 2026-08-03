<?php

declare(strict_types=1);

return [
    'version' => 'draft-2026-08-02',

    'confirmed' => [
        // Synclab currently exposes no separate acknowledgement mechanism.
        'accepted_http_statuses' => [200],
        // A patient is identified by name plus at least one official identifier.
        'patient_identification' => 'name_and_cpf_or_cns',
        // Samples and barcodes are assigned later inside Synclab.
        'sample_identification' => 'synclab_after_request',
    ],

    // Keep every unresolved external decision explicit. Transmission remains held
    // in awaiting_contract until this map is completed in a dedicated commit.
    'pending' => [
        'external_tenant_code_semantics' => null,
        'duplicate_order_behaviour' => null,
        'external_order_number_format' => null,
        'result_partial_final_indicator' => null,
        'stable_result_identifiers' => null,
        'catalog_source' => null,
        'success_response_identifiers' => null,
    ],
];
