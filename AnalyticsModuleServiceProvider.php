<?php

declare(strict_types=1);

namespace DbCms\Modules\Analytics;

use DbCms\Core\Modules\BaseModuleServiceProvider;
use DbCms\Core\Modules\ModuleSettingsExtensionRegistry;
use DbCms\Modules\Analytics\Contracts\CampaignAttributionGatewayInterface;
use DbCms\Modules\Analytics\Contracts\Ga4SettingsNormalizerInterface;
use DbCms\Modules\Analytics\Services\AnalyticsCookieDisclosure;
use DbCms\Modules\Analytics\Services\AnalyticsManagerService;
use DbCms\Modules\Analytics\Services\Ga4CspContributor;
use DbCms\Modules\Analytics\Services\Ga4ReportGateway;
use DbCms\Modules\Analytics\Services\Ga4SettingsNormalizer;
use Illuminate\View\View;

class AnalyticsModuleServiceProvider extends BaseModuleServiceProvider
{
    protected string $identifier = 'analytics';

    protected string $name = 'Analítica GA4';

    protected bool $isPageable = false;

    public function register(): void
    {
        $this->app->singleton(AnalyticsCookieDisclosure::class);
        $this->app->tag(AnalyticsCookieDisclosure::class, 'cms.cookie-disclosures');
        $this->app->singleton(AnalyticsManagerService::class);
        $this->app->bind(Ga4SettingsNormalizerInterface::class, Ga4SettingsNormalizer::class);
        $this->app->bind(CampaignAttributionGatewayInterface::class, Ga4ReportGateway::class);
        $this->app->singleton(Ga4CspContributor::class);
        $this->app->tag(Ga4CspContributor::class, 'cms.csp.connect');
    }

    public function boot(): void
    {
        \Illuminate\Support\Facades\View::composer('themes::components.analytics', function (View $view): void {
            $view->with('analyticsScript', $this->app->make(AnalyticsManagerService::class)->renderGa4Script());
        });
        $this->loadViewsFrom(__DIR__.'/Resources/views', 'analytics');
        $this->app->make(ModuleSettingsExtensionRegistry::class)->registerNormalizer('analytics',
            fn (array $settings): array => $this->app->make(Ga4SettingsNormalizerInterface::class)->normalize($settings),
        );
    }
}
