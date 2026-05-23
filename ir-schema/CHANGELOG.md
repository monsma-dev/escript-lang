# EScript IR Schema Changelog

All changes to the IR schema follow [Semantic Versioning](https://semver.org/).

## Versioning Rules

The IR schema version (`"version": "1.x.y"`) follows strict compatibility rules:

### MAJOR (breaking) — `2.0.0`

- Removing a required field
- Changing the type of an existing field
- Renaming a field
- Changing the meaning of a field value
- Removing a top-level section (`routes`, `services`, etc.)

**Policy:** Major bumps require a 6-month deprecation window. Old adapters must continue to work with v1 IR until the window closes.

### MINOR (additive) — `1.1.0`

- Adding a new optional field to an existing object
- Adding a new top-level section (e.g., `pipelines`)
- Adding new enum values to existing fields
- Adding new `$defs` types

**Policy:** Existing adapters **must not break** when new fields appear. Adapters should ignore unknown fields.

### PATCH (docs/meta) — `1.0.1`

- Clarifying field descriptions
- Fixing typos in the schema
- Adding examples
- Tightening validation without breaking existing valid IR

---

## Changelog

### v1.0.1 — 2026-05-23

**Additive (non-breaking).**

- Document optional `dto` on `route` objects (already emitted by reference compilers when route options include `dto`).
- Add `ir-schema/compliance.schema.json`, a composed schema for IR documents that must include at least one guard (compliance / gate tooling).

### v1.0.0 — 2026-05-23

**Initial stable release.** Locked schema for adapter development.

**Top-level sections:**

- `modules` — Package/module declarations
- `routes` — HTTP route definitions with tier, target, middleware, auth
- `services` — Service classes with DI, guards, methods
- `dtos` — Data Transfer Objects with typed fields
- `guards` — Fail-closed validation guards
- `islands` — Frontend island components

**Guard extensions (v1.0.0):**

- `trigger` — Reactive event trigger (`{ "on": "event_name" }`)
- `action` — Reactive action dispatch (`{ "dispatch": "handler" }`)
- `conditions` — Guard activation conditions
- `ceiling` — Spending/rate ceiling value
- `unsafe_acknowledged` — Explicit opt-in for fail-open guards

**Route fields:**

- `method` — HTTP method (GET, POST, PUT, DELETE, PATCH, HEAD)
- `path` — URL path with `{param}` placeholders
- `tier` — Runtime tier (php, rust, elixir, node)
- `target` — Controller and action reference
- `middleware` — Framework middleware array
- `auth` — Authentication requirement
- `rate_limit` — Rate limiting strategy
- `dto` — Request/response DTO reference
- `annotations.fail_closed` — Always `true` (enforced by compiler)

**Service fields:**

- `name`, `tier`, `fail_mode`
- `implements` — Interface list
- `injects` — Constructor dependencies
- `guards` — Guard references
- `methods` — Method signatures with params, return types, throws

**DTO fields:**

- `name`, `fields[]` with `name`, `type`, `nullable`, `default`
- `fluid` — Optional fluid interface configuration

**Return type kinds:**

- `simple` — Direct type reference
- `nullable` — Optional type (`?Type`)
- `array` — Array of type (`Type[]`)
- `result` — Result type with ok/err (`Result<Ok, Err>`)

---

## Backwards Compatibility Contract

Adapters **must** follow these rules:

1. **Ignore unknown fields.** If the IR contains a field your adapter doesn't recognize, skip it silently. Never fail on unknown data.

2. **Check `version` on load.** Only process IR where the major version matches your supported range.

3. **Handle missing optional fields.** Every field except `version` and `source` may be absent. Use sensible defaults.

4. **Never assume array order.** Routes, services, DTOs, and guards may appear in any order.

5. **Treat `annotations` as extensible.** New annotation keys may appear in any release.

```json
{
  "version": "1.0.0",
  "source": "example.es"
}
```

This is the **minimum valid IR document**. Your adapter must handle it without error.
