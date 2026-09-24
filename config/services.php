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
   'google' => [
    'client_id' => env('GOOGLE_CLIENT_ID'),
    'client_secret' => env('GOOGLE_CLIENT_SECRET'),
    'redirect' => env('GOOGLE_REDIRECT'),
],
'ai' => [
    'url'            => env('AI_SERVICE_URL', 'http://localhost:8001'),
    // Shopping chatbot: short timeout so a down service doesn't slow chat; SQL search takes over.
    'chat_timeout'   => env('AI_CHAT_SEARCH_TIMEOUT', 3),
    'chat_min_score' => env('AI_CHAT_MIN_SCORE', 0.35),
],

'stripe' => [
    'key'            => env('STRIPE_KEY'),
    'secret'         => env('STRIPE_SECRET'),
    'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
],
'groq' => [
    'key'              => env('GROQ_API_KEY'),
    'model'            => env('GROQ_MODEL', 'openai/gpt-oss-120b'),
    'timeout'          => env('GROQ_TIMEOUT', 15),
    // Stay under the Groq free tier (1,000 requests/day, ~30/min for this model).
    'daily_budget'     => env('GROQ_DAILY_BUDGET', 950),
    'minute_budget'    => env('GROQ_MINUTE_BUDGET', 25),
    // Chatbot reply language when a message gives no signal (e.g. "iphone 13").
    'default_language' => env('CHAT_DEFAULT_LANGUAGE', 'fr'),
],
'd17' => [
    // Shown on the checkout page for D17 transfers. Leave empty until the real number is known.
    'account_number' => env('D17_ACCOUNT_NUMBER', ''),
],
'serper' => [
    'key' => env('SERPER_API_KEY', ''),
],
'openrouter' => [
    'key' => env('OPENROUTER_API_KEY'),
],
];
