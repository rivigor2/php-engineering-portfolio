<?php

declare(strict_types=1);

namespace Portfolio\Commerce\Application;

enum DeliveryAction: string
{
    case Sent = 'sent';
    case Retry = 'retry';
    case FailedTemporary = 'failed_temporary';
    case FailedPermanent = 'failed_permanent';
}
