<?php

declare(strict_types=1);

namespace Tests\Support;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;

/** Twig like config/twig.php (strict variables, BRL filter), without the cache. */
final class AdminTestTwig
{
    public static function create(): Environment
    {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 2) . '/views'), [
            'strict_variables' => true,
            'cache' => false,
        ]);
        $twig->addFilter(new TwigFilter('BRL', static fn ($price): string =>
            'R$ ' . number_format((float) $price, 2, ',', '.'), ['is_safe' => ['html']]));

        return $twig;
    }
}
