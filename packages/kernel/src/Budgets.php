<?php

declare(strict_types=1);

namespace Gmd\Kernel;

/**
 * Every limit the kernel enforces, in one place.
 *
 * These are not tuning knobs so much as guarantees. A rules engine that can loop forever
 * is not merely slow: an unattended 10,000-match simulation would hang instead of
 * reporting which two cards trigger each other. Each budget turns a hang into a named
 * diagnostic.
 */
final class Budgets
{
    /** Target combinations enumerated eagerly before an action goes parameterised (doc 07). */
    public const TARGET_COMBINATIONS = 512;

    /** Nesting depth of triggered abilities before TriggerDepthExceeded. */
    public const TRIGGER_DEPTH = 64;

    /** State-check passes before StateCheckLoop. */
    public const STATE_CHECK_ITERATIONS = 32;

    /** Fixed-point passes in the modifier layer walk before ModifierCycle (ADR-0004). */
    public const MODIFIER_PASSES = 8;

    /** Settle iterations before BudgetExceeded. Makes "settle always terminates" checkable. */
    public const SETTLE_STEPS = 10000;

    /** Events retained in GameState::$log. The log is a presentation buffer, not the record. */
    public const LOG_CAPACITY = 200;
}
