<?php

declare(strict_types=1);

namespace LiquidRazor\DtoApiBundle\OpenApi;

use JsonException;
use LiquidRazor\DtoApiBundle\Lib\Attributes\{DtoApi, DtoApiOperation, DtoApiRequest, DtoApiResponse};
use LiquidRazor\DtoApiBundle\Lib\Response\ResponseMappingResolver;
use LiquidRazor\DtoApiBundle\OpenApi\Schema\{DtoSchemaFactory, DtoSchemaRegistry};
use BackedEnum;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionEnum;
use ReflectionException;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouterInterface;

use function preg_match_all;
use function str_contains;

final readonly class OpenApiBuilder
{
    public function __construct(
        private RouterInterface         $router,
        private DtoSchemaFactory        $schemas,
        private DtoSchemaRegistry       $registry,
        private ResponseMappingResolver $responseMappingResolver,
        private string                  $title = 'API',
        private string                  $version = '0.1.0',
    )
    {
    }

    /** Build the full OpenAPI 3.1 document as an array
     * @throws ReflectionException|JsonException
     */
    public function build(): array
    {
        $paths = [];
        $components = ['schemas' => (object)[]];
        $tags = [];

        foreach ($this->router->getRouteCollection() as $routeName => $route) {
            $controller = $route->getDefault('_controller') ?? null;
            if (!$controller || !is_string($controller)) continue;

            [$class, $method] = $this->parseCallable($controller);
            if (!$class || !$method) continue;
            try {
                $rc = new ReflectionClass($class);
            } catch (ReflectionException) {
                continue;
            }
            if (!$rc->isInstantiable()) continue;
            if (!$rc->hasMethod($method)) continue;
            $rm = $rc->getMethod($method);

            $opAttr = $rm->getAttributes(DtoApiOperation::class, ReflectionAttribute::IS_INSTANCEOF)[0] ?? null;
            if (!$opAttr) continue; // not a DtoApi operation

            /** @var DtoApiOperation $op */
            $op = $opAttr->newInstance();
            $path = $this->toOpenApiPath($route);

            $httpMethods = $route->getMethods() ?: ['GET'];
            $multiMethod = count($httpMethods) > 1;
            foreach ($httpMethods as $http) {
                $http = strtolower($http);
                // operationId reuses the (unique) route name. A route is unique
                // within a RouteCollection, so this is collision-free by
                // construction — unlike the short class name, which repeats
                // across modules under one-route-one-controller. When a single
                // route declares more than one HTTP method, the method is
                // suffixed so the two operations under one path stay distinct.
                $operationId = $multiMethod ? $routeName . '_' . $http : $routeName;
                $operation = $this->operationObject($rc, $rm, $op, $route, $operationId, $components, $tags);
                $paths[$path][$http] = $operation;
            }
        }

        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => $this->title,
                'version' => $this->version,
            ],
            'paths' => $this->objectify($paths),
            'components' => ['schemas' => $this->registry->export()],
//            'components' => $components,
            'tags' => array_values($tags),
        ];
    }

    private
    function parseCallable(string $controller): array
    {
        // Formats: 'App\Controller\X::method' or invokable 'App\Controller\X'
        if (str_contains($controller, '::')) {
            return explode('::', $controller, 2);
        }
        if (class_exists($controller)) {
            return [$controller, '__invoke'];
        }
        return [null, null];
    }

    private
    function toOpenApiPath(Route $route): string
    {
        // Convert /users/{id} style – Symfony routes already use {}
        $path = $route->getPath();
        return $path === '' ? '/' : $path;
    }

    /**
     * @throws ReflectionException
     * @throws JsonException
     */
    private function operationObject(ReflectionClass $rc, ReflectionMethod $rm, DtoApiOperation $op, Route $route, string $operationId, array &$components, array &$tags): array
    {
        // Tags: prefer DtoApi on class, else attribute tag, else short class
        $classMeta = ($rc->getAttributes(DtoApi::class)[0] ?? null)?->newInstance();
        $tagName = $op->tag ?? $classMeta?->name ?? $rc->getShortName();

        $tags[$tagName] ??= ['name' => $tagName];

        $out = [
            'operationId' => $operationId,
            'summary' => $op->summary,
            'description' => $op->description,
            'tags' => [$tagName]
        ];

        // Path placeholders + #[MapQueryString] query DTO -> parameters
        $parameters = $this->buildParameters($route, $rm);
        if ($parameters !== []) {
            $out['parameters'] = $parameters;
        }

        // Request body
        if (is_string($op->request) && class_exists($op->request)) {
            $this->registry->ensure($op->request);
            $schemaName = $this->schemas->schemaName($op->request);
            $components['schemas']->{$schemaName} = $this->schemas->build($op->request);
            // default content type
            $contentType = 'application/json';
            $reqMeta = (new ReflectionClass($op->request))->getAttributes(DtoApiRequest::class)[0] ?? null;
            if ($reqMeta) {
                $ct = $reqMeta->newInstance()->contentType ?? null;
                if ($ct) {
                    $contentType = $ct;
                }
            }
            $out['requestBody'] = [
                'required' => true, // you can refine by checking properties marked required
                'content' => [
                    $contentType => [
                        'schema' => ['$ref' => '#/components/schemas/' . $schemaName]
                    ]
                ]
            ];
        }

        $methodLevel = array_map(
            static fn($a) => $a->newInstance(),
            $rm->getAttributes(DtoApiResponse::class, ReflectionAttribute::IS_INSTANCEOF)
        );
        $opLevel = is_array($op->response) ? $op->response : (array)$op->response;

        $responses = $this->responseMappingResolver->resolve($methodLevel, $opLevel);

        $respObj = [];
        foreach ($responses as $r) {
            $r = is_array($r) ? $r : (array)$r;

            $status = (string)$r['status'];
            $desc = $r['description'] ?? ($r['name'] ?? '');
            if (!$desc) {
                $desc = Response::$statusTexts[$status] ?? 'Response ' . $status;
            }

            $resp = ['description' => $desc];

            $mappingClass = $r['class'] ?? null;
            $contentType = $r['contentType'] ?? 'application/json';
            $isStream = !empty($r['stream']);

            if ($isStream && $contentType === 'application/json') {
                $contentType = 'application/x-ndjson';
            }

            if ($mappingClass) {
                $this->registry->ensure($mappingClass);
                $schemaName = $this->schemas->schemaName($mappingClass);
                $components['schemas']->{$schemaName} ??= $this->schemas->build($mappingClass);

                $resp['content'][$contentType] = [
                    'schema' => ['$ref' => '#/components/schemas/' . $schemaName]
                ];
            } elseif ($status !== '204' && $status !== '202') {
                // If class is null but it's not a "no content" status, we might still want to emit a schema
                // if a contentType is declared.
                if ($contentType) {
                    $resp['content'][$contentType] = [
                        'schema' => ['type' => 'object'] // Simple fallback
                    ];
                }
            }

            $respObj[$status] = $resp;
        }

        $out['responses'] = $this->objectify($respObj);
        return $out;
    }

    /**
     * Build the OpenAPI `parameters` list for an operation: one entry per path
     * `{placeholder}`, plus the query string DTO bound via #[MapQueryString].
     *
     * Path params come first; query params follow. Path placeholders win on a
     * name clash (a query DTO property never shadows a path segment).
     *
     * @return list<array<string, mixed>>
     */
    private function buildParameters(Route $route, ReflectionMethod $rm): array
    {
        $parameters = $this->pathParameters($route);

        $seen = [];
        foreach ($parameters as $parameter) {
            $seen[$parameter['name']] = true;
        }

        foreach ($this->queryParameters($rm) as $parameter) {
            if (isset($seen[$parameter['name']])) {
                continue;
            }
            $parameters[] = $parameter;
        }

        return $parameters;
    }

    /**
     * One `{name, in: path, required: true, schema}` entry per `{placeholder}`
     * in the route path. Type is inferred from the route requirement: a numeric
     * requirement (`\d+` / `[0-9]+`) yields `integer`, everything else `string`.
     *
     * @return list<array<string, mixed>>
     */
    private function pathParameters(Route $route): array
    {
        if (!preg_match_all('/\{([^}]+)\}/', $route->getPath(), $matches)) {
            return [];
        }

        $requirements = $route->getRequirements();
        $parameters = [];
        foreach ($matches[1] as $name) {
            $requirement = $requirements[$name] ?? null;
            $type = ($requirement !== null && (str_contains($requirement, '\d') || str_contains($requirement, '[0-9]')))
                ? 'integer'
                : 'string';

            $parameters[] = [
                'name' => $name,
                'in' => 'path',
                'required' => true,
                'schema' => ['type' => $type],
            ];
        }

        return $parameters;
    }

    /**
     * Reflect the #[MapQueryString] DTO bound to the controller method into
     * `{name, in: query, required, schema}` entries — one per constructor
     * promoted property. Returns `[]` when the method binds no query DTO.
     *
     * @return list<array<string, mixed>>
     */
    private function queryParameters(ReflectionMethod $rm): array
    {
        foreach ($rm->getParameters() as $parameter) {
            if ($parameter->getAttributes(MapQueryString::class) === []) {
                continue;
            }

            $type = $parameter->getType();
            if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                return [];
            }

            $dto = $type->getName();
            if (!class_exists($dto)) {
                return [];
            }

            return $this->queryParametersForDto($dto);
        }

        return [];
    }

    /**
     * @param class-string $dto
     *
     * @return list<array<string, mixed>>
     * @throws ReflectionException
     */
    private function queryParametersForDto(string $dto): array
    {
        $constructor = (new ReflectionClass($dto))->getConstructor();
        if ($constructor === null) {
            return [];
        }

        $parameters = [];
        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            if (!$type instanceof ReflectionNamedType) {
                continue;
            }

            // Required only when the value is neither nullable nor defaulted.
            $required = !$type->allowsNull() && !$parameter->isDefaultValueAvailable();

            $parameters[] = [
                'name' => $parameter->getName(),
                'in' => 'query',
                'required' => $required,
                'schema' => $this->queryParameterSchema($type, $parameter),
            ];
        }

        return $parameters;
    }

    /**
     * @return array<string, mixed>
     * @throws ReflectionException
     */
    private function queryParameterSchema(ReflectionNamedType $type, ReflectionParameter $parameter): array
    {
        $name = $type->getName();

        $schema = match (true) {
            $name === 'int' => ['type' => 'integer'],
            $name === 'float' => ['type' => 'number'],
            $name === 'bool' => ['type' => 'boolean'],
            $name === 'array' => ['type' => 'array', 'items' => ['type' => 'string']],
            enum_exists($name) => $this->enumSchema($name),
            default => ['type' => 'string'],
        };

        if ($parameter->isDefaultValueAvailable()) {
            $default = $parameter->getDefaultValue();
            if ($default instanceof BackedEnum) {
                $schema['default'] = $default->value;
            } elseif ($default !== null && !is_array($default)) {
                $schema['default'] = $default;
            }
        }

        return $schema;
    }

    /**
     * @param class-string $enum
     *
     * @return array<string, mixed>
     * @throws ReflectionException
     */
    private function enumSchema(string $enum): array
    {
        $reflection = new ReflectionEnum($enum);
        $backingType = $reflection->getBackingType();
        $type = ($backingType instanceof ReflectionNamedType && $backingType->getName() === 'int')
            ? 'integer'
            : 'string';

        $values = [];
        foreach ($reflection->getCases() as $case) {
            $instance = $case->getValue();
            if ($instance instanceof BackedEnum) {
                $values[] = $instance->value;
            }
        }

        return ['type' => $type, 'enum' => $values];
    }

    /**
     * @throws JsonException
     */
    private function objectify(array $arr): object
    {
        // Convert associative arrays to stdClass for cleaner JSON output. The
        // (object) cast guards the empty case: an empty array round-trips to `[]`
        // (an array, not an object), so an operation with no responses or a
        // document with no paths would otherwise violate the object return type
        // and, in JSON, render `[]` where OpenAPI requires `{}`.
        return (object) json_decode(json_encode($arr, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), false, 512, JSON_THROW_ON_ERROR);
    }
}
