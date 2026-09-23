<?php

declare(strict_types=1);

namespace LiquidRazor\DtoApiBundle\Validation;

use LiquidRazor\DtoApiBundle\Lib\Attributes\DtoApiProperty;
use LiquidRazor\DtoApiBundle\Validation\Constraints\UniqueItems;
use ReflectionNamedType;
use ReflectionProperty;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Translates DtoApiProperty into Symfony Constraints.
 * Does NOT override native PHP-attribute constraints; core loads those.
 */
final class PropertyConstraintMapper
{
    /** @var iterable<ConstraintContributorInterface> */
    private iterable $contributors;

    public function __construct(iterable $contributors = [])
    {
        $this->contributors = $contributors;
    }

    /**
     * @return array<int, object> constraints
     */
    public function map(ReflectionProperty $rp, DtoApiProperty $meta): array
    {
        $constraints = [];

        // 0) TYPE
        array_push($constraints, ...$this->typeConstraints($rp, $meta));

        // 1) REQUIRED / NULLABLE semantics
        // Presence is best enforced earlier (request-hydration phase),
        // but we still add value-level guards here.
        $isString = $this->isString($rp, $meta);

        if ($meta->required === true && $meta->nullable !== true) {
            // Must be present and non-null; for strings, also not blank.
            $constraints[] = $isString ? new Assert\NotBlank() : new Assert\NotNull();
        } elseif ($meta->required === true && $meta->nullable === true) {
            // Must be present, null allowed → Assert\Optional is a Collection thing;
            // here we can only assert type if non-null.
            // No extra constraint; presence handled in the REQUEST subscriber.
        } elseif ($meta->nullable === false) {
            // Not required, but if provided, must be non-null.
            $constraints[] = new Assert\NotNull();
        }

        // 2) STRINGS
        if ($isString) {
            if ($meta->minLength !== null || $meta->maxLength !== null) {
                $constraints[] = new Assert\Length(min: $meta->minLength, max: $meta->maxLength);
            }
            if ($meta->pattern) {
                $constraints[] = new Assert\Regex('#' . $meta->pattern . '#u');
            }
            if ($meta->format) {
                $fc = $this->formatConstraint($meta->format);
                if ($fc) { $constraints[] = $fc; }
            }
        }

        // 3) NUMBERS / INTEGERS
        if ($this->isNumeric($rp, $meta)) {
            if ($meta->exclusiveMinimum === true && $meta->minimum !== null) {
                $constraints[] = new Assert\GreaterThan($meta->minimum);
            } elseif ($meta->minimum !== null) {
                $constraints[] = new Assert\GreaterThanOrEqual($meta->minimum);
            }
            if ($meta->exclusiveMaximum === true && $meta->maximum !== null) {
                $constraints[] = new Assert\LessThan($meta->maximum);
            } elseif ($meta->maximum !== null) {
                $constraints[] = new Assert\LessThanOrEqual($meta->maximum);
            }
            if ($meta->multipleOf !== null) {
                $constraints[] = new Assert\DivisibleBy($meta->multipleOf);
            }
        }

        // 4) ARRAYS
        if ($this->isArray($rp, $meta)) {
            if ($meta->minItems !== null || $meta->maxItems !== null) {
                $constraints[] = new Assert\Count(min: $meta->minItems, max: $meta->maxItems);
            }
            if ($meta->uniqueItems) {
                $constraints[] = new UniqueItems();
            }

            // Items constraints
            $itemConstraints = [];
            if ($meta->itemsType) {
                $pt = $this->primitiveTypeConstraint($meta->itemsType);
                if ($pt) { $itemConstraints[] = $pt; }
            }
            if ($meta->itemsRef) {
                $constraints[] = new Assert\Valid();
            }
            if ($itemConstraints !== []) {
                $constraints[] = new Assert\All($itemConstraints);
            }
        }

        // 5) ENUM
        if (is_array($meta->enum) && $meta->enum !== []) {
            $constraints[] = new Assert\Choice(choices: $meta->enum);
        } elseif ($meta->enumClass && enum_exists($meta->enumClass)) {
            $choices = array_map(static fn($c) => $c->value ?? $c->name, $meta->enumClass::cases());
            $constraints[] = new Assert\Choice(choices: $choices);
        }

        // 6) EXTENSIBILITY via x: (adhoc constraints)
        // Accepts:
        //   x: { assert: [
        //      "\\Symfony\\Component\\Validator\\Constraints\\Ip",
        //      ["\\Symfony\\...\\Range", {"min":1,"max":5}],
        //      ["App\\Validator\\MyConstraint", {"foo":"bar"}]
        //   ]}
        if (is_array($meta->x) && isset($meta->x['assert']) && is_array($meta->x['assert'])) {
            foreach ($meta->x['assert'] as $desc) {
                if (is_string($desc) && class_exists($desc)) {
                    $constraints[] = new $desc();
                } elseif (is_array($desc) && isset($desc[0]) && class_exists($desc[0])) {
                    $class = $desc[0];
                    $opts  = $desc[1] ?? [];
                    $constraints[] = new $class(...$this->normalizeCtorArgs($opts));
                }
            }
        }

        // 7) EXTENSIBILITY via tagged contributors
        foreach ($this->contributors as $c) {
            foreach ($c->contribute($rp, $meta) as $contrib) {
                $constraints[] = $contrib;
            }
        }

        return $constraints;
    }

    /** @return array<int, object> */
    private function typeConstraints(ReflectionProperty $rp, DtoApiProperty $meta): array
    {
        // Prefer explicit type override
        if ($meta->type) {
            $c = $this->primitiveTypeConstraint($meta->type);
            return $c ? [$c] : [];
        }

        $t = $rp->getType();
        if (!$t instanceof ReflectionNamedType) {
            return [];
        }
        $name = $t->getName();
        $nullable = $t->allowsNull();

        $map = [
            'string' => 'string',
            'int'    => 'integer',
            'float'  => 'float',
            'bool'   => 'bool',
            'array'  => 'array',
        ];

        if (isset($map[$name])) {
            return [new Assert\Type($map[$name])];
        }

        // Objects: let nested validation handle their own constraints
        // If you want to enforce a non-null object, NotNull is handled above.
        if (class_exists($name)) {
            return [new Assert\Type($name), new Assert\Valid()];
        }

        // Fallback: no-op
        return [];
    }

    private function primitiveTypeConstraint(string $type): ?object
    {
        return match ($type) {
            'string'  => new Assert\Type('string'),
            'integer' => new Assert\Type('integer'),
            'number'  => new Assert\Type('float'),
            'boolean' => new Assert\Type('bool'),
            'array'   => new Assert\Type('array'),
            'object'  => new Assert\Type('object'),
            default   => class_exists($type) ? new Assert\Type($type) : null,
        };
    }

    private function formatConstraint(string $format): ?object
    {
        return match ($format) {
            'email'      => new Assert\Email(),
            'uuid'       => new Assert\Uuid(),
            'uri', 'url' => new Assert\Url(),
            'date'       => new Assert\Date(),
            'time'       => new Assert\Time(),
            'date-time'  => new Assert\DateTime(), // accepts many formats; OK for API
            'int64'      => new Assert\Type('integer'),
            'float'      => new Assert\Type('float'),
            default      => null,
        };
    }

    private function isString(ReflectionProperty $rp, DtoApiProperty $meta): bool
    {
        if ($meta->type === 'string') return true;
        $t = $rp->getType();
        return $t instanceof ReflectionNamedType && $t->getName() === 'string';
    }

    private function isNumeric(ReflectionProperty $rp, DtoApiProperty $meta): bool
    {
        if (in_array($meta->type, ['integer','number'], true)) return true;
        $t = $rp->getType();
        return $t instanceof ReflectionNamedType && in_array($t->getName(), ['int','float'], true);
    }

    private function isArray(ReflectionProperty $rp, DtoApiProperty $meta): bool
    {
        if ($meta->type === 'array') return true;
        $t = $rp->getType();
        return $t instanceof ReflectionNamedType && $t->getName() === 'array';
    }

    private function normalizeCtorArgs(array $opts): array
    {
        // Best-effort: allow passing an associative array as named args.
        // Works for most Symfony constraints’ constructors.
        return [$opts];
    }
}
