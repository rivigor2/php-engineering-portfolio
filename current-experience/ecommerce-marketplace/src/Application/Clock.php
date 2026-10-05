<?php

declare(strict_types=1);

namespace Portfolio\Commerce\Application;

use DateTimeImmutable;

interface Clock
{
    public function now(): DateTimeImmutable;
}
