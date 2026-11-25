# rasuvaeff/mcp-openapi

[![Stable Version](https://img.shields.io/packagist/v/rasuvaeff/mcp-openapi?label=stable&sort_semver=1)](https://packagist.org/packages/rasuvaeff/mcp-openapi)
[![Total Downloads](https://img.shields.io/packagist/dt/rasuvaeff/mcp-openapi)](https://packagist.org/packages/rasuvaeff/mcp-openapi)
[![Build](https://img.shields.io/github/actions/workflow/status/rasuvaeff/mcp-openapi/build.yml?branch=master)](https://github.com/rasuvaeff/mcp-openapi/actions)
[![Static analysis](https://img.shields.io/github/actions/workflow/status/rasuvaeff/mcp-openapi/static-analysis.yml?branch=master&label=static%20analysis)](https://github.com/rasuvaeff/mcp-openapi/actions)
[![Psalm level](https://img.shields.io/badge/psalm-level%201-141F48?logo=psalm&logoColor=white)](https://github.com/rasuvaeff/mcp-openapi/blob/master/psalm.xml)
[![License](https://img.shields.io/packagist/l/rasuvaeff/mcp-openapi)](LICENSE.md)

[Русская версия](README.ru.md)

Serve allow-listed operations of an OpenAPI 3.x document as MCP tools. Runs on
[`rasuvaeff/mcp`](https://github.com/rasuvaeff/mcp) and needs no framework: a spec,
a PSR-18 client (or a PSR-15 handler) and PSR-17 factories are enough. Using Yii3?
[`rasuvaeff/yii3-mcp`](https://github.com/rasuvaeff/yii3-mcp) wires it from params.

> **Using an AI coding assistant?** [llms.txt](llms.txt) contains a compact API
> reference you can share with the model. Contributors: see [AGENTS.md](AGENTS.md).
> Projects using the [llm/skills](https://github.com/roxblnfk/skills) Composer
> plugin also get this package's agent skill synced into `.agents/skills/`
> automatically on install.

## Requirements

| Requirement | Version |
|---|---|
| PHP | 8.3 – 8.6 |
| `rasuvaeff/mcp` | `^0.1` |
| `rasuvaeff/openapi-contract` | `^0.19` |
| PSR-17 implementation | any |
| PSR-18 client | for HTTP execution or a URL spec |

## Installation

```bash
composer require rasuvaeff/mcp-openapi
```

## Usage

```php
use Rasuvaeff\Mcp\McpServerFactory;
use Rasuvaeff\Mcp\OpenApi\OpenApiBridgeFactory;

$bridge = OpenApiBridgeFactory::create(
    spec: __DIR__ . '/openapi.json', // JSON file, http(s) URL or decoded array
    baseUrl: 'https://api.example.com',
    httpClient: $httpClient,
    requestFactory: $psr17,
    streamFactory: $psr17,
    operations: ['getBlogTags'],
    headers: ['Authorization' => 'Bearer ' . getenv('API_TOKEN')],
    safeMethodsOnly: true,
    toolNames: ['getBlogTags' => 'blog_tags_list'],
);

$server = (new McpServerFactory(
    container: $container,
    sessionStore: $sessionStore,
    name: 'my-api',
    version: '1.0.0',
))->create([], [$bridge]);
```

A tool is named after its `operationId` (or `toolNames`), described by
`summary`/`description`; parameters and the JSON request body become arguments,
the body as one `body` argument. Shared schemas are inlined; a recursive one stays
a reference with its definition in the tool schema's `$defs`. A response schema too
large to inline is not advertised as `outputSchema`.

### In-process execution

Pass `inProcessHandler` (PSR-15) and `serverRequestFactory` to call the
application's own handler without network I/O; `httpClient` and `requestFactory`
may then be `null` unless the spec is a URL.

### Limits

Checked when the server is built, for the allow-listed operations:

- path parameters are scalars, query parameters are scalars or arrays of scalars;
- header and cookie parameters, non-default `style`/`explode` (except for query
  arrays) and `allowReserved` are refused;
- the document is OpenAPI 3.0 or 3.1, compiled by
  [`rasuvaeff/openapi-contract`](https://github.com/rasuvaeff/openapi-contract);
  references must be local; its `servers`, `security` and `webhooks` are ignored;
- only `application/json` request bodies; the document is JSON up to 10 MiB.

### Components

| Class | Role |
|---|---|
| `OpenApiBridgeFactory` | builds the bridge from a spec and PSR services |
| `OpenApiServerConfigurator` | registers the allow-listed operations on the SDK builder |
| `OpenApiConfiguratorResolver` | for framework packages: the `openapi` params → the bridge, or null while it is disabled |
| `SpecLoader` | fetches a URL spec, with an optional PSR-16 cache |
| `Operation`, `OperationModifierInterface` | customize a tool per operation |
| `DelegatedHeaderProviderInterface` | upstream headers for the current execution identity |
| `ExecutionRequestAttributesInterface` | identity as attributes of an in-process request |
| `InProcessScopeInterface` | resets request-scoped state around an in-process call |
| `Exception\InvalidSpecException` | the document or an operation cannot be bridged |
| `Exception\UnknownOperationException` | an allow-listed operationId is not in the document |
| `Exception\UnsafeOperationException` | a non-GET operation under `safeMethodsOnly` |
| `Exception\InvalidToolArgumentException` | an argument cannot be put into the request |
| `Exception\OperationFailedException` | the upstream call failed |

## Security

- Only operations in `operations` are served; an empty list serves nothing.
- `headers` go to `baseUrl` only, `specHeaders` to the spec URL only; a spec URL
  with embedded credentials is refused.
- A path argument that is empty, `.`, or contains `..`, `/` or `\` is refused;
  `multiSegmentPathParams` relaxes only `/`, per parameter.
- Response bodies are read incrementally up to `maxResponseBytes`.
- `opaqueErrors: true` hides upstream error bodies.
- The spec and `baseUrl` are trusted configuration: there is no SSRF protection.

## Examples

See [examples/](examples/): the script runs offline.

## Development

No PHP/Composer on the host, run in Docker via the `composer:2` image:

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
```

Or with Make: `make build`, `make cs-fix`, `make psalm`, `make test`.

## License

BSD-3-Clause. See [LICENSE.md](LICENSE.md).
