<?php

declare(strict_types=1);

/**
 * Hyperf Autoload Configuration
 *
 * This file configures PSR-4 autoloading and annotation scanning for the ec-hub application.
 */

return [
    'scan' => [
        'paths' => [
            BASE_PATH . '/app',
        ],
        'ignore_annotations' => [
            'mixin',
        ],
    ],
    'dependencies' => [
        // Dependency injection container configuration
        // Autoload dependencies
    ],
    'annotations' => [
        'scan' => [
            'paths' => [
                BASE_PATH . '/app',
            ],
            'collectors' => [
                // Hyperf annotation collectors
            ],
        ],
    ],
];
