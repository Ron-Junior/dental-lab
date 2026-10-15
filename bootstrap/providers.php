<?php

use App\Providers\AppServiceProvider;
use App\Providers\TenancyServiceProvider;
use App\Providers\TelescopeServiceProvider;
use App\Providers\VoltServiceProvider;

return [
    AppServiceProvider::class,
    TenancyServiceProvider::class,
    TelescopeServiceProvider::class,
    VoltServiceProvider::class,
];
