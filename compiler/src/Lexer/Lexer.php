<?php

declare(strict_types=1);

namespace EScript\Compiler\Lexer;

final class Lexer
{
    private string $source;
    private int $pos = 0;
    private int $line = 1;
    private int $col = 1;
    private int $length;

    private const KEYWORDS = [
        'module'     => Token::T_MODULE,
        'service'    => Token::T_SERVICE,
        'route'      => Token::T_ROUTE,
        'dto'        => Token::T_DTO,
        'guard'      => Token::T_GUARD,
        'island'     => Token::T_ISLAND,
        'fn'         => Token::T_FN,
        'inject'     => Token::T_INJECT,
        'pub'        => Token::T_PUB,
        'private'    => Token::T_PRIVATE,
        'protected'  => Token::T_PROTECTED,
        'extends'    => Token::T_EXTENDS,
        'implements' => Token::T_IMPLEMENTS,
        'throws'     => Token::T_THROWS,
        'return'     => Token::T_RETURN,
        'let'        => Token::T_LET,
        'if'         => Token::T_IF,
        'else'       => Token::T_ELSE,
        'readonly'   => Token::T_READONLY,
        'true'       => Token::T_TRUE,
        'false'      => Token::T_FALSE,
        'null'       => Token::T_NULL,
        'GET'        => Token::T_GET,
        'POST'       => Token::T_POST,
        'PUT'        => Token::T_PUT,
        'PATCH'      => Token::T_PATCH,
        'DELETE'     => Token::T_DELETE,
        'HEAD'       => Token::T_HEAD,
    ];

    public function __construct(string $source)
    {
        $this->source = $source;
        $this->length = strlen($source);
    }

    /** @return Token[] */
    public function tokenize(): array
    {
        $tokens = [];

        while ($this->pos < $this->length) {
            $this->skipWhitespaceAndComments();

            if ($this->pos >= $this->length) {
                break;
            }

            $tokens[] = $this->readToken();
        }

        $tokens[] = new Token(Token::T_EOF, '', $this->line, $this->col);

        return $tokens;
    }

    private function readToken(): Token
    {
        $ch = $this->source[$this->pos];
        $startLine = $this->line;
        $startCol = $this->col;

        // String literal
        if ($ch === '"') {
            return $this->readString($startLine, $startCol);
        }

        // Number literal
        if (ctype_digit($ch)) {
            return $this->readNumber($startLine, $startCol);
        }

        // Identifier or keyword
        if (ctype_alpha($ch) || $ch === '_') {
            return $this->readIdentifier($startLine, $startCol);
        }

        // @ — could be tier prefix or annotation
        if ($ch === '@') {
            return $this->readAtToken($startLine, $startCol);
        }

        // Arrow ->
        if ($ch === '-' && $this->peek(1) === '>') {
            $this->advance(2);
            return new Token(Token::T_ARROW, '->', $startLine, $startCol);
        }

        // Single-char punctuation
        $singleTokens = [
            ':' => Token::T_COLON,
            ';' => Token::T_SEMICOLON,
            ',' => Token::T_COMMA,
            '.' => Token::T_DOT,
            '=' => Token::T_EQUALS,
            '?' => Token::T_QUESTION,
            '\\' => Token::T_BACKSLASH,
            '/' => Token::T_SLASH,
            '{' => Token::T_LBRACE,
            '}' => Token::T_RBRACE,
            '(' => Token::T_LPAREN,
            ')' => Token::T_RPAREN,
            '[' => Token::T_LBRACKET,
            ']' => Token::T_RBRACKET,
            '<' => Token::T_LT,
            '>' => Token::T_GT,
        ];

        if (isset($singleTokens[$ch])) {
            $this->advance();
            return new Token($singleTokens[$ch], $ch, $startLine, $startCol);
        }

        throw new \RuntimeException(sprintf(
            'Unexpected character "%s" at line %d, column %d',
            $ch, $startLine, $startCol
        ));
    }

    private function readString(int $line, int $col): Token
    {
        $this->advance(); // skip opening "
        $value = '';

        while ($this->pos < $this->length && $this->source[$this->pos] !== '"') {
            if ($this->source[$this->pos] === '\\' && $this->pos + 1 < $this->length) {
                $this->advance();
                $escaped = $this->source[$this->pos];
                $value .= match ($escaped) {
                    'n' => "\n",
                    't' => "\t",
                    '\\' => '\\',
                    '"' => '"',
                    default => '\\' . $escaped,
                };
            } else {
                $value .= $this->source[$this->pos];
            }
            $this->advance();
        }

        if ($this->pos >= $this->length) {
            throw new \RuntimeException("Unterminated string at line {$line}, column {$col}");
        }

        $this->advance(); // skip closing "

        return new Token(Token::T_STRING, $value, $line, $col);
    }

    private function readNumber(int $line, int $col): Token
    {
        $value = '';
        $isFloat = false;

        while ($this->pos < $this->length && (ctype_digit($this->source[$this->pos]) || $this->source[$this->pos] === '.')) {
            if ($this->source[$this->pos] === '.') {
                if ($isFloat) break;
                $isFloat = true;
            }
            $value .= $this->source[$this->pos];
            $this->advance();
        }

        return new Token(
            $isFloat ? Token::T_FLOAT : Token::T_INTEGER,
            $value,
            $line,
            $col
        );
    }

    private function readIdentifier(int $line, int $col): Token
    {
        $value = '';

        while ($this->pos < $this->length && (ctype_alnum($this->source[$this->pos]) || $this->source[$this->pos] === '_')) {
            $value .= $this->source[$this->pos];
            $this->advance();
        }

        $type = self::KEYWORDS[$value] ?? Token::T_IDENT;

        return new Token($type, $value, $line, $col);
    }

    private function readAtToken(int $line, int $col): Token
    {
        $this->advance(); // skip @

        // Check for tier prefix: @php, @rust, @elixir, @node
        $word = '';
        $savedPos = $this->pos;
        $savedLine = $this->line;
        $savedCol = $this->col;

        while ($this->pos < $this->length && (ctype_alnum($this->source[$this->pos]) || $this->source[$this->pos] === '_')) {
            $word .= $this->source[$this->pos];
            $this->advance();
        }

        return match ($word) {
            'php'    => new Token(Token::T_TIER_PHP, '@php', $line, $col),
            'rust'   => new Token(Token::T_TIER_RUST, '@rust', $line, $col),
            'elixir' => new Token(Token::T_TIER_ELIXIR, '@elixir', $line, $col),
            'node'   => new Token(Token::T_TIER_NODE, '@node', $line, $col),
            default  => $this->makeAnnotationToken($word, $line, $col),
        };
    }

    private function makeAnnotationToken(string $name, int $line, int $col): Token
    {
        // @ident — annotation start, return AT + IDENT will be handled by parser
        // We return AT and rewind so the parser sees @<ident>
        return new Token(Token::T_AT, '@' . $name, $line, $col);
    }

    private function skipWhitespaceAndComments(): void
    {
        while ($this->pos < $this->length) {
            $ch = $this->source[$this->pos];

            // Whitespace
            if ($ch === ' ' || $ch === "\t" || $ch === "\r") {
                $this->advance();
                continue;
            }

            // Newline
            if ($ch === "\n") {
                $this->pos++;
                $this->line++;
                $this->col = 1;
                continue;
            }

            // Line comment
            if ($ch === '/' && $this->peek(1) === '/') {
                while ($this->pos < $this->length && $this->source[$this->pos] !== "\n") {
                    $this->pos++;
                }
                continue;
            }

            // Block comment
            if ($ch === '/' && $this->peek(1) === '*') {
                $this->advance(2);
                while ($this->pos < $this->length) {
                    if ($this->source[$this->pos] === '*' && $this->peek(1) === '/') {
                        $this->advance(2);
                        break;
                    }
                    if ($this->source[$this->pos] === "\n") {
                        $this->line++;
                        $this->col = 0;
                    }
                    $this->advance();
                }
                continue;
            }

            break;
        }
    }

    private function peek(int $offset = 0): ?string
    {
        $target = $this->pos + $offset;
        return $target < $this->length ? $this->source[$target] : null;
    }

    private function advance(int $count = 1): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->pos++;
            $this->col++;
        }
    }
}
