<?php
declare(strict_types=1);

namespace App\Controller\Exceptions;

/**
 * Exception thrown when request validation fails
 */
class InvalidRequestException extends \RuntimeException
{
    private int $httpCode;

    public function __construct(string $message, int $httpCode = 400)
    {
        parent::__construct($message);
        $this->httpCode = $httpCode;
    }

    public function getHttpCode(): int
    {
        return $this->httpCode;
    }
}
