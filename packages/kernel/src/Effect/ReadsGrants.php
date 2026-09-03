<?php

declare(strict_types=1);

namespace Gmd\Kernel\Effect;

/**
 * An op that consults a permission or restriction the game grants by keyword.
 *
 * The linter's most useful rule is "this keyword grants something nothing ever reads",
 * because a rule that reads like a rule and does nothing is the most expensive kind of
 * nothing a card game can print. That rule scans authored effects and requirements — which
 * is everywhere a grant *can* be read, except one: the kernel itself.
 *
 * `may_defend` is the first of those. Declaring it here rather than listing it in the
 * linter keeps the fact next to the code that depends on it, so an op that stops reading a
 * grant makes the keyword dead again on the same edit.
 */
interface ReadsGrants
{
    /** @return list<string> permission and restriction names this op consults */
    public function grantsRead(): array;
}
