<?php

return [
    'fuseki' => [
        'endpoint' => env('FUSEKI_ENDPOINT'),
    ],

    'wikidata' => [
        'endpoint'   => env('WIKIDATA_ENDPOINT', 'https://query.wikidata.org/sparql'),
        'user_agent' => env('WIKIDATA_USER_AGENT', 'ITVSemanticApp/1.0 (https://example.org)'),
        'timeout'    => (int) env('WIKIDATA_TIMEOUT', 8),
    ],

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

    'tmdb' => [
        'token' => env('TMDB_TOKEN'),
    ],

];
