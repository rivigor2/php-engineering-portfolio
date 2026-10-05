<?php

declare(strict_types=1);

namespace Portfolio\Commerce\Domain;

enum DeliveryState: string
{
    case Pending = 'pending';
    case RetryScheduled = 'retry_scheduled';
    case Sent = 'sent';
    case FailedTemporary = 'failed_temporary';
    case FailedPermanent = 'failed_permanent';
}
