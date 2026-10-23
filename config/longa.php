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

    // Pedidos de aula e matching TRIM TRIM (Fase 2). Valores [AJUSTAR] com o cliente.
    'requests' => [
        'commission_percent' => (int) env('LONGA_COMMISSION_PERCENT', 15),
        'min_amount' => (int) env('LONGA_REQUEST_MIN_AMOUNT', 2000),
        'max_amount' => (int) env('LONGA_REQUEST_MAX_AMOUNT', 5000000),
        'max_active_per_student' => (int) env('LONGA_REQUEST_MAX_ACTIVE', 2),
        // Tempo total de procura do pedido e prazo de cada oferta (segundos)
        'ttl' => (int) env('LONGA_REQUEST_TTL', 300),
        'offer_ttl' => (int) env('LONGA_OFFER_TTL', 60),
        // Antecedência mínima da aula (minutos)
        'min_lead_minutes' => (int) env('LONGA_REQUEST_MIN_LEAD_MINUTES', 30),
        // Mesmo aluno, mesma área e hora próxima com um pedido ativo = duplicado
        'duplicate_window_minutes' => (int) env('LONGA_REQUEST_DUPLICATE_WINDOW', 120),
        'create_per_hour' => (int) env('LONGA_REQUEST_CREATE_PER_HOUR', 5),
    ],

    'matching' => [
        'radius_m' => (int) env('LONGA_MATCHING_RADIUS_M', 5000),
        'first_wave_size' => (int) env('LONGA_MATCHING_FIRST_WAVE', 10),
        'next_wave_size' => (int) env('LONGA_MATCHING_NEXT_WAVE', 15),
        // Pesos da ordenação: score do formador vs proximidade (somam 1)
        'weight_score' => (float) env('LONGA_MATCHING_WEIGHT_SCORE', 0.6),
        'weight_distance' => (float) env('LONGA_MATCHING_WEIGHT_DISTANCE', 0.4),
        // Folga (minutos) antes e depois de uma aula marcada em que o formador não recebe outra
        'conflict_buffer_minutes' => (int) env('LONGA_MATCHING_CONFLICT_BUFFER', 30),
        'accept_per_minute' => (int) env('LONGA_OFFER_ACCEPT_PER_MINUTE', 30),
        // SMS de oferta quando o formador não tem push ativo (custa dinheiro)
        'sms_fallback_per_trainer_per_hour' => (int) env('LONGA_OFFER_SMS_PER_HOUR', 5),
    ],

    'chat' => [
        'max_attachment_kb' => (int) env('LONGA_CHAT_MAX_ATTACHMENT_KB', 10240),
        'attachment_link_minutes' => (int) env('LONGA_CHAT_ATTACHMENT_LINK_MINUTES', 10),
        // Esconde telefones/e-mails escritos no chat (comunicação fica na LONGA nesta fase) [AJUSTAR]
        'mask_contacts' => (bool) env('LONGA_CHAT_MASK_CONTACTS', true),
    ],

    // WhatsApp (o utilizador pode escolher receber o código por WhatsApp)
    'whatsapp' => [
        'driver' => env('WHATSAPP_DRIVER', 'log'),
        'token' => env('WHATSAPP_TOKEN'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'api_version' => env('WHATSAPP_API_VERSION', 'v23.0'),
        'otp_template' => env('WHATSAPP_OTP_TEMPLATE', 'longa_codigo_acesso'),
        'language' => env('WHATSAPP_TEMPLATE_LANGUAGE', 'pt_PT'),
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
