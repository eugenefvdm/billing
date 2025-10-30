<?php

namespace Tests\Fixtures;

use Eugenefvdm\Billing\Billable;
use Illuminate\Foundation\Auth\User as Model;

class User extends Model
{
    use Billable;

    protected $guarded = [];
}
