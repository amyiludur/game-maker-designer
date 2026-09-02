<?php

declare(strict_types=1);

namespace Gmd\Kernel;

use Gmd\Kernel\Contract\Action;
use Gmd\Kernel\Contract\ActionList;
use Gmd\Kernel\Contract\ChoiceResponse;
use Gmd\Kernel\Contract\MatchSetup;
use Gmd\Kernel\Contract\StepResult;
use Gmd\Kernel\Diagnostics\IllegalAction;
use Gmd\Kernel\Effect\EffectInterpreter;
use Gmd\Kernel\Effect\OpRegistry;
use Gmd\Kernel\Event\EventBus;
use Gmd\Kernel\Event\TriggerQueue;
use Gmd\Kernel\Expr\Runtime;
use Gmd\Kernel\Flow\PhaseMachine;
use Gmd\Kernel\Flow\Settler;
use Gmd\Kernel\Flow\StateCheckRunner;
use Gmd\Kernel\Flow\WinConditionRunner;
use Gmd\Kernel\Legality\CostChecker;
use Gmd\Kernel\Legality\LegalActionEnumerator;
use Gmd\Kernel\Rng\Pcg64Rng;
use Gmd\Kernel\Setup\MatchBuilder;
use Gmd\Kernel\State\Draft;
use Gmd\Kernel\State\GameState;
use Gmd\Kernel\State\StackFrame;
use Gmd\Kernel\State\StackItem;
use Gmd\Kernel\System\SystemDocument;
use Gmd\Kernel\View\PlayerView;
use Gmd\Kernel\View\ViewProjector;

/**
 * The whole rules engine, as five functions.
 *
 * Every driver in the platform — the play table, the bots, the simulation workers, the
 * replay verifier, the regression tests — is a loop around these. There is exactly one
 * place where the game's meaning lives, which is the point of ADR-0002: the engine used to
 * playtest a card is byte-identically the engine used to simulate it ten thousand times.
 *
 * Note that `apply` does not settle. It checks legality, runs the action, and returns; the
 * driver calls `settle` next. Keeping them apart makes "this action produced these events"
 * a clean boundary for the animation layer, and lets a timing test look at the state in
 * between.
 */
final class Kernel
{
    private readonly EffectInterpreter $interpreter;

    private readonly Settler $settler;

    private readonly LegalActionEnumerator $legality;

    private readonly ViewProjector $projector;

    private readonly MatchBuilder $builder;

    public function __construct(
        private readonly SystemDocument $system,
        private readonly Runtime $runtime = new Runtime(
            new Expr\ExpressionEvaluator,
            new Query\QueryEngine,
            new Modifier\ModifierEngine,
            new Query\SelectorResolver,
        ),
        ?OpRegistry $ops = null,
    ) {
        $events = new EventBus;
        $this->interpreter = new EffectInterpreter($ops ?? OpRegistry::standard(), $events, $this->runtime);
        $phases = new PhaseMachine;

        $this->legality = new LegalActionEnumerator($this->runtime, $phases, new CostChecker($this->interpreter));
        $this->settler = new Settler(
            $this->interpreter,
            $events,
            new TriggerQueue,
            $phases,
            new StateCheckRunner,
            new WinConditionRunner,
            $this->runtime,
            $this->legality,
        );
        $this->projector = new ViewProjector($this->runtime);
        $this->builder = new MatchBuilder;
    }

    public function system(): SystemDocument
    {
        return $this->system;
    }

    /** Build the opening position. It still needs settling, which runs the game's setup script. */
    public function begin(MatchSetup $setup): GameState
    {
        return $this->builder->build($this->system, $setup);
    }

    public function legalActions(GameState $state, string $side): ActionList
    {
        return $this->legality->legalActions($state, $this->system, $side);
    }

    /**
     * Advance automatic processing until the game is waiting on a decision or has ended.
     */
    public function settle(GameState $state): StepResult
    {
        $draft = $this->draft($state);
        $this->settler->settle($draft, $this->system);

        return new StepResult($draft->commit(), $draft->emitted());
    }

    /**
     * Take an action.
     *
     * @throws IllegalAction when the action is not one this side may take right now
     */
    public function apply(GameState $state, Action $action): StepResult
    {
        $legal = $this->legalActions($state, $action->side);
        if ($legal->find($action) === null) {
            throw IllegalAction::because(
                "\"{$action->actionId}\" is not legal for {$action->side} in {$state->qualifiedStep()}",
                ['action' => $action->actionId, 'side' => $action->side, 'step' => $state->qualifiedStep()],
            );
        }

        $draft = $this->draft($state);

        if ($action->isPass()) {
            $draft->setConsecutivePasses($draft->consecutivePasses() + 1);
            (new PhaseMachine)->passPriority($draft);

            return new StepResult($draft->commit(), $draft->emitted());
        }

        $template = $this->system->action($action->actionId);
        $bindings = ['you' => $action->side];
        foreach ($action->params as $id => $value) {
            $bindings['target.' . $id] = $value;
        }

        $draft->setConsecutivePasses(0);
        $draft->pushStack(new StackItem(
            id: $draft->nextId('stack', 's'),
            kind: StackItem::KIND_ACTION,
            controller: $action->side,
            frames: [new StackFrame($template->playProgram())],
            bindings: $bindings,
            sourceInstance: is_string($action->params['card'] ?? null) ? $action->params['card'] : null,
        ));

        return new StepResult($draft->commit(), $draft->emitted());
    }

    /**
     * Answer a pending choice.
     *
     * A first-class entry point rather than a callback passed into settle(), because a
     * callback cannot cross a process boundary and the runtime parks half-finished effects
     * in Redis (doc 08).
     */
    public function answer(GameState $state, ChoiceResponse $response): StepResult
    {
        $choice = $state->pendingChoice();
        if ($choice === null) {
            throw IllegalAction::because('there is no choice to answer');
        }
        if ($response->choiceId !== $choice->id && $response->choiceId !== $choice->key()) {
            throw IllegalAction::because(
                "answered \"{$response->choiceId}\" but the game is waiting on \"{$choice->key()}\"",
            );
        }

        $draft = $this->draft($state);
        $stack = $draft->stack();
        if ($stack === []) {
            throw IllegalAction::because('a choice is pending but nothing is waiting on it');
        }

        $top = $stack[count($stack) - 1];
        $answer = match ($choice->kind) {
            \Gmd\Kernel\Contract\PendingChoice::CHOOSE_NUMBER => $response->number,
            \Gmd\Kernel\Contract\PendingChoice::YES_NO => $response->yes,
            default => $response->selection,
        };

        $draft->replaceTopOfStack($top->with([
            'awaiting' => null,
            'bindings' => [...$top->bindings, 'choice.' . $choice->id => $answer],
        ]));
        $draft->setPendingChoice(null);

        return new StepResult($draft->commit(), $draft->emitted());
    }

    public function view(GameState $state, string $side): PlayerView
    {
        return $this->projector->view($state, $this->system, $side);
    }

    private function draft(GameState $state): Draft
    {
        return Draft::of($state, Pcg64Rng::at($state->seed, $state->rngPosition));
    }
}
