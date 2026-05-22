<?php

declare(strict_types=1);

namespace EScript\Compiler\Parser;

use EScript\Compiler\Ast\Node;
use EScript\Compiler\Lexer\Token;

final class Parser
{
    /** @var Token[] */
    private array $tokens;
    private int $pos = 0;
    private string $file;

    /** @param Token[] $tokens */
    public function __construct(array $tokens, string $file = '<stdin>')
    {
        $this->tokens = $tokens;
        $this->file = $file;
    }

    /** @return Node[] */
    public function parse(): array
    {
        $declarations = [];

        while (!$this->current()->is(Token::T_EOF)) {
            $declarations[] = $this->parseDeclaration();
        }

        return $declarations;
    }

    private function parseDeclaration(): Node
    {
        // Collect leading annotations
        $annotations = $this->parseAnnotations();
        $tok = $this->current();

        return match ($tok->type) {
            Token::T_MODULE  => $this->parseModule(),
            Token::T_SERVICE => $this->parseService($annotations),
            Token::T_ROUTE   => $this->parseRoute($annotations),
            Token::T_DTO     => $this->parseDto($annotations),
            Token::T_GUARD   => $this->parseGuard($annotations),
            Token::T_ISLAND  => $this->parseIsland(),
            default => throw $this->error("Expected declaration, got {$tok}"),
        };
    }

    // ─── Annotations ────────────────────────────────────────────────

    private function parseAnnotations(): array
    {
        $annotations = [];

        while ($this->current()->is(Token::T_AT)) {
            $tok = $this->consume(Token::T_AT);
            $name = ltrim($tok->value, '@');
            $args = [];

            if ($this->current()->is(Token::T_LPAREN)) {
                $this->consume(Token::T_LPAREN);
                $args = $this->parseAnnotationArgs();
                $this->consume(Token::T_RPAREN);
            }

            $annotations[] = ['name' => $name, 'args' => $args];
        }

        return $annotations;
    }

    private function parseAnnotationArgs(): array
    {
        $args = [];
        while (!$this->current()->is(Token::T_RPAREN)) {
            if ($this->current()->is(Token::T_IDENT) && ($this->peek()->is(Token::T_COLON) || $this->peek()->is(Token::T_EQUALS))) {
                $key = $this->consume(Token::T_IDENT)->value;
                $this->advance(); // skip : or =
                $value = $this->parseLiteralValue();
                $args[$key] = $value;
            } else {
                $args[] = $this->parseLiteralValue();
            }
            if ($this->current()->is(Token::T_COMMA)) {
                $this->advance();
            }
        }
        return $args;
    }

    // ─── DTO ────────────────────────────────────────────────────────

    private function parseDto(array $annotations): Node
    {
        $line = $this->current()->line;
        $this->consume(Token::T_DTO);
        $name = $this->consume(Token::T_IDENT)->value;

        $extends = null;
        if ($this->current()->is(Token::T_EXTENDS)) {
            $this->advance();
            $extends = $this->parseFqcn();
        }

        $this->consume(Token::T_LBRACE);
        $fields = [];

        while (!$this->current()->is(Token::T_RBRACE)) {
            $fieldAnnotations = $this->parseAnnotations();
            $fieldName = $this->consume(Token::T_IDENT)->value;
            $this->consume(Token::T_COLON);
            $fieldType = $this->parseTypeExpr();

            $default = null;
            if ($this->current()->is(Token::T_EQUALS)) {
                $this->advance();
                $default = $this->parseLiteralValue();
            }
            $this->consume(Token::T_SEMICOLON);

            $fields[] = [
                'name' => $fieldName,
                'type' => $fieldType,
                'default' => $default,
                'annotations' => $fieldAnnotations,
            ];
        }
        $this->consume(Token::T_RBRACE);

        return new Node('dto', [
            'name' => $name,
            'extends' => $extends,
            'fields' => $fields,
            'annotations' => $annotations,
        ], $line);
    }

    // ─── Guard ──────────────────────────────────────────────────────

    private function parseGuard(array $annotations): Node
    {
        $line = $this->current()->line;
        $this->consume(Token::T_GUARD);
        $name = $this->consume(Token::T_IDENT)->value;
        $this->consume(Token::T_LBRACE);

        $data = ['name' => $name, 'annotations' => $annotations];

        while (!$this->current()->is(Token::T_RBRACE)) {
            $key = $this->consume(Token::T_IDENT)->value;
            $this->consume(Token::T_COLON);

            $data[$key] = match ($key) {
                'tier' => $this->parseTierPrefix(),
                'fail_mode' => $this->consume(Token::T_IDENT)->value,
                'input', 'output' => $this->consume(Token::T_IDENT)->value,
                'ceiling' => $this->parseNumericValue(),
                default => $this->parseLiteralValue(),
            };
            $this->consume(Token::T_SEMICOLON);
        }
        $this->consume(Token::T_RBRACE);

        return new Node('guard', $data, $line);
    }

    // ─── Route ──────────────────────────────────────────────────────

    private function parseRoute(array $annotations): Node
    {
        $line = $this->current()->line;
        $this->consume(Token::T_ROUTE);
        $method = $this->current()->value;
        $this->advance(); // consume HTTP method token
        $path = $this->consume(Token::T_STRING)->value;
        $this->consume(Token::T_ARROW);

        $tier = $this->parseTierPrefix();
        $target = $this->parseRouteTarget();

        $options = [];
        if ($this->current()->is(Token::T_LBRACE)) {
            $options = $this->parseRouteOptions();
        }
        $this->consume(Token::T_SEMICOLON);

        return new Node('route', [
            'method' => $method,
            'path' => $path,
            'tier' => $tier,
            'target' => $target,
            'options' => $options,
            'annotations' => $annotations,
        ], $line);
    }

    private function parseRouteTarget(): array
    {
        $fqcn = $this->parseFqcn();
        $action = null;

        // Controller@action syntax
        if ($this->current()->is(Token::T_AT)) {
            $tok = $this->consume(Token::T_AT);
            // The AT token already consumed the identifier after @
            $actionName = ltrim($tok->value, '@');
            if ($actionName === '') {
                $actionName = $this->consume(Token::T_IDENT)->value;
            }
            $action = $actionName;
        }

        return ['controller' => $fqcn, 'action' => $action];
    }

    private function parseRouteOptions(): array
    {
        $this->consume(Token::T_LBRACE);
        $options = [];

        while (!$this->current()->is(Token::T_RBRACE)) {
            $key = $this->consumeIdentOrKeyword()->value;
            $this->consume(Token::T_COLON);

            $options[$key] = match ($key) {
                'middleware', 'rust_middlewares' => $this->parseIdentArray(),
                'auth' => $this->consumeIdentOrKeyword()->value,
                'rate_limit' => $this->consumeIdentOrKeyword()->value,
                'dto' => $this->consumeIdentOrKeyword()->value,
                default => $this->parseLiteralValue(),
            };
            $this->consume(Token::T_SEMICOLON);
        }
        $this->consume(Token::T_RBRACE);

        return $options;
    }

    // ─── Service ────────────────────────────────────────────────────

    private function parseService(array $annotations): Node
    {
        $line = $this->current()->line;
        $this->consume(Token::T_SERVICE);
        $name = $this->consume(Token::T_IDENT)->value;

        $extends = null;
        if ($this->current()->is(Token::T_EXTENDS)) {
            $this->advance();
            $extends = $this->parseFqcn();
        }

        $implements = [];
        if ($this->current()->is(Token::T_IMPLEMENTS)) {
            $this->advance();
            $implements = $this->parseFqcnList();
        }

        $this->consume(Token::T_LBRACE);

        $injects = [];
        $guards = [];
        $methods = [];

        while (!$this->current()->is(Token::T_RBRACE)) {
            $memberAnnotations = $this->parseAnnotations();

            if ($this->current()->is(Token::T_INJECT)) {
                $this->advance();
                $iName = $this->consume(Token::T_IDENT)->value;
                $this->consume(Token::T_COLON);
                $iType = $this->parseTypeExpr();
                $this->consume(Token::T_SEMICOLON);
                $injects[] = ['name' => $iName, 'type' => $iType];
            } elseif ($this->current()->is(Token::T_GUARD)) {
                $this->advance();
                $guards[] = $this->consume(Token::T_IDENT)->value;
                $this->consume(Token::T_SEMICOLON);
            } elseif ($this->current()->is(Token::T_PUB) || $this->current()->is(Token::T_PRIVATE) || $this->current()->is(Token::T_PROTECTED) || $this->current()->is(Token::T_FN)) {
                $methods[] = $this->parseMethod($memberAnnotations);
            } else {
                throw $this->error("Unexpected token in service body: {$this->current()}");
            }
        }
        $this->consume(Token::T_RBRACE);

        return new Node('service', [
            'name' => $name,
            'extends' => $extends,
            'implements' => $implements,
            'injects' => $injects,
            'guards' => $guards,
            'methods' => $methods,
            'annotations' => $annotations,
        ], $line);
    }

    private function parseMethod(array $annotations): array
    {
        $visibility = 'public';
        if ($this->current()->is(Token::T_PUB)) {
            $visibility = 'public';
            $this->advance();
        } elseif ($this->current()->is(Token::T_PRIVATE)) {
            $visibility = 'private';
            $this->advance();
        } elseif ($this->current()->is(Token::T_PROTECTED)) {
            $visibility = 'protected';
            $this->advance();
        }

        $this->consume(Token::T_FN);
        $name = $this->consume(Token::T_IDENT)->value;

        $this->consume(Token::T_LPAREN);
        $params = [];
        while (!$this->current()->is(Token::T_RPAREN)) {
            $pName = $this->consume(Token::T_IDENT)->value;
            $this->consume(Token::T_COLON);
            $pType = $this->parseTypeExpr();

            $default = null;
            if ($this->current()->is(Token::T_EQUALS)) {
                $this->advance();
                $default = $this->parseLiteralValue();
            }

            $params[] = ['name' => $pName, 'type' => $pType, 'default' => $default];

            if ($this->current()->is(Token::T_COMMA)) {
                $this->advance();
            }
        }
        $this->consume(Token::T_RPAREN);

        $returnType = null;
        if ($this->current()->is(Token::T_ARROW)) {
            $this->advance();
            $returnType = $this->parseTypeExpr();
        }

        $throws = [];
        if ($this->current()->is(Token::T_THROWS)) {
            $this->advance();
            $throws[] = $this->parseTypeExpr();
        }

        // Skip method body or semicolon
        if ($this->current()->is(Token::T_LBRACE)) {
            $this->skipBlock();
        } else {
            $this->consume(Token::T_SEMICOLON);
        }

        return [
            'name' => $name,
            'visibility' => $visibility,
            'params' => $params,
            'return_type' => $returnType,
            'throws' => $throws,
            'annotations' => $annotations,
        ];
    }

    // ─── Module ─────────────────────────────────────────────────────

    private function parseModule(): Node
    {
        $line = $this->current()->line;
        $this->consume(Token::T_MODULE);

        $name = $this->consume(Token::T_IDENT)->value;
        while ($this->current()->is(Token::T_SLASH)) {
            $this->advance();
            $name .= '/' . $this->consume(Token::T_IDENT)->value;
        }

        $this->consume(Token::T_LBRACE);
        $data = ['name' => $name];

        while (!$this->current()->is(Token::T_RBRACE)) {
            $key = $this->consume(Token::T_IDENT)->value;
            $this->consume(Token::T_COLON);

            $data[$key] = match ($key) {
                'type', 'surface' => $this->consume(Token::T_IDENT)->value,
                'version', 'bootstrapper', 'license' => $this->parseFqcnOrString(),
                'priority' => (int) $this->consume(Token::T_INTEGER)->value,
                'requires', 'middleware' => $this->parseIdentArray(),
                default => $this->parseLiteralValue(),
            };
            $this->consume(Token::T_SEMICOLON);
        }
        $this->consume(Token::T_RBRACE);

        return new Node('module', $data, $line);
    }

    // ─── Island ─────────────────────────────────────────────────────

    private function parseIsland(): Node
    {
        $line = $this->current()->line;
        $this->consume(Token::T_ISLAND);
        $name = $this->consume(Token::T_IDENT)->value;
        $this->consume(Token::T_LBRACE);

        $data = ['name' => $name];

        while (!$this->current()->is(Token::T_RBRACE)) {
            $key = $this->consume(Token::T_IDENT)->value;
            $this->consume(Token::T_COLON);

            $data[$key] = match ($key) {
                'dto', 'lane' => $this->consume(Token::T_IDENT)->value,
                'component', 'wasm', 'fallback' => $this->consume(Token::T_STRING)->value,
                default => $this->parseLiteralValue(),
            };
            $this->consume(Token::T_SEMICOLON);
        }
        $this->consume(Token::T_RBRACE);

        return new Node('island', $data, $line);
    }

    // ─── Helpers ────────────────────────────────────────────────────

    private function parseTierPrefix(): string
    {
        $tok = $this->current();
        if (in_array($tok->type, [Token::T_TIER_PHP, Token::T_TIER_RUST, Token::T_TIER_ELIXIR, Token::T_TIER_NODE], true)) {
            $this->advance();
            return str_replace('@', '', $tok->value);
        }
        throw $this->error("Expected tier prefix (@php, @rust, @elixir, @node), got {$tok}");
    }

    private function parseFqcn(): string
    {
        $name = $this->consume(Token::T_IDENT)->value;
        while ($this->current()->is(Token::T_BACKSLASH)) {
            $this->advance();
            $name .= '\\' . $this->consume(Token::T_IDENT)->value;
        }
        // Also support :: for Rust-style paths
        while ($this->current()->is(Token::T_COLON) && $this->peek()->is(Token::T_COLON)) {
            $this->advance();
            $this->advance();
            $name .= '::' . $this->consume(Token::T_IDENT)->value;
        }
        return $name;
    }

    private function parseFqcnList(): array
    {
        $list = [$this->parseFqcn()];
        while ($this->current()->is(Token::T_COMMA)) {
            $this->advance();
            $list[] = $this->parseFqcn();
        }
        return $list;
    }

    private function parseFqcnOrString(): string
    {
        if ($this->current()->is(Token::T_STRING)) {
            return $this->consume(Token::T_STRING)->value;
        }
        return $this->parseFqcn();
    }

    private function parseIdentArray(): array
    {
        $this->consume(Token::T_LBRACKET);
        $items = [];
        while (!$this->current()->is(Token::T_RBRACKET)) {
            $item = $this->consume(Token::T_IDENT)->value;
            // Support colon-separated idents like ip:ban_check
            if ($this->current()->is(Token::T_COLON)) {
                $this->advance();
                $item .= ':' . $this->consume(Token::T_IDENT)->value;
            }
            $items[] = $item;
            if ($this->current()->is(Token::T_COMMA)) {
                $this->advance();
            }
        }
        $this->consume(Token::T_RBRACKET);
        return $items;
    }

    private function parseTypeExpr(): string
    {
        // Result<Ok, Err>
        if ($this->current()->is(Token::T_IDENT) && $this->current()->value === 'Result') {
            $this->advance();
            $this->consume(Token::T_LT);
            $ok = $this->parseTypeExpr();
            $this->consume(Token::T_COMMA);
            $err = $this->parseTypeExpr();
            $this->consume(Token::T_GT);
            return "Result<{$ok}, {$err}>";
        }

        // Map<K, V>
        if ($this->current()->is(Token::T_IDENT) && $this->current()->value === 'Map') {
            $this->advance();
            $this->consume(Token::T_LT);
            $k = $this->parseTypeExpr();
            $this->consume(Token::T_COMMA);
            $v = $this->parseTypeExpr();
            $this->consume(Token::T_GT);
            return "Map<{$k}, {$v}>";
        }

        $base = $this->parseFqcn();

        // Nullable: Type?
        if ($this->current()->is(Token::T_QUESTION)) {
            $this->advance();
            return $base . '?';
        }

        // Array: Type[]
        if ($this->current()->is(Token::T_LBRACKET) && $this->peek()->is(Token::T_RBRACKET)) {
            $this->advance();
            $this->advance();
            return $base . '[]';
        }

        return $base;
    }

    private function parseLiteralValue(): string|int|float|bool|null
    {
        $tok = $this->current();
        $this->advance();

        return match ($tok->type) {
            Token::T_STRING  => $tok->value,
            Token::T_INTEGER => (int) $tok->value,
            Token::T_FLOAT   => (float) $tok->value,
            Token::T_TRUE    => true,
            Token::T_FALSE   => false,
            Token::T_NULL    => null,
            Token::T_IDENT   => $tok->value,
            default => throw $this->error("Expected literal, got {$tok}"),
        };
    }

    private function parseNumericValue(): float|int
    {
        $tok = $this->current();
        $this->advance();

        return match ($tok->type) {
            Token::T_INTEGER => (int) $tok->value,
            Token::T_FLOAT   => (float) $tok->value,
            default => throw $this->error("Expected number, got {$tok}"),
        };
    }

    private function skipBlock(): void
    {
        $this->consume(Token::T_LBRACE);
        $depth = 1;
        while ($depth > 0 && !$this->current()->is(Token::T_EOF)) {
            if ($this->current()->is(Token::T_LBRACE)) $depth++;
            if ($this->current()->is(Token::T_RBRACE)) $depth--;
            if ($depth > 0) $this->advance();
        }
        $this->consume(Token::T_RBRACE);
    }

    private function current(): Token
    {
        return $this->tokens[$this->pos] ?? new Token(Token::T_EOF, '', 0, 0);
    }

    private function peek(): Token
    {
        return $this->tokens[$this->pos + 1] ?? new Token(Token::T_EOF, '', 0, 0);
    }

    private function advance(): void
    {
        $this->pos++;
    }

    private function consume(string $type): Token
    {
        $tok = $this->current();
        if (!$tok->is($type)) {
            throw $this->error("Expected {$type}, got {$tok}");
        }
        $this->advance();
        return $tok;
    }

    private function consumeIdentOrKeyword(): Token
    {
        $tok = $this->current();
        // Accept IDENT or any keyword token — keywords can appear as option keys
        if ($tok->is(Token::T_IDENT) || $tok->is(Token::T_DTO) || $tok->is(Token::T_GUARD)
            || $tok->is(Token::T_MODULE) || $tok->is(Token::T_SERVICE) || $tok->is(Token::T_ROUTE)
            || $tok->is(Token::T_ISLAND) || $tok->is(Token::T_INJECT) || $tok->is(Token::T_FN)) {
            $this->advance();
            return new Token(Token::T_IDENT, $tok->value, $tok->line, $tok->column);
        }
        throw $this->error("Expected identifier, got {$tok}");
    }

    private function error(string $msg): \RuntimeException
    {
        $tok = $this->current();
        return new \RuntimeException("[{$this->file}:{$tok->line}:{$tok->column}] {$msg}");
    }
}
