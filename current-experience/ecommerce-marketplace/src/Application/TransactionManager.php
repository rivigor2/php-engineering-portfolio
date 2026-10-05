<?php

declare(strict_types=1);

namespace Portfolio\Commerce\Application;

interface TransactionManager
{
    /** @template T
     *  @param callable(): T $operation
     *  @return T
     */
    public function run(callable $operation): mixed;
}

