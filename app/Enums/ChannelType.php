<?php

namespace App\Enums;

enum ChannelType: string
{
    case SMS = 'sms';
    case WHATSAPP = 'whatsapp';

    public static function options(): array
    {
        return [
            self::SMS->value => 'SMS',
            self::WHATSAPP->value => 'WhatsApp',
        ];
    }
}
