# EScript

> The language that makes insecure code uncompilable.

EScript is a framework-agnostic DSL that enforces security-by-design at compile time. It compiles to a stable Intermediate Representation (IR) that pluggable adapters translate into any target framework's native config.

**Zero runtime. Zero overhead. Zero fail-open defaults.**

---

## What EScript Does

```escript
@auth(bearer)
@rate_limit(strict)
route POST "/api/users"
    -> @php UserController@store
    {
        middleware: [AuthMiddleware, RateLimitMiddleware];
    };
```

This compiles to your framework's native format — Laravel routes, Symfony YAML, raw JSON, or anything with an adapter. If you forget `@auth` on a mutating endpoint, **it doesn't compile.**

## Why EScript Exists

Every framework defaults to **fail-open**:
- Forgot auth? Endpoint is public.
- Redis down? Rate limiter passes everything.
- Guard throws? Service continues.

EScript inverts this. Security is the default. Insecurity requires explicit `@unsafe` acknowledgment.

| Rule | Violation = |
|---|---|
| Mutating route without `@auth` | Compile error |
| `fail_mode: open` without `@unsafe` | Compile error |
| Type `mixed` without `@unsafe` | Compile error |
| Guard binary missing | Compile error |
| Dependency cycle | Compile error |
| Tier not declared | Compile error |

## Architecture

```
.es source → Parser → Validator → IR Emitter → Adapter → Your framework
```

The **Intermediate Representation (IR)** is the stable contract between the compiler and adapters. Write an adapter (~200 lines) and EScript works with your stack.

## Quick Start

```bash
# Install syntax highlighting
npm install tree-sitter-escript

# Create config
cat > escript.config.json << 'EOF'
{
  "adapter": "laravel",
  "source_dirs": ["escript/"],
  "adapter_options": {
    "routes_file": "routes/api.php",
    "controllers_dir": "app/Http/Controllers"
  }
}
EOF

# Compile
escript compile

# CI gate (validate only, no file writes)
escript compile --validate-only
```

## Adapters

| Adapter | Status | Target |
|---|---|---|
| `evolution` | Reference implementation | JSON routes + PHP classes |
| `laravel` | Example | `routes/api.php` + Controllers + FormRequests |
| `symfony` | Planned | `config/routes.yaml` + `#[Route]` controllers |
| `django` | Open for contribution | `urls.py` + ViewSets |
| `express` | Open for contribution | Express router + middleware |

## Project Structure

```
escript-lang/
├── spec/                    # Language specification (EBNF grammar)
├── tree-sitter-escript/     # Tree-sitter grammar (syntax highlighting)
├── ir-schema/               # IR JSON Schema (adapter contract)
├── gate/                    # Rust gate binary (artifact validator)
├── adapters/
│   ├── evolution/           # Reference adapter
│   └── laravel/             # Example community adapter
├── playground/              # Web-based EScript → IR demo
└── examples/                # Example .es files
```

## The Safety Hierarchy

```
Level 4: @unsafe acknowledged     ← Developer explicitly accepts risk
Level 3: Compile-time verified    ← EScript compiler catches it
Level 2: Gate-validated           ← Rust binary validates output
Level 1: Schema-bound             ← JSON Schema prevents structural drift
Level 0: Hardcoded in binary      ← Cannot change without recompile
```

No automated tool, no AI agent, no CI misconfiguration can bypass Levels 0–2.

## Current Status

**Phase: Compiler Bootstrap**

The language is defined. The AST mapping is stable. The IR schema is locked. We are now building:

1. ✅ Language specification (EBNF)
2. ✅ Tree-sitter grammar (syntax highlighting works)
3. ✅ IR schema (adapter contract)
4. ✅ Gate binary (Rust, schema-driven)
5. 🔄 Reference compiler (PHP-based)
6. 🔄 Playground (web demo)
7. 📋 Community adapters

## Design Philosophy

Read [DESIGN.md](DESIGN.md) — explains self-healing guards, the safety hierarchy, tier-explicit architecture, and the adapter contract with concrete examples.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for details.

**We're looking for:**
- **Adapter authors** — Port EScript to your framework (Laravel, Symfony, Django, Express, etc.)
- **Grammar reviewers** — Review the EBNF and suggest syntax improvements
- **Security researchers** — Challenge our fail-closed guarantees

## License

MIT — Use it, fork it, adapt it. The spec is open. The safety is non-negotiable.

---

*EScript exists because "move fast and break things" broke the wrong things.*
