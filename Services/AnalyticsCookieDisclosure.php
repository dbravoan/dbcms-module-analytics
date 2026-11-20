<?php

declare(strict_types=1);

namespace DbCms\Modules\Analytics\Services;

use DbCms\Core\Modules\ModuleManager;
use DbCms\Core\Security\Contracts\CookieDisclosureContributorInterface;
use DbCms\Core\Services\Privacy\LegalPageService;

final class AnalyticsCookieDisclosure implements CookieDisclosureContributorInterface
{
    public function __construct(private readonly ModuleManager $modules) {}

    public function revisionData(): array
    {
        return $this->categories('en') === [] ? [] : ['measurement_id' => (string) app(AnalyticsManagerService::class)->measurementId()];
    }

    public function categories(string $locale): array
    {
        if (! app(LegalPageService::class)->canCollect('analytics', true) || ! $this->modules->isModuleEnabled('analytics') || app(AnalyticsManagerService::class)->measurementId() === null) {
            return [];
        }

        return ['analytics' => [
            'key' => 'analytics',
            'title' => $locale === 'ca' ? 'Cookies d\'Anàlisi i Rendiment' : ($locale === 'en' ? 'Analytics & Performance Cookies' : 'Cookies de Análisis y Rendimiento'),
            'description' => $locale === 'ca'
                ? 'Recullen informació pseudònima i agregada sobre la interacció dels usuaris per mesurar i optimitzar el rendiment de la plataforma.'
                : ($locale === 'en'
                    ? 'Collects pseudonymous and aggregated usage data to measure and improve platform performance.'
                    : 'Recopilan información seudónima y agregada sobre la interacción de los usuarios para medir y optimizar el rendimiento de la plataforma.'),
            'required' => false,
            'default' => false,
            'cookies' => [
                [
                    'name' => '_ga',
                    'provider' => 'Google Analytics',
                    'purpose' => $locale === 'ca' ? 'Identificador únic per distingir usuaris únics mitjançant un identificador pseudònim.' : ($locale === 'en' ? 'Unique identifier used to distinguish visitors using a pseudonymous identifier.' : 'Identificador único para distinguir usuarios únicos mediante un identificador seudónimo.'),
                    'duration' => $locale === 'ca' ? '2 anys' : ($locale === 'en' ? '2 years' : '2 años'),
                ],
                [
                    'name' => '_ga_*',
                    'provider' => 'Google Analytics',
                    'purpose' => $locale === 'ca' ? 'Estat i comptador de sessions de navegació.' : ($locale === 'en' ? 'Maintains session state across page requests.' : 'Estado y contador de sesiones de navegación.'),
                    'duration' => $locale === 'ca' ? '2 anys' : ($locale === 'en' ? '2 years' : '2 años'),
                ],
            ],
        ]];
    }
}
