<?php

declare(strict_types=1);

namespace Rasuvaeff\Mcp\OpenApi;

use Rasuvaeff\Mcp\OpenApi\Exception\InvalidSpecException;

/**
 * A compiled schema keeps shared components under its own `$defs`. A tool
 * schema is assembled from several of them and is read by clients that do not
 * all follow references, so every definition is inlined where it is used; only
 * a recursive one stays a `$ref`, with its definition at the schema root.
 *
 * Not readonly: the node budget is shared by every schema of one operation.
 *
 * @internal
 */
final class LocalDefinitions
{
    private const string PREFIX = '#/$defs/';

    private const int MAX_NODES = 100_000;

    /** Keywords whose value is data, where a "$ref" member is not a reference. */
    private const array DATA = ['const', 'default', 'enum', 'example', 'examples'];

    private int $nodes = 0;

    /**
     * @param array<array-key, mixed> $schema
     *
     * @return array<array-key, mixed>
     */
    public function inline(array $schema): array
    {
        /** @var mixed $defs */
        $defs = $schema['$defs'] ?? null;

        if (!is_array($defs)) {
            return $schema;
        }

        unset($schema['$defs']);
        $recursive = [];
        $result = $this->walk($schema, $defs, [], $recursive);
        $kept = [];

        while (($missing = array_diff_key($recursive, $kept)) !== []) {
            foreach (array_keys($missing) as $name) {
                $kept[$name] = $this->walk($this->definition($defs, $name), $defs, [$name], $recursive);
            }
        }

        return $kept === [] ? $result : [...$result, '$defs' => $kept];
    }

    /**
     * @param array<array-key, mixed> $node
     * @param array<array-key, mixed> $defs
     * @param list<string> $open definitions being inlined around this node
     * @param array<string, true> $recursive
     *
     * @return array<array-key, mixed>
     */
    private function walk(array $node, array $defs, array $open, array &$recursive): array
    {
        if (++$this->nodes > self::MAX_NODES) {
            throw new InvalidSpecException(sprintf('OpenAPI operation exceeds the schema-inlining budget of %d nodes', self::MAX_NODES));
        }

        /** @var mixed $ref */
        $ref = $node['$ref'] ?? null;

        if (is_string($ref) && str_starts_with($ref, self::PREFIX)) {
            $name = str_replace(['~1', '~0'], ['/', '~'], substr($ref, strlen(self::PREFIX)));

            if (in_array($name, $open, strict: true)) {
                $recursive[$name] = true;

                return $node;
            }

            unset($node['$ref']);

            return $this->walk([...$this->definition($defs, $name), ...$node], $defs, [...$open, $name], $recursive);
        }

        /** @var mixed $value */
        foreach ($node as $key => $value) {
            if (is_array($value) && !in_array($key, self::DATA, strict: true)) {
                $node[$key] = $this->walk($value, $defs, $open, $recursive);
            }
        }

        return $node;
    }

    /**
     * @param array<array-key, mixed> $defs
     *
     * @return array<array-key, mixed>
     */
    private function definition(array $defs, string $name): array
    {
        /** @var mixed $definition */
        $definition = $defs[$name] ?? null;

        return is_array($definition) ? $definition : throw new InvalidSpecException(sprintf('Unresolvable $ref "%s%s" in a compiled schema', self::PREFIX, $name));
    }
}
