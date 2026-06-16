<?php

declare(strict_types=1);

namespace LiquidRazor\DtoApiBundle\Tests\Unit\OpenApi;

use LiquidRazor\DtoApiBundle\OpenApi\TypeScript\TsTypeGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TsTypeGenerator::class)]
final class TsTypeGeneratorTest extends TestCase
{
    private function generate(array $schemas): string
    {
        return (new TsTypeGenerator())->generate([
            'components' => ['schemas' => $schemas],
        ]);
    }

    public function testNullableWrapperWrapsAndImportsWhenUsed(): void
    {
        $ts = (new TsTypeGenerator())->generate([
            'components' => ['schemas' => [
                'Foo' => [
                    'type' => 'object',
                    'properties' => [
                        'note' => ['type' => ['null', 'string']],
                        'tags' => ['type' => 'array', 'items' => ['type' => ['null', 'string']]],
                        'id' => ['type' => 'integer'],
                    ],
                    'required' => ['note', 'tags', 'id'],
                ],
            ]],
        ], 'Option', '../shared.types');

        self::assertStringContainsString("import type { Option } from '../shared.types';", $ts);
        self::assertStringContainsString('note: Option<string>;', $ts);
        // Nullable array items wrap too.
        self::assertStringContainsString('tags: Option<string>[];', $ts);
        // Non-nullable stays bare.
        self::assertStringContainsString('id: number;', $ts);
        self::assertStringNotContainsString('| null', $ts);
    }

    public function testNullableWrapperImportOmittedWhenUnused(): void
    {
        $ts = (new TsTypeGenerator())->generate([
            'components' => ['schemas' => [
                'Foo' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']], 'required' => ['id']],
            ]],
        ], 'Option', '../shared.types');

        // No nullable property → no wrapper usage → no import line.
        self::assertStringNotContainsString('import type', $ts);
    }

    public function testEmitsHeaderAndOneTypePerSchema(): void
    {
        $ts = $this->generate([
            'Bar' => ['type' => 'object', 'properties' => ['x' => ['type' => 'integer']], 'required' => ['x']],
        ]);

        self::assertStringContainsString('AUTO-GENERATED', $ts);
        self::assertStringContainsString('export type Bar = {', $ts);
        self::assertStringContainsString('x: number;', $ts);
    }

    public function testScalarMappingAndRequiredVsOptional(): void
    {
        $ts = $this->generate([
            'Foo' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'title' => ['type' => 'string'],
                    'active' => ['type' => 'boolean'],
                    'ratio' => ['type' => 'number'],
                ],
                'required' => ['id', 'title'],
            ],
        ]);

        // Required → no `?`; not required → `?`.
        self::assertStringContainsString('id: number;', $ts);
        self::assertStringContainsString('title: string;', $ts);
        self::assertStringContainsString('active?: boolean;', $ts);
        self::assertStringContainsString('ratio?: number;', $ts);
    }

    public function testNullableUnionAndNullableFallback(): void
    {
        $ts = $this->generate([
            'Foo' => [
                'type' => 'object',
                'properties' => [
                    // OpenAPI 3.1 nullable union form.
                    'note' => ['type' => ['null', 'string']],
                    // Pre-3.1 `nullable: true` fallback.
                    'legacy' => ['type' => 'string', 'nullable' => true],
                ],
                'required' => ['note', 'legacy'],
            ],
        ]);

        self::assertStringContainsString('note: string | null;', $ts);
        self::assertStringContainsString('legacy: string | null;', $ts);
    }

    public function testRefAndArrays(): void
    {
        $ts = $this->generate([
            'Foo' => [
                'type' => 'object',
                'properties' => [
                    'bar' => ['$ref' => '#/components/schemas/Bar'],
                    'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'bars' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Bar']],
                ],
                'required' => ['bar', 'tags', 'bars'],
            ],
        ]);

        self::assertStringContainsString('bar: Bar;', $ts);
        self::assertStringContainsString('tags: string[];', $ts);
        self::assertStringContainsString('bars: Bar[];', $ts);
    }

    public function testEnumLiteralUnionForStringAndInt(): void
    {
        $ts = $this->generate([
            'Foo' => [
                'type' => 'object',
                'properties' => [
                    'dir' => ['type' => 'string', 'enum' => ['asc', 'desc']],
                    'level' => ['type' => 'integer', 'enum' => [1, 2, 3]],
                ],
                'required' => ['dir', 'level'],
            ],
        ]);

        self::assertStringContainsString("dir: 'asc' | 'desc';", $ts);
        self::assertStringContainsString('level: 1 | 2 | 3;', $ts);
    }

    public function testObjectFallbackAndQuotedKeys(): void
    {
        $ts = $this->generate([
            'Foo' => [
                'type' => 'object',
                'properties' => [
                    'meta' => ['type' => 'object'],
                    // Kebab-case wire name is not a bare TS identifier.
                    'x-total' => ['type' => 'integer'],
                ],
                'required' => ['meta', 'x-total'],
            ],
        ]);

        self::assertStringContainsString('meta: Record<string, unknown>;', $ts);
        // Non-identifier wire names are quoted; snake_case (a valid identifier) is not.
        self::assertStringContainsString("'x-total': number;", $ts);
    }

    public function testEmptyObjectSchema(): void
    {
        $ts = $this->generate([
            'Empty' => ['type' => 'object', 'properties' => []],
        ]);

        self::assertStringContainsString('export type Empty = Record<string, never>;', $ts);
    }
}
