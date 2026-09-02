<?php

declare(strict_types=1);

namespace Gmd\Kernel\Legality;

use Gmd\Kernel\Diagnostics\CostUnpayable;
use Gmd\Kernel\Diagnostics\KernelException;
use Gmd\Kernel\Effect\EffectInterpreter;
use Gmd\Kernel\Rng\Pcg64Rng;
use Gmd\Kernel\State\Draft;
use Gmd\Kernel\State\StackFrame;
use Gmd\Kernel\State\StackItem;
use Gmd\Kernel\State\StateView;
use Gmd\Kernel\System\ActionTemplate;
use Gmd\Kernel\System\SystemDocument;

/**
 * Can this action's cost actually be paid?
 *
 * Answered by paying it, on a copy of the state that is then thrown away. The alternative —
 * a second implementation that inspects costs and decides — is a second opinion about the
 * rules, and the two will disagree the first time someone adds a cost op. Doc 04 promises
 * that "costs are checked *and* paid by the engine"; this is what makes that one engine.
 */
final class CostChecker
{
    public function __construct(private readonly EffectInterpreter $interpreter) {}

    /**
     * @param  array<string, string>  $params
     */
    public function canPay(
        ActionTemplate $template,
        array $params,
        StateView $state,
        SystemDocument $system,
        string $side,
    ): bool {
        $bindings = ['you' => $side];
        foreach ($params as $id => $value) {
            $bindings['target.' . $id] = $value;
        }

        // A throwaway draft on its own RNG stream: a cost that draws randomness must not
        // move the real generator, or merely looking at the legal actions would change the
        // shuffle.
        $draft = $state instanceof Draft
            ? $state->fork(Pcg64Rng::at($state->seed(), $state->rngPosition()))
            : Draft::of($state, Pcg64Rng::at($state->seed, $state->rngPosition));
        $draft->setStack([]);
        $draft->pushStack(new StackItem(
            id: 'cost-probe',
            kind: StackItem::KIND_ACTION,
            controller: $side,
            frames: [new StackFrame($template->costProgram())],
            bindings: $bindings,
        ));

        try {
            $guard = 0;
            while ($draft->stack() !== [] && $guard++ < 256) {
                if (! $this->interpreter->step($draft, $system)) {
                    break;
                }
            }
        } catch (CostUnpayable) {
            return false;
        } catch (KernelException) {
            // Anything else going wrong in a cost is a design error, not an unpayable cost.
            // Report it as unpayable here so a fuzz run keeps going, and let the linter and
            // the real apply() surface the diagnostic.
            return false;
        }

        // A cost that stops to ask a question cannot be settled here; treat it as payable
        // and let apply() raise the choice for real.
        return true;
    }
}
