<?php

return [
    'app_id' => env('ONESIGNAL_APP_ID'),
    'app_api_key' => env('ONESIGNAL_APP_API_KEY'),
    'android_channel_id' => env('ONESIGNAL_ANDROID_CHANNEL_ID'),
    // Create these three channel IDs in the OneSignal Android dashboard,
    // then place their IDs in the production environment. Keeping the
    // routing here means one notification type cannot accidentally use a
    // noisy channel intended for another type.
    'android_channels' => [
        'messages' => env('ONESIGNAL_ANDROID_MESSAGES_CHANNEL_ID'),
        'orders' => env('ONESIGNAL_ANDROID_ORDERS_CHANNEL_ID'),
        'general' => env('ONESIGNAL_ANDROID_GENERAL_CHANNEL_ID'),
    ],
    // The provider must explicitly configure the mobile web origin before
    // enabling this. Android native notifications keep working independently.
    'web_enabled' => env('ONESIGNAL_WEB_ENABLED', false),
];
