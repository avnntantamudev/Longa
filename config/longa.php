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
        // PIN: pedir de novo ao fim de X minutos sem uso; administradores no máximo X horas
        'pin_idle_minutes' => (int) env('LONGA_PIN_IDLE_MINUTES', 60),
        'admin_pin_hours' => (int) env('LONGA_ADMIN_PIN_HOURS', 8),
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

    // Termos e Política de Privacidade: versionados em ficheiros (resources/legal/{terms,privacy}/<versão>.md);
    // a versão em vigor é o ficheiro mais recente. Juntar um ficheiro novo pede nova aceitação a todos.
    'legal' => [
        'path' => env('LONGA_LEGAL_PATH', resource_path('legal')),
    ],

    // Versões em vigor dos outros consentimentos (muda a versão para pedir nova autorização)
    'consents' => [
        'location' => env('LONGA_LOCATION_CONSENT_VERSION', '2026-10'),
        // Autorização para a empresa (RH) ver os serviços do colaborador
        'company_reports' => env('LONGA_COMPANY_REPORTS_CONSENT_VERSION', '2026-10'),
    ],

    // Pedidos de serviço e matching TRIM TRIM (Fase 2). Valores [AJUSTAR] com o cliente.
    'requests' => [
        'commission_percent' => (int) env('LONGA_COMMISSION_PERCENT', 20),
        'min_amount' => (int) env('LONGA_REQUEST_MIN_AMOUNT', 2000),
        'max_amount' => (int) env('LONGA_REQUEST_MAX_AMOUNT', 5000000),
        'max_active_per_client' => (int) env('LONGA_REQUEST_MAX_ACTIVE', 2),
        // Tempo total de procura do pedido e prazo de cada oferta (segundos)
        'ttl' => (int) env('LONGA_REQUEST_TTL', 300),
        'offer_ttl' => (int) env('LONGA_OFFER_TTL', 60),
        // Antecedência mínima do serviço (minutos)
        'min_lead_minutes' => (int) env('LONGA_REQUEST_MIN_LEAD_MINUTES', 30),
        // Mesmo cliente, mesma área e hora próxima com um pedido ativo = duplicado
        'duplicate_window_minutes' => (int) env('LONGA_REQUEST_DUPLICATE_WINDOW', 120),
        'create_per_hour' => (int) env('LONGA_REQUEST_CREATE_PER_HOUR', 5),
    ],

    'matching' => [
        'radius_m' => (int) env('LONGA_MATCHING_RADIUS_M', 5000),
        'first_wave_size' => (int) env('LONGA_MATCHING_FIRST_WAVE', 10),
        'next_wave_size' => (int) env('LONGA_MATCHING_NEXT_WAVE', 15),
        // Pesos da ordenação: score do técnico vs proximidade (somam 1)
        'weight_score' => (float) env('LONGA_MATCHING_WEIGHT_SCORE', 0.6),
        'weight_distance' => (float) env('LONGA_MATCHING_WEIGHT_DISTANCE', 0.4),
        // Folga (minutos) antes e depois de um serviço marcado em que o técnico não recebe outra
        'conflict_buffer_minutes' => (int) env('LONGA_MATCHING_CONFLICT_BUFFER', 30),
        'accept_per_minute' => (int) env('LONGA_OFFER_ACCEPT_PER_MINUTE', 30),
        // SMS de oferta quando o técnico não tem push ativo (custa dinheiro)
        'sms_fallback_per_provider_per_hour' => (int) env('LONGA_OFFER_SMS_PER_HOUR', 5),
    ],

    // Execução do serviço, QR e escrow (Fase 3). Todas as regras de tempo num só sítio [AJUSTAR]
    'session_rules' => [
        'payment_window_minutes' => (int) env('LONGA_PAYMENT_WINDOW_MINUTES', 10),
        // O técnico pode iniciar o serviço a partir de N minutos antes da hora marcada
        'start_early_minutes' => (int) env('LONGA_SESSION_START_EARLY_MINUTES', 15),
        // QR: cada token vale 30 s; o lote cobre 10 janelas (5 min) para aguentar falhas de rede
        'qr_rotation_seconds' => (int) env('LONGA_QR_ROTATION_SECONDS', 30),
        'qr_batch_size' => (int) env('LONGA_QR_BATCH_SIZE', 10),
        // Leitura aceite desde o início previsto até N horas depois do fim previsto
        'qr_window_hours_after_end' => (int) env('LONGA_QR_WINDOW_HOURS', 4),
        'qr_max_failed_attempts' => (int) env('LONGA_QR_MAX_FAILED_ATTEMPTS', 5),
        'qr_attempt_window_minutes' => (int) env('LONGA_QR_ATTEMPT_WINDOW_MINUTES', 10),
        // Sinal de risco (nunca bloqueio): cliente a mais de N metros do local do serviço ao ler o QR
        'presence_risk_meters' => (int) env('LONGA_PRESENCE_RISK_METERS', 500),
        // R1/R2: N horas após o fim previsto sem confirmação → awaiting_confirmation; janela de 48 h
        'auto_awaiting_after_end_hours' => (int) env('LONGA_AUTO_AWAITING_HOURS', 2),
        'confirmation_window_hours' => (int) env('LONGA_CONFIRMATION_WINDOW_HOURS', 48),
        // R4: faltas
        'no_show_provider_after_minutes' => (int) env('LONGA_NO_SHOW_PROVIDER_MINUTES', 15),
        'no_show_client_after_minutes' => (int) env('LONGA_NO_SHOW_CLIENT_MINUTES', 30),
        'no_show_contest_hours' => (int) env('LONGA_NO_SHOW_CONTEST_HOURS', 24),
        // Política de reembolso (percentagens da parte que fica para o técnico, antes da comissão)
        'late_cancel_hours' => (int) env('LONGA_LATE_CANCEL_HOURS', 6),
        'late_cancel_provider_share_percent' => (int) env('LONGA_LATE_CANCEL_PROVIDER_SHARE', 25),
        'no_show_client_provider_share_percent' => (int) env('LONGA_NO_SHOW_CLIENT_PROVIDER_SHARE', 50),
        // Reconciliação: uma retenção sem destino N dias após o fim previsto gera alerta
        'hold_max_days' => (int) env('LONGA_HOLD_MAX_DAYS', 7),
    ],

    'payments' => [
        // Gateway: só 'mock' nesta fase (o real será integrado depois de o cliente o escolher)
        'provider' => env('PAYMENT_PROVIDER', 'mock'),
        'mock_outcome' => env('PAYMENT_MOCK_OUTCOME', 'succeeded'),
    ],

    'chat' => [
        'max_attachment_kb' => (int) env('LONGA_CHAT_MAX_ATTACHMENT_KB', 10240),
        'attachment_link_minutes' => (int) env('LONGA_CHAT_ATTACHMENT_LINK_MINUTES', 10),
        // Esconde telefones/e-mails escritos no chat (comunicação fica no BISCA nesta fase) [AJUSTAR]
        'mask_contacts' => (bool) env('LONGA_CHAT_MASK_CONTACTS', true),
    ],

    // Técnico VIP (subscrição anual). Valores por omissão: o admin muda-os em Backoffice → Definições.
    'vip' => [
        'price' => (int) env('LONGA_VIP_PRICE', 50000),
        'commission_percent' => (int) env('LONGA_VIP_COMMISSION_PERCENT', 10),
        'duration_days' => (int) env('LONGA_VIP_DURATION_DAYS', 365),
        // Avisos antes de a subscrição terminar (dias)
        'reminder_days' => [30, 7, 1],
    ],

    // Pedidos para outro dia (não hoje): ficam abertos até X horas antes do serviço
    'scheduled_requests' => [
        'close_hours_before' => (int) env('LONGA_SCHEDULED_CLOSE_HOURS', 2),
        // Máximo de técnicos avisados por push num pedido agendado (os restantes veem-no na lista)
        'max_push' => (int) env('LONGA_SCHEDULED_MAX_PUSH', 50),
    ],

    // Foto de perfil obrigatória para técnicos. Os técnicos registados antes desta regra
    // continuam a receber pedidos sem foto até esta data (aviso na app).
    'providers' => [
        'photo_required_from' => env('LONGA_PROVIDER_PHOTO_REQUIRED_FROM', '2026-11-02 00:00:00'),
    ],

    // Disputas: evidências (imagem ou PDF) no disco privado
    'disputes' => [
        'max_attachments' => (int) env('LONGA_DISPUTE_MAX_ATTACHMENTS', 5),
        'max_attachment_kb' => (int) env('LONGA_DISPUTE_MAX_ATTACHMENT_KB', 5120),
        'attachment_link_minutes' => (int) env('LONGA_DISPUTE_ATTACHMENT_LINK_MINUTES', 10),
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
        'sender_id' => env('SMS_SENDER_ID', 'BISCA'),
        'api_url' => env('SMS_API_URL'),
        'api_key' => env('SMS_API_KEY'),
    ],

    'vapid' => [
        'subject' => env('VAPID_SUBJECT'),
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
    ],

];
