<?php

namespace Eugenefvdm\Billing\Enums;

// use Filament\Support\Contracts\HasLabel;

enum PaymentMethod: string
{
    case Card = 'card';
    case Eft = 'eft';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Card => 'Credit Card',
            self::Eft => 'EFTr',            
        };
    }
}

