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

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Temporary registration OTP
    |--------------------------------------------------------------------------
    |
    | This short-lived code is intentionally a bridge until an SMS provider is
    | connected. Keep the code hint enabled only while this temporary flow is
    | in use, then replace this setting with provider-backed verification.
    |
    */
    'temporary_otp' => [
        'code' => env('TEMPORARY_OTP_CODE', '123456'),
        'ttl_seconds' => (int) env('TEMPORARY_OTP_TTL_SECONDS', 600),
        'max_attempts' => (int) env('TEMPORARY_OTP_MAX_ATTEMPTS', 5),
        'resend_cooldown_seconds' => (int) env('TEMPORARY_OTP_RESEND_COOLDOWN_SECONDS', 60),
        'show_code_hint' => env('TEMPORARY_OTP_SHOW_CODE_HINT', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Web Push (PWA)
    |--------------------------------------------------------------------------
    |
    | The private key stays only in the server environment. The public key is
    | returned to an authenticated mobile user while enabling notifications.
    |
    */
    'web_push' => [
        'subject' => env('VAPID_SUBJECT', 'https://our-qiq.com'),
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
    ],

];
