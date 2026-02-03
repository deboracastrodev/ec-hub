<?php

declare(strict_types=1);

namespace App\Shared\Router;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Simple Router
 *
 * Basic router implementation for Clean Architecture
 */
class SimpleRouter
{
    /**
     * Dispatch request to appropriate controller
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    public function dispatch(ServerRequestInterface $request): ResponseInterface
    {
        // For now, return a simple response
        // In a full implementation, this would:
        // 1. Parse the request URI and method
        // 2. Match against defined routes
        // 3. Instantiate the appropriate controller
        // 4. Call the action with parameters
        // 5. Return the response

        return new class implements ResponseInterface {
            private $statusCode = 200;
            private $headers = [];
            private $body = '{"message":"ec-hub API - Clean Architecture + DDD"}';

            public function getStatusCode()
            {
                return $this->statusCode;
            }

            public function withStatus($code, $reasonPhrase = '')
            {
                $this->statusCode = $code;
                return $this;
            }

            public function getReasonPhrase()
            {
                return 'OK';
            }

            public function getProtocolVersion()
            {
                return '1.1';
            }

            public function withProtocolVersion($version)
            {
                return $this;
            }

            public function getHeaders()
            {
                return $this->headers;
            }

            public function hasHeader($name)
            {
                return isset($this->headers[$name]);
            }

            public function getHeader($name)
            {
                return $this->headers[$name] ?? [];
            }

            public function getHeaderLine($name)
            {
                return implode(',', $this->getHeader($name));
            }

            public function withHeader($name, $value)
            {
                $this->headers[$name] = (array) $value;
                return $this;
            }

            public function withAddedHeader($name, $value)
            {
                $this->headers[$name][] = $value;
                return $this;
            }

            public function withoutHeader($name)
            {
                unset($this->headers[$name]);
                return $this;
            }

            public function getBody()
            {
                return new \GuzzleHttp\Psr7\Stream(
                    fopen('data://text/plain,' . $this->body, 'r')
                );
            }

            public function withBody(\Psr\Http\Message\StreamInterface $body)
            {
                return $this;
            }
        };
    }
}
