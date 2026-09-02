<?php

declare(strict_types=1);

namespace Gmd\Kernel\Diagnostics;

/**
 * The kernel's error taxonomy (doc 07, "Errors").
 *
 * Every failure the kernel can produce has a code here. Nothing throws a bare exception:
 * a designer debugging a broken card needs "Ashen Vanguard, ability a1, round 4", and a
 * fuzz run that dies at 2am needs a machine-readable reason.
 */
enum DiagnosticCode: string
{
    case IllegalAction = 'illegal_action';
    case UnknownOp = 'unknown_op';
    case UnresolvedSelector = 'unresolved_selector';
    case ModifierCycle = 'modifier_cycle';
    case StateCheckLoop = 'state_check_loop';
    case TriggerDepthExceeded = 'trigger_depth_exceeded';
    case NoLegalActions = 'no_legal_actions';
    case CostUnpayable = 'cost_unpayable';
    case BudgetExceeded = 'budget_exceeded';
    case NonDeterministicExpression = 'non_deterministic_expression';
    case InvariantViolation = 'invariant_violation';
    case CompileError = 'compile_error';
    case BadDocument = 'bad_document';
}
