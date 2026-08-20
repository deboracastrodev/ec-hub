<?php

declare(strict_types=1);

namespace App\Shared\Http;

use App\Controller\Exceptions\InvalidRequestException;
use App\Domain\Recommendation\Exception\RecommendationException;
use Twig\Environment;
use Twig\Error\Error as TwigError;

/**
 * Maps an exception to an HTTP response, extracted out of public/index.php
 * (R5.6). The single place that turns a domain failure into a status code
 * (R3.2) -- RecommendationException itself carries no HTTP awareness.
 */
final class ErrorHandler
{
    public function __construct(private readonly Environment $twig)
    {
    }

    public function handleInvalidRequest(InvalidRequestException $e, bool $isApiRoute): void
    {
        http_response_code($e->getHttpCode());

        if ($isApiRoute) {
            $this->sendJson(['error' => $e->getMessage(), 'code' => $e->getHttpCode()]);

            return;
        }

        $this->renderHtmlError('error/400.html.twig', $e->getMessage(), '400 Bad Request');
    }

    public function handleRecommendationFailure(RecommendationException $e, bool $isApiRoute): void
    {
        http_response_code(500);

        if ($isApiRoute) {
            $this->sendJson(['error' => 'Failed to generate recommendations', 'code' => 500]);

            return;
        }

        $this->renderHtmlError('error/500.html.twig', 'Erro interno', '500 Internal Server Error');
    }

    public function handleUnexpected(\Throwable $e, bool $isApiRoute): void
    {
        http_response_code(500);

        if ($isApiRoute) {
            $this->sendJson(['error' => 'Internal server error', 'code' => 500]);

            return;
        }

        $this->renderHtmlError('error/500.html.twig', 'Erro interno', '500 Internal Server Error');
    }

    /**
     * @param array<string, mixed> $data
     */
    private function sendJson(array $data): void
    {
        header('Content-Type: application/json');
        echo json_encode($data, JSON_PRETTY_PRINT);
    }

    private function renderHtmlError(string $template, string $message, string $title): void
    {
        try {
            echo $this->twig->render($template, ['message' => $message]);
        } catch (TwigError $twigError) {
            error_log('Twig error template rendering failed: ' . $twigError->getMessage());
            echo $this->fallbackHtml($title, $message);
        }
    }

    private function fallbackHtml(string $title, string $message): string
    {
        return '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
            . '<title>' . htmlspecialchars($title) . '</title></head><body>'
            . '<h1>' . htmlspecialchars($title) . '</h1>'
            . '<p>' . htmlspecialchars($message) . '</p>'
            . '<a href="/products">&larr; Ver todos os produtos</a></body></html>';
    }
}
