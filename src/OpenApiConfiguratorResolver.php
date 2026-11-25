<?php

declare(strict_types=1);

namespace Rasuvaeff\Mcp\OpenApi;

use LogicException;
use Psr\Container\ContainerInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\SimpleCache\CacheInterface;
use Rasuvaeff\Mcp\Identity\ExecutionIdentityProviderInterface;

/**
 * Turns the `openapi` params into the bridge, resolving application services
 * only when a configured feature needs them. Shared by the framework packages:
 * the keys are the same in each of them.
 *
 * @api
 */
final readonly class OpenApiConfiguratorResolver
{
    /**
     * @param array<string, mixed> $params the `openapi` block
     */
    public function __construct(
        private ContainerInterface $container,
        private array $params,
    ) {}

    /**
     * Null while the bridge is disabled: no spec or no operations.
     */
    public function resolve(): ?OpenApiServerConfigurator
    {
        /** @var array{spec_path?: string, base_url?: string, operations?: list<string>, headers?: array<string, string>, spec_headers?: array<string, string>, cache_ttl?: int, max_response_bytes?: int, opaque_errors?: bool, identity_provider?: class-string<ExecutionIdentityProviderInterface>|'', delegated_header_provider?: class-string<DelegatedHeaderProviderInterface>|'', safe_methods_only?: bool, tool_names?: array<string, string>, operation_modifier?: class-string<OperationModifierInterface>|'', dry_run?: list<string>, multi_segment_path_params?: array<array-key, mixed>, executor?: string, handler?: class-string<RequestHandlerInterface>|'', request_attributes?: class-string<ExecutionRequestAttributesInterface>|'', in_process_scope?: class-string<InProcessScopeInterface>|'', array_query_style?: string, array_query_params?: array<string, array<string, string>>} $openapi */
        $openapi = $this->params;

        $specPath = $openapi['spec_path'] ?? '';
        $operations = $openapi['operations'] ?? [];

        if ($specPath === '' || $operations === []) {
            return null;
        }

        $identityProviderClass = $openapi['identity_provider'] ?? '';
        /** @var ?ExecutionIdentityProviderInterface $identityProvider */
        $identityProvider = $identityProviderClass === '' ? null : $this->getService($identityProviderClass);

        $cacheTtl = $openapi['cache_ttl'] ?? 0;

        // in-process execution mode: 'http' (default, real PSR-18 calls)
        // or 'psr15' (the application's own handler, no network I/O).
        // Validated here so a typo fails the server build, not the first
        // tool call; the handler/mapper/scope keys are psr15-only and
        // rejected in http mode instead of being silently ignored
        /** @var string $executorMode */
        $executorMode = $openapi['executor'] ?? 'http';
        /** @var class-string<RequestHandlerInterface>|'' $inProcessHandlerClass */
        $inProcessHandlerClass = $openapi['handler'] ?? '';
        /** @var class-string<ExecutionRequestAttributesInterface>|'' $requestAttributesClass */
        $requestAttributesClass = $openapi['request_attributes'] ?? '';
        /** @var class-string<InProcessScopeInterface>|'' $inProcessScopeClass */
        $inProcessScopeClass = $openapi['in_process_scope'] ?? '';
        /** @var ?string $arrayQueryStyle */
        $configuredArrayQueryStyle = $openapi['array_query_style'] ?? '';
        $arrayQueryStyle = $configuredArrayQueryStyle === '' ? null : $configuredArrayQueryStyle;
        /** @var array<string, array<string, string>> $arrayQueryParams */
        $arrayQueryParams = $openapi['array_query_params'] ?? [];

        if (!in_array($executorMode, ['http', 'psr15'], strict: true)) {
            throw new LogicException(sprintf('Unsupported openapi.executor "%s"; supported: http, psr15', $executorMode));
        }

        if ($executorMode === 'psr15' && $inProcessHandlerClass === '') {
            throw new LogicException('openapi.executor "psr15" requires openapi.handler (a Psr\Http\Server\RequestHandlerInterface class)');
        }

        if ($executorMode === 'http' && ($inProcessHandlerClass !== '' || $requestAttributesClass !== '' || $inProcessScopeClass !== '')) {
            throw new LogicException('openapi.handler, openapi.request_attributes and openapi.in_process_scope are only read when openapi.executor is "psr15"');
        }

        $delegatedHeaderProviderClass = $openapi['delegated_header_provider'] ?? '';

        /** @var ?DelegatedHeaderProviderInterface $delegatedHeaderProvider */
        $delegatedHeaderProvider = $delegatedHeaderProviderClass === '' ? null : $this->getService($delegatedHeaderProviderClass);

        $operationModifierClass = $openapi['operation_modifier'] ?? '';
        /** @var ?OperationModifierInterface $operationModifier */
        $operationModifier = $operationModifierClass === '' ? null : $this->getService($operationModifierClass);

        // HTTP transport services are resolved ONLY where the bridge
        // actually uses them: http execution, or fetching a URL spec.
        // A psr15 server with a local spec builds in a container that
        // binds no PSR-18 client at all (#60)
        $urlSpec = str_starts_with($specPath, 'http://') || str_starts_with($specPath, 'https://');
        $needsHttpTransport = $executorMode === 'http' || $urlSpec;
        /** @var ?ClientInterface $httpClient */
        $httpClient = $needsHttpTransport ? $this->getService(ClientInterface::class) : null;
        /** @var ?RequestFactoryInterface $requestFactory */
        $requestFactory = $needsHttpTransport ? $this->getService(RequestFactoryInterface::class) : null;

        // Same construction path as a standalone consumer: spec
        // credentials and operation credentials keep separate scopes
        // inside the factory.
        return OpenApiBridgeFactory::create(
            spec: $specPath,
            baseUrl: $openapi['base_url'] ?? '',
            httpClient: $httpClient,
            requestFactory: $requestFactory,
            streamFactory: $this->getService(StreamFactoryInterface::class),
            operations: $operations,
            headers: $openapi['headers'] ?? [],
            specHeaders: $openapi['spec_headers'] ?? [],
            specCache: $cacheTtl > 0 ? $this->getService(CacheInterface::class) : null,
            specCacheTtl: $cacheTtl,
            safeMethodsOnly: $openapi['safe_methods_only'] ?? false,
            toolNames: $openapi['tool_names'] ?? [],
            modifier: $operationModifier,
            dryRunOperations: $openapi['dry_run'] ?? [],
            identityProvider: $identityProvider,
            delegatedHeaderProvider: $delegatedHeaderProvider,
            maxResponseBytes: $openapi['max_response_bytes'] ?? null,
            opaqueErrors: $openapi['opaque_errors'] ?? false,
            multiSegmentPathParams: $openapi['multi_segment_path_params'] ?? [],
            inProcessHandler: $inProcessHandlerClass !== '' ? $this->getService($inProcessHandlerClass) : null,
            serverRequestFactory: $inProcessHandlerClass !== '' ? $this->getService(ServerRequestFactoryInterface::class) : null,
            requestAttributes: $requestAttributesClass !== '' ? $this->getService($requestAttributesClass) : null,
            inProcessScope: $inProcessScopeClass !== '' ? $this->getService($inProcessScopeClass) : null,
            arrayQueryStyle: $arrayQueryStyle,
            arrayQueryParams: $arrayQueryParams,
        );
    }

    /**
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    private function getService(string $id): object
    {
        $service = $this->container->get($id);

        if (!$service instanceof $id) {
            throw new LogicException(sprintf('Service "%s" must be an instance of %s', $id, $id));
        }

        return $service;
    }
}
