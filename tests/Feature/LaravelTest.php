<?php

uses(\Tests\Feature\FeatureTestCase::class);
use Eugenefvdm\Billing\Facades\Payfast;

test('laravel dependency injection works', function () {
    $result = Payfast::di();

    expect($result)->toBeTrue();
});
