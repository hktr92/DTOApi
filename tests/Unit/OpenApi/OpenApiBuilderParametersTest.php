<?php

declare(strict_types=1);

namespace LiquidRazor\DtoApiBundle\Tests\Unit\OpenApi;

use LiquidRazor\DtoApiBundle\Lib\Response\ResponseMappingResolver;
use LiquidRazor\DtoApiBundle\OpenApi\OpenApiBuilder;
use LiquidRazor\DtoApiBundle\OpenApi\Schema\DtoSchemaFactory;
use LiquidRazor\DtoApiBundle\OpenApi\Schema\DtoSchemaRegistry;
use LiquidRazor\DtoApiBundle\Tests\Fixtures\ListThingsController;
use LiquidRazor\DtoApiBundle\Tests\Fixtures\ViewThingController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;

#[CoversClass(OpenApiBuilder::class)]
final class OpenApiBuilderParametersTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function buildDocument(): array
    {
        $collection = new RouteCollection();
        $collection->add('view', new Route(
            '/things/{id}',
            ['_controller' => ViewThingController::class],
            ['id' => '\d+'],
            [],
            '',
            [],
            ['GET'],
        ));
        $collection->add('list', new Route(
            '/things',
            ['_controller' => ListThingsController::class],
            [],
            [],
            '',
            [],
            ['GET'],
        ));

        $router = $this->createStub(RouterInterface::class);
        $router->method('getRouteCollection')->willReturn($collection);

        $factory = new DtoSchemaFactory();
        $builder = new OpenApiBuilder(
            $router,
            $factory,
            new DtoSchemaRegistry($factory),
            new ResponseMappingResolver([]),
        );

        // Normalise the mixed array/stdClass graph to plain arrays for assertions.
        return json_decode(json_encode($builder->build()), true);
    }

    public function testPathPlaceholderBecomesIntegerPathParameter(): void
    {
        $doc = $this->buildDocument();
        $parameters = $doc['paths']['/things/{id}']['get']['parameters'];

        self::assertCount(1, $parameters);
        self::assertSame('id', $parameters[0]['name']);
        self::assertSame('path', $parameters[0]['in']);
        self::assertTrue($parameters[0]['required']);
        // `\d+` requirement infers integer.
        self::assertSame('integer', $parameters[0]['schema']['type']);
    }

    public function testMapQueryStringDtoBecomesQueryParameters(): void
    {
        $doc = $this->buildDocument();
        $parameters = $doc['paths']['/things']['get']['parameters'];

        $byName = [];
        foreach ($parameters as $parameter) {
            $byName[$parameter['name']] = $parameter;
        }

        self::assertArrayHasKey('limit', $byName);
        self::assertSame('query', $byName['limit']['in']);
        self::assertSame('integer', $byName['limit']['schema']['type']);
        self::assertSame(25, $byName['limit']['schema']['default']);
        // Defaulted scalar → optional.
        self::assertFalse($byName['limit']['required']);

        // Backed enum surfaces its case values + default.
        self::assertSame('string', $byName['orderDir']['schema']['type']);
        self::assertSame(['asc', 'desc'], $byName['orderDir']['schema']['enum']);
        self::assertSame('desc', $byName['orderDir']['schema']['default']);

        // Nullable + defaulted → optional string.
        self::assertSame('string', $byName['search']['schema']['type']);
        self::assertFalse($byName['search']['required']);
    }
}
