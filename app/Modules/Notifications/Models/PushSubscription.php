<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Models;

use NotificationChannels\WebPush\PushSubscription as BasePushSubscription;

/**
 * Subscrição Web Push com o nome do dispositivo e a data do último envio com sucesso.
 *
 * @property int $id
 * @property string $endpoint
 * @property string|null $device
 * @property \Illuminate\Support\Carbon|null $last_used_at
 */
class PushSubscription extends BasePushSubscription
{
    protected $fillable = ['endpoint', 'public_key', 'auth_token', 'content_encoding', 'device', 'last_used_at'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime'];
    }
}
