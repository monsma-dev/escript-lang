// ─── EScript Example: Compliance Automation ─────────────────────────────────
//
// This is NOT a "hello world". This is how EScript enforces architectural
// rules and auto-repairs violations — the killer feature that makes EScript
// more than a config language.
//
// Scenario: A developer imports a Model directly in a Controller (layer
// violation). EScript detects this at compile time and dispatches an
// automated refactoring job to fix it.
//
// This is "Security-by-Design" applied to architecture itself.

// ─── DTOs for the compliance pipeline ───────────────────────────────────────

dto ViolationReport {
    file_path: string;
    rule_id: string;
    severity: string;
    message: string;
    suggested_fix: string?;
    auto_fixable: bool = false;
}

dto DispatchResult {
    job_id: string;
    status: string;
    dispatched_at: string;
    estimated_ms: int;
}

dto AnalysisPoolStatus {
    workers_active: int;
    workers_total: int;
    queue_depth: int;
    avg_response_ms: float;
}

// ─── Guards: the immutable enforcement layer ────────────────────────────────

// LayerViolationGuard: Detects architectural boundary crossings.
// When a Controller directly references a Model (bypassing Repository/Service),
// this guard fires and triggers auto-remediation.
//
// This compiles to a gate probe that runs in the analysis pool.
// The Rust binary validates; the PHP service dispatches the fix.

@trigger(on: "layer_violation")
@action(dispatch: "rector_auto_fix")
@condition(severity: "error", auto_fixable: true)
guard LayerViolationGuard {
    tier: @rust;
    input: ViolationReport;
    output: DispatchResult;
    fail_mode: closed;
}

// SpendingCeilingGuard: Hard cap on infrastructure costs.
// Cannot be overridden by any AI agent or config change.
// Only a Rust recompile can change the ceiling.

@trigger(on: "provision_request")
@condition(cost_exceeds_ceiling: true)
guard SpendingCeilingGuard {
    tier: @rust;
    input: SpendRequest;
    output: SpendDecision;
    fail_mode: closed;
    ceiling: 20.00;
}

// ─── Service: the compliance orchestrator ───────────────────────────────────

@tier(php)
@fail_closed
service ComplianceService implements ComplianceServiceInterface {
    inject pool: AnalysisPoolClient;
    inject dispatcher: JobDispatcher;
    inject telemetry: TelemetryCollector;

    guard LayerViolationGuard;
    guard SpendingCeilingGuard;

    // Analyze a file for architectural violations.
    // Returns violations that the guard pipeline will act on.
    pub fn analyzeFile(filePath: string) -> ViolationReport[] {
        // The adapter emits:
        // 1. A call to the analysis pool (2 workers, socket-based)
        // 2. PHPStan/Psalm layer rules evaluation
        // 3. Violation report collection
    }

    // Dispatch an auto-fix job to the rector worker.
    // Only called when LayerViolationGuard approves (auto_fixable = true).
    pub fn dispatchAutoFix(violation: ViolationReport) -> Result<DispatchResult, DispatchError> {
        // The adapter emits:
        // 1. Validation through LayerViolationGuard (fail_mode: closed)
        // 2. rector_auto_dispatch to the analysis pool
        // 3. Telemetry event (response time, fix applied)
    }

    // Get pool health for monitoring dashboards.
    pub fn poolStatus() -> AnalysisPoolStatus {
        // Maps to socket-based health check on the analysis pool
    }
}

// ─── Routes: compliance API endpoints ───────────────────────────────────────

// Pool health endpoint — no auth required (monitoring)
@auth(none)
@rate_limit(sliding)
route GET "/api/v1/compliance/pool-status"
    -> @php ComplianceController@poolStatus
    {
        middleware: [RateLimitMiddleware];
        dto: AnalysisPoolStatus;
    };

// Trigger analysis — requires admin auth
@auth(bearer)
@rate_limit(strict)
route POST "/api/v1/compliance/analyze"
    -> @php ComplianceController@analyze
    {
        middleware: [AdminAuthMiddleware, RateLimitMiddleware];
    };

// View violation reports — requires admin auth
@auth(bearer)
route GET "/api/v1/compliance/violations"
    -> @php ComplianceController@listViolations
    {
        middleware: [AdminAuthMiddleware];
        dto: ViolationReport;
    };

// Dispatch auto-fix — the most dangerous endpoint.
// Requires admin + explicit rate limiting + guard approval.
@auth(bearer)
@rate_limit(strict)
route POST "/api/v1/compliance/auto-fix"
    -> @php ComplianceController@dispatchAutoFix
    {
        middleware: [AdminAuthMiddleware, RateLimitMiddleware];
        dto: DispatchResult;
    };

// ─── What this example demonstrates ─────────────────────────────────────────
//
// 1. GUARDS AS BEHAVIORAL RULES
//    Guards aren't just "rate limiters" — they define system-level behavior.
//    @trigger + @action + @condition makes guards reactive:
//    "When X happens, if Y is true, do Z."
//
// 2. COMPILE-TIME ARCHITECTURE ENFORCEMENT
//    The LayerViolationGuard cannot be bypassed. If a violation is detected
//    and it's auto_fixable, the rector job WILL be dispatched. No developer
//    can forget to fix it, because the system fixes it automatically.
//
// 3. FAIL-CLOSED BY DEFAULT
//    If the analysis pool is down, violations are NOT silently ignored.
//    The guard returns "closed" (deny) and the deploy pipeline blocks.
//
// 4. THE CLOSED LOOP
//    Audit → Detect → Guard → Auto-Fix → Verify → Deploy
//    This loop is defined in code, not in a wiki page or a Jira ticket.
//
// ─── This would NOT compile: ─────────────────────────────────────────────────
//
// @trigger(on: "layer_violation")
// @action(dispatch: "rector_auto_fix")
// guard UnsafeLayerGuard {
//     tier: @rust;
//     input: ViolationReport;
//     output: DispatchResult;
//     fail_mode: open;    // ← COMPILE ERROR: fail_mode 'open' requires @unsafe
// }
//
// To make it compile, you'd need:
// @unsafe("Explicitly allowing fail-open for testing environments only")
// guard UnsafeLayerGuard { ... fail_mode: open; }
//
// And that @unsafe tag shows up in every code review and audit report.
