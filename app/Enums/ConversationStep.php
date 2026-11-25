<?php

namespace App\Enums;

enum ConversationStep: string
{
    case Menu = 'menu';
    case Product = 'product';
    case Quantity = 'quantity';
    case Address = 'address';
    case Confirm = 'confirm';
}
