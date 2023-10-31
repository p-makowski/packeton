<?php

declare(strict_types=1);

namespace Packeton\Validator\Constraint;

use Symfony\Component\Validator\Constraint;

class PackageRepository extends Constraint
{
    /**
     * {@inheritdoc}
     */
    public function getTargets(): string|array
    {
        return self::CLASS_CONSTRAINT;
    }
}
