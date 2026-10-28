<?php

declare(strict_types=1);

namespace App\Modules\Payments\Console;

use App\Modules\Notifications\Notifications\VipNotification;
use App\Modules\Payments\Models\ProviderSubscription;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Avisa os formadores cujo VIP está a terminar (30/7/1 dias) ou terminou. Cada aviso é enviado uma vez,
 * registado na última subscrição do formador (a que define o fim).
 */
class VipRemindersCommand extends Command
{
    protected $signature = 'longa:vip:reminders';

    protected $description = 'Avisos de fim da subscrição VIP';

    public function handle(): int
    {
        /** @var list<int> $days */
        $days = config('longa.vip.reminder_days');
        $sent = 0;

        // Última subscrição ativa de cada formador = fim do VIP
        $latest = ProviderSubscription::query()
            ->where('status', ProviderSubscription::ACTIVE)
            ->whereIn('id', ProviderSubscription::query()->selectRaw('MAX(id)')->where('status', ProviderSubscription::ACTIVE)->groupBy('provider_profile_id'))
            ->where('ends_at', '>', now()->subDays(2))
            ->with('provider.user')
            ->get();

        foreach ($latest as $subscription) {
            /** @var Carbon $end */
            $end = $subscription->ends_at;
            $already = $subscription->reminders_sent ?? [];
            $left = (int) ceil(now()->diffInHours($end, false) / 24);

            $due = $end->isPast() ? 0 : collect($days)->filter(fn (int $d) => $left <= $d)->min();
            if ($due === null || in_array($due, $already, true)) {
                continue;
            }

            $subscription->provider->user->notify(new VipNotification($due === 0 ? VipNotification::ENDED : VipNotification::ENDING, $end, max(0, $left)));
            // Marca também os avisos maiores (quem renovou tarde não recebe o de 30 dias depois do de 7)
            $subscription->forceFill(['reminders_sent' => array_values(array_unique([...$already, ...array_filter($days, fn (int $d) => $d >= $due), $due]))])->save();
            $sent++;
        }

        $this->info("Avisos enviados: {$sent}");

        return self::SUCCESS;
    }
}
