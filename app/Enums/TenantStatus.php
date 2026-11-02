<?php

namespace App\Enums;

enum TenantStatus: string
{
    case PendingPayment = 'pending_payment';
    case Provisioning = 'provisioning';
    case Active = 'active';
    case Failed = 'failed';
}
