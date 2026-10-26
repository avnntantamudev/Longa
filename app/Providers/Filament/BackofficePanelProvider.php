<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Modules\Auth\Http\Middleware\EnsurePinUnlocked;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Backoffice em /backoffice (só administradores). Sem página de login própria: o admin entra pela PWA
 * com o código (OTP) e a sessão web é partilhada; sem sessão vai para /entrar.
 */
class BackofficePanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('backoffice')
            ->path('backoffice')
            ->brandName('LONGA Backoffice')
            ->authGuard('web')
            ->colors(['primary' => Color::hex('#1f416f')])
            ->discoverResources(in: app_path('Modules/Admin/Filament/Resources'), for: 'App\Modules\Admin\Filament\Resources')
            ->discoverPages(in: app_path('Modules/Admin/Filament/Pages'), for: 'App\Modules\Admin\Filament\Pages')
            ->discoverWidgets(in: app_path('Modules/Admin/Filament/Widgets'), for: 'App\Modules\Admin\Filament\Widgets')
            ->pages([Dashboard::class])
            ->navigationGroups(['Operações', 'Finanças'])
            ->databaseNotifications()
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            // O admin confirma o PIN ao abrir o backoffice e de novo após 1 h sem uso ou 8 h
            ->authMiddleware([Authenticate::class, EnsurePinUnlocked::class]);
    }
}
