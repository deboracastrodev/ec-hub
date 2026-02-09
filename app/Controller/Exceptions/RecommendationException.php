<?php
declare(strict_types=1);

namespace App\Controller\Exceptions;

/**
 * Exception thrown when recommendation generation fails
 */
class RecommendationException extends \RuntimeException
{
    private int $httpCode;

    public function __construct(string $message, int $httpCode = 500, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->httpCode = $httpCode;
    }

    public function getHttpCode(): int
    {
        return $this->httpCode;
    }
}
