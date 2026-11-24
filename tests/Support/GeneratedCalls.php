<?php

declare(strict_types=1);

namespace Rasuvaeff\Mcp\OpenApi\Tests\Support;

use Rasuvaeff\Mcp\OpenApi\InputSchemaBuilder;
use Rasuvaeff\OpenApiContract\Contract;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\OpenApi\NegativeRequestCaseArbitrary;
use Rasuvaeff\PropertyTesting\OpenApi\RequestCaseArbitrary;

/**
 * Request cases generated from one OpenAPI document, as tool calls.
 */
final class GeneratedCalls
{
    /** Misuses a tool call cannot express: the bridge itself writes the media type and the JSON. */
    private const array TRANSPORT_MISUSE = ['media-type', 'part-content-type', 'json-syntax'];

    private static ?Contract $contract = null;

    /**
     * @return array<string, mixed>
     */
    public static function document(): array
    {
        $string = ['type' => 'string', 'minLength' => 1, 'maxLength' => 12];

        return [
            'openapi' => '3.1.0',
            'info' => ['title' => 'Generated API', 'version' => '1.0.0'],
            'servers' => [['url' => 'https://api.test']],
            'paths' => [
                '/items/{id}/notes/{slug}' => [
                    'get' => [
                        'operationId' => 'getNote',
                        'parameters' => [
                            ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 9999]],
                            // the bridge refuses ".." in a path value whatever the document allows
                            ['name' => 'slug', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'pattern' => '^[a-z0-9 +%?#&=-]{1,10}$']],
                        ],
                        'responses' => ['200' => ['description' => 'Note']],
                    ],
                ],
                '/items' => [
                    'get' => [
                        'operationId' => 'findItems',
                        'parameters' => [
                            ['name' => 'q', 'in' => 'query', 'required' => true, 'schema' => $string],
                            ['name' => 'limit', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]],
                            ['name' => 'ratio', 'in' => 'query', 'schema' => ['type' => 'number', 'minimum' => -2, 'maximum' => 2]],
                            ['name' => 'active', 'in' => 'query', 'schema' => ['type' => 'boolean']],
                            ['name' => 'sort', 'in' => 'query', 'schema' => ['type' => 'string', 'enum' => ['name', 'created at', 'a&b=c']]],
                            ['name' => 'tags', 'in' => 'query', 'schema' => ['type' => 'array', 'items' => $string, 'minItems' => 1, 'maxItems' => 3]],
                            ['name' => 'ids', 'in' => 'query', 'explode' => false, 'schema' => ['type' => 'array', 'items' => ['type' => 'integer'], 'minItems' => 1, 'maxItems' => 3]],
                        ],
                        'responses' => ['200' => ['description' => 'Items']],
                    ],
                    'post' => [
                        'operationId' => 'createItem',
                        'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Item']]]],
                        'responses' => ['201' => ['description' => 'Created']],
                    ],
                ],
            ],
            'components' => ['schemas' => [
                'Item' => [
                    'type' => 'object',
                    'required' => ['name', 'owner'],
                    'additionalProperties' => false,
                    'properties' => [
                        'name' => $string,
                        'price' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1000],
                        'state' => ['type' => 'string', 'enum' => ['new', 'old']],
                        'labels' => ['type' => 'array', 'items' => $string, 'maxItems' => 3],
                        'owner' => ['$ref' => '#/components/schemas/Owner'],
                    ],
                ],
                'Owner' => [
                    'type' => 'object',
                    'required' => ['id'],
                    'properties' => ['id' => ['type' => 'integer', 'minimum' => 1], 'title' => ['type' => ['string', 'null']]],
                ],
            ]],
        ];
    }

    public static function contract(): Contract
    {
        return self::$contract ??= Contract::fromArray(self::document());
    }

    /**
     * @return array<string, ArbitraryInterface<array<string, mixed>>>
     */
    public static function valid(): array
    {
        return ['case' => Gen::frequency([
            [2, (new RequestCaseArbitrary())->forOperation(self::contract()->operation('getNote'))],
            [3, (new RequestCaseArbitrary())->forOperation(self::contract()->operation('findItems'))],
            [3, (new RequestCaseArbitrary())->forOperation(self::contract()->operation('createItem'))],
        ])];
    }

    /**
     * @return array<string, ArbitraryInterface<array<string, mixed>>>
     */
    public static function negative(): array
    {
        $choices = [];

        foreach (['getNote', 'findItems', 'createItem'] as $operationId) {
            foreach ((new NegativeRequestCaseArbitrary())->categoriesForOperation(self::contract()->operation($operationId)) as $category => $arbitrary) {
                if (!in_array($category, self::TRANSPORT_MISUSE, strict: true)) {
                    $choices[] = [1, $arbitrary];
                }
            }
        }

        return ['case' => Gen::frequency($choices)];
    }

    /**
     * @param array<string, mixed> $case
     *
     * @return array<string, mixed>
     */
    public static function arguments(array $case): array
    {
        $arguments = [];

        foreach (self::contract()->operation($case['operationKey'])->parameters as $parameter) {
            $values = $case[$parameter['in']];

            if (array_key_exists($parameter['name'], $values)) {
                $arguments[$parameter['name']] = self::typed($values[$parameter['name']], $parameter['schema']);
            }
        }

        if ($case['body'] !== null) {
            $arguments[InputSchemaBuilder::BODY_ARGUMENT] = $case['body']['value'];
        }

        return $arguments;
    }

    /**
     * A generated parameter is spelled as on the wire; a tool argument is a JSON value.
     *
     * @param array<array-key, mixed> $schema
     */
    private static function typed(mixed $value, array $schema): mixed
    {
        if (is_array($value)) {
            return array_map(static fn(mixed $item): mixed => self::typed($item, $schema['items'] ?? []), $value);
        }

        return match ($schema['type'] ?? null) {
            'integer', 'number' => is_numeric($value) ? json_decode($value, flags: JSON_THROW_ON_ERROR) : $value,
            'boolean' => ['true' => true, 'false' => false][$value] ?? $value,
            default => $value,
        };
    }
}
