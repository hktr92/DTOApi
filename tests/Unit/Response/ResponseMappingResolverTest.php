<?php

declare(strict_types=1);

namespace LiquidRazor\DtoApiBundle\Tests\Unit\Response;

use LiquidRazor\DtoApiBundle\Lib\Attributes\DtoApiResponse;
use LiquidRazor\DtoApiBundle\Lib\Response\ResponseMappingResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ResponseMappingResolver::class)]
final class ResponseMappingResolverTest extends TestCase
{
    public function testOperationResponseWithoutAttributeHasCompleteOptionalMetadata(): void
    {
        $responses = (new ResponseMappingResolver())->resolve([], [PlainResponseDto::class]);

        self::assertSame([[
            'status' => 200,
            'class' => PlainResponseDto::class,
            'contentType' => 'application/json',
            'stream' => false,
            'name' => null,
            'description' => null,
        ]], $responses);
    }

    public function testClassResponseAttributeMetadataIsPreserved(): void
    {
        $responses = (new ResponseMappingResolver())->resolve([], [AttributedResponseDto::class]);

        self::assertSame([[
            'status' => 201,
            'class' => AttributedResponseDto::class,
            'contentType' => 'application/json',
            'stream' => false,
            'name' => 'Preview',
            'description' => 'Credit schedule preview',
        ]], $responses);
    }

    public function testMethodLevelResponseOverridesOperationFallback(): void
    {
        $explicit = [
            'status' => 200,
            'class' => null,
            'contentType' => 'text/plain',
            'stream' => false,
            'name' => 'Explicit',
            'description' => 'Method-level response',
        ];

        $responses = (new ResponseMappingResolver())->resolve([$explicit], [PlainResponseDto::class]);

        self::assertSame([$explicit], $responses);
    }
}

final class PlainResponseDto
{
}

#[DtoApiResponse(name: 'Preview', description: 'Credit schedule preview', status: 201)]
final class AttributedResponseDto
{
}
