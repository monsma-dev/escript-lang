; EScript syntax highlighting queries for tree-sitter

; Keywords
[
  "module"
  "service"
  "route"
  "dto"
  "guard"
  "island"
  "extends"
  "implements"
  "fn"
  "inject"
  "let"
  "return"
  "if"
  "else"
  "throws"
] @keyword

; Visibility
[
  "pub"
  "private"
  "protected"
  "readonly"
] @keyword.modifier

; HTTP methods
[
  "GET"
  "POST"
  "PUT"
  "PATCH"
  "DELETE"
  "HEAD"
] @keyword.directive

; Tier prefixes
[
  "@php"
  "@rust"
  "@elixir"
  "@node"
] @attribute

; Module/type keywords
[
  "package"
  "theme"
  "page"
  "storefront"
  "admin"
  "shared"
  "none"
  "session"
  "bearer"
  "api_key"
  "closed"
  "open"
] @constant.builtin

; Built-in types
[
  "string"
  "int"
  "float"
  "bool"
  "void"
  "null"
  "mixed"
  "Map"
  "Result"
] @type.builtin

; Literals
(string) @string
(integer) @number
(float) @number.float
(boolean) @boolean
(null_literal) @constant.builtin

; Annotations
(annotation
  "@" @punctuation.special
  (identifier) @attribute)

; Declarations
(module_decl
  "module" @keyword
  (module_path) @namespace)

(service_decl
  "service" @keyword
  (identifier) @type.definition)

(dto_decl
  "dto" @keyword
  (identifier) @type.definition)

(guard_decl
  "guard" @keyword
  (identifier) @type.definition)

(island_decl
  "island" @keyword
  (identifier) @type.definition)

; Methods
(method_decl
  "fn" @keyword.function
  (identifier) @function.method)

; Parameters
(param
  (identifier) @variable.parameter)

; Fields
(field_decl
  (identifier) @property)

(dto_field
  (identifier) @property)

; FQCN (namespaced identifiers)
(fqcn) @type

; Route paths
(route_decl
  (string) @string.special)

; Comments
(line_comment) @comment
(block_comment) @comment

; Operators
[
  "->"
  "="
  ":"
  ";"
  ","
  "."
] @punctuation.delimiter

; Brackets
[
  "("
  ")"
  "["
  "]"
  "{"
  "}"
  "<"
  ">"
] @punctuation.bracket
