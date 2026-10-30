<?php

declare(strict_types=1);

namespace App\Modules\Sessions\Actions;

use App\Modules\Requests\Models\ServiceRequest;
use App\Modules\Sessions\Events\MessageSent;
use App\Modules\Sessions\Models\Message;
use App\Modules\Sessions\Services\ChatFlagger;
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
    public function __construct(private readonly ContactMasker $masker, private readonly ChatFlagger $flagger) {}

    public function handle(ServiceRequest $session, User $sender, ?string $body, ?UploadedFile $attachment, ?string $clientId): Message
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

        $mime = $attachment ? (string) $attachment->getMimeType() : null;
        $path = $attachment?->storeAs("chat/{$session->id}", Str::uuid()->toString().'.'.$attachment->extension(), 'local');
        $text = $body !== null && trim($body) !== '' ? trim($body) : null;
        // Contactos ou desvio para fora da plataforma: a outra parte vê o texto mascarado, a equipa revê o original
        $reasons = $text === null ? [] : $this->flagger->reasons($text);

        $message = Message::query()->create([
            'conversation_id' => $conversation->id,
            'sender_id' => $sender->id,
            'body' => $text === null ? null : $this->masker->mask($text),
            'attachment_path' => $path ?: null,
            'attachment_name' => $attachment ? Str::limit($attachment->getClientOriginalName(), 200, '') : null,
            'attachment_size' => $attachment?->getSize(),
            'attachment_mime' => $mime,
            'client_id' => $clientId,
            'flagged' => $reasons !== [],
            'flag_reasons' => $reasons === [] ? null : $reasons,
            'flagged_body' => $reasons === [] ? null : $text,
            'flag_status' => $reasons === [] ? null : Message::FLAG_PENDING,
        ]);

        MessageSent::dispatch($message->setRelation('sender', $sender), $session->id);

        return $message;
    }
}
