<?php

declare(strict_types=1);

namespace App\Modules\Notifications\WhatsApp;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * WhatsApp Cloud API (Meta), diretamente, sem intermediários.
 *
 * Requisitos (a tratar pelo cliente): conta Meta Business verificada, número dedicado do Garimpo e um
 * modelo de categoria "Authentication" aprovado (WHATSAPP_OTP_TEMPLATE) com botão "copiar código".
 * Ver https://developers.facebook.com/docs/whatsapp/business-management-api/authentication-templates
 */
class CloudApiWhatsAppProvider implements WhatsAppProvider
{
    public function __construct(
        private readonly string $token,
        private readonly string $phoneNumberId,
        private readonly string $apiVersion,
        private readonly string $otpTemplate,
        private readonly string $language,
    ) {}

    public function sendOtp(string $to, string $code): void
    {
        try {
            $response = Http::withToken($this->token)
                ->acceptJson()
                ->timeout(10)
                ->post("https://graph.facebook.com/{$this->apiVersion}/{$this->phoneNumberId}/messages", [
                    'messaging_product' => 'whatsapp',
                    'recipient_type' => 'individual',
                    'to' => ltrim($to, '+'),
                    'type' => 'template',
                    'template' => [
                        'name' => $this->otpTemplate,
                        'language' => ['code' => $this->language],
                        'components' => [
                            ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => $code]]],
                            // Botão "copiar código" do modelo de autenticação
                            ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [['type' => 'text', 'text' => $code]]],
                        ],
                    ],
                ]);
        } catch (ConnectionException $e) {
            throw new WhatsAppDeliveryException('Sem ligação à API do WhatsApp: '.$e->getMessage(), previous: $e);
        }

        if ($response->failed()) {
            $error = $response->json('error.message') ?? $response->body();

            throw new WhatsAppDeliveryException("WhatsApp recusou a mensagem ({$response->status()}): {$error}");
        }
    }
}
