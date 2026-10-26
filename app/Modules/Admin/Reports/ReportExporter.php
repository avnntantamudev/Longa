<?php

declare(strict_types=1);

namespace App\Modules\Admin\Reports;

use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exporta um relatório em CSV (abre no Excel: separador ";" e BOM UTF-8) ou PDF.
 *
 * Formato comum: secções com título e tabela (cabeçalho + linhas), para os relatórios do admin,
 * do formador e da empresa usarem a mesma exportação.
 */
class ReportExporter
{
    /**
     * @param  list<array{title: string, headers: list<string>, rows: list<list<string|int|float|null>>}>  $sections
     */
    public function csv(string $filename, string $title, string $period, array $sections): StreamedResponse
    {
        return response()->streamDownload(function () use ($title, $period, $sections) {
            $out = fopen('php://output', 'wb');
            if ($out === false) {
                return;
            }
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [$title], ';');
            fputcsv($out, [$period], ';');
            foreach ($sections as $section) {
                fputcsv($out, [], ';');
                fputcsv($out, [$section['title']], ';');
                fputcsv($out, $section['headers'], ';');
                foreach ($section['rows'] as $row) {
                    fputcsv($out, array_map(fn ($v) => $this->cell($v), $row), ';');
                }
            }
            fclose($out);
        }, $filename.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @param  list<array{title: string, headers: list<string>, rows: list<list<string|int|float|null>>}>  $sections
     */
    public function pdf(string $filename, string $title, string $period, array $sections): StreamedResponse
    {
        $pdf = Pdf::loadView('reports.pdf', compact('title', 'period', 'sections'))->setPaper('a4');

        return response()->streamDownload(fn () => print ($pdf->output()), $filename.'.pdf', ['Content-Type' => 'application/pdf']);
    }

    private function cell(string|int|float|null $value): string
    {
        $text = (string) $value;

        // Evita fórmulas injetadas ao abrir no Excel (=, +, -, @ no início de texto)
        return $text !== '' && ! is_numeric($text) && in_array($text[0], ['=', '+', '-', '@'], true) ? "'".$text : $text;
    }
}
