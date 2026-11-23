<?php

declare(strict_types=1);

namespace Rasuvaeff\Mcp\OpenApi;

use Rasuvaeff\Mcp\OpenApi\Exception\InvalidSpecException;
use Rasuvaeff\Mcp\OpenApi\Exception\UnknownOperationException;
use Rasuvaeff\OpenApiContract\Contract;
use Rasuvaeff\OpenApiContract\ContractException;
use Rasuvaeff\OpenApiContract\Operation as CompiledOperation;

/**
 * The operations of one OpenAPI document, by operationId. Parsing, references
 * and schemas are `rasuvaeff/openapi-contract`'s: this class only lists the
 * declared ids (for the allow-list check and the duplicate check), compiles the
 * selected operations and projects them onto {@see Operation}. Operations
 * without an operationId cannot be bridged and are not listed.
 *
 * @internal
 */
final readonly class SpecIndex
{
    private const array HTTP_METHODS = ['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace'];

    /**
     * Upper bound on the raw OpenAPI document accepted for decoding — a
     * remote spec endpoint must not be able to make indexing buffer an
     * arbitrarily large body.
     */
    public const int MAX_DOCUMENT_BYTES = 10 * 1024 * 1024;

    /**
     * @var array<string, mixed>
     */
    private array $document;

    /**
     * @var array<non-empty-string, string> operationId => "METHOD /path"
     */
    private array $declared;

    private OperationContractValidator $validator;

    /**
     * @param array<string, mixed> $spec decoded OpenAPI document
     */
    public function __construct(array $spec)
    {
        $paths = $spec['paths'] ?? null;

        if (!is_array($paths) || $paths === []) {
            throw new InvalidSpecException('OpenAPI document has no paths');
        }

        $bridgeable = [];
        $declared = [];
        /** @var mixed $pathItem */
        foreach ($paths as $path => $pathItem) {
            // Only a "/"-rooted path is bridged: the executors concatenate
            // baseUrl . path with no separator, so "evil.com/x" would turn
            // "https://api.test" into another host.
            if (!is_string($path) || !str_starts_with($path, '/') || !is_array($pathItem)) {
                continue;
            }

            $item = $this->bridgeableItem($pathItem);
            $bridgeable[$path] = $item;

            foreach (self::HTTP_METHODS as $method) {
                /** @var mixed $operation */
                $operation = $item[$method] ?? null;
                /** @var mixed $operationId */
                $operationId = is_array($operation) ? $operation['operationId'] ?? null : null;

                if (!is_string($operationId) || $operationId === '') {
                    continue;
                }

                $where = strtoupper($method) . ' ' . $path;

                if (isset($declared[$operationId])) {
                    throw new InvalidSpecException(sprintf('Duplicate operationId "%s" for %s and %s', $operationId, $declared[$operationId], $where));
                }

                $declared[$operationId] = $where;
            }
        }

        // The bridge calls base_url with its own credentials, so what the
        // document says about servers, security and inbound webhooks is not
        // the bridge's business and must not be able to refuse the document.
        unset($spec['servers'], $spec['security'], $spec['webhooks']);

        /** @var mixed $components */
        $components = $spec['components'] ?? null;

        if (is_array($components)) {
            unset($components['securitySchemes']);
            $spec['components'] = $components;
        }

        $this->document = ['paths' => $bridgeable] + $spec;
        $this->declared = $declared;
        $this->validator = new OperationContractValidator();
    }

    public static function fromJson(string $json): self
    {
        if (strlen($json) > self::MAX_DOCUMENT_BYTES) {
            throw new InvalidSpecException(sprintf(
                'OpenAPI document of %d bytes exceeds the %d-byte limit',
                strlen($json),
                self::MAX_DOCUMENT_BYTES,
            ));
        }

        try {
            $decoded = json_decode($json, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidSpecException('OpenAPI document is not valid JSON', $e->getCode(), previous: $e);
        }

        if (!is_array($decoded)) {
            throw new InvalidSpecException('OpenAPI document must decode to an object');
        }

        /** @var array<string, mixed> $decoded */
        return new self($decoded);
    }

    public static function fromFile(string $path): self
    {
        // bound before buffering: a runaway generated spec file must fail
        // with a clear message, not exhaust the worker
        $size = @filesize($path);

        if (is_int($size) && $size > self::MAX_DOCUMENT_BYTES) {
            throw new InvalidSpecException(sprintf(
                'OpenAPI document "%s" of %d bytes exceeds the %d-byte limit',
                $path,
                $size,
                self::MAX_DOCUMENT_BYTES,
            ));
        }

        $json = @file_get_contents($path);

        if ($json === false) {
            throw new InvalidSpecException(sprintf('OpenAPI document "%s" is not readable', $path));
        }

        return self::fromJson($json);
    }

    public function get(string $operationId): Operation
    {
        return $this->select([$operationId])[$operationId];
    }

    /**
     * @param list<string> $operationIds
     *
     * @return array<string, Operation>
     */
    public function select(array $operationIds): array
    {
        if ($operationIds === []) {
            return [];
        }

        $selected = [];

        foreach ($operationIds as $operationId) {
            if ($operationId === '' || !isset($this->declared[$operationId])) {
                throw new UnknownOperationException(sprintf(
                    'Operation "%s" is not present in the OpenAPI document; known operations: %s',
                    $operationId,
                    implode(', ', array_keys($this->declared)),
                ));
            }

            if (!ToolNameValidator::isValid($operationId)) {
                throw new InvalidSpecException(sprintf(
                    'Operation "%s" (%s) has an operationId that cannot be used as an MCP tool name',
                    $operationId,
                    $this->declared[$operationId],
                ));
            }

            $selected[] = $operationId;
        }

        try {
            $contract = Contract::fromArray($this->document, only: $selected);
        } catch (ContractException $e) {
            throw new InvalidSpecException($e->getMessage(), $e->getCode(), previous: $e);
        }

        $operations = [];

        foreach ($selected as $operationId) {
            $operation = $this->project($operationId, $contract->operation($operationId));
            $this->validator->validate($operation);
            $operations[$operationId] = $operation;
        }

        return $operations;
    }

    /**
     * @param array<array-key, mixed> $pathItem
     *
     * @return array<array-key, mixed>
     */
    private function bridgeableItem(array $pathItem): array
    {
        unset($pathItem['servers']);

        foreach (self::HTTP_METHODS as $method) {
            /** @var mixed $operation */
            $operation = $pathItem[$method] ?? null;

            if (!is_array($operation)) {
                unset($pathItem[$method]);

                continue;
            }

            unset($operation['servers'], $operation['security']);

            // a document may say nothing about responses: the bridge then has
            // no output schema, it is not a reason to refuse the operation
            $operation += ['responses' => ['default' => []]];
            $pathItem[$method] = $operation;
        }

        return $pathItem;
    }

    /**
     * @param non-empty-string $operationId
     */
    private function project(string $operationId, CompiledOperation $compiled): Operation
    {
        $defs = new LocalDefinitions();
        $parameters = [];

        foreach ($compiled->parameters as $parameter) {
            $parameters[] = [
                'name' => $parameter['name'],
                'in' => $parameter['in'],
                'required' => $parameter['required'],
                'schema' => $defs->inline($parameter['schema']),
                'description' => $parameter['description'] ?? '',
                'style' => $parameter['style'],
                'explode' => $parameter['explode'],
                'allowReserved' => $parameter['allowReserved'],
            ];
        }

        $body = $compiled->requestBody['content']['application/json']['schema'] ?? null;

        return new Operation(
            operationId: $operationId,
            method: $compiled->method === '' ? throw new \LogicException('Compiled operation has no method') : $compiled->method,
            path: $compiled->path === '' ? throw new \LogicException('Compiled operation has no path') : $compiled->path,
            description: $compiled->description ?? $compiled->summary ?? '',
            parameters: $parameters,
            requestBodySchema: is_array($body) && $body !== [] ? $defs->inline($body) : null,
            requestBodyRequired: $compiled->requestBody['required'] ?? false,
            outputSchema: (new OutputSchemaProjector($defs))->project($compiled->responses),
            tags: $compiled->tags,
        );
    }
}
