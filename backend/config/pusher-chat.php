<?php

return [
    'enabled' => env('PUSHER_CHAT_ENABLED', true),
    'app_id' => env('PUSHER_APP_ID'),
    'key' => env('PUSHER_APP_KEY'),
    'secret' => env('PUSHER_APP_SECRET'),
    'cluster' => env('PUSHER_APP_CLUSTER', 'eu'),
];
