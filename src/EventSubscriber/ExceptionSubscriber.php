<?php

declare(strict_types=1);

namespace LiquidRazor\DtoApiBundle\EventSubscriber;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Serializer\Exception\ExceptionInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Throwable;

final readonly class ExceptionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private LoggerInterface     $logger,
        private SerializerInterface $serializer,
        private bool                $debug = false,
    ) {}

    public static function getSubscribedEvents(): array
    {
        // Run fairly early so other listeners can still override if needed
        return [ KernelEvents::EXCEPTION => ['onException', 50] ];
    }

    /**
     * @throws ExceptionInterface
     */
    public function onException(ExceptionEvent $event): void
    {
        $e   = $event->getThrowable();
        $req = $event->getRequest();

        // 1) Decide status
        $status = $this->resolveStatusCode($e);

        // 2) Log everything relevant
        $meta = (array) $req->attributes->get('_dtoapi.meta', []);
        $this->logger->error('Caught exception', [
            'exception'  => $e::class,
            'message'    => $e->getMessage(),
            'file'       => $e->getFile() . ':' . $e->getLine(),
            'code'       => $e->getCode(),
            'status'     => $status,
            'path'       => $req->getPathInfo(),
            'operation'  => ($meta['tag'] ?? null) . ' ' . ($meta['summary'] ?? ($meta['controller'] ?? '') . '::' . ($meta['method'] ?? '')),
        ]);

        // 3) Try to find a matching response mapping on this operation
        $mapping = $this->pickMappingForStatus($meta['responses'] ?? [], $status)
            ?? $this->pickMappingForStatus($meta['responses'] ?? [], 500);

        $req->attributes->set('_dtoapi.response_selected', [
            'status'      => $status,
            'contentType' => $mapping['contentType'] ?? 'application/json',
            'stream'      => (bool)($mapping['stream'] ?? false),
            'class'       => $mapping['class'] ?? null,
            'source'      => 'exception',
        ]);

        // 4) Build an error payload (array) we can denormalize OR just serialize directly
        $payload = $this->buildErrorPayload($e, $status, $req->getRequestUri());

        // 5) If mapping has a DTO class, attempt to denormalize into it
        $contentType = $mapping['contentType'] ?? 'application/json';
        $format      = 'json'; // keep JSON for now
        if (is_array($mapping) && !empty($mapping['class'])) {
            try {
                // Best effort: denormalize an array into the declared error DTO class
                $dto = $this->serializer->denormalize($payload, $mapping['class'], $format);
                $body = $this->serializer->serialize($dto, $format);
                $event->setResponse(new Response($body, $status, ['Content-Type' => $contentType]));
                return;
            } catch (Throwable $runtimeException) {
                // fall back to generic JSON below
                $this->logger->warning('Failed to denormalize error payload into declared class', [
                    'target' => $mapping['class'],
                    'error'  => $runtimeException->getMessage(),
                ]);
            }
        }

        // 6) Generic JSON fallback
        $body = $this->serializer->serialize($payload, $format);
        $event->setResponse(new Response($body, $status, ['Content-Type' => 'application/json']));
    }

    /**
     * @param array<int,array<string,mixed>> $responses
     */
    private function pickMappingForStatus(array $responses, int $want): ?array
    {
        // exact match first
        foreach ($responses as $r) {
            if ((int) ($r['status'] ?? 0) === $want) {
                return $r;
            }
        }
        $wantHundreds = intdiv($want, 100);
        foreach ($responses as $r) {
            $st = (int) ($r['status'] ?? 0);
            if ($st !== 0 && intdiv($st, 100) === $wantHundreds) {
                return $r;
            }
        }
        return null;
    }

    /**
     * Maps well-known exception types to their correct HTTP status codes.
     *
     * Symfony's security exceptions ({@see \Symfony\Component\Security\Core\Exception\AccessDeniedException},
     * {@see \Symfony\Component\Security\Core\Exception\AuthenticationException}) do not implement
     * {@see HttpExceptionInterface}, so a plain `instanceof` check would misclassify them as 500.
     *
     * The FQCN strings are used intentionally so that this bundle works without
     * a hard dependency on {@code symfony/security-core}.
     */
    private function resolveStatusCode(Throwable $e): int
    {
        if ($e instanceof HttpExceptionInterface) {
            return $e->getStatusCode();
        }

        // is_a() with string FQCNs so this bundle works without symfony/security-core.
        if (is_a($e, 'Symfony\Component\Security\Core\Exception\AccessDeniedException')) {
            return Response::HTTP_FORBIDDEN;
        }

        if (is_a($e, 'Symfony\Component\Security\Core\Exception\AuthenticationException')) {
            return Response::HTTP_UNAUTHORIZED;
        }

        return 500;
    }

    private function buildErrorPayload(Throwable $e, int $status, string $uri): array
    {
        // Hide details for 5xx; show a message for 4xx (safe client error)
        $isServer = $status >= 500;

        // The exception class + code are a debugging aid only. Exposing them to
        // clients in production leaks internal implementation detail, so the hint
        // is gated behind kernel.debug. The `errors` key is always present (null
        // when withheld) so the response shape stays stable across environments.
        $exposeHint = $this->debug && !$isServer;

        return [
            'type'   => $isServer ? 'Internal server error' : ('https://httpstatuses.com/' . $status),
            'message'  => $isServer ? 'Something went wrong' : ($e->getMessage() ?: 'Request error'),
            'status' => $status,
            'detail' => $isServer ? null : $e->getMessage(),
            'instance' => $uri,
            'errors' => $exposeHint ? [
                'exception' => $e::class,
                'code'      => $e->getCode(),
            ] : null,
        ];
    }
}
