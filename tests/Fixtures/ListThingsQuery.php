<?php

declare(strict_types=1);

namespace LiquidRazor\DtoApiBundle\Tests\Fixtures;

final readonly class ListThingsQuery
{
    public function __construct(
        public int $limit = 25,
        public int $offset = 0,
        public SortDir $orderDir = SortDir::Desc,
        public ?string $search = null,
    ) {}
}
