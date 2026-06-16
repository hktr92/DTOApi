<?php

declare(strict_types=1);

namespace LiquidRazor\DtoApiBundle\Tests\Fixtures;

use LiquidRazor\DtoApiBundle\Lib\Attributes\DtoApiOperation;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;

final class ListThingsController
{
    #[DtoApiOperation(summary: 'List things', tag: 'Test')]
    public function __invoke(
        #[MapQueryString] ListThingsQuery $query = new ListThingsQuery(),
    ): array {
        return [];
    }
}
