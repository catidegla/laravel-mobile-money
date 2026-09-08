<?php

declare(strict_types=1);

namespace Catidegla\MobileMoney\Exceptions;

use RuntimeException;

/**
 * Base for everything this package throws, so an application can catch the
 * whole surface with one clause.
 */
class MobileMoneyException extends RuntimeException
{
    /**
     * Provider payload that produced the failure, minus anything secret.
     *
     * @var array<string, mixed>
     */
    public array $context = [];

    /** @param array<string, mixed> $context */
    public function withContext(array $context): static
    {
        $this->context = $context;

        return $this;
    }
}
