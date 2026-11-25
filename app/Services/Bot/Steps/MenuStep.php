<?php

namespace App\Services\Bot\Steps;

use App\Services\Bot\BotContext;

/**
 * Idle state: any message shows the welcome + product list.
 */
class MenuStep implements Step
{
    public function handle(BotContext $context): void
    {
        app(ProductStep::class)->prompt($context);
    }
}
