<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Pages;

use App\Modules\Admin\Reports\AdminReportSections;
use App\Modules\Admin\Reports\FinancialReport;
use App\Modules\Admin\Reports\OperationsReport;
use App\Modules\Admin\Reports\ReportExporter;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * Relatórios do admin: financeiro (do ledger) e operação, por período, com exportação CSV e PDF.
 */
class Reports extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Finanças';

    protected static ?string $title = 'Relatórios';

    protected static ?string $slug = 'relatorios';

    protected static ?int $navigationSort = 0;

    protected string $view = 'filament.reports';

    #[Url]
    public string $tab = 'financial';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    public function mount(): void
    {
        $this->from = $this->from ?: now()->startOfMonth()->toDateString();
        $this->to = $this->to ?: now()->toDateString();
    }

    /**
     * @return list<array{title: string, headers: list<string>, rows: list<list<string|int|float|null>>}>
     */
    public function sections(): array
    {
        [$from, $to] = $this->period();

        return $this->tab === 'operations'
            ? AdminReportSections::operations(app(OperationsReport::class)->build($from, $to))
            : AdminReportSections::financial(app(FinancialReport::class)->build($from, $to));
    }

    public function periodLabel(): string
    {
        [$from, $to] = $this->period();

        return 'De '.$from->format('d/m/Y').' a '.$to->format('d/m/Y');
    }

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                Action::make('csv')->label('Excel (CSV)')->action(fn () => $this->export('csv')),
                Action::make('pdf')->label('PDF')->action(fn () => $this->export('pdf')),
            ])->label('Exportar')->icon(Heroicon::OutlinedArrowDownTray)->button(),
        ];
    }

    public function export(string $format): StreamedResponse
    {
        $title = $this->tab === 'operations' ? 'Relatório de operação' : 'Relatório financeiro';
        $filename = 'longa-'.($this->tab === 'operations' ? 'operacao' : 'financeiro').'-'.$this->from.'-'.$this->to;
        $exporter = app(ReportExporter::class);

        return $format === 'pdf'
            ? $exporter->pdf($filename, $title, $this->periodLabel(), $this->sections())
            : $exporter->csv($filename, $title, $this->periodLabel(), $this->sections());
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function period(): array
    {
        $from = rescue(fn () => Carbon::parse($this->from), now()->startOfMonth(), false);
        $to = rescue(fn () => Carbon::parse($this->to), now(), false);

        return $from->gt($to) ? [$to, $from] : [$from, $to];
    }
}
