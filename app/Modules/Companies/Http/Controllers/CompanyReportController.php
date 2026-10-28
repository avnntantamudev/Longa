<?php

declare(strict_types=1);

namespace App\Modules\Companies\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Reports\ReportExporter;
use App\Modules\Companies\Actions\EmployeeConsent;
use App\Modules\Companies\Models\Company;
use App\Modules\Companies\Models\CompanyEmployee;
use App\Modules\Companies\Reports\CompanyServicesReport;
use App\Modules\Users\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Relatório RH e autorizações dos colaboradores.
 *
 * @tags Empresas
 */
class CompanyReportController extends Controller
{
    /**
     * Serviços dos colaboradores que autorizaram a empresa (por defeito, mês atual).
     */
    public function show(Request $request, Company $company, CompanyServicesReport $report): JsonResponse
    {
        Gate::authorize('view', $company);
        [$from, $to] = $this->period($request);

        return response()->json(['data' => $report->build($company, $from, $to)]);
    }

    /**
     * Exportar o relatório RH (`format`: pdf ou csv).
     */
    public function export(Request $request, Company $company, CompanyServicesReport $report, ReportExporter $exporter): StreamedResponse
    {
        Gate::authorize('view', $company);
        $format = $request->validate(['format' => ['required', 'in:pdf,csv']])['format'];
        [$from, $to] = $this->period($request);
        $sections = CompanyServicesReport::sections($report->build($company, $from, $to));
        $period = 'De '.$from->format('d/m/Y').' a '.$to->format('d/m/Y');
        $filename = 'longa-formacoes-'.$from->toDateString().'-'.$to->toDateString();
        $title = "Serviços — {$company->name}";

        return $format === 'pdf' ? $exporter->pdf($filename, $title, $period, $sections) : $exporter->csv($filename, $title, $period, $sections);
    }

    /**
     * Pedidos de empresas para acompanhar os meus serviços.
     */
    public function myLinks(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $links = CompanyEmployee::query()->where('phone', $user->phone)->where('status', 'active')->with('company')->get();

        return response()->json(['data' => $links->map(fn (CompanyEmployee $e) => [
            'id' => $e->id,
            'company' => $e->company->name,
            'department' => $e->department,
            'consent_status' => $e->consent_status,
            'consent_at' => $e->consent_at?->toIso8601String(),
        ])]);
    }

    /**
     * Aceitar, recusar ou retirar a autorização (`decision`: accepted, declined, revoked).
     */
    public function decide(Request $request, CompanyEmployee $employee, EmployeeConsent $consent): JsonResponse
    {
        $decision = $request->validate(['decision' => ['required', Rule::in([EmployeeConsent::ACCEPTED, EmployeeConsent::DECLINED, EmployeeConsent::REVOKED])]])['decision'];
        /** @var User $user */
        $user = $request->user();
        $employee = $consent->decide($employee, $user, $decision);

        return response()->json(['data' => ['id' => $employee->id, 'consent_status' => $employee->consent_status]]);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function period(Request $request): array
    {
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);

        return [
            isset($data['from']) ? Carbon::parse($data['from']) : now()->startOfMonth(),
            isset($data['to']) ? Carbon::parse($data['to']) : now(),
        ];
    }
}
