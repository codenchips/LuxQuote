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

    'salesforce' => [
        'auth_method' => env('SALESFORCE_AUTH_METHOD', 'client_credentials'),
        'client_id' => env('SALESFORCE_API_KEY'),
        'client_secret' => env('SALESFORCE_CONSUMER_SECRET'),
        'url' => env('SALESFORCE_BASE_URL'),
        'jwt_subject' => env('SALESFORCE_JWT_SUBJECT'),
        'jwt_audience' => env('SALESFORCE_JWT_AUDIENCE', env('SALESFORCE_BASE_URL')),
        'jwt_private_key' => env('SALESFORCE_JWT_PRIVATE_KEY'),
        'jwt_private_key_path' => env('SALESFORCE_JWT_PRIVATE_KEY_PATH'),
    ],

    'datasheets' => [
        'endpoint' => env('DATASHEET_MERGE_ENDPOINT', 'https://tamlite.co.uk/ci_index.php/download_schedule'),
        'public_base_url' => env('DATASHEET_MERGE_PUBLIC_BASE_URL', 'https://tamlite.co.uk/pdfmerge'),
        'timeout' => (int) env('DATASHEET_MERGE_TIMEOUT', 60),
    ],

];
