/**
 * Tree-sitter grammar for EScript
 *
 * EScript is a security-by-design DSL that compiles to
 * any polyglot stack through pluggable adapters.
 */

module.exports = grammar({
  name: 'escript',

  extras: $ => [
    /\s/,
    $.line_comment,
    $.block_comment,
  ],

  word: $ => $.identifier,

  rules: {
    // ─── Program ──────────────────────────────────────────────
    program: $ => repeat($.declaration),

    declaration: $ => choice(
      $.module_decl,
      $.service_decl,
      $.route_decl,
      $.dto_decl,
      $.guard_decl,
      $.island_decl,
    ),

    // ─── Module ───────────────────────────────────────────────
    module_decl: $ => seq(
      'module',
      $.module_path,
      '{',
      repeat($.module_field),
      '}',
    ),

    module_path: $ => seq(
      $.identifier,
      repeat(seq('/', $.identifier)),
    ),

    module_field: $ => choice(
      seq('type', ':', $.module_type, ';'),
      seq('version', ':', $.string, ';'),
      seq('bootstrapper', ':', $.fqcn, ';'),
      seq('priority', ':', $.integer, ';'),
      seq('requires', ':', $.identifier_array, ';'),
      seq('surface', ':', $.surface_type, ';'),
      seq('license', ':', $.string, ';'),
      seq('middleware', ':', $.fqcn_array, ';'),
      seq('hooks', ':', '{', repeat($.hook_entry), '}', ';'),
    ),

    module_type: $ => choice('package', 'theme', 'page'),
    surface_type: $ => choice('storefront', 'admin', 'shared'),

    hook_entry: $ => seq(
      $.identifier, ':', $.fqcn,
      optional(','),
    ),

    // ─── Service ──────────────────────────────────────────────
    service_decl: $ => seq(
      repeat($.annotation),
      'service',
      $.identifier,
      optional(seq('extends', $.fqcn)),
      optional(seq('implements', $.fqcn_list)),
      '{',
      repeat($.service_member),
      '}',
    ),

    service_member: $ => choice(
      $.field_decl,
      $.method_decl,
      $.inject_decl,
      $.guard_ref,
    ),

    field_decl: $ => seq(
      optional($.visibility),
      optional('readonly'),
      $.identifier,
      ':',
      $.type_expr,
      optional(seq('=', $.literal)),
      ';',
    ),

    method_decl: $ => seq(
      repeat($.annotation),
      optional($.visibility),
      'fn',
      $.identifier,
      '(',
      optional($.param_list),
      ')',
      optional(seq('->', $.type_expr)),
      optional(seq('throws', $.type_expr)),
      choice($.block, ';'),
    ),

    inject_decl: $ => seq('inject', $.identifier, ':', $.type_expr, ';'),

    guard_ref: $ => seq('guard', $.identifier, ';'),

    visibility: $ => choice('pub', 'private', 'protected'),

    // ─── Route ────────────────────────────────────────────────
    route_decl: $ => seq(
      repeat($.annotation),
      'route',
      $.http_method,
      $.string,
      '->',
      $.route_target,
      optional($.route_options),
      ';',
    ),

    http_method: $ => choice('GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD'),

    route_target: $ => seq(
      $.tier_prefix,
      $.fqcn_action,
    ),

    tier_prefix: $ => choice('@php', '@rust', '@elixir', '@node'),

    fqcn_action: $ => seq(
      $.fqcn,
      optional(seq('@', $.identifier)),
    ),

    route_options: $ => seq(
      '{',
      repeat($.route_option),
      '}',
    ),

    route_option: $ => choice(
      seq('middleware', ':', $.fqcn_array, ';'),
      seq('rate_limit', ':', $.identifier, ';'),
      seq('auth', ':', $.auth_type, ';'),
      seq('rust_middlewares', ':', $.identifier_array, ';'),
      seq('cache', ':', $.cache_spec, ';'),
      seq('dto', ':', $.identifier, ';'),
    ),

    auth_type: $ => choice('none', 'session', 'bearer', 'api_key'),

    cache_spec: $ => seq(
      '{',
      'ttl', ':', $.integer,
      optional(seq(',', 'tags', ':', $.identifier_array)),
      '}',
    ),

    // ─── DTO ──────────────────────────────────────────────────
    dto_decl: $ => seq(
      repeat($.annotation),
      'dto',
      $.identifier,
      optional(seq('extends', $.fqcn)),
      '{',
      repeat($.dto_field),
      '}',
    ),

    dto_field: $ => seq(
      repeat($.annotation),
      $.identifier,
      ':',
      $.type_expr,
      optional(seq('=', $.literal)),
      ';',
    ),

    // ─── Guard ────────────────────────────────────────────────
    guard_decl: $ => seq(
      repeat($.annotation),
      'guard',
      $.identifier,
      '{',
      seq('tier', ':', $.tier_prefix, ';'),
      seq('input', ':', $.identifier, ';'),
      seq('output', ':', $.identifier, ';'),
      optional(seq('fail_mode', ':', $.fail_mode, ';')),
      optional(seq('ceiling', ':', $.literal, ';')),
      '}',
    ),

    fail_mode: $ => choice('closed', 'open'),

    // ─── Island ───────────────────────────────────────────────
    island_decl: $ => seq(
      'island',
      $.identifier,
      '{',
      seq('dto', ':', $.identifier, ';'),
      seq('component', ':', $.string, ';'),
      optional(seq('wasm', ':', $.string, ';')),
      optional(seq('fallback', ':', $.string, ';')),
      optional(seq('lane', ':', $.identifier, ';')),
      '}',
    ),

    // ─── Annotations ──────────────────────────────────────────
    annotation: $ => seq(
      '@',
      $.identifier,
      optional(seq('(', optional($.annotation_args), ')')),
    ),

    annotation_args: $ => seq(
      $.annotation_arg,
      repeat(seq(',', $.annotation_arg)),
    ),

    annotation_arg: $ => choice(
      seq($.identifier, '=', $.literal),
      seq($.identifier, ':', $.literal),
      $.literal,
      $.identifier,
    ),

    // ─── Type Expressions ─────────────────────────────────────
    type_expr: $ => choice(
      $.nullable_type,
      $.array_type,
      $.map_type,
      $.result_type,
      $.base_type,
    ),

    nullable_type: $ => seq($.base_type, '?'),
    array_type: $ => seq($.base_type, '[', ']'),
    map_type: $ => seq('Map', '<', $.type_expr, ',', $.type_expr, '>'),
    result_type: $ => seq('Result', '<', $.type_expr, ',', $.type_expr, '>'),

    base_type: $ => choice(
      'string',
      'int',
      'float',
      'bool',
      'void',
      'null',
      'mixed',
      $.fqcn,
    ),

    // ─── Parameters ───────────────────────────────────────────
    param_list: $ => seq(
      $.param,
      repeat(seq(',', $.param)),
    ),

    param: $ => seq(
      $.identifier,
      ':',
      $.type_expr,
      optional(seq('=', $.literal)),
    ),

    // ─── Shared Constructs ────────────────────────────────────
    fqcn: $ => seq(
      $.identifier,
      repeat(seq('\\', $.identifier)),
    ),

    fqcn_list: $ => seq(
      $.fqcn,
      repeat(seq(',', $.fqcn)),
    ),

    fqcn_array: $ => seq('[', optional($.fqcn_list), ']'),

    identifier_array: $ => seq(
      '[',
      optional(seq(
        $.identifier_or_colon,
        repeat(seq(',', $.identifier_or_colon)),
      )),
      ']',
    ),

    identifier_or_colon: $ => seq(
      $.identifier,
      optional(seq(':', $.identifier)),
    ),

    block: $ => seq('{', repeat($.statement), '}'),

    statement: $ => choice(
      $.expression_statement,
      $.return_statement,
      $.if_statement,
      $.let_statement,
    ),

    expression_statement: $ => seq($.expression, ';'),
    return_statement: $ => seq('return', optional($.expression), ';'),
    if_statement: $ => seq(
      'if', '(', $.expression, ')',
      $.block,
      optional(seq('else', $.block)),
    ),
    let_statement: $ => seq(
      'let', $.identifier, optional(seq(':', $.type_expr)),
      '=', $.expression, ';',
    ),

    expression: $ => choice(
      $.identifier,
      $.literal,
      $.method_call,
      $.field_access,
      $.binary_expression,
    ),

    method_call: $ => seq(
      $.expression, '.', $.identifier,
      '(', optional($.expression_list), ')',
    ),

    field_access: $ => seq($.expression, '.', $.identifier),

    binary_expression: $ => prec.left(1, seq(
      $.expression,
      choice('+', '-', '*', '/', '==', '!=', '<', '>', '<=', '>=', '&&', '||'),
      $.expression,
    )),

    expression_list: $ => seq(
      $.expression,
      repeat(seq(',', $.expression)),
    ),

    // ─── Literals ─────────────────────────────────────────────
    literal: $ => choice(
      $.string,
      $.integer,
      $.float,
      $.boolean,
      $.null_literal,
      $.array_literal,
      $.object_literal,
    ),

    string: $ => seq('"', /[^"]*/, '"'),
    integer: $ => /\d+/,
    float: $ => /\d+\.\d+/,
    boolean: $ => choice('true', 'false'),
    null_literal: $ => 'null',

    array_literal: $ => seq('[', optional(seq($.literal, repeat(seq(',', $.literal)))), ']'),
    object_literal: $ => seq('{', optional(seq($.kv_pair, repeat(seq(',', $.kv_pair)))), '}'),
    kv_pair: $ => seq(choice($.string, $.identifier), ':', $.literal),

    // ─── Lexical ──────────────────────────────────────────────
    identifier: $ => /[a-zA-Z_][a-zA-Z0-9_]*/,

    line_comment: $ => seq('//', /.*/),
    block_comment: $ => seq('/*', /[^*]*\*+([^/*][^*]*\*+)*/, '/'),
  },
});
