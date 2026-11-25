<?php

namespace App\Services\Bot\Steps;

use App\Services\Bot\BotContext;

/**
 * A conversation step. handle() processes the customer's answer to this step's
 * question, then prompts the next step (or re-prompts itself on invalid input).
 *
 * Steps resolve each other through the container at call time (not constructor
 * injection) because the flow loops back: e.g. Confirm -> Product when the product
 * was deactivated meanwhile.
 */
interface Step
{
    public function handle(BotContext $context): void;
}
