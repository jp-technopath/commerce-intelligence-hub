<?php

namespace App\Providers\Filament;

use App\Filament\Widgets\RecentFindingsWidget;
use App\Filament\Widgets\RecentSyncActivityWidget;
use App\Filament\Widgets\StatsOverviewWidget;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login(\App\Filament\Pages\Auth\GoogleLogin::class)
            ->colors([
                'primary' => Color::Blue,
            ])
            ->brandName('Technopath Forge')
            ->brandLogo(asset('images/technopath-brand-logo-sharp.png'))
            ->darkModeBrandLogo(asset('images/technopath-brand-logo-dark.png'))
            ->brandLogoHeight('2.5rem')
            ->sidebarCollapsibleOnDesktop()
            ->maxContentWidth('max-w-[85%]')
            ->renderHook(
                \Filament\View\PanelsRenderHook::SIDEBAR_NAV_START,
                fn (): string => \Illuminate\Support\Facades\Blade::render('@include("filament.components.sidebar-search")')
            )
            ->renderHook(
                \Filament\View\PanelsRenderHook::HEAD_END,
                fn (): string => \Illuminate\Support\Facades\Blade::render('@include("filament.components.sidebar-custom-styles")')
            )
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                \App\Filament\Pages\AgencyDashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                StatsOverviewWidget::class,
                RecentFindingsWidget::class,
                RecentSyncActivityWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            ->userMenuItems([
                \Filament\Navigation\UserMenuItem::make()
                    ->label('My Profile')
                    ->url(fn (): string => \App\Filament\Pages\MyProfile::getUrl())
                    ->icon('heroicon-o-user'),
            ])
            ->navigationGroups([
                \Filament\Navigation\NavigationGroup::make('Dashboard')
                    ->collapsible(),
                \Filament\Navigation\NavigationGroup::make('Clients')
                    ->collapsible(),
                \Filament\Navigation\NavigationGroup::make('Intelligence')
                    ->collapsible(),
                \Filament\Navigation\NavigationGroup::make('Delivery')
                    ->collapsible(),
                \Filament\Navigation\NavigationGroup::make('Deployments')
                    ->collapsible(),
                \Filament\Navigation\NavigationGroup::make('Financials')
                    ->collapsible(),
                \Filament\Navigation\NavigationGroup::make('Meetings')
                    ->collapsible(),
                \Filament\Navigation\NavigationGroup::make('Administration')
                    ->collapsible(),
            ]);
    }
}
