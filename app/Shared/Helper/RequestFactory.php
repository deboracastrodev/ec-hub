<?php

declare(strict_types=1);

namespace App\Shared\Helper;

use Swoole\Http\Request as SwooleRequest;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Request Factory
 *
 * Creates PSR-7 requests from Swoole requests
 */
class RequestFactory
{
    /**
     * Create PSR-7 request from Swoole request
     *
     * @param SwooleRequest $swooleRequest
     * @return ServerRequestInterface
     */
    public static function createFromSwoole(SwooleRequest $swooleRequest): ServerRequestInterface
    {
        // For now, return a simple implementation
        // In a full implementation, this would use a PSR-7 factory
        // to properly convert Swoole request to PSR-7 format

        return new class($swooleRequest) implements ServerRequestInterface {
            private $swooleRequest;

            public function __construct(SwooleRequest $swooleRequest)
            {
                $this->swooleRequest = $swooleRequest;
            }

            public function getServerParams()
            {
                return $this->swooleRequest->server ?? [];
            }

            public function getCookieParams()
            {
                return $this->swooleRequest->cookie ?? [];
            }

            public function withCookieParams(array $cookies)
            {
                // Implementation needed
                return $this;
            }

            public function getQueryParams()
            {
                return $this->swooleRequest->get ?? [];
            }

            public function withQueryParams(array $query)
            {
                // Implementation needed
                return $this;
            }

            public function getUploadedFiles()
            {
                return [];
            }

            public function withUploadedFiles(array $uploadedFiles)
            {
                return $this;
            }

            public function getServerParam($key, $default = null)
            {
                return $this->swooleRequest->server[$key] ?? $default;
            }

            public function getParsedBody()
            {
                return $this->swooleRequest->post ?? null;
            }

            public function withParsedBody($data)
            {
                return $this;
            }

            public function getAttributes()
            {
                return [];
            }

            public function getAttribute($name, $default = null)
            {
                return $default;
            }

            public function withAttribute($name, $value)
            {
                return $this;
            }

            public function withoutAttribute($name)
            {
                return $this;
            }

            public function getRequestTarget()
            {
                return $this->swooleRequest->server['request_uri'] ?? '/';
            }

            public function withRequestTarget($requestTarget)
            {
                return $this;
            }

            public function getMethod()
            {
                return $this->swooleRequest->server['request_method'] ?? 'GET';
            }

            public function withMethod($method)
            {
                return $this;
            }

            public function getUri()
            {
                // Simple URI implementation
                return new \GuzzleHttp\Psr7\Uri(
                    $this->swooleRequest->server['request_uri'] ?? '/'
                );
            }

            public function withUri(\Psr\Http\Message\UriInterface $uri, $preserveHost = false)
            {
                return $this;
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
                return $this->swooleRequest->header ?? [];
            }

            public function hasHeader($name)
            {
                return isset($this->swooleRequest->header[$name]);
            }

            public function getHeader($name)
            {
                return $this->swooleRequest->header[$name] ?? [];
            }

            public function getHeaderLine($name)
            {
                return implode(',', $this->getHeader($name));
            }

            public function withHeader($name, $value)
            {
                return $this;
            }

            public function withAddedHeader($name, $value)
            {
                return $this;
            }

            public function withoutHeader($name)
            {
                return $this;
            }

            public function getBody()
            {
                return new \GuzzleHttp\Psr7\Stream(
                    fopen('php://temp', 'r+')
                );
            }

            public function withBody(\Psr\Http\Message\StreamInterface $body)
            {
                return $this;
            }
        };
    }
}
