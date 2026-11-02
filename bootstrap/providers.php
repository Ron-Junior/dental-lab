<?php

use App\Providers\AppServiceProvider;
use App\Providers\TenancyServiceProvider;
use App\Providers\VoltServiceProvider;

return [
    AppServiceProvider::class,
    TenancyServiceProvider::class,
    VoltServiceProvider::class,
];
