<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Reports\ReportExporter;
use App\Modules\Users\Models\TrainerProfile;
use App\Modules\Users\Models\User;
use App\Modules\Wallet\Services\EarningsReport;
use App\Support\Api\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Relatório de ganhos do formador.
 *
 * @tags Carteira
 */
class EarningsController extends Controller
{
    /**
     * Ganhos por mês num ano (aulas, creditado, comissões, recebido em mão).
     */
    public function show(Request $request, EarningsReport $report): JsonResponse
    {
        return response()->json(['data' => $report->build($this->trainer($request), $this->year($request))]);
    }

    /**
     * Exportar o relatório de ganhos (`format`: pdf ou csv).
     */
    public function export(Request $request, EarningsReport $report, ReportExporter $exporter): StreamedResponse
    {
        $format = $request->validate(['format' => ['required', 'in:pdf,csv']])['format'];
        $trainer = $this->trainer($request);
        $year = $this->year($request);
        $sections = EarningsReport::sections($report->build($trainer, $year));
        $title = 'Ganhos de '.($trainer->user->name ?? 'formador');
        $filename = "longa-ganhos-{$year}";

        return $format === 'pdf'
            ? $exporter->pdf($filename, $title, "Ano {$year}", $sections)
            : $exporter->csv($filename, $title, "Ano {$year}", $sections);
    }

    private function year(Request $request): int
    {
        $year = (int) $request->integer('year', (int) now()->year);

        return max(2024, min((int) now()->year, $year));
    }

    private function trainer(Request $request): TrainerProfile
    {
        /** @var User $user */
        $user = $request->user();

        return $user->trainerProfile ?? throw new ApiException('forbidden', 'Só formadores têm relatório de ganhos.', 403);
    }
}
