<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Pages;

use App\Modules\Admin\Services\PlatformAlerts;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Alertas para a equipa: o que está por rever, padrões de desvio da plataforma e técnicos com saldo negativo.
 */
class Alerts extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static string|UnitEnum|null $navigationGroup = 'Operações';

    protected static ?string $title = 'Alertas';

    protected static ?string $slug = 'alertas';

    protected static ?int $navigationSort = 0;

    protected string $view = 'filament.alerts';

    /**
     * @return array<string, int>
     */
    public function counts(): array
    {
        return app(PlatformAlerts::class)->counts();
    }

    /**
     * @return list<array{title: string, headers: list<string>, rows: list<list<string|int>>, empty: string}>
     */
    public function sections(): array
    {
        $alerts = app(PlatformAlerts::class);
        $config = config('longa.alerts');

        return [
            [
                'title' => "Cancelamentos logo após aceitar ({$config['cancel_count']} ou mais em {$config['cancel_days']} dias, até {$config['cancel_window_hours']} h depois)",
                'headers' => ['Técnico', 'Telefone', 'Cancelamentos'],
                'rows' => array_map(fn (array $r) => [$r['name'], $r['phone'], $r['cancellations']], $alerts->suspiciousCancellations()),
                'empty' => 'Sem padrões suspeitos.',
            ],
            [
                'title' => 'Técnicos com saldo negativo',
                'headers' => ['Técnico', 'Telefone', 'Saldo líquido (Kz)', 'Recebe pedidos'],
                'rows' => array_map(fn (array $r) => [$r['name'], $r['phone'], $r['net'], $r['blocked'] ? 'Não' : 'Sim'], $alerts->lowBalances()),
                'empty' => 'Nenhum técnico com saldo negativo.',
            ],
        ];
    }
}
