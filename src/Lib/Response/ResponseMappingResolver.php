<?php
// src/Lib/Response/ResponseMappingResolver.php
declare(strict_types=1);

namespace LiquidRazor\DtoApiBundle\Lib\Response;

use LiquidRazor\DtoApiBundle\Lib\Attributes\DtoApiResponse;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionException;

final readonly class ResponseMappingResolver
{
    public function __construct(
        private array $globalDefaults = [] // injected from param
    ) {}


    /**
     * @param array<int, array{status:int,class:string|null,contentType:string|null,stream:bool|null,name:string|null,description:string|null}> $methodLevel
     * @param array<int, class-string> $operationResponses
     * @return array<int, array{status:int,class:string|null,contentType:string,stream:bool,name:string|null,description:string|null}>
     * @throws ReflectionException
     */
    public function resolve(array $methodLevel, array $operationResponses): array
    {
        $out = [];

        foreach ($methodLevel as $m) {
            if($m instanceof DtoApiResponse) {
                $m = [
                    'status'      => $m->status,
                    'class'       => $m->class,
                    'contentType' => $m->contentType,
                    'stream'      => $m->stream,
                    'name'        => $m->name,
                    'description' => $m->description,
                ];
            }
            $stream = (bool)($m['stream'] ?? false);
            $contentType = $m['contentType'] ?? ($stream ? 'application/x-ndjson' : 'application/json');

            $out[(int)$m['status']] = [
                'status'      => (int)$m['status'],
                'class'       => $m['class'] ?? null,
                'contentType' => $contentType,
                'stream'      => $stream,
                'name'        => $m['name'] ?? null,
                'description' => $m['description'] ?? null,
            ];
        }

        // 2) operation-level classes → read class-level attributes
        foreach ($operationResponses as $fqcn) {
            if (!is_string($fqcn) || !class_exists($fqcn)) { continue; }
            $meta = $this->readClassResponseMeta($fqcn);
            if ($meta === null) { // default to 200 if the class forgot to annotate
                $meta = [
                    'status' => 200,
                    'class' => $fqcn,
                    'contentType' => 'application/json',
                    'stream' => false,
                    'name' => null,
                    'description' => null,
                ];
            }
            // don’t override an existing method-level mapping for the same status
            $out[$meta['status']] ??= $meta;
        }

        // 3. inject global defaults if status not present
        foreach ($this->globalDefaults as $status => $def) {
            if (!isset($out[$status])) {
                $out[$status] = [
                    'status'      => (int) $status,
                    'class'       => $def['class'],
                    'contentType' => $def['contentType'] ?? 'application/json',
                    'stream'      => (bool)($def['stream'] ?? false),
                    'name'        => null,
                    'description' => $def['description'] ?? null,
                ];
            }
        }

        ksort($out); // nice ordering
        return array_values($out);
    }

    /**
     * @return array{status:int,class:string|null,contentType:string,stream:bool,name:string|null,description:string|null}|null
     * @throws ReflectionException
     */
    private function readClassResponseMeta(string $fqcn): ?array
    {
        $rc = new ReflectionClass($fqcn);
        $attr = $rc->getAttributes(DtoApiResponse::class, ReflectionAttribute::IS_INSTANCEOF)[0] ?? null;
        if (!$attr) return null;
        /** @var DtoApiResponse $r */
        $r = $attr->newInstance();

        $stream = (bool)($r->stream ?? false);
        return [
            'status'      => ($r->status ?? 200),
            'class'       => $fqcn,
            'contentType' => $r->contentType ?? ($stream ? 'application/x-ndjson' : 'application/json'),
            'stream'      => $stream,
            'name'        => $r->name,
            'description' => $r->description,
        ];
    }
}
