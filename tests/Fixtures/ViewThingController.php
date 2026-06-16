<?php

declare(strict_types=1);

namespace LiquidRazor\DtoApiBundle\Tests\Fixtures;

use LiquidRazor\DtoApiBundle\Lib\Attributes\DtoApiOperation;

final class ViewThingController
{
    #[DtoApiOperation(summary: 'View a thing', tag: 'Test')]
    public function __invoke(int $id): array
    {
        return ['id' => $id];
    }
}
