<?php

declare(strict_types=1);

namespace App\Application\Monitoring;

/**
 * Story 8.4: renders ExportMetrics::collect() as Prometheus text exposition
 * format 0.0.4. `# HELP`/`# TYPE` appear once per family; a source that is
 * down only omits its own families (ec_hub_metrics_source_up says which).
 *
 * The recommendation summary has no quantiles: `_sum` is derived from
 * RecommendationExperiment::results() as avg_response_time_ms * requests,
 * so it carries the 2-decimal rounding of that average.
 */
final class PrometheusFormatter
{
    private const PREFIX = 'ec_hub_';

    /** @var list<string> */
    private array $lines = [];

    /** @param array<string, mixed> $collected ExportMetrics::collect() */
    public function format(array $collected): string
    {
        $this->lines = [];
        $data = is_array($collected['data'] ?? null) ? $collected['data'] : [];
        $sources = is_array($collected['meta']['sources'] ?? null) ? $collected['meta']['sources'] : [];

        if (($sources['http'] ?? false) === true) {
            $this->http($data, is_array($collected['histograms'] ?? null) ? $collected['histograms'] : []);
        }
        if (($sources['memory'] ?? false) === true && is_array($data['memory'] ?? null)) {
            $this->memory($data['memory']);
        }
        if (($sources['recommendations'] ?? false) === true && is_array($data['recommendations'] ?? null)) {
            $this->recommendations($data['recommendations']);
        }
        if (($sources['event_bus'] ?? false) === true && is_array($data['event_bus'] ?? null)) {
            $this->eventBus($data['event_bus']);
        }

        $this->family('metrics_source_up', 'gauge', 'Whether each metrics source could be read (1) or not (0).');
        foreach (ExportMetrics::SOURCES as $source) {
            $this->sample('metrics_source_up', ['source' => $source], ($sources[$source] ?? false) === true ? 1 : 0);
        }

        return implode("\n", $this->lines) . "\n";
    }

    /**
     * @param array<string, mixed> $data
     * @param array<mixed> $histograms
     */
    private function http(array $data, array $histograms): void
    {
        $routes = is_array($data['requests']['routes'] ?? null) ? $data['requests']['routes'] : [];

        $this->family('http_requests_total', 'counter', 'HTTP requests by method, route and status code.');
        foreach ($routes as $route) {
            foreach ((array) ($route['statuses'] ?? []) as $status => $count) {
                $this->sample('http_requests_total', $this->routeLabels($route) + ['status' => (string) $status], $count);
            }
        }

        $this->family('http_errors_total', 'counter', 'HTTP 5xx responses by method and route.');
        foreach ($routes as $route) {
            $this->sample('http_errors_total', $this->routeLabels($route), $route['errors'] ?? 0);
        }

        $this->family('http_request_duration_seconds', 'histogram', 'HTTP request duration in seconds.');
        foreach ($histograms as $histogram) {
            if (! is_array($histogram) || ! is_array($histogram['buckets'] ?? null)) {
                continue;
            }
            $labels = $this->routeLabels($histogram);
            foreach ($histogram['buckets'] as $le => $count) {
                $this->sample('http_request_duration_seconds_bucket', $labels + ['le' => (string) $le], $count);
            }
            $this->sample('http_request_duration_seconds_sum', $labels, $histogram['sum_seconds'] ?? 0);
            $this->sample('http_request_duration_seconds_count', $labels, $histogram['count'] ?? 0);
        }
    }

    /** @param array<string, mixed> $memory */
    private function memory(array $memory): void
    {
        $this->family('memory_usage_bytes', 'gauge', 'Memory in use by the PHP process serving the scrape.');
        $this->sample('memory_usage_bytes', [], $memory['current_usage_bytes'] ?? 0);
        $this->family('memory_peak_usage_bytes', 'gauge', 'Peak memory of the PHP process serving the scrape.');
        $this->sample('memory_peak_usage_bytes', [], $memory['peak_usage_bytes'] ?? 0);
        $this->family('memory_growth_percent', 'gauge', 'Memory growth over the request baseline, in percent.');
        $this->sample('memory_growth_percent', [], $memory['growth_percent'] ?? 0);
    }

    /** @param array<string, mixed> $results */
    private function recommendations(array $results): void
    {
        $rows = [];
        foreach ((array) ($results['algorithms'] ?? []) as $row) {
            if (is_array($row) && is_string($row['algorithm'] ?? null)) {
                $rows[] = $row;
            }
        }

        $counters = [
            'recommendation_requests_total' => ['requests', 'Recommendation requests served, by algorithm.'],
            'recommendation_items_total' => ['total_items', 'Recommended items served, by algorithm.'],
            'recommendation_ml_items_total' => ['ml_items', 'Recommended items that came from ML, by algorithm.'],
        ];
        foreach ($counters as $name => [$field, $help]) {
            $this->family($name, 'counter', $help);
            foreach ($rows as $row) {
                $this->sample($name, ['algorithm' => $row['algorithm']], $row[$field] ?? 0);
            }
        }

        $this->family('recommendation_response_time_seconds', 'summary', 'Recommendation response time, by algorithm (no quantiles). _sum is derived from the rounded average and may dip slightly; use _sum/_count, not rate(_sum).');
        foreach ($rows as $row) {
            $requests = is_int($row['requests'] ?? null) ? $row['requests'] : 0;
            $average = is_numeric($row['avg_response_time_ms'] ?? null) ? (float) $row['avg_response_time_ms'] : 0.0;
            $labels = ['algorithm' => $row['algorithm']];
            $this->sample('recommendation_response_time_seconds_sum', $labels, $average * $requests / 1000);
            $this->sample('recommendation_response_time_seconds_count', $labels, $requests);
        }
    }

    /** @param array<string, mixed> $eventBus */
    private function eventBus(array $eventBus): void
    {
        $this->family('event_bus_connected', 'gauge', 'Whether the event bus is connected (1) or not (0).');
        $this->sample('event_bus_connected', [], ($eventBus['connected'] ?? false) === true ? 1 : 0);
        $this->family('events_published_total', 'counter', 'Events published on the event bus.');
        $this->sample('events_published_total', [], $eventBus['published_count'] ?? 0);
    }

    private function family(string $name, string $type, string $help): void
    {
        $this->lines[] = '# HELP ' . self::PREFIX . $name . ' ' . $help;
        $this->lines[] = '# TYPE ' . self::PREFIX . $name . ' ' . $type;
    }

    /** @param array<string, string> $labels */
    private function sample(string $name, array $labels, mixed $value): void
    {
        $line = self::PREFIX . $name;
        if ($labels !== []) {
            $pairs = [];
            foreach ($labels as $label => $labelValue) {
                $pairs[] = $label . '="' . self::escapeLabel($labelValue) . '"';
            }
            $line .= '{' . implode(',', $pairs) . '}';
        }

        $this->lines[] = $line . ' ' . self::number($value);
    }

    /**
     * @param array<mixed> $route
     * @return array{method: string, route: string}
     */
    private function routeLabels(array $route): array
    {
        return [
            'method' => is_string($route['method'] ?? null) ? $route['method'] : '',
            'route' => is_string($route['route'] ?? null) ? $route['route'] : '',
        ];
    }

    public static function escapeLabel(string $value): string
    {
        return str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $value);
    }

    /** Plain decimal, never NaN/Inf nor exponent notation. */
    public static function number(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (! is_numeric($value)) {
            return '0';
        }

        $float = (float) $value;
        if (! is_finite($float)) {
            return '0';
        }
        if ($float === floor($float) && abs($float) < 1e15) {
            return (string) (int) $float;
        }

        $formatted = rtrim(rtrim(sprintf('%.10F', $float), '0'), '.');

        return $formatted === '-0' || $formatted === '' ? '0' : $formatted;
    }
}
