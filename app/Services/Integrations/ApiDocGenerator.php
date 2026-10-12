<?php

namespace App\Services\Integrations;

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * The API reference of the application generated from its routes: an OpenAPI 3.0 document (Swagger) and a Postman collection
 * (v2.1). It documents paths, methods, path parameters and the controller action each route runs, grouped by resource; request
 * bodies are not inferred (the validation rules live in the controllers), so each write operation carries a free-form JSON body.
 */
class ApiDocGenerator
{
    /**
     * @return list<array{method: string, path: string, name: ?string, action: string, tag: string, params: list<string>, summary: string}>
     */
    public function operations(): array
    {
        $rows = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            /** @var LaravelRoute $route */
            $uri = $route->uri();

            if (! str_starts_with($uri, 'api/')) {
                continue;
            }

            $path = '/'.substr($uri, 4);
            preg_match_all('/\{(\w+)\??\}/', $path, $matches);
            $segments = explode('/', trim($path, '/'));
            $action = $route->getActionName();

            foreach ($route->methods() as $method) {
                if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }

                $rows[] = [
                    'method' => $method, 'path' => $path, 'name' => $route->getName(), 'tag' => $segments[0] ?: 'root', 'params' => $matches[1],
                    'action' => $action === 'Closure' ? 'closure' : class_basename(explode('@', $action)[0]).'@'.(explode('@', $action)[1] ?? ''),
                    'summary' => ucfirst(strtolower($method)).' '.$path,
                ];
            }
        }

        usort($rows, fn (array $a, array $b): int => [$a['path'], $a['method']] <=> [$b['path'], $b['method']]);

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    public function openApi(string $title, string $version, string $serverUrl): array
    {
        $paths = [];

        foreach ($this->operations() as $operation) {
            $parameters = array_map(fn (string $name): array => ['name' => $name, 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']], $operation['params']);
            $entry = [
                'tags' => [$operation['tag']], 'summary' => $operation['summary'], 'operationId' => ($operation['name'] ?: strtolower($operation['method']).str_replace(['/', '{', '}', '-'], '_', $operation['path'])),
                'description' => 'Handled by '.$operation['action'].'.',
                'responses' => ['200' => ['description' => 'Success'], '401' => ['description' => 'Not signed in'], '403' => ['description' => 'Not permitted'], '422' => ['description' => 'Validation failed']],
                'security' => [['bearerAuth' => []], ['cookieAuth' => []]],
            ];

            if ($parameters !== []) {
                $entry['parameters'] = $parameters;
            }

            if (in_array($operation['method'], ['POST', 'PUT', 'PATCH'], true)) {
                $entry['requestBody'] = ['required' => false, 'content' => ['application/json' => ['schema' => ['type' => 'object', 'additionalProperties' => true]]]];
            }

            $paths[$operation['path']][strtolower($operation['method'])] = $entry;
        }

        return [
            'openapi' => '3.0.3',
            'info' => ['title' => $title, 'version' => $version, 'description' => 'Generated from the application routes.'],
            'servers' => [['url' => rtrim($serverUrl, '/').'/api']],
            'components' => ['securitySchemes' => [
                'bearerAuth' => ['type' => 'http', 'scheme' => 'bearer', 'description' => 'An API key or Sanctum token'],
                'cookieAuth' => ['type' => 'apiKey', 'in' => 'cookie', 'name' => 'laravel_session'],
            ]],
            'paths' => $paths,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function postman(string $title, string $serverUrl): array
    {
        $folders = [];

        foreach ($this->operations() as $operation) {
            $raw = '{{base_url}}'.preg_replace('/\{(\w+)\??\}/', ':$1', $operation['path']);
            $item = [
                'name' => $operation['summary'],
                'request' => [
                    'method' => $operation['method'],
                    'header' => [['key' => 'Accept', 'value' => 'application/json'], ['key' => 'Authorization', 'value' => 'Bearer {{token}}']],
                    'url' => [
                        'raw' => $raw, 'host' => ['{{base_url}}'],
                        'path' => array_values(array_filter(explode('/', preg_replace('/\{(\w+)\??\}/', ':$1', ltrim($operation['path'], '/'))))),
                        'variable' => array_map(fn (string $p): array => ['key' => $p, 'value' => ''], $operation['params']),
                    ],
                ],
            ];

            if (in_array($operation['method'], ['POST', 'PUT', 'PATCH'], true)) {
                $item['request']['body'] = ['mode' => 'raw', 'raw' => "{\n}", 'options' => ['raw' => ['language' => 'json']]];
                $item['request']['header'][] = ['key' => 'Content-Type', 'value' => 'application/json'];
            }

            $folders[$operation['tag']][] = $item;
        }

        ksort($folders);

        return [
            'info' => ['name' => $title, '_postman_id' => (string) Str::uuid(), 'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json'],
            'variable' => [['key' => 'base_url', 'value' => rtrim($serverUrl, '/').'/api'], ['key' => 'token', 'value' => '']],
            'item' => collect($folders)->map(fn (array $items, string $name): array => ['name' => $name, 'item' => $items])->values()->all(),
        ];
    }
}
