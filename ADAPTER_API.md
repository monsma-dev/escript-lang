# EScript Adapter API

> The complete guide to building a framework adapter for EScript.

If you want EScript to work with FastAPI, NestJS, Spring Boot, Rails, or any other framework — this is the document you need.

---

## What an Adapter Does

An adapter is a **one-way translator**: it reads EScript IR (a JSON document) and writes your framework's native files.

```
.es source → [Compiler] → IR JSON → [Your Adapter] → Framework files
```

The adapter never touches `.es` files. It only reads IR. This means:

- You don't need to understand EScript syntax
- You don't need a parser or lexer
- You only need to map JSON objects to your framework's conventions

## The IR Contract

The IR schema is defined in [`ir-schema/escript-ir-v1.json`](ir-schema/escript-ir-v1.json).

### Top-Level Structure

```json
{
  "version": "1.0.0",
  "source": "path/to/source.es",
  "compiled_at": "2026-05-23T00:00:00Z",
  "modules": [],
  "routes": [],
  "services": [],
  "dtos": [],
  "guards": [],
  "islands": []
}
```

Each array is optional. If a `.es` file defines no routes, the `routes` key is absent. Your adapter must handle missing keys gracefully.

### Route Object

```json
{
  "method": "POST",
  "path": "/api/v1/users",
  "tier": "php",
  "target": {
    "controller": "UserController",
    "action": "store"
  },
  "auth": "bearer",
  "rate_limit": "strict",
  "middleware": ["AuthMiddleware", "RateLimitMiddleware"],
  "dto": "CreateUserRequest",
  "annotations": {
    "fail_closed": true
  }
}
```

**Your adapter must:**

| Field | Action |
|---|---|
| `method` | Map to framework route method (`Route::post()`, `@app.post()`, `@Post()`) |
| `path` | Use as-is, or translate `{param}` to framework syntax (`:param`, `<param>`) |
| `tier` | Filter routes — only emit routes matching your tier |
| `target.controller` | Map to controller class name |
| `target.action` | Map to controller method name |
| `auth` | Map to framework auth middleware/decorator |
| `rate_limit` | Map to framework rate limiting |
| `middleware` | Map middleware names to framework equivalents |
| `dto` | Reference the generated DTO class for request validation |

### DTO Object

```json
{
  "name": "CreateUserRequest",
  "fields": [
    { "name": "email", "type": "string", "nullable": false },
    { "name": "role", "type": "string", "nullable": false, "default": "user" },
    { "name": "avatar_url", "type": "string", "nullable": true }
  ]
}
```

**Your adapter must:**

| Field | Action |
|---|---|
| `name` | Use as class name |
| `fields[].name` | Property/field name |
| `fields[].type` | Map to language type (`string` → `str`, `String`, etc.) |
| `fields[].nullable` | Add nullable type hint or optional marker |
| `fields[].default` | Set default value in constructor/definition |

### Service Object

```json
{
  "name": "UserService",
  "tier": "php",
  "fail_mode": "closed",
  "implements": ["UserServiceInterface"],
  "injects": [
    { "name": "db", "type": "DatabaseConnection" },
    { "name": "hasher", "type": "PasswordHasher" }
  ],
  "guards": ["RateLimitGuard"],
  "methods": [
    {
      "name": "create",
      "visibility": "public",
      "params": [{ "name": "request", "type": "CreateUserRequest" }],
      "return_type": { "kind": "result", "ok": "UserDto", "err": "ApiError" },
      "throws": ["NotFoundError"]
    }
  ]
}
```

**Your adapter must:**

| Field | Action |
|---|---|
| `tier` | Filter services — only emit services matching your tier |
| `injects` | Map to constructor injection / DI container bindings |
| `guards` | Reference guard names (informational — for documentation) |
| `methods[].return_type.kind` | Handle `simple`, `nullable`, `array`, `result` |

### Guard Object

```json
{
  "name": "LayerViolationGuard",
  "tier": "rust",
  "fail_mode": "closed",
  "input_type": "ViolationReport",
  "output_type": "DispatchResult",
  "trigger": { "on": "layer_violation" },
  "action": { "dispatch": "rector_auto_fix" },
  "conditions": { "severity": "error", "auto_fixable": true },
  "ceiling": 20.00,
  "unsafe_acknowledged": false
}
```

Guards are **informational for most adapters**. They describe system-level behavior enforced by the gate binary. Your adapter should:

- Document guards in generated code comments
- Validate that `fail_mode: open` has `unsafe_acknowledged: true`
- Generate configuration files for guard-aware middleware if your framework supports it

### Return Type Kinds

```json
{ "kind": "simple",   "type": "UserDto" }
{ "kind": "nullable", "type": "UserDto" }
{ "kind": "array",    "type": "UserDto" }
{ "kind": "result",   "ok": "UserDto", "err": "ApiError" }
```

Map these to your language:

| Kind | PHP | Python | TypeScript | Rust | Go |
|---|---|---|---|---|---|
| `simple` | `UserDto` | `UserDto` | `UserDto` | `UserDto` | `UserDto` |
| `nullable` | `?UserDto` | `Optional[UserDto]` | `UserDto \| null` | `Option<UserDto>` | `*UserDto` |
| `array` | `array` | `list[UserDto]` | `UserDto[]` | `Vec<UserDto>` | `[]UserDto` |
| `result` | `UserDto` (throws) | `UserDto` (raises) | `UserDto` (throws) | `Result<UserDto, ApiError>` | `(UserDto, error)` |

## Building Your Adapter

### Step 1: Read IR

```python
# Python example (FastAPI adapter)
import json

def read_ir(path: str) -> dict:
    with open(path) as f:
        ir = json.load(f)
    assert ir["version"] == "1.0.0", f"Unsupported IR version: {ir['version']}"
    return ir
```

### Step 2: Map Routes

```python
def emit_routes(routes: list[dict]) -> str:
    lines = [
        "from fastapi import FastAPI, Depends",
        "from app.controllers import *",
        "",
        "app = FastAPI()",
        "",
    ]
    for r in routes:
        if r["tier"] != "php":  # FastAPI maps to "php" tier for Python
            continue
        method = r["method"].lower()
        path = r["path"].replace("{", "{")  # FastAPI uses same syntax
        controller = r["target"]["controller"]
        action = r["target"]["action"]
        lines.append(f'@app.{method}("{path}")')
        lines.append(f'async def {action}():')
        lines.append(f'    return {controller}.{action}()')
        lines.append('')
    return "\n".join(lines)
```

### Step 3: Map DTOs

```python
def emit_dto(dto: dict) -> str:
    lines = [
        "from pydantic import BaseModel",
        "from typing import Optional",
        "",
        f"class {dto['name']}(BaseModel):",
    ]
    for field in dto["fields"]:
        py_type = {"string": "str", "int": "int", "float": "float", "bool": "bool"}.get(field["type"], field["type"])
        if field.get("nullable"):
            py_type = f"Optional[{py_type}]"
        default = ""
        if "default" in field:
            default = f" = {repr(field['default'])}"
        elif field.get("nullable"):
            default = " = None"
        lines.append(f"    {field['name']}: {py_type}{default}")
    return "\n".join(lines)
```

### Step 4: Map Services

```python
def emit_service(service: dict) -> str:
    lines = [f"class {service['name']}:"]
    # Constructor with DI
    params = ["self"]
    for inj in service.get("injects", []):
        params.append(f"{inj['name']}: {inj['type']}")
    lines.append(f"    def __init__({', '.join(params)}):")
    for inj in service.get("injects", []):
        lines.append(f"        self.{inj['name']} = {inj['name']}")
    lines.append("")
    # Methods
    for method in service.get("methods", []):
        mp = ["self"]
        for p in method.get("params", []):
            mp.append(f"{p['name']}: {p['type']}")
        rt = method.get("return_type", {})
        ret_hint = map_return_type(rt)
        lines.append(f"    def {method['name']}({', '.join(mp)}) -> {ret_hint}:")
        lines.append(f"        raise NotImplementedError")
        lines.append("")
    return "\n".join(lines)
```

### Step 5: Validate

Every adapter **must** check:

1. `version` field matches a supported IR version
2. `fail_mode: open` guards have `unsafe_acknowledged: true`
3. Routes with mutating methods have an `auth` field

If any check fails, the adapter must **refuse to generate code** and print an error. This is the second line of defense after the compiler.

## Adapter Interface

### PHP

```php
interface EScriptAdapterInterface
{
    public function name(): string;
    public function emit(array $ir, array $config): EmitResult;
    public function verify(array $artifacts): VerifyResult;
}
```

### TypeScript

```typescript
interface EScriptAdapter {
  name: string;
  emit(ir: EScriptIR, config: AdapterConfig): EmitResult;
  verify(artifacts: EmitResult): VerifyResult;
}

interface EmitResult {
  files: Map<string, string>;  // path → content
  warnings: string[];
}

interface VerifyResult {
  valid: boolean;
  errors: string[];
}
```

### Python

```python
class EScriptAdapter:
    def name(self) -> str: ...
    def emit(self, ir: dict, config: dict) -> dict[str, str]: ...
    def verify(self, artifacts: dict[str, str]) -> list[str]: ...
```

## Testing Your Adapter

### Round-trip test

```bash
# 1. Compile an example
php compiler/bin/escript compile examples/basic-api.es --output=build/ir

# 2. Run your adapter
python my_adapter.py build/ir/basic-api.ir.json --output=build/my-framework

# 3. Verify the output is valid for your framework
cd build/my-framework && your-framework lint .
```

### Required test cases

Your adapter must handle these cases from the example files:

| Test | File | What to verify |
|---|---|---|
| Basic CRUD | `basic-api.ir.json` | 4 routes, 1 service, 3 DTOs, 1 guard |
| Reactive guards | `compliance-automation.ir.json` | Guards with trigger/action/conditions |
| Missing sections | Custom IR with only `dtos` | No crash on missing `routes`, `services` |
| Path parameters | Route with `{id}` | Framework-correct param syntax |
| Nullable types | DTO field with `"nullable": true` | Correct optional/nullable mapping |
| Result types | Service method with `"kind": "result"` | Correct error handling pattern |

## Existing Adapters

| Framework | Language | Path | Status |
|---|---|---|---|
| **Laravel** | PHP | `adapters/laravel/` | ✅ Complete |
| **Symfony** | PHP | `adapters/symfony/` | ✅ Complete |
| **FastAPI** | Python | — | 📋 Template above |
| **NestJS** | TypeScript | — | 📋 Wanted |
| **Spring Boot** | Java/Kotlin | — | 📋 Wanted |
| **Rails** | Ruby | — | 📋 Wanted |
| **Express** | TypeScript | — | 📋 Wanted |
| **Gin** | Go | — | 📋 Wanted |

## Submitting Your Adapter

1. Place it in `adapters/your-framework/`
2. Include a `README.md` with usage instructions
3. Include a `generate.*` entry point that reads IR and writes files
4. Test against both example IR files
5. Open a PR with generated output samples

---

**The IR is the contract. Your adapter is the translator. The compiler guarantees the contract is valid. Your adapter guarantees the translation is correct.**
