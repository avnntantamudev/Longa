<?php

declare(strict_types=1);

use App\Modules\Auth\Enums\OtpChannel;
use App\Modules\Auth\Jobs\SendOtpCode;
use App\Modules\Notifications\Sms\ArraySmsProvider;
use App\Modules\Notifications\Sms\SmsProvider;
use App\Modules\Notifications\WhatsApp\ArrayWhatsAppProvider;
use App\Modules\Notifications\WhatsApp\CloudApiWhatsAppProvider;
use App\Modules\Notifications\WhatsApp\WhatsAppDeliveryException;
use App\Modules\Notifications\WhatsApp\WhatsAppProvider;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => Cache::flush());

function whatsapp(): ArrayWhatsAppProvider
{
    /** @var ArrayWhatsAppProvider */
    return app(WhatsAppProvider::class);
}

it('envia o código por WhatsApp quando o utilizador escolhe e o código funciona', function () {
    $this->postJson('/api/v1/auth/otp/request', ['phone' => '923456789', 'channel' => 'whatsapp'])
        ->assertStatus(202)
        ->assertJsonPath('channel', 'whatsapp')
        ->assertJsonPath('message', 'Se o número estiver correto, vai receber um código por WhatsApp dentro de instantes.');

    expect(whatsapp()->sent)->toHaveCount(1)
        ->and(whatsapp()->sent[0]['to'])->toBe('+244923456789');

    $this->postJson('/api/v1/auth/otp/verify', ['phone' => '923456789', 'code' => whatsapp()->sent[0]['code']])->assertCreated();
});

it('usa SMS por defeito e rejeita canais desconhecidos', function () {
    $this->postJson('/api/v1/auth/otp/request', ['phone' => '923456789'])->assertStatus(202)->assertJsonPath('channel', 'sms');
    $this->postJson('/api/v1/auth/otp/request', ['phone' => '923456780', 'channel' => 'pombo'])->assertStatus(422);

    expect(whatsapp()->sent)->toBeEmpty();
});

it('permite pedir logo por WhatsApp depois de um SMS (intervalo por canal), mantendo o limite por hora', function () {
    $this->postJson('/api/v1/auth/otp/request', ['phone' => '923456789', 'channel' => 'sms'])->assertStatus(202);
    $this->postJson('/api/v1/auth/otp/request', ['phone' => '923456789', 'channel' => 'whatsapp'])->assertStatus(202);
    $this->postJson('/api/v1/auth/otp/request', ['phone' => '923456789', 'channel' => 'whatsapp'])->assertStatus(429);

    // Só o último código é válido
    $this->postJson('/api/v1/auth/otp/verify', ['phone' => '923456789', 'code' => whatsapp()->sent[0]['code']])->assertCreated();
});

it('se o WhatsApp falhar envia o código por SMS', function () {
    whatsapp()->failNext = true;

    $this->postJson('/api/v1/auth/otp/request', ['phone' => '923456789', 'channel' => 'whatsapp'])->assertStatus(202);

    /** @var ArraySmsProvider $sms */
    $sms = app(SmsProvider::class);
    expect(whatsapp()->sent)->toBeEmpty()
        ->and($sms->messagesTo('+244923456789'))->toHaveCount(1);
});

it('cifra o código no payload da fila', function () {
    expect(new SendOtpCode('+244923456789', '123456', 5, OtpChannel::WhatsApp))->toBeInstanceOf(ShouldBeEncrypted::class);
});

it('na PWA o utilizador escolhe o WhatsApp e pode reenviar por outro canal no ecrã do código', function () {
    $this->post('/entrar/codigo', ['phone' => '923456789', 'channel' => 'whatsapp'])
        ->assertSessionHas('otp_channel', 'whatsapp')
        ->assertSessionHas('success', 'Enviámos um código por WhatsApp para +244 9•• ••• 789.');

    $this->get('/entrar')->assertInertia(fn (Assert $page) => $page->where('channel', 'whatsapp')->where('phone', '+244923456789'));

    $this->post('/entrar/codigo', ['phone' => '+244923456789', 'channel' => 'sms'])->assertSessionHas('otp_channel', 'sms');
});

it('chama a WhatsApp Cloud API com o modelo de autenticação', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]], 200)]);
    $provider = new CloudApiWhatsAppProvider('token-123', '555', 'v23.0', 'longa_codigo_acesso', 'pt_PT');

    $provider->sendOtp('+244923456789', '482913');

    Http::assertSent(function ($request) {
        $body = $request->data();

        return $request->url() === 'https://graph.facebook.com/v23.0/555/messages'
            && $request->hasHeader('Authorization', 'Bearer token-123')
            && $body['to'] === '244923456789'
            && $body['template']['name'] === 'longa_codigo_acesso'
            && $body['template']['language']['code'] === 'pt_PT'
            && $body['template']['components'][0]['parameters'][0]['text'] === '482913';
    });
});

it('trata a recusa da Cloud API como falha de entrega', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Recipient not on WhatsApp']], 400)]);
    $provider = new CloudApiWhatsAppProvider('t', '555', 'v23.0', 'tpl', 'pt_PT');

    expect(fn () => $provider->sendOtp('+244923456789', '1'))->toThrow(WhatsAppDeliveryException::class, 'Recipient not on WhatsApp');
});
