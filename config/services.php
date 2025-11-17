<?php

return [

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
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

    // OpenAI Configuration
    'openai' => [
        'key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
        'temperature' => env('TEMPERATURE', 0.2),
        'timeout' => env('OPENAI_HTTP_TIMEOUT', 60),
    ],

    // Gemini Configuration
    'gemini' => [
        'key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-2.0-flash'),
    ],

    // SerpAPI Configuration
    'serpapi' => [
        'key' => env('SERPAPI_KEY'),
        'hl' => env('SERPAPI_HL', 'en'),
        'gl' => env('SERPAPI_GL', 'us'),
        'location' => env('SERPAPI_LOCATION', 'United States'),
        'paa_budget' => env('SERPAPI_PAA_BUDGET', 10),
        'timeout' => env('SERP_HTTP_TIMEOUT', 60),
    ],

    // Run Configuration
    'run' => [
        'concurrency' => env('RUN_CONCURRENCY', 6),
        'page_size' => env('RUN_PAGE_SIZE', 200),
        'rate_limit_ms' => env('RATE_LIMIT_MS', 0),
    ],

    // AIO Configuration
    'aio' => [
        'concurrency' => env('AIO_CONCURRENCY', 3),
        'page_size' => env('AIO_PAGE_SIZE', 50),
        'rate_limit_ms' => env('AIO_RATE_LIMIT_MS', 1000),
    ],

    // Topic Generation
    'topics' => [
        'time_budget_sec' => env('ADMIN_TIME_BUDGET_SEC', 90),
        'max_ai_total' => env('MAX_AI_TOTAL', 2),
        'max_ai_per_provider' => env('MAX_AI_PER_PROVIDER', 1),
        'paa_per_seed' => env('PAA_PER_SEED', 1),
        'min_branded_queries' => env('MIN_BRANDED_Q', 1),
    ],

];