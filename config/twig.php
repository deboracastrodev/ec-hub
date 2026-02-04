<?php

declare(strict_types=1);

/**
 * Twig Configuration
 *
 * Configures Twig standalone environment for template rendering.
 */

require_once __DIR__ . '/../vendor/autoload.php';

$loader = new \Twig\Loader\FilesystemLoader(__DIR__ . '/../views');

$twig = new \Twig\Environment($loader, [
    'cache' => getenv('APP_DEBUG') === 'true' ? false : __DIR__ . '/../var/cache/twig',
    'debug' => getenv('APP_DEBUG') === 'true',
    'auto_reload' => getenv('APP_DEBUG') === 'true',
    'strict_variables' => true,
]);

// Adicionar extensões úteis
if (getenv('APP_DEBUG') === 'true') {
    $twig->addExtension(new \Twig\Extension\DebugExtension());
}

// Custom filters para formatação brasileira
$twig->addFilter(new \Twig\TwigFilter('BRL', function ($price) {
    return 'R$ ' . number_format($price, 2, ',', '.');
}, ['is_safe' => ['html']]));

return $twig;
