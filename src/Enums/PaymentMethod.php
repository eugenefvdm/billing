<?php

namespace Eugenefvdm\Billing\Enums;

use Filament\Support\Contracts\HasLabel;

enum PaymentMethod: string implements HasLabel
{
    case Card = 'card';
    case Eft = 'eft';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Card => 'Credit Card',
            self::Eft => 'EFT / Bank Transfer',            
        };
    }
}

