<?php

declare(strict_types=1);

namespace App\Shared\Helper;

/**
 * Response Formatter Helper
 *
 * Wraps API responses following JSON:API style format
 */
class ResponseFormatter
{
    /**
     * Format success response
     *
     * @param array $data Response data
     * @param int $status HTTP status code
     * @return array Formatted response
     */
    public static function success(array $data, int $status = 200): array
    {
        return [
            'data' => $data,
            'meta' => [
                'timestamp' => date('c'),
                'status' => $status,
            ],
        ];
    }

    /**
     * Format paginated response
     *
     * @param array $items Data items
     * @param int $total Total items count
     * @param int $page Current page
     * @param int $perPage Items per page
     * @return array Formatted response
     */
    public static function paginate(array $items, int $total, int $page, int $perPage): array
    {
        $lastPage = (int) ceil($total / $perPage);

        return [
            'data' => $items,
            'meta' => [
                'pagination' => [
                    'total' => $total,
                    'count' => count($items),
                    'per_page' => $perPage,
                    'current_page' => $page,
                    'total_pages' => $lastPage,
                    'has_next_page' => $page < $lastPage,
                    'has_prev_page' => $page > 1,
                ],
                'timestamp' => date('c'),
            ],
        ];
    }

    /**
     * Format empty response (no content)
     *
     * @return array Empty response
     */
    public static function noContent(): array
    {
        return [
            'data' => null,
            'meta' => [
                'timestamp' => date('c'),
                'status' => 204,
            ],
        ];
    }

    /**
     * Format created response (201 Created)
     *
     * @param array $data Created resource data
     * @param string|null $location Location header value
     * @return array Formatted response
     */
    public static function created(array $data, ?string $location = null): array
    {
        $response = [
            'data' => $data,
            'meta' => [
                'timestamp' => date('c'),
                'status' => 201,
            ],
        ];

        if ($location !== null) {
            $response['meta']['location'] = $location;
        }

        return $response;
    }
}
