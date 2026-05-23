# Contributing to EScript

## Current Phase: Compiler Bootstrap

The language specification is stable. The IR schema is locked. We are actively building the reference compiler and seeking adapter implementations for popular frameworks.

From the repository root, run `npm test` to execute the Node-based regression tests for the playground compiler (`playground/compiler.js`). When the `php` executable is on your `PATH`, those tests also validate the `examples/` and `stdlib/` trees with the reference CLI under `compiler/`.

## How to Contribute

### 1. Write an Adapter

This is the highest-impact contribution right now. An adapter translates EScript's IR (a JSON document) into your framework's native configuration.

**What you need:**
- Understanding of your target framework's routing/config system
- Ability to read JSON (the IR schema is documented in `ir-schema/`)

**What you produce:**
- A function/class that reads `escript-ir.json` and emits framework-native files
- Tests that verify round-trip correctness

**Example adapter interface (PHP):**

```php
interface EScriptAdapterInterface
{
    /** Adapter identifier */
    public function name(): string;

    /** Transform IR into framework-specific files */
    public function emit(array $ir, array $config): array;

    /** Verify emitted artifacts are consistent */
    public function verify(array $artifacts): array;
}
```

**Example adapter interface (TypeScript):**

```typescript
interface EScriptAdapter {
  name: string;
  emit(ir: EScriptIR, config: AdapterConfig): EmitResult;
  verify(artifacts: EmitResult): VerifyResult;
}
```

A typical adapter is ~200 lines. See `adapters/evolution/` for the reference implementation.

### 2. Review the Grammar

The EBNF is in `spec/SPEC.md`. If you find ambiguities, edge cases, or syntax that feels unnatural for PHP/Rust/Python developers, open an issue.

**We value:**
- Syntax that reads naturally for someone who knows typed languages
- Minimal keyword count (fewer reserved words = less to learn)
- Unambiguous parsing (no context-dependent rules)

### 3. Challenge the Safety Model

EScript claims "insecure code is uncompilable." If you can write a `.es` file that:
- Produces an unprotected mutating endpoint without `@unsafe`
- Bypasses the gate validator
- Creates a fail-open path through valid syntax

...then you've found a real bug. Open an issue with a reproduction `.es` file.

### 4. Improve Tree-sitter Grammar

The grammar in `tree-sitter-escript/` provides syntax highlighting for editors. If you use Neovim, VS Code, Helix, or Zed, test the highlighting and report issues.

## What We're NOT Looking For (Yet)

- Runtime libraries (EScript has zero runtime)
- Framework integrations that require EScript knowledge at runtime
- Changes to the IR schema (it's locked for v1)

## Code Style

- Rust code: `cargo fmt` + `cargo clippy`
- PHP code: PSR-12
- TypeScript: Standard prettier config
- Documentation: Clear, concise, example-driven

## Submitting

1. Fork the repo
2. Create a branch: `adapter/laravel`, `fix/grammar-ambiguity`, etc.
3. Write tests for your changes
4. Open a PR with a clear description of what it does and why

## Questions?

Open a Discussion (not an Issue) for questions about the language design, adapter architecture, or contribution process.

---

**Remember:** EScript's core value is that security defaults cannot be weakened without explicit human acknowledgment. Every contribution should strengthen this guarantee, never weaken it.
