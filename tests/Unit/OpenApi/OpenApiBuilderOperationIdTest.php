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
final class OpenApiBuilderOperationIdTest extends TestCase
{
    /**
     * @param array<string, array{string, string, list<string>}> $routes name => [path, controller, methods]
     *
     * @return array<string, mixed>
     */
    private function buildDocument(array $routes): array
    {
        $collection = new RouteCollection();
        foreach ($routes as $name => [$path, $controller, $methods]) {
            $collection->add($name, new Route($path, ['_controller' => $controller], [], [], '', [], $methods));
        }

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

    public function testOperationIdIsTheRouteName(): void
    {
        $doc = $this->buildDocument([
            'api.things.view' => ['/things/{id}', ViewThingController::class, ['GET']],
            'api.things.list' => ['/things', ListThingsController::class, ['GET']],
        ]);

        self::assertSame('api.things.view', $doc['paths']['/things/{id}']['get']['operationId']);
        self::assertSame('api.things.list', $doc['paths']['/things']['get']['operationId']);
    }

    /**
     * The real collision the route-name scheme fixes: under one-route-one-controller
     * every module ships a `ListController`, so the old `{ShortClass}::__invoke`
     * scheme produced duplicate operationIds. Two distinct named routes backed by the
     * SAME controller class must still get distinct, unique operationIds.
     */
    public function testSameControllerOnTwoRoutesGetsDistinctOperationIds(): void
    {
        $doc = $this->buildDocument([
            'incidents.list' => ['/api/incidents', ListThingsController::class, ['GET']],
            'risks.list' => ['/api/risks', ListThingsController::class, ['GET']],
        ]);

        $incidents = $doc['paths']['/api/incidents']['get']['operationId'];
        $risks = $doc['paths']['/api/risks']['get']['operationId'];

        self::assertSame('incidents.list', $incidents);
        self::assertSame('risks.list', $risks);
        self::assertNotSame($incidents, $risks);
    }

    public function testMultiMethodRouteSuffixesTheHttpMethod(): void
    {
        $doc = $this->buildDocument([
            'things.upsert' => ['/things', ViewThingController::class, ['GET', 'POST']],
        ]);

        self::assertSame('things.upsert_get', $doc['paths']['/things']['get']['operationId']);
        self::assertSame('things.upsert_post', $doc['paths']['/things']['post']['operationId']);
    }
}
