<?php

namespace App\Enums;

enum TenantStatus: string
{
    case Provisioning = 'provisioning';
    case Active = 'active';
    case Failed = 'failed';
}
