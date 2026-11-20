<?php

declare(strict_types=1);

namespace DbCms\Modules\Analytics\Services;

use DbCms\Core\Modules\ModuleManager;
use DbCms\Core\Security\Contracts\ContentSecurityPolicyContributorInterface;
use DbCms\Core\Services\Cms\CookieConsentService;

final class Ga4CspContributor implements ContentSecurityPolicyContributorInterface
{
    public function __construct(private readonly ModuleManager $modules, private readonly AnalyticsManagerService $analytics) {}

    /** @return list<string> */
    public function connectOrigins(): array
    {
        if (request()->attributes->getBoolean('cms.editorial_preview')
            || ! $this->modules->isModuleEnabled('analytics') || $this->analytics->measurementId() === null
            || ! app(CookieConsentService::class)->hasConsent('analytics')) {
            return [];
        }

        // Exact hosts for gtag and GA4 global/EU collection; no Ads features.
        return [
            'https://www.googletagmanager.com',
            'https://www.google-analytics.com',
            'https://google-analytics.com',
            'https://region1.google-analytics.com',
            'https://analytics.google.com',
            'https://region1.analytics.google.com',
        ];
    }

    public function frameOrigins(): array
    {
        return [];
    }
}
