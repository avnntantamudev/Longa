<?php

declare(strict_types=1);

use App\Modules\Admin\Filament\Pages\Reports;
use App\Modules\Admin\Reports\FinancialReport;
use App\Modules\Admin\Reports\OperationsReport;
use App\Modules\Admin\Reports\ReportExporter;
use App\Modules\Payments\Services\VipSubscriptions;
use App\Modules\Sessions\Actions\CancelSession;
use App\Modules\Sessions\Actions\ConfirmSession;
use App\Modules\Sessions\Actions\FinishSession;
use App\Modules\Sessions\Actions\StartSession;
use App\Modules\Users\Enums\Role;
use App\Modules\Users\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    Cache::flush();
    Notification::fake();
    Filament::setCurrentPanel('backoffice');
});

/** Um serviço confirmado por QR, uma cancelado cedo e um VIP pago em mão. */
function reportFixture(): void
{
    $done = paidSession();
    test()->travelTo($done->scheduled_at);
    app(StartSession::class)->handle($done, $done->provider->user);
    $token = app(FinishSession::class)->handle($done, $done->provider->user)['qr']['tokens'][0]['token'];
    app(ConfirmSession::class)->handle($done->fresh() ?? $done, $done->client, $token, null);
    test()->travelBack();

    $cancelled = paidSession();
    app(CancelSession::class)->handle($cancelled, $cancelled->client, null);

    app(VipSubscriptions::class)->grant($done->provider, User::factory()->withRole(Role::Admin)->create(), 'cash', 'Pagou em mão');
}

it('o relatório financeiro bate com o ledger', function () {
    reportFixture();

    $report = app(FinancialReport::class)->build(now()->subDays(3), now()->addDays(3));

    expect($report['totals'])->toMatchArray([
        'paid_in' => 50000,
        'commissions' => 3750,
        'vip' => 50000,
        'to_providers' => 21250,
        'refunds_decided' => 25000,
        'refunds_paid' => 25000,
        'escrow_at_end' => 0,
        'refunds_pending_at_end' => 0,
        'providers_available_at_end' => 21250,
    ]);
    expect(collect($report['months'])->sum('commissions'))->toBe(3750);
});

it('o relatório de operação conta serviços por estado e técnicos ativos', function () {
    reportFixture();

    $report = app(OperationsReport::class)->build(now()->subDays(3), now()->addDays(3));

    expect($report['sessions']['by_state'])->toMatchArray(['completed' => 1, 'cancelled_by_client' => 1])
        ->and($report['top_providers'][0]['lessons'])->toBe(1)
        ->and($report['top_providers'][0]['net'])->toBe(21250);
});

it('o admin vê os relatórios e exporta CSV (Excel) e PDF', function () {
    reportFixture();
    loginWeb(User::factory()->withRole(Role::Admin)->create());

    $this->get('/backoffice/relatorios?from='.now()->subDays(3)->toDateString().'&to='.now()->addDays(3)->toDateString())
        ->assertOk()->assertSee('Comissões do BISCA')->assertSee('3.750');
    $this->get('/backoffice/relatorios?tab=operations')->assertOk()->assertSee('Serviços por estado');

    $csv = Livewire::test(Reports::class)->call('export', 'csv');
    $csv->assertFileDownloaded();

    $sections = [['title' => 'Teste', 'headers' => ['Nome', 'Kz'], 'rows' => [['=HYPERLINK("x")', 1000]]]];
    $response = app(ReportExporter::class)->csv('t', 'Título', 'Período', $sections);
    ob_start();
    $response->sendContent();
    $content = (string) ob_get_clean();
    expect($content)->toStartWith("\xEF\xBB\xBF")->toContain("'=HYPERLINK")->toContain(';1000');

    $pdf = app(ReportExporter::class)->pdf('t', 'Título', 'Período', $sections);
    ob_start();
    $pdf->sendContent();
    expect((string) ob_get_clean())->toStartWith('%PDF-');
});

it('só administradores', function () {
    $this->actingAs(User::factory()->withRole(Role::Provider)->create())->get('/backoffice/relatorios')->assertForbidden();
});
