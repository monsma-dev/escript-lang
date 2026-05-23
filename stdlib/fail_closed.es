// ─── EScript stdlib: fail-closed guard contracts ─────────────────────────────
//
// This file is a canonical reference for security guards (fail-closed by
// default). It is not automatically "imported" by the compiler today; copy
// patterns into your module or split shared DTOs with your own build layout.
//
// Aligns with spec/SPEC.md (guard_decl, fail_mode, reactive annotations).
// ───────────────────────────────────────────────────────────────────────────

// ─── Shared DTOs (financial / provisioning guards) ───────────────────────────

dto SpendRequest {
    account_id: string;
    amount: float;
    currency: string;
    requested_by: string;
    correlation_id: string;
}

dto SpendDecision {
    allowed: bool;
    reason: string;
    ceiling: float;
    evaluated_at: string;
}

// ─── Rate limiting (typical service guard) ─────────────────────────────────

dto RateLimitContext {
    route_key: string;
    client_id: string;
    window_started_at: string;
}

dto RateLimitDecision {
    allowed: bool;
    retry_after_ms: int;
}

guard RateLimitGuard {
    tier: @rust;
    input: RateLimitContext;
    output: RateLimitDecision;
    fail_mode: closed;
}

// ─── Spending ceiling (hard numeric cap in IR as `ceiling`) ────────────────

guard SpendingCeilingGuard {
    tier: @rust;
    input: SpendRequest;
    output: SpendDecision;
    fail_mode: closed;
    ceiling: 10000.00;
}

// ─── Reactive guard template (compliance / auto-remediation) ─────────────
//
// @trigger / @action / @condition are optional extensions compiled into IR
// fields `trigger`, `action`, and `conditions` respectively.

@trigger(on: "policy_violation")
@action(dispatch: "remediation_job")
@condition(severity: "error")
guard PolicyViolationGuard {
    tier: @rust;
    input: ViolationSignal;
    output: RemediationTicket;
    fail_mode: closed;
}

dto ViolationSignal {
    rule_id: string;
    severity: string;
    resource: string;
    details: string;
}

dto RemediationTicket {
    ticket_id: string;
    dispatched: bool;
}

// ─── Explicit fail-open (requires @unsafe per SPEC) ───────────────────────
//
// @unsafe("…reason…")
// guard ExperimentalGuard {
//     tier: @php;
//     input: RateLimitContext;
//     output: RateLimitDecision;
//     fail_mode: open;
// }
