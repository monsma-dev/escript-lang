# EScript Gate

A compiled Rust binary that validates all transpiler output before it enters your codebase.

## Why a compiled binary?

The gate is the **immutable trust boundary** between generated code and your production system. Because it's compiled Rust:

- No AI agent can modify its validation logic at runtime
- No configuration drift can weaken its checks
- All rules are read from a JSON config (editable), but the enforcement engine is immutable

## How it works

```
Transpiler output → stdin (JSON) → escript_gate → stdout (verdict JSON)
```

### Input

```json
{
  "action": "validate",
  "artifact_type": "route",
  "content": { "routes": [...] },
  "rules_path": "escript_gate_rules.json"
}
```

### Output

```json
{
  "trusted": false,
  "violations": ["Route[0]: mutating method POST requires rate limiting"],
  "artifact_hash": "sha256:abc123...",
  "gate_version": "0.1.0"
}
```

## Building

```bash
cd gate/
cargo build --release
```

The binary is at `target/release/escript_gate`.

## Configuration

Copy `escript_gate_rules.example.json` to your project and customize:

- **`forbidden_controller_prefixes`** — Block specific namespaces from being route targets
- **`require_rate_limit_on_mutating`** — Enforce rate limiting on POST/PUT/DELETE
- **`fail_open_requires_unsafe`** — Block services with `fail_mode: open` unless annotated
- **`max_routes_per_file`** — Prevent oversized route files

All rules are project-specific. The binary itself has zero knowledge of your framework.
