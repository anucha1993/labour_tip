<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // Shared secret between this server and the worker portal (labour.importer168.com)
    'portal' => [
        'key' => env('PORTAL_API_KEY'),
    ],

    'google' => [
        'translate_key' => env('GOOGLE_TRANSLATE_API_KEY'),
        // Cloud Vision OCR for the AI document reader; falls back to the translate key when unset.
        'vision_key' => env('GOOGLE_VISION_API_KEY'),
        // OCR through DataCorp when the AI uses DataCorp (same dca_sk_ key) — false = call Google directly
        'vision_via_datacorp' => env('GOOGLE_VISION_VIA_DATACORP', true),
    ],

    'openai' => [
        'key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'timeout' => (int) env('OPENAI_TIMEOUT', 60),
        // extra attempts when the API is busy (429/5xx) or the connection drops; waits 1 s, 2 s, 4 s … × delay
        'retries' => (int) env('OPENAI_RETRIES', 2),
        'retry_delay_ms' => (int) env('OPENAI_RETRY_DELAY_MS', 1000),
        // retouch photos in the AI chat (POST /images/edits)
        'image_model' => env('OPENAI_IMAGE_MODEL', 'gpt-image-2'),
        'image_timeout' => (int) env('OPENAI_IMAGE_TIMEOUT', 240),
        // true = AI goes through DataCorp (plan/credits bar + payment links); default: on when base_url is datacorpapi.com
        'datacorp' => env('OPENAI_DATACORP'),
    ],

];
