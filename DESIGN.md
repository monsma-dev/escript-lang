# EScript Design Document

> How EScript turns security rules into self-healing systems.

This document explains the core design concepts that make EScript different from every other configuration language. If you're evaluating whether EScript is worth adopting, start here.

---

## The Core Problem

Every production system accumulates violations:

- A developer bypasses the repository layer and queries the database from a controller
- A new endpoint ships without rate limiting
- A service silently continues when its validation guard fails
- An AI agent provisions infrastructure beyond the spending ceiling

Traditional tools **detect** these problems. EScript **prevents and repairs** them.

## Design Concept 1: Fail-Closed as a Language Primitive

Most frameworks default to **fail-open**: if something goes wrong, the system continues. This is convenient during development and catastrophic in production.

EScript inverts this at the language level:

```escript
guard SpendingCeilingGuard {
    tier: @rust;
    input: SpendRequest;
    output: SpendDecision;
    fail_mode: closed;      // If the guard binary is unreachable, DENY.
    ceiling: 20.00;
}
```

`fail_mode: closed` is the default. You cannot change it without `@unsafe`:

```escript
// This does NOT compile:
guard PermissiveGuard {
    tier: @rust;
    input: Request;
    output: Decision;
    fail_mode: open;   // COMPILE ERROR: fail_mode 'open' requires @unsafe
}

// This compiles, but @unsafe is visible in every review and audit:
@unsafe("Testing environment only — never deploy to production")
guard PermissiveGuard {
    tier: @rust;
    input: Request;
    output: Decision;
    fail_mode: open;
}
```

The key insight: **making insecurity visible is more effective than making it impossible.** The `@unsafe` tag creates a searchable, auditable trail. `grep -r "@unsafe" escript/` instantly shows every security exception in the codebase.

## Design Concept 2: Self-Healing Guards

Traditional guards are passive: they check a condition and return pass/fail. EScript guards are **reactive**: they can trigger automated remediation.

### The Trigger-Condition-Action Pattern

```escript
@trigger(on: "layer_violation")
@action(dispatch: "rector_auto_fix")
@condition(severity: "error", auto_fixable: true)
guard LayerViolationGuard {
    tier: @rust;
    input: ViolationReport;
    output: DispatchResult;
    fail_mode: closed;
}
```

This reads as: *"When a layer violation is detected, if it's an error-severity violation that can be auto-fixed, dispatch a rector refactoring job."*

### How the Closed Loop Works

```
Developer commits code
        │
        ▼
┌─────────────────┐
│ EScript Compile  │  Parse .es files, validate against spec
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│ Analysis Pool    │  PHPStan/Psalm layer rules check the code
└────────┬────────┘
         │ ViolationReport
         ▼
┌─────────────────┐
│ Guard Evaluates  │  @trigger fires, @condition checks pass
└────────┬────────┘
         │ DispatchResult
         ▼
┌─────────────────┐
│ Rector Auto-Fix  │  Automated refactoring applied
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│ Re-validate      │  Verify the fix resolves the violation
└────────┬────────┘
         │
         ▼
      Deploy ✓
```

The entire loop is defined in code — not in a wiki, not in a Jira ticket, not in someone's memory.

### Why This Matters

Without self-healing guards, the response to a layer violation is:

1. CI detects violation → creates a ticket
2. Ticket sits in backlog for weeks
3. Developer eventually fixes it (maybe)
4. During those weeks, the violation is in production

With EScript self-healing guards:

1. Compile detects violation → guard fires
2. Guard dispatches auto-fix → rector repairs it
3. Re-validation confirms → deploy proceeds
4. Total time: milliseconds, not weeks

## Design Concept 3: The Safety Hierarchy

EScript defines five levels of trust. Each level is harder to bypass than the one above it:

```
Level 4: @unsafe acknowledged
         │
         │  Developer explicitly writes @unsafe("reason")
         │  Visible in all code reviews and audits
         │
Level 3: Compile-time verified
         │
         │  EScript compiler catches violations
         │  Missing @auth, type errors, dependency cycles
         │
Level 2: Gate-validated
         │
         │  Compiled Rust binary validates all artifacts
         │  Rules loaded from JSON config, engine is immutable
         │
Level 1: Schema-bound
         │
         │  JSON Schema prevents structural drift
         │  IR must conform to escript-ir-v1.json
         │
Level 0: Hardcoded in binary
         │
         │  Absolute limits compiled into Rust
         │  Cannot change without recompiling the gate binary
```

**No automated tool can bypass Levels 0-2.** Level 3 requires a human writing `@unsafe`. Level 4 requires a human recompiling a Rust binary.

## Design Concept 4: Tier-Explicit Architecture

Most polyglot systems hide which runtime handles what. A PHP controller might silently fall back to a slow path when the Rust service is down. EScript makes tiers explicit:

```escript
// Hot path: Rust handles the read
route GET "/api/v1/listings/browse"
    -> @rust listing::paginated
    {
        rust_middlewares: [ip:ban_check, rate_limit:sliding];
    };

// Business logic: PHP handles the write
@auth(bearer)
route POST "/api/v1/listings"
    -> @php ListingController@store
    {
        middleware: [AuthMiddleware, RateLimitMiddleware];
    };

// Real-time: Elixir handles the stream
route GET "/ws/auctions/{id}/live"
    -> @elixir auction::live_bids
    {
        auth: bearer;
    };
```

The compiler validates each tier independently:

- A `@rust` route cannot reference PHP middleware
- A `@php` service cannot claim `@tier(rust)` without a compiled binary
- A `@elixir` route must have a corresponding channel definition

There is no implicit fallback. If the Rust service is down, the route fails — it doesn't silently degrade to a slower PHP path. **Explicit failure is safer than implicit degradation.**

## Design Concept 5: The Adapter Contract

EScript doesn't generate framework-specific code directly. It emits a framework-agnostic **Intermediate Representation (IR)** that adapters translate:

```
.es source → Compiler → IR (JSON) → Adapter → Your framework
```

The IR is the stable contract (see [`ir-schema/escript-ir-v1.json`](ir-schema/escript-ir-v1.json)). Adapters are ~200 lines of code. This means:

- **Laravel developers** can use EScript without leaving Laravel
- **Symfony developers** can use EScript without learning a new framework
- **Custom framework authors** write one adapter and get compile-time safety

The core team maintains the language and IR. The community builds adapters. Nobody needs access to anyone else's proprietary code.

---

## Further Reading

- [`spec/SPEC.md`](spec/SPEC.md) — EBNF grammar and language specification
- [`examples/compliance-automation.es`](examples/compliance-automation.es) — Full self-healing guard example
- [`examples/basic-api.es`](examples/basic-api.es) — Standard REST API example
- [`ir-schema/escript-ir-v1.json`](ir-schema/escript-ir-v1.json) — The adapter contract
- [`CONTRIBUTING.md`](CONTRIBUTING.md) — How to build an adapter for your framework
