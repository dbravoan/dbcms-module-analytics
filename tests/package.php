<?php

declare(strict_types=1);

return [
    'laravel' => [
        'version' => '13.11.0',
        'reference' => '06d016a364a37430eec9cbc52209adce3d7667ce',
    ],
    'kind' => 'module',
    'core' => '0.0.1',
    'fixtureVersion' => '0.0.1',
    'theme' => 'default-clean',
    'adminTheme' => 'dbcms',
    'require' => [],
    'sourceDirs' => [
        '.',
    ],
    'testPaths' => [
        'Unit',
    ],
    'modules' => [
        'analytics',
    ],
    'blockPackages' => [],
    'privacyProcessing' => [],
    'preserveRows' => [],
    'profiles' => [
        'integration' => [
            'require' => [
                'dbravoan/dbcms-theme-default-clean' => '0.0.1',
                'dbravoan/dbcms-theme-db-cyber' => '0.0.1',
                'dbravoan/dbcms-theme-db-editorial' => '0.0.1',
                'dbravoan/dbcms-theme-db-minimal' => '0.0.1',
                'dbravoan/dbcms-theme-db-sunset' => '0.0.1',
                'dbravoan/dbcms-theme-db-brutalist' => '0.0.1',
                'dbravoan/dbcms-theme-db-enterprise' => '0.0.1',
                'dbravoan/dbcms-admin-theme-termocms' => '0.0.1',
            ],
            'testPaths' => [
                'Feature',
            ],
        ],
    ],
];
