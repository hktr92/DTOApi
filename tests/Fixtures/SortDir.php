<?php

declare(strict_types=1);

namespace LiquidRazor\DtoApiBundle\Tests\Fixtures;

enum SortDir: string
{
    case Asc = 'asc';
    case Desc = 'desc';
}
