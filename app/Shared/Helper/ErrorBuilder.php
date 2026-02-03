<?php

declare(strict_types=1);

namespace App\Shared\Helper;

use Throwable;

/**
 * Error Builder Helper
 *
 * Builds RFC 7807 Problem Details for HTTP APIs
 * @see https://datatracker.ietf.org/doc/html/rfc7807
 */
class ErrorBuilder
{
    /**
     * Build error response from exception
     *
     * @param Throwable $e Exception
     * @return array RFC 7807 error response
     */
    public static function fromException(Throwable $e): array
    {
        $status = self::getStatusCode($e);
        $type = self::getType($e, $status);
        $title = self::getTitle($e, $status);

        return [
            'type' => $type,
            'title' => $title,
            'detail' => $e->getMessage(),
            'status' => $status,
            'instance' => self::getInstance(),
        ];
    }

    /**
     * Build custom error response
     *
     * @param int $status HTTP status code
     * @param string $title Error title
     * @param string $detail Error detail
     * @param string|null $type Error type URI
     * @return array RFC 7807 error response
     */
    public static function error(int $status, string $title, string $detail, ?string $type = null): array
    {
        return [
            'type' => $type ?? "/errors/{$status}",
            'title' => $title,
            'detail' => $detail,
            'status' => $status,
            'instance' => self::getInstance(),
        ];
    }

    /**
     * Build validation error response
     *
     * @param array $errors Validation errors array
     * @return array RFC 7807 error response
     */
    public static function validationError(array $errors): array
    {
        return [
            'type' => '/errors/validation-error',
            'title' => 'Validation Error',
            'detail' => 'The request contains invalid data',
            'status' => 400,
            'instance' => self::getInstance(),
            'errors' => $errors,
        ];
    }

    /**
     * Get HTTP status code from exception
     */
    private static function getStatusCode(Throwable $e): int
    {
        // Map exception types to HTTP status codes
        $exceptionMap = [
            \InvalidArgumentException::class => 400,
            \OutOfBoundsException::class => 404,
            \RuntimeException::class => 500,
        ];

        foreach ($exceptionMap as $exceptionClass => $status) {
            if ($e instanceof $exceptionClass) {
                return $status;
            }
        }

        return 500;
    }

    /**
     * Get error type URI
     */
    private static function getType(Throwable $e, int $status): string
    {
        return "/errors/{$status}";
    }

    /**
     * Get error title
     */
    private static function getTitle(Throwable $e, int $status): string
    {
        $titles = [
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            409 => 'Conflict',
            422 => 'Unprocessable Entity',
            500 => 'Internal Server Error',
            503 => 'Service Unavailable',
        ];

        return $titles[$status] ?? 'Error';
    }

    /**
     * Get request instance identifier
     */
    private static function getInstance(): string
    {
        return '/requests/' . uniqid('', true);
    }
}
