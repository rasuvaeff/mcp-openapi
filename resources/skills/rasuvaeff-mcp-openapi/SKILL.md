---
name: rasuvaeff-mcp-openapi
description: >-
  Serve allow-listed OpenAPI 3.0/3.1 operations as MCP tools with
  rasuvaeff/mcp-openapi — OpenApiBridgeFactory, PSR-18 or in-process PSR-15
  execution, tool renames, OperationModifierInterface, dry run, delegated headers.
  Use when bridging an HTTP API to an MCP server, or debugging why an operation is
  refused, in a project that has this package installed.
---

# rasuvaeff/mcp-openapi

Namespace `Rasuvaeff\Mcp\OpenApi\`, on `rasuvaeff/mcp`; documents are compiled by
`rasuvaeff/openapi-contract`. No framework needed.

## Safety rules — verify these on every change

1. **Operations are an explicit allow-list** of operationIds; an empty list serves
   nothing. Use `safeMethodsOnly: true` for a read-only bridge: a non-GET operation
   then throws `UnsafeOperationException`. Never widen either to "everything".
2. **Keep credential scopes apart.** `headers` go to `baseUrl`, `specHeaders` to a
   spec URL. Never reuse one for the other; a spec URL must not embed credentials.
3. **The spec and `baseUrl` are trusted configuration.** There is no SSRF protection:
   never take either from a user, a tenant or a tool argument.
4. **Do not relax path arguments.** Empty, `.`, `..`, `/` and `\` are refused;
   `multiSegmentPathParams` allows only `/`, for the named parameter, with a count.
5. **Delegated headers need an identity.** Configure `identityProvider` and
   `delegatedHeaderProvider` together.
6. **Set `opaqueErrors: true`** when upstream error bodies may hold internals, and
   keep `maxResponseBytes`.
7. **In-process execution shares the application's services.** A handler that keeps
   request-scoped state needs `inProcessScope`.

## Canonical usage

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
    safeMethodsOnly: true,
);
$server = (new McpServerFactory(container: $c, sessionStore: $store, name: 'api', version: '1.0.0'))
    ->create([], [$bridge]);
```

## When an operation is refused

The build fails with `InvalidSpecException`; change the document or drop the
operation, do not work around the check:

- not OpenAPI 3.0/3.1 JSON, over 10 MiB, or an external `$ref`;
- header or cookie parameters, `deepObject` and other non-default serialization,
  `allowReserved`, a parameter schema that is not a scalar (or a scalar array in
  query) or has no `type`;
- a path parameter that is not `required: true`.

A very large document is expensive to compile: build the server once in a
long-running process, not on every request.

## Full API

Read `vendor/rasuvaeff/mcp-openapi/llms.txt` before guessing an argument name.
