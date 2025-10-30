<?php

namespace Eugenefvdm\Billing\Facades;

use Illuminate\Support\Facades\Facade;

class Payfast extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'payfast';
    }
}
