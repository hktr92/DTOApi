<?php

declare(strict_types=1);

namespace LiquidRazor\DtoApiBundle\EventSubscriber;

use LiquidRazor\DtoApiBundle\Lib\Attributes\DtoApi;
use LiquidRazor\DtoApiBundle\Lib\Attributes\DtoApiOperation;
use LiquidRazor\DtoApiBundle\Lib\Attributes\DtoApiRequest;
use LiquidRazor\DtoApiBundle\Lib\Attributes\DtoApiResponse;
use LiquidRazor\DtoApiBundle\Lib\Response\ResponseMappingResolver;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Throwable;

readonly class RequestDtoSubscriber implements EventSubscriberInterface
{

    public function __construct(
        private SerializerInterface     $serializer,
        private ValidatorInterface      $validator,
        private ResponseMappingResolver $responseMappingResolver,
    )
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::CONTROLLER => ['onController', 50]];
    }

    /**
     * @throws ReflectionException
     */
    public function onController(ControllerEvent $event): void
    {
        $controller = $event->getController();
        $obj = null;
        $method = null;

        if (is_array($controller)) {
            [$obj, $method] = $controller;
        } elseif (is_object($controller) && method_exists($controller, '__invoke')) {
            $obj = $controller;
            $method = '__invoke';
        } elseif (is_string($controller)) {
            if (str_contains($controller, '::')) {
                [$obj, $method] = explode('::', $controller);
            } else {
                $obj = $controller;
                $method = '__invoke';
            }
        }

        if (!$obj || !class_exists((string)(is_object($obj) ? $obj::class : $obj))) {
            error_log(sprintf('DTOApi: Skipping unsupported controller shape: %s', is_scalar($controller) ? $controller : gettype($controller)));
            return;
        }

        $rc = new ReflectionClass($obj);
        if (!$rc->hasMethod((string)$method)) {
            error_log(sprintf('DTOApi: Controller %s has no method %s', $rc->getName(), $method));
            return;
        }
        $rm = $rc->getMethod((string)$method);

        $opAttr = $rm->getAttributes(DtoApiOperation::class, ReflectionAttribute::IS_INSTANCEOF)[0] ?? null;
        if (!$opAttr) {
            return;
        }
        $op = $opAttr->newInstance();

        // collect method-level responses (for profiler; optional here)
        $responses = array_map(
            static fn($a) => $a->newInstance(),
            $rm->getAttributes(DtoApiResponse::class, ReflectionAttribute::IS_INSTANCEOF)
        );
        $req = $event->getRequest();

        $methodLevel = array_map(fn($a) => (array)$a, $responses);
        $opLevel = is_array($op->response) ? $op->response : (array)$op->response;

        $resolvedResponses = $this->responseMappingResolver->resolve($methodLevel, $opLevel);

        $req->attributes->set('_dtoapi.meta', [
            'controller' => $rc->getName(),
            'method' => $rm->getName(),
            'tag' => ($rc->getAttributes(DtoApi::class)[0] ?? null)?->newInstance()?->name
                ?? $op->tag ?? $rc->getShortName(),
            'summary' => $op->summary,
            'description' => $op->description,
            'request' => $op->request,
            'responses' => array_map(static fn($r) => [
                'class' => $r['class'],
                'status' => $r['status'],
                'contentType' => $r['contentType'],
                'stream' => $r['stream'],
                'name' => $r['name'] ?? null,
                'description' => $r['description'] ?? null,
            ], $resolvedResponses),
            'deprecated' => $op->deprecated,
        ]);

        // hydrate requests DTO here (so the ValueResolver can pick it up)
        if (is_string($op->request) && class_exists($op->request)) {
            $this->hydrateRequestDto($req, $op->request);
        }
        if($req->attributes->get('_dtoapi.request_invalid')) {
            $req->attributes->set('_dtoapi.response_selected', $this->selectResponseMapping($resolvedResponses, 422));
            $event->setController(fn() => new JsonResponse(
                $this->buildValidationErrorPayload($req->attributes->get('_dtoapi.request_violations')),
                422
            ));
        }
        if($req->attributes->get('_dtoapi.request_error')) {
            $req->attributes->set('_dtoapi.response_selected', $this->selectResponseMapping($resolvedResponses, 400));
            $event->setController(fn() => new JsonResponse(
                $this->buildRequestErrorPayload($req->attributes->get('_dtoapi.request_error')['message']),
                400
            ));
        }
    }

    private function hydrateRequestDto(Request $req, string $dtoClass): void
    {
        $content = $req->getContent();

        if ($content === '' && $req->getMethod() === Request::METHOD_GET) {
            $content = $req->query->all();
        }

        $format = $this->resolveFormat($req, $dtoClass);

        if ($format === 'form') {
            $content = $req->request->all();
        }

        try {
            $dto = match (true) {
                is_array($content) => $this->serializer->denormalize($content, $dtoClass, $format === 'form' ? null : $format),
                is_string($content) && $content !== '' => $this->serializer->deserialize($content, $dtoClass, $format),
                default => $this->serializer->denormalize([], $dtoClass, $format),
            };
            $validationErrors = $this->validator->validate($dto);

            if (count($validationErrors) > 0) {
                $req->attributes->set('_dtoapi.request_invalid', true);
                $req->attributes->set('_dtoapi.request_violations', iterator_to_array($validationErrors));

            }
            $req->attributes->set('_dtoapi.request_dto', $dto);
        } catch (Throwable $e) {
            $req->attributes->set('_dtoapi.request_error', [
                'class' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function resolveFormat(Request $req, string $dtoClass): string
    {
        // 1. Check DtoApiRequest attribute on the DTO class
        try {
            $rc = new ReflectionClass($dtoClass);
            $attr = $rc->getAttributes(DtoApiRequest::class, ReflectionAttribute::IS_INSTANCEOF)[0] ?? null;
            if ($attr) {
                /** @var DtoApiRequest $instance */
                $instance = $attr->newInstance();
                if ($instance->contentType) {
                    $format = $this->mapContentTypeToFormat($instance->contentType);
                    if ($format) {
                        return $format;
                    }
                }
            }
        } catch (ReflectionException) {
        }

        // 2. Check Request content type
        $contentType = $req->headers->get('Content-Type');
        if ($contentType) {
            $format = $this->mapContentTypeToFormat($contentType);
            if ($format) {
                return $format;
            }
        }

        // 3. Fallback to Request::getContentTypeFormat()
        return $req->getContentTypeFormat() ?? 'json';
    }

    private function mapContentTypeToFormat(string $contentType): ?string
    {
        $parts = explode(';', $contentType);
        $mimeType = strtolower(trim($parts[0]));

        return match ($mimeType) {
            'application/json' => 'json',
            'application/x-www-form-urlencoded' => 'form',
            'multipart/form-data' => 'form',
            default => null,
        };
    }

    private function buildValidationErrorPayload(iterable $validationErrors): array
    {
        return [
            'type' => 'Validation error',
            'title' => 'Invalid request body.',
            'status' => 422,
            'violations' => array_map(
                fn(ConstraintViolation $validationError) => [
                    'property' => $validationError->getPropertyPath(),
                    'message' => $validationError->getMessage()
                ],
                iterator_to_array($validationErrors)
            ),
        ];
    }

    private function buildRequestErrorPayload(?string $message): array
    {
        return [
            'type' => 'about:blank',
            'title' => 'Malformed request body.',
            'status' => 400,
            'detail' => $message ?? 'Unknown error',
        ];
    }

    private function selectResponseMapping(array $responses, int $status): array
    {
        foreach ($responses as $response) {
            if (($response['status'] ?? null) === $status) {
                return [
                    'status' => $response['status'],
                    'contentType' => $response['contentType'] ?? 'application/json',
                    'stream' => false,
                    'class' => null,
                    'source' => 'controller',
                ];
            }
        }

        return [
            'status' => $status,
            'contentType' => 'application/json',
            'stream' => false,
            'class' => null,
            'source' => 'controller',
        ];
    }
}
