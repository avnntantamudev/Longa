<?php

declare(strict_types=1);

namespace App\Modules\Sessions\Actions;

use App\Modules\Sessions\Events\MessageSent;
use App\Modules\Sessions\Models\Message;
use App\Modules\Sessions\Models\ServiceJob;
use App\Modules\Sessions\Services\ContactMasker;
use App\Modules\Users\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Envia uma mensagem no chat do serviço (texto e/ou PDF).
 *
 *  - Idempotente por `client_id`: o reenvio de uma mensagem que já chegou (ligação lenta, "tentar de novo")
 *    devolve a mensagem existente em vez de a duplicar.
 *  - O PDF fica no disco privado; o acesso é por link assinado temporário.
 *  - Contactos (telefones, e-mails) no texto são mascarados nesta fase (config longa.chat.mask_contacts).
 */
class SendMessage
{
    public function __construct(private readonly ContactMasker $masker) {}

    public function handle(ServiceJob $session, User $sender, ?string $body, ?UploadedFile $attachment, ?string $clientId): Message
    {
        $conversation = $session->conversation()->firstOrCreate([]);

        if ($clientId !== null) {
            $existing = Message::query()
                ->where('conversation_id', $conversation->id)
                ->where('sender_id', $sender->id)
                ->where('client_id', $clientId)
                ->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        $path = $attachment?->storeAs("chat/{$session->id}", Str::uuid()->toString().'.pdf', 'local');

        $message = Message::query()->create([
            'conversation_id' => $conversation->id,
            'sender_id' => $sender->id,
            'body' => $body !== null && trim($body) !== '' ? $this->masker->mask(trim($body)) : null,
            'attachment_path' => $path ?: null,
            'attachment_name' => $attachment ? Str::limit($attachment->getClientOriginalName(), 200, '') : null,
            'attachment_size' => $attachment?->getSize(),
            'client_id' => $clientId,
        ]);

        MessageSent::dispatch($message->setRelation('sender', $sender), $session->id);

        return $message;
    }
}
