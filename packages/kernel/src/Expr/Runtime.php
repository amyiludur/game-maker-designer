<?php

declare(strict_types=1);

namespace Gmd\Kernel\Expr;

use Gmd\Kernel\Modifier\ModifierEngine;
use Gmd\Kernel\Query\QueryEngine;
use Gmd\Kernel\Query\SelectorResolver;

/**
 * The three stateless services that need each other.
 *
 * Expressions call queries (`count of cards where ...`), queries evaluate expressions
 * (`where`, `limit`), and both read modified characteristics, which are themselves computed
 * from expressions. Rather than wire that cycle through constructors, nobody holds anybody:
 * each takes an EvalContext, and the context holds this.
 */
final readonly class Runtime
{
    public function __construct(
        public ExpressionEvaluator $expressions,
        public QueryEngine $queries,
        public ModifierEngine $modifiers,
        public SelectorResolver $selectors,
    ) {}

    public static function make(): self
    {
        return new self(
            new ExpressionEvaluator,
            new QueryEngine,
            new ModifierEngine,
            new SelectorResolver,
        );
    }
}
