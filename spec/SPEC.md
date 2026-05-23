# EScript Language Specification v0.2

> A framework-agnostic DSL that enforces security-by-design and compiles to any polyglot stack through pluggable adapters.

## Design Principles

1. **Fail-closed by default** — All services, routes, and guards produce explicit denial unless success is proven.
2. **Mandatory type-safety** — Every field, parameter, and return is typed; no `mixed` or `any` without explicit `@unsafe` annotation.
3. **Adapter-driven** — The compiler emits a framework-agnostic IR. Pluggable adapters translate IR to any target.
4. **Tier-explicit** — Every callable declares which tier executes it. No implicit fallback.
5. **Schema-bound** — Definitions validate against schemas at compile time.
6. **Zero runtime** — EScript compiles away completely. No runtime dependency, no overhead.

---

## EBNF Grammar

```ebnf
(* ─── Top-Level Declarations ─────────────────────────────── *)

program          = { declaration } ;

declaration      = module_decl
                 | service_decl
                 | route_decl
                 | dto_decl
                 | guard_decl
                 | island_decl ;

(* ─── Module Declaration ─────────────────────────────────── *)

module_decl      = "module" IDENT "{"
                     module_body
                   "}" ;

module_body      = { module_field } ;

module_field     = "type"         ":" module_type
                 | "version"      ":" STRING
                 | "bootstrapper" ":" FQCN
                 | "priority"     ":" INTEGER
                 | "requires"     ":" "[" ident_list "]"
                 | "surface"      ":" surface_type
                 | "license"      ":" STRING
                 | "middleware"    ":" "[" middleware_list "]"
                 | "hooks"        ":" "{" hook_entries "}" ;

module_type      = "package" | "theme" | "page" ;
surface_type     = "storefront" | "admin" | "shared" ;

(* ─── Service Declaration ────────────────────────────────── *)

service_decl     = [ annotation_list ]
                   "service" IDENT
                   [ "extends" FQCN ]
                   [ "implements" fqcn_list ]
                   "{"
                     { service_member }
                   "}" ;

service_member   = field_decl
                 | method_decl
                 | inject_decl
                 | guard_ref ;

method_decl      = [ annotation_list ]
                   [ visibility ] "fn" IDENT
                   "(" [ param_list ] ")"
                   [ "->" type_expr ]
                   [ "throws" type_expr ]
                   ( block | ";" ) ;

inject_decl      = "inject" IDENT ":" type_expr ";" ;
guard_ref        = "guard" IDENT ";" ;
visibility       = "pub" | "private" | "protected" ;

(* ─── Route Declaration ──────────────────────────────────── *)

route_decl       = [ annotation_list ]
                   "route" route_method STRING
                   "->" route_target
                   [ route_options ]
                   ";" ;

route_method     = "GET" | "POST" | "PUT" | "PATCH" | "DELETE" | "HEAD" ;

route_target     = tier_prefix FQCN_OR_ACTION ;

tier_prefix      = "@php" | "@rust" | "@elixir" | "@node" ;

route_options    = "{" { route_option } "}" ;

route_option     = "middleware"      ":" "[" middleware_list "]"
                 | "rate_limit"      ":" IDENT
                 | "auth"            ":" auth_type
                 | "cache"           ":" cache_spec
                 | "dto"             ":" IDENT ;

auth_type        = "none" | "session" | "bearer" | "api_key" ;

(* ─── DTO Declaration ────────────────────────────────────── *)

dto_decl         = [ annotation_list ]
                   "dto" IDENT
                   [ "extends" FQCN ]
                   "{"
                     { dto_field }
                   "}" ;

dto_field        = [ annotation_list ]
                   IDENT ":" type_expr
                   [ "=" literal ]
                   ";" ;

(* ─── Guard Declaration ──────────────────────────────────── *)

guard_decl       = [ annotation_list ]
                   "guard" IDENT "{"
                     "tier"      ":" tier_prefix ";"
                     "input"     ":" IDENT ";"
                     "output"    ":" IDENT ";"
                     [ "fail_mode" ":" fail_mode ";" ]
                     [ "ceiling"  ":" FLOAT ";" ]
                   "}" ;

fail_mode        = "closed" | "open" ;
(* NOTE: "open" requires @unsafe — enforced at compile time *)

(* ─── Guard Annotations (reactive behavior) ─────────────── *)
(* Guards support @trigger, @action, @condition annotations  *)
(* that define reactive rules:                                *)
(*   @trigger(on: "event_name")                               *)
(*   @action(dispatch: "job_name")                            *)
(*   @condition(key: value, ...)                              *)
(* This enables guards to act as behavioral rules:            *)
(*   "When X happens, if Y is true, do Z."                   *)

(* ─── Island Declaration ─────────────────────────────────── *)

island_decl      = "island" IDENT "{"
                     "dto"       ":" IDENT ";"
                     "component" ":" STRING ";"
                     [ "wasm"    ":" STRING ";" ]
                     [ "fallback" ":" STRING ";" ]
                     [ "lane"    ":" IDENT ";" ]
                   "}" ;

(* ─── Annotations ────────────────────────────────────────── *)

annotation_list  = { annotation } ;
annotation       = "@" IDENT [ "(" annotation_args ")" ] ;

(* ─── Type Expressions ───────────────────────────────────── *)

type_expr        = base_type [ "?" ]
                 | base_type "[" "]"
                 | "Map" "<" type_expr "," type_expr ">"
                 | "Result" "<" type_expr "," type_expr ">" ;

base_type        = "string" | "int" | "float" | "bool"
                 | "void" | "null" | "mixed"
                 | FQCN ;

(* ─── Lexical ────────────────────────────────────────────── *)

IDENT            = LETTER { LETTER | DIGIT | "_" } ;
FQCN             = IDENT { separator IDENT } ;
STRING           = '"' { CHAR } '"' ;
INTEGER          = DIGIT { DIGIT } ;
FLOAT            = DIGIT { DIGIT } "." DIGIT { DIGIT } ;
```

---

## Examples

### Route definition

```escript
@auth(bearer)
@rate_limit(strict)
route POST "/api/users"
    -> @php UserController@store
    {
        middleware: [AuthMiddleware, RateLimitMiddleware];
    };
```

### Service definition

```escript
@tier(php)
@fail_closed
service PaymentService implements PaymentInterface {
    inject db: PDO;
    inject gateway: PaymentGateway;

    guard SpendingGuard;

    pub fn charge(userId: int, amount: float) -> Result<Receipt, PaymentError> {
        // ...
    }
}
```

### DTO definition

```escript
@fluid(raw_path: "/api/v1/raw/orders", rust_action: "order::list")
dto OrderDto {
    id: int;
    total: float;
    status: string;
    created_at: string;
    buyer_name: string?;
}
```

### Guard definition

```escript
guard SpendingGuard {
    tier: @rust;
    input: SpendRequest;
    output: SpendDecision;
    fail_mode: closed;
}
```

**Reference library:** See `stdlib/fail_closed.es` for reusable DTO and guard patterns (rate limits, spending ceilings, reactive `@trigger` / `@action` / `@condition` examples).

**Compliance IR:** Pipelines that must ship with at least one guard can validate emitted JSON against `ir-schema/compliance.schema.json` (composed on top of `escript-ir-v1.json`).

---

## Compile-Time Enforcements

The compiler MUST reject:

1. Missing tier annotation on service/route
2. `fail_mode: open` without `@unsafe`
3. Type `mixed` without `@unsafe`
4. Mutating route (POST/PUT/DELETE) without `@auth` (unless explicitly `@auth(none)`)
5. Module dependency cycles
6. Guard reference without corresponding guard declaration or binary
7. Unknown types in method signatures
