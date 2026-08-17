<?php

return [
    'hmac_key' => env('PATIENT_IDENTIFIER_HMAC_KEY'),
    'hmac_key_version' => (int) env('PATIENT_IDENTIFIER_HMAC_KEY_VERSION', 1),
];
