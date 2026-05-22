<?php

declare(strict_types=1);

namespace EScript\Compiler\Lexer;

final class Token
{
    // Keywords
    public const T_MODULE     = 'MODULE';
    public const T_SERVICE    = 'SERVICE';
    public const T_ROUTE      = 'ROUTE';
    public const T_DTO        = 'DTO';
    public const T_GUARD      = 'GUARD';
    public const T_ISLAND     = 'ISLAND';
    public const T_FN         = 'FN';
    public const T_INJECT     = 'INJECT';
    public const T_PUB        = 'PUB';
    public const T_PRIVATE    = 'PRIVATE';
    public const T_PROTECTED  = 'PROTECTED';
    public const T_EXTENDS    = 'EXTENDS';
    public const T_IMPLEMENTS = 'IMPLEMENTS';
    public const T_THROWS     = 'THROWS';
    public const T_RETURN     = 'RETURN';
    public const T_LET        = 'LET';
    public const T_IF         = 'IF';
    public const T_ELSE       = 'ELSE';
    public const T_READONLY   = 'READONLY';

    // Literals
    public const T_STRING     = 'STRING';
    public const T_INTEGER    = 'INTEGER';
    public const T_FLOAT      = 'FLOAT';
    public const T_TRUE       = 'TRUE';
    public const T_FALSE      = 'FALSE';
    public const T_NULL       = 'NULL';

    // Identifiers
    public const T_IDENT      = 'IDENT';

    // HTTP methods
    public const T_GET        = 'GET';
    public const T_POST       = 'POST';
    public const T_PUT        = 'PUT';
    public const T_PATCH      = 'PATCH';
    public const T_DELETE     = 'DELETE';
    public const T_HEAD       = 'HEAD';

    // Tier prefixes
    public const T_TIER_PHP    = 'TIER_PHP';
    public const T_TIER_RUST   = 'TIER_RUST';
    public const T_TIER_ELIXIR = 'TIER_ELIXIR';
    public const T_TIER_NODE   = 'TIER_NODE';

    // Operators / punctuation
    public const T_AT         = 'AT';
    public const T_COLON      = 'COLON';
    public const T_SEMICOLON  = 'SEMICOLON';
    public const T_COMMA      = 'COMMA';
    public const T_DOT        = 'DOT';
    public const T_ARROW      = 'ARROW';
    public const T_EQUALS     = 'EQUALS';
    public const T_QUESTION   = 'QUESTION';
    public const T_BACKSLASH  = 'BACKSLASH';
    public const T_SLASH      = 'SLASH';
    public const T_LBRACE     = 'LBRACE';
    public const T_RBRACE     = 'RBRACE';
    public const T_LPAREN     = 'LPAREN';
    public const T_RPAREN     = 'RPAREN';
    public const T_LBRACKET   = 'LBRACKET';
    public const T_RBRACKET   = 'RBRACKET';
    public const T_LT         = 'LT';
    public const T_GT         = 'GT';

    // Special
    public const T_EOF        = 'EOF';

    public function __construct(
        public readonly string $type,
        public readonly string $value,
        public readonly int $line,
        public readonly int $column,
    ) {}

    public function is(string $type): bool
    {
        return $this->type === $type;
    }

    public function isKeyword(string $value): bool
    {
        return $this->type === self::T_IDENT && strtolower($this->value) === strtolower($value);
    }

    public function __toString(): string
    {
        return sprintf('%s(%s) at %d:%d', $this->type, $this->value, $this->line, $this->column);
    }
}
