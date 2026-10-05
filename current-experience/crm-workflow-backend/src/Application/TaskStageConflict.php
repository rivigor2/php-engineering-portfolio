<?php

declare(strict_types=1);

namespace Portfolio\CrmWorkflow\Application;

use DomainException;

final class TaskStageConflict extends DomainException
{
    public function __construct(string $message)
    {
        parent::__construct($message, 409);
    }
}
