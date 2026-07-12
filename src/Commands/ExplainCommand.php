<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator\Commands;

use Illuminate\Console\Command;
use Tsitsishvili\Documentator\Data\EndpointData;
use Tsitsishvili\Documentator\Documentator;

/** Shows why Documentator produced the documentation for one operation. */
final class ExplainCommand extends Command
{
    protected $signature = 'documentator:explain
        {method : HTTP method, for example GET}
        {uri : Laravel route URI, with or without a leading slash}
        {--json : Emit the explanation as JSON}';

    protected $description = 'Explain which inference sources documented an endpoint';

    public function handle(Documentator $documentator): int
    {
        $method = strtolower((string) $this->argument('method'));
        $uri = ltrim((string) $this->argument('uri'), '/');
        $endpoint = $this->find($documentator->endpoints(), $method, $uri);

        if ($endpoint === null) {
            $this->error(strtoupper($method)." /{$uri} is not in the documented route set.");

            return self::FAILURE;
        }

        $payload = [
            'operation' => strtoupper($method).' /'.$endpoint->uri,
            'action' => $this->action($endpoint),
            'trace' => $endpoint->provenance,
            'warnings' => $this->warnings($endpoint),
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info($payload['operation']);
        $this->line('Action: '.$payload['action']);
        $this->newLine();
        $this->line('Inference trace:');

        foreach ($endpoint->provenance as $event) {
            $this->line("  {$event['field']} <= {$event['strategy']} ({$event['effect']})");
        }

        foreach ($payload['warnings'] as $warning) {
            $this->warn('Warning: '.$warning);
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<int, EndpointData>  $endpoints
     */
    private function find(array $endpoints, string $method, string $uri): ?EndpointData
    {
        foreach ($endpoints as $endpoint) {
            if ($endpoint->uri === $uri && in_array($method, $endpoint->verbs(), true)) {
                return $endpoint;
            }
        }

        return null;
    }

    private function action(EndpointData $endpoint): string
    {
        if ($endpoint->controller !== null) {
            return $endpoint->controller.'@'.($endpoint->method ?? '__invoke');
        }

        return $endpoint->introspectable ? 'Closure' : 'Not introspectable';
    }

    /** @return array<int, string> */
    private function warnings(EndpointData $endpoint): array
    {
        $warnings = [];

        if (! $endpoint->introspectable) {
            $warnings[] = 'The route action could not be introspected.';
        }

        if ($endpoint->summary === null || trim($endpoint->summary) === '') {
            $warnings[] = 'No summary was inferred.';
        }

        $hasSuccessSchema = false;

        foreach ($endpoint->responses as $response) {
            if ($response->status >= 200 && $response->status < 300
                && ($response->schema !== null || $response->resource !== null || $response->example !== null)) {
                $hasSuccessSchema = true;
                break;
            }
        }

        if (! $hasSuccessSchema) {
            $warnings[] = 'No success response schema was inferred; add a return type or #[Response].';
        }

        return $warnings;
    }
}
