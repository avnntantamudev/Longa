<?php

declare(strict_types=1);

namespace App\Support\Settings;

use App\Modules\Users\Models\User;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Valores de negócio que o admin muda no backoffice (Definições), sem deploy.
 * Sem valor gravado, vale o de config/longa.php. Cada alteração é auditada.
 * Os valores são lidos no momento de cada operação e ficam gravados nela (ex.: a comissão de um serviço
 * não muda se a definição mudar depois).
 */
class PlatformSettings
{
    private const CACHE_KEY = 'platform_settings';

    /**
     * Definições editáveis: chave => [config por omissão, tipo, mínimo, máximo, rótulo].
     *
     * @var array<string, array{config: string, type: 'int', min: int, max: int, label: string}>
     */
    public const DEFINITIONS = [
        'commission_percent' => ['config' => 'longa.requests.commission_percent', 'type' => 'int', 'min' => 0, 'max' => 50, 'label' => 'Comissão do BISCA (%)'],
        'vip_commission_percent' => ['config' => 'longa.vip.commission_percent', 'type' => 'int', 'min' => 0, 'max' => 50, 'label' => 'Comissão para técnicos VIP (%)'],
        'vip_price' => ['config' => 'longa.vip.price', 'type' => 'int', 'min' => 0, 'max' => 10_000_000, 'label' => 'Preço VIP por ano (Kz)'],
        'vip_duration_days' => ['config' => 'longa.vip.duration_days', 'type' => 'int', 'min' => 30, 'max' => 730, 'label' => 'Duração da subscrição VIP (dias)'],
        'dispatch_initial_radius_m' => ['config' => 'longa.matching.initial_radius_m', 'type' => 'int', 'min' => 500, 'max' => 50_000, 'label' => 'Despacho: raio inicial (metros)'],
        'dispatch_radius_step_m' => ['config' => 'longa.matching.radius_step_m', 'type' => 'int', 'min' => 500, 'max' => 50_000, 'label' => 'Despacho: alargamento por ronda (metros)'],
        'dispatch_max_radius_m' => ['config' => 'longa.matching.max_radius_m', 'type' => 'int', 'min' => 500, 'max' => 100_000, 'label' => 'Despacho: raio máximo (metros)'],
        'dispatch_search_minutes' => ['config' => 'longa.matching.search_minutes', 'type' => 'int', 'min' => 5, 'max' => 240, 'label' => 'Despacho: tempo para um técnico aceitar (minutos)'],
        'dispatch_min_balance' => ['config' => 'longa.matching.min_balance', 'type' => 'int', 'min' => 0, 'max' => 10_000_000, 'label' => 'Saldo mínimo do técnico para receber pedidos (Kz; 0 = desligado)'],
        'scheduled_request_close_hours' => ['config' => 'longa.scheduled_requests.close_hours_before', 'type' => 'int', 'min' => 1, 'max' => 48, 'label' => 'Pedidos para outro dia: fecham X horas antes do serviço'],
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    public function int(string $key): int
    {
        $this->definition($key);

        return (int) ($this->all()[$key] ?? config(self::DEFINITIONS[$key]['config']));
    }

    /**
     * @return array<string, int>
     */
    public function values(): array
    {
        $result = [];
        foreach (array_keys(self::DEFINITIONS) as $key) {
            $result[$key] = $this->int($key);
        }

        return $result;
    }

    /**
     * @param  array<string, int>  $values
     */
    public function update(array $values, User $admin): void
    {
        $before = $this->values();
        $changes = [];

        DB::transaction(function () use ($values, $admin, $before, &$changes) {
            foreach ($values as $key => $value) {
                $definition = $this->definition($key);
                $value = (int) $value;
                if ($value < $definition['min'] || $value > $definition['max']) {
                    throw new InvalidArgumentException("{$definition['label']}: tem de estar entre {$definition['min']} e {$definition['max']}.");
                }
                if ($before[$key] === $value) {
                    continue;
                }
                DB::table('platform_settings')->updateOrInsert(['key' => $key], ['value' => json_encode($value), 'updated_by' => $admin->id, 'updated_at' => now(), 'created_at' => now()]);
                $changes[$key] = ['from' => $before[$key], 'to' => $value];
            }
        });

        Cache::forget(self::CACHE_KEY);
        if ($changes !== []) {
            $this->audit->record('settings.updated', null, $changes, $admin->id);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function all(): array
    {
        /** @var array<string, mixed> */
        return Cache::remember(self::CACHE_KEY, 300, fn () => DB::table('platform_settings')->pluck('value', 'key')
            ->map(fn ($v) => json_decode((string) $v, true))
            ->all());
    }

    /**
     * @return array{config: string, type: 'int', min: int, max: int, label: string}
     */
    private function definition(string $key): array
    {
        return self::DEFINITIONS[$key] ?? throw new InvalidArgumentException("Definição desconhecida: {$key}");
    }
}
