<?php

declare(strict_types=1);

return [

    /*
    | Moeda: Kwanza. Todos os montantes são inteiros (Kz, sem cêntimos).
    */
    'currency' => [
        'code' => env('LONGA_CURRENCY_CODE', 'AOA'),
        'symbol' => env('LONGA_CURRENCY_SYMBOL', 'Kz'),
        'decimals' => 0,
    ],

    'phone' => [
        'country_code' => env('LONGA_PHONE_COUNTRY_CODE', '244'),
    ],

    'auth' => [
        // Token de acesso da API (minutos) e token de refresh (minutos; 30 dias)
        'access_token_ttl' => (int) env('SANCTUM_TOKEN_EXPIRATION', 60),
        'refresh_token_ttl' => (int) env('SANCTUM_REFRESH_EXPIRATION', 43200),
    ],

    'otp' => [
        'length' => (int) env('OTP_LENGTH', 6),
        'ttl' => (int) env('OTP_TTL', 300),
        'max_attempts' => (int) env('OTP_MAX_ATTEMPTS', 5),
        'lockout_minutes' => (int) env('OTP_LOCKOUT_MINUTES', 15),
        'resend_cooldown' => (int) env('OTP_RESEND_COOLDOWN', 60),
        'max_sends_per_phone_per_hour' => (int) env('OTP_MAX_SENDS_PER_PHONE_PER_HOUR', 5),
        'max_sends_per_ip_per_hour' => (int) env('OTP_MAX_SENDS_PER_IP_PER_HOUR', 20),
    ],

    // Versões em vigor dos documentos que o utilizador aceita (muda a versão para pedir nova aceitação)
    'consents' => [
        'terms' => env('LONGA_TERMS_VERSION', '2026-10'),
        'privacy' => env('LONGA_PRIVACY_VERSION', '2026-10'),
        'location' => env('LONGA_LOCATION_CONSENT_VERSION', '2026-10'),
    ],

    'sms' => [
        'driver' => env('SMS_DRIVER', 'log'),
        'sender_id' => env('SMS_SENDER_ID', 'LONGA'),
        'api_url' => env('SMS_API_URL'),
        'api_key' => env('SMS_API_KEY'),
    ],

    'vapid' => [
        'subject' => env('VAPID_SUBJECT'),
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
    ],

];
