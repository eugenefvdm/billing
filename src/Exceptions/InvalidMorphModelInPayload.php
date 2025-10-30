<?php

namespace Eugenefvdm\Billing\Exceptions;

use Exception;

class InvalidMorphModelInPayload extends Exception
{
    public function errorMessage()
    {
        $errorMsg = $this->getMessage().' is an invalid morph model.';

        return $errorMsg;
    }
}
