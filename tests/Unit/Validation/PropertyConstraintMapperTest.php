<?php

declare(strict_types=1);

namespace LiquidRazor\DtoApiBundle\Tests\Unit\Validation;

use LiquidRazor\DtoApiBundle\Lib\Attributes\DtoApiProperty;
use LiquidRazor\DtoApiBundle\Validation\DtoApiConstraintLoader;
use LiquidRazor\DtoApiBundle\Validation\PropertyConstraintMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validation;

final readonly class PropertyConstraintMapperCriteriaInput
{
    public function __construct(
        #[Assert\Positive]
        public int $option,
    ) {
    }
}

final readonly class PropertyConstraintMapperCriteriaRequest
{
    /**
     * @param list<array{option: int}> $criteria
     */
    public function __construct(
        #[DtoApiProperty(type: 'array', itemsRef: PropertyConstraintMapperCriteriaInput::class)]
        public array $criteria,
    ) {
    }
}

#[CoversClass(PropertyConstraintMapper::class)]
final class PropertyConstraintMapperTest extends TestCase
{
    public function testItemsRefValidationSupportsRawArrayPayloadsWithoutNestedValidConstraint(): void
    {
        $validator = Validation::createValidatorBuilder()
            ->addLoader(new DtoApiConstraintLoader(new PropertyConstraintMapper()))
            ->getValidator();

        $violations = $validator->validate(new PropertyConstraintMapperCriteriaRequest([['option' => 1]]));

        self::assertCount(0, $violations);
    }
}
