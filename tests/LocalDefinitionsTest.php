<?php

declare(strict_types=1);

namespace Rasuvaeff\Mcp\OpenApi\Tests;

use Rasuvaeff\Mcp\OpenApi\Exception\InvalidSpecException;
use Rasuvaeff\Mcp\OpenApi\InputSchemaBuilder;
use Rasuvaeff\Mcp\OpenApi\LocalDefinitions;
use Rasuvaeff\Mcp\OpenApi\Operation;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(LocalDefinitions::class)]
#[Covers(InputSchemaBuilder::class)]
final class LocalDefinitionsTest
{
    public function aSchemaWithoutDefinitionsIsUntouched(): void
    {
        $schema = ['type' => 'object', 'properties' => ['x' => ['$ref' => '#/$defs/none']]];

        Assert::same((new LocalDefinitions())->inline($schema), $schema);
    }

    public function definitionsAreInlinedAtEveryDepthAndDropped(): void
    {
        Assert::same((new LocalDefinitions())->inline([
            'properties' => ['a' => ['$ref' => '#/$defs/A', 'type' => 'object'], 'b' => ['items' => ['$ref' => '#/$defs/x~1y~0z']]],
            '$defs' => [
                'A' => ['type' => 'object', 'properties' => ['leaf' => ['$ref' => '#/$defs/x~1y~0z']]],
                'x/y~z' => ['type' => 'string'],
            ],
        ]), [
            'properties' => [
                'a' => ['type' => 'object', 'properties' => ['leaf' => ['type' => 'string']]],
                'b' => ['items' => ['type' => 'string']],
            ],
        ]);
    }

    public function siblingsOfAReferenceWin(): void
    {
        Assert::same(
            (new LocalDefinitions())->inline(['$ref' => '#/$defs/A', 'description' => 'own', '$defs' => ['A' => ['description' => 'shared', 'type' => 'string']]]),
            ['description' => 'own', 'type' => 'string'],
        );
    }

    public function mutuallyRecursiveDefinitionsAreKept(): void
    {
        $a = ['properties' => ['b' => ['$ref' => '#/$defs/B']]];
        $result = (new LocalDefinitions())->inline([
            'type' => 'object',
            'properties' => ['a' => ['$ref' => '#/$defs/A']],
            '$defs' => [
                'A' => $a,
                'B' => ['properties' => ['a' => ['$ref' => '#/$defs/A'], 'self' => ['$ref' => '#/$defs/B'], 'plain' => ['$ref' => '#/$defs/C']]],
                'C' => ['type' => 'string'],
            ],
        ]);

        Assert::same($result['type'], 'object');
        Assert::same(array_keys($result['$defs']), ['A', 'B']);
        Assert::same($result['$defs']['B']['properties']['plain'], ['type' => 'string']);
        $this->assertResolvable($result, $result['$defs']);
    }

    public function aRecursiveReferenceKeepsItsSiblings(): void
    {
        $node = ['type' => 'object', 'properties' => ['child' => ['$ref' => '#/$defs/Node', 'type' => 'object', 'description' => 'next']]];

        Assert::same((new LocalDefinitions())->inline(['title' => 'Root', '$ref' => '#/$defs/Node', '$defs' => ['Node' => $node]]), [
            ...$node,
            'title' => 'Root',
            '$defs' => ['Node' => $node],
        ]);
    }

    public function dataThatLooksLikeAReferenceIsData(): void
    {
        $data = ['$ref' => '#/$defs/A'];

        Assert::same(
            (new LocalDefinitions())->inline(['default' => $data, 'const' => $data, 'enum' => [$data], 'example' => $data, 'examples' => [$data], '$defs' => ['A' => ['type' => 'string']]]),
            ['default' => $data, 'const' => $data, 'enum' => [$data], 'example' => $data, 'examples' => [$data]],
        );
    }

    public function anUnknownDefinitionFails(): void
    {
        $caught = null;

        try {
            (new LocalDefinitions())->inline(['$ref' => '#/$defs/A', '$defs' => ['B' => []]]);
        } catch (InvalidSpecException $caught) {
        }

        Assert::notNull($caught);
        Assert::same($caught->getMessage(), 'Unresolvable $ref "#/$defs/A" in a compiled schema');
    }

    public function theNodeBudgetIsExactAndSharedByTheSchemasOfAnOperation(): void
    {
        $definitions = new LocalDefinitions();
        // one root and 99_999 children: exactly the budget
        $schema = ['anyOf' => array_fill(0, 99_998, ['type' => 'string']), '$defs' => []];

        Assert::same(count($definitions->inline($schema)['anyOf']), 99_998);

        Expect::exception(InvalidSpecException::class);

        $definitions->inline(['type' => 'string', '$defs' => []]);
    }

    public function recursiveDefinitionsMoveToTheRootOfTheToolSchema(): void
    {
        $node = ['properties' => ['child' => ['$ref' => '#/$defs/Node']]];
        $parameter = ['in' => 'query', 'required' => false, 'description' => '', 'style' => 'form', 'explode' => true, 'allowReserved' => false];
        $schema = (new InputSchemaBuilder())->build(new Operation(
            operationId: 'op',
            method: 'POST',
            path: '/x',
            description: '',
            parameters: [['name' => 'q', 'schema' => ['type' => 'string', '$defs' => ['Other' => ['type' => 'string']]]] + $parameter],
            requestBodySchema: ['type' => 'object', 'properties' => ['root' => $node], '$defs' => ['Node' => $node]],
            requestBodyRequired: true,
        ));

        Assert::same($schema['properties']['body'], ['type' => 'object', 'properties' => ['root' => $node]]);
        Assert::same($schema['$defs'] ?? null, ['Other' => ['type' => 'string'], 'Node' => $node]);
    }

    /**
     * @param array<array-key, mixed> $node
     * @param array<array-key, mixed> $defs
     */
    private function assertResolvable(array $node, array $defs): void
    {
        foreach ($node as $key => $value) {
            if ($key === '$ref') {
                Assert::true(isset($defs[substr((string) $value, 8)]));
            } elseif (is_array($value)) {
                $this->assertResolvable($value, $defs);
            }
        }
    }
}
