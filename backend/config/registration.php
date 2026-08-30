<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Courier document request limits
    |--------------------------------------------------------------------------
    |
    | Courier registration contains five documents in one request.  These
    | conservative limits keep requests below typical shared-hosting proxy
    | limits and are passed to the registration page so the browser can
    | prepare images before an upload begins.  Laravel enforces the same
    | limits as a non-bypassable server-side safeguard.
    |
    */
    'courier_documents' => [
        // Shared cPanel installations commonly reject multipart requests at
        // 2 MB before Laravel can validate them. Five optimized documents
        // therefore stay comfortably below that proxy ceiling.
        'max_file_kilobytes' => (int) env('COURIER_DOCUMENT_MAX_KB', 480),
        'max_total_kilobytes' => (int) env('COURIER_DOCUMENT_TOTAL_MAX_KB', 1600),
        'target_image_kilobytes' => (int) env('COURIER_DOCUMENT_TARGET_IMAGE_KB', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | Merchant verification document request limits
    |--------------------------------------------------------------------------
    |
    | The verification sheet sends four identity documents in one multipart
    | request. Keep this bundle below the same conservative shared-hosting
    | ceiling as courier registration so a proxy cannot reject it with HTTP
    | 413 before Laravel has a chance to report a useful validation error.
    |
    */
    'merchant_verification_documents' => [
        'max_file_kilobytes' => (int) env('MERCHANT_VERIFICATION_DOCUMENT_MAX_KB', 480),
        'max_total_kilobytes' => (int) env('MERCHANT_VERIFICATION_DOCUMENT_TOTAL_MAX_KB', 1600),
        'target_image_kilobytes' => (int) env('MERCHANT_VERIFICATION_DOCUMENT_TARGET_IMAGE_KB', 300),
    ],
];
