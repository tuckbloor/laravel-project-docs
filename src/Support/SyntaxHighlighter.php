<?php

namespace DevDocs\LaravelProjectDocs\Support;

class SyntaxHighlighter
{
    /** @return array<int, string> */
    public function lines(string $source, string $language): array
    {
        $source = str_replace(["\r\n", "\r"], "\n", $source);
        $language = strtolower($language);

        return match ($language) {
            'php' => $this->phpLines($source),
            'env' => $this->envLines($source),
            default => $this->genericLines($source, $language),
        };
    }


    /** @return array<int, string> */
    private function envLines(string $source): array
    {
        $result = [];
        foreach (explode("\n", $source) as $line) {
            if ($line === '') {
                $result[] = '';
                continue;
            }

            if (preg_match('/^(\s*)#(.*)$/', $line, $comment)) {
                $result[] = $this->escape($comment[1]).$this->span('#'.$comment[2], 'syn-comment');
                continue;
            }

            if (preg_match('/^(\s*)([A-Za-z_][A-Za-z0-9_.-]*)(\s*=\s*)(.*)$/', $line, $match)) {
                $value = (string) $match[4];
                $valueClass = preg_match('/^-?\d+(?:\.\d+)?$/', $value) ? 'syn-number' : 'syn-string';
                $result[] = $this->escape($match[1])
                    .$this->span($match[2], 'syn-attribute')
                    .$this->span($match[3], 'syn-operator')
                    .$this->span($value, $valueClass);
                continue;
            }

            $result[] = $this->escape($line);
        }

        return $result;
    }

    /** @return array<int, string> */
    private function phpLines(string $source): array
    {
        $lines = [''];
        $tokens = token_get_all($source);
        $expectTypeName = false;
        $expectFunctionName = false;
        $previousSignificant = null;

        $typeIntroducers = array_filter([
            defined('T_NEW') ? T_NEW : null,
            defined('T_EXTENDS') ? T_EXTENDS : null,
            defined('T_IMPLEMENTS') ? T_IMPLEMENTS : null,
            defined('T_INSTANCEOF') ? T_INSTANCEOF : null,
            defined('T_CATCH') ? T_CATCH : null,
        ], static fn ($value) => $value !== null);

        $keywordTokens = array_filter([
            T_ABSTRACT, T_ARRAY, T_AS, T_BREAK, T_CALLABLE, T_CASE, T_CATCH,
            T_CLASS, T_CLONE, T_CONST, T_CONTINUE, T_DECLARE, T_DEFAULT, T_DO,
            T_ECHO, T_ELSE, T_ELSEIF, T_EMPTY, T_ENDDECLARE, T_ENDFOR,
            T_ENDFOREACH, T_ENDIF, T_ENDSWITCH, T_ENDWHILE, T_EVAL, T_EXIT,
            T_EXTENDS, T_FINAL, T_FINALLY, T_FN, T_FOR, T_FOREACH, T_FUNCTION,
            T_GLOBAL, T_GOTO, T_IF, T_IMPLEMENTS, T_INCLUDE, T_INCLUDE_ONCE,
            T_INSTANCEOF, T_INSTEADOF, T_INTERFACE, T_ISSET, T_LIST, T_MATCH,
            T_NAMESPACE, T_NEW, T_PRINT, T_PRIVATE, T_PROTECTED, T_PUBLIC,
            T_REQUIRE, T_REQUIRE_ONCE, T_RETURN, T_STATIC, T_SWITCH, T_THROW,
            T_TRAIT, T_TRY, T_UNSET, T_USE, T_VAR, T_WHILE, T_YIELD,
            defined('T_ENUM') ? T_ENUM : null,
            defined('T_READONLY') ? T_READONLY : null,
        ], static fn ($value) => $value !== null);

        $operatorTokens = array_filter([
            T_BOOLEAN_AND, T_BOOLEAN_OR, T_COALESCE, T_CONCAT_EQUAL,
            T_DEC, T_DIV_EQUAL, T_DOUBLE_ARROW, T_DOUBLE_COLON, T_ELLIPSIS,
            T_INC, T_IS_EQUAL, T_IS_GREATER_OR_EQUAL, T_IS_IDENTICAL,
            T_IS_NOT_EQUAL, T_IS_NOT_IDENTICAL, T_IS_SMALLER_OR_EQUAL,
            T_LOGICAL_AND, T_LOGICAL_OR, T_LOGICAL_XOR, T_MINUS_EQUAL,
            T_MOD_EQUAL, T_MUL_EQUAL, T_OBJECT_OPERATOR, T_OR_EQUAL,
            T_PLUS_EQUAL, T_POW, T_POW_EQUAL, T_SL, T_SL_EQUAL, T_SPACESHIP,
            T_SR, T_SR_EQUAL, T_XOR_EQUAL,
            defined('T_NULLSAFE_OBJECT_OPERATOR') ? T_NULLSAFE_OBJECT_OPERATOR : null,
        ], static fn ($value) => $value !== null);

        foreach ($tokens as $index => $token) {
            if (is_string($token)) {
                $class = str_contains('{}()[];,:.?=+-*/%!&|<>@', $token) ? 'syn-operator' : null;
                $this->append($lines, $token, $class);

                if (trim($token) !== '' && ! in_array($token, ['&', '\\'], true)) {
                    $previousSignificant = $token;
                }
                continue;
            }

            [$id, $text] = $token;
            $class = null;

            if (in_array($id, [T_COMMENT, T_DOC_COMMENT], true)) {
                $class = 'syn-comment';
            } elseif (in_array($id, [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                $class = 'syn-string';
            } elseif ($id === T_VARIABLE) {
                $class = 'syn-variable';
            } elseif (in_array($id, [T_LNUMBER, T_DNUMBER], true)) {
                $class = 'syn-number';
            } elseif (defined('T_ATTRIBUTE') && $id === T_ATTRIBUTE) {
                $class = 'syn-attribute';
            } elseif (in_array($id, $keywordTokens, true)) {
                $class = 'syn-keyword';

                if (in_array($id, [T_CLASS, T_INTERFACE, T_TRAIT], true) || (defined('T_ENUM') && $id === T_ENUM)) {
                    $expectTypeName = true;
                }

                if ($id === T_FUNCTION) {
                    $expectFunctionName = true;
                }
            } elseif ($id === T_STRING) {
                $nextSignificant = $this->nextSignificantToken($tokens, $index);
                $previousIsAccess = in_array($previousSignificant, [
                    T_OBJECT_OPERATOR,
                    T_DOUBLE_COLON,
                    defined('T_NULLSAFE_OBJECT_OPERATOR') ? T_NULLSAFE_OBJECT_OPERATOR : -1,
                ], true);

                if ($expectFunctionName) {
                    $class = 'syn-function';
                    $expectFunctionName = false;
                } elseif ($expectTypeName || in_array($previousSignificant, $typeIntroducers, true)) {
                    $class = 'syn-type';
                    $expectTypeName = false;
                } elseif ($previousSignificant === ':' || (is_array($nextSignificant) && $nextSignificant[0] === T_VARIABLE)) {
                    $class = 'syn-type';
                } elseif ($previousIsAccess) {
                    $class = $nextSignificant === '(' ? 'syn-function' : 'syn-property';
                } elseif ($nextSignificant === '(') {
                    $class = 'syn-function';
                } else {
                    $class = 'syn-identifier';
                }
            } elseif (in_array($id, array_filter([
                defined('T_NAME_QUALIFIED') ? T_NAME_QUALIFIED : null,
                defined('T_NAME_FULLY_QUALIFIED') ? T_NAME_FULLY_QUALIFIED : null,
                defined('T_NAME_RELATIVE') ? T_NAME_RELATIVE : null,
            ], static fn ($value) => $value !== null), true)) {
                $class = in_array($previousSignificant, $typeIntroducers, true) || $expectTypeName
                    ? 'syn-type'
                    : 'syn-identifier';
                $expectTypeName = false;
            } elseif (in_array($id, $operatorTokens, true)) {
                $class = 'syn-operator';
            } elseif (in_array($id, [T_OPEN_TAG, T_OPEN_TAG_WITH_ECHO, T_CLOSE_TAG], true)) {
                $class = 'syn-keyword';
            }

            $this->append($lines, $text, $class);

            if ($id !== T_WHITESPACE && ! in_array($id, [T_COMMENT, T_DOC_COMMENT], true)) {
                $previousSignificant = $id;
            }
        }

        return $lines;
    }

    /** @return array<int, string> */
    private function genericLines(string $source, string $language): array
    {
        $rawLines = explode("\n", $source);
        $result = [];
        $state = [
            'block_comment' => false,
            'blade_comment' => false,
            'quote' => null,
            'expect_function' => false,
            'expect_type' => false,
        ];

        foreach ($rawLines as $line) {
            $result[] = $this->genericLine($line, $language, $state);
        }

        return $result;
    }

    /** @param array<string, mixed> $state */
    private function genericLine(string $line, string $language, array &$state): string
    {
        $out = '';
        $length = strlen($line);
        $i = 0;
        $keywords = $this->keywords($language);

        while ($i < $length) {
            if ($state['blade_comment']) {
                $end = strpos($line, '--}}', $i);
                if ($end === false) {
                    $out .= $this->span(substr($line, $i), 'syn-comment');
                    return $out;
                }
                $out .= $this->span(substr($line, $i, $end + 4 - $i), 'syn-comment');
                $state['blade_comment'] = false;
                $i = $end + 4;
                continue;
            }

            if ($state['block_comment']) {
                $end = strpos($line, '*/', $i);
                if ($end === false) {
                    $out .= $this->span(substr($line, $i), 'syn-comment');
                    return $out;
                }
                $out .= $this->span(substr($line, $i, $end + 2 - $i), 'syn-comment');
                $state['block_comment'] = false;
                $i = $end + 2;
                continue;
            }

            if ($state['quote'] !== null) {
                [$fragment, $closed, $next] = $this->consumeString($line, $i, (string) $state['quote'], false);
                $out .= $this->span($fragment, 'syn-string');
                $i = $next;
                if ($closed) {
                    $state['quote'] = null;
                }
                continue;
            }

            if ($language === 'blade' && substr($line, $i, 4) === '{{--') {
                $end = strpos($line, '--}}', $i + 4);
                if ($end === false) {
                    $out .= $this->span(substr($line, $i), 'syn-comment');
                    $state['blade_comment'] = true;
                    return $out;
                }
                $out .= $this->span(substr($line, $i, $end + 4 - $i), 'syn-comment');
                $i = $end + 4;
                continue;
            }

            if (substr($line, $i, 2) === '/*') {
                $end = strpos($line, '*/', $i + 2);
                if ($end === false) {
                    $out .= $this->span(substr($line, $i), 'syn-comment');
                    $state['block_comment'] = true;
                    return $out;
                }
                $out .= $this->span(substr($line, $i, $end + 2 - $i), 'syn-comment');
                $i = $end + 2;
                continue;
            }

            if (substr($line, $i, 2) === '//') {
                $out .= $this->span(substr($line, $i), 'syn-comment');
                return $out;
            }

            $char = $line[$i];

            if (in_array($char, ['\'', '"', '`'], true)) {
                [$fragment, $closed, $next] = $this->consumeString($line, $i, $char, true);
                $out .= $this->span($fragment, 'syn-string');
                $i = $next;
                if (! $closed) {
                    $state['quote'] = $char;
                }
                continue;
            }

            if ($language === 'blade' && $char === '@' && preg_match('/\G@[A-Za-z_][A-Za-z0-9_]*/A', $line, $match, 0, $i)) {
                $out .= $this->span($match[0], 'syn-directive');
                $i += strlen($match[0]);
                continue;
            }

            if ($char === '$' && preg_match('/\G\$[A-Za-z_][A-Za-z0-9_]*/A', $line, $match, 0, $i)) {
                $out .= $this->span($match[0], 'syn-variable');
                $i += strlen($match[0]);
                continue;
            }

            if (ctype_digit($char) && preg_match('/\G\d+(?:\.\d+)?/A', $line, $match, 0, $i)) {
                $out .= $this->span($match[0], 'syn-number');
                $i += strlen($match[0]);
                continue;
            }

            if ($char === '<' && preg_match('/\G<\/?([A-Za-z][A-Za-z0-9:._-]*)/A', $line, $match, 0, $i)) {
                $prefix = str_starts_with($match[0], '</') ? '</' : '<';
                $out .= $this->span($prefix, 'syn-operator').$this->span($match[1], 'syn-type');
                $i += strlen($match[0]);
                continue;
            }

            if (preg_match('/[A-Za-z_]/', $char) && preg_match('/\G[A-Za-z_][A-Za-z0-9_$]*/A', $line, $match, 0, $i)) {
                $word = $match[0];
                $class = 'syn-identifier';

                if (in_array($word, $keywords, true)) {
                    $class = 'syn-keyword';
                    $state['expect_function'] = in_array($word, ['function'], true);
                    $state['expect_type'] = in_array($word, ['class', 'interface', 'extends', 'implements', 'new', 'type'], true);
                } elseif ($state['expect_function']) {
                    $class = 'syn-function';
                    $state['expect_function'] = false;
                } elseif ($state['expect_type']) {
                    $class = 'syn-type';
                    $state['expect_type'] = false;
                } else {
                    $rest = substr($line, $i + strlen($word));
                    if (preg_match('/^\s*\(/', $rest)) {
                        $class = 'syn-function';
                    } elseif (preg_match('/^[A-Z]/', $word)) {
                        $class = 'syn-type';
                    }
                }

                $out .= $this->span($word, $class);
                $i += strlen($word);
                continue;
            }

            if (str_contains('{}()[];,:.?=+-*/%!&|<>', $char)) {
                $out .= $this->span($char, 'syn-operator');
            } else {
                $out .= $this->escape($char);
            }
            $i++;
        }

        return $out;
    }

    /** @return array{0:string,1:bool,2:int} */
    private function consumeString(string $line, int $start, string $quote, bool $includeOpening): array
    {
        $length = strlen($line);
        $i = $start;
        if ($includeOpening) {
            $i++;
        }
        $escaped = false;

        while ($i < $length) {
            $char = $line[$i];
            if ($escaped) {
                $escaped = false;
                $i++;
                continue;
            }
            if ($char === '\\') {
                $escaped = true;
                $i++;
                continue;
            }
            if ($char === $quote) {
                $i++;
                return [substr($line, $start, $i - $start), true, $i];
            }
            $i++;
        }

        return [substr($line, $start), false, $length];
    }


    /** @param array<int, array{0:int,1:string,2?:int}|string> $tokens */
    private function nextSignificantToken(array $tokens, int $index): array|string|null
    {
        $count = count($tokens);
        for ($i = $index + 1; $i < $count; $i++) {
            $token = $tokens[$i];
            if (is_string($token)) {
                if (trim($token) !== '') {
                    return $token;
                }
                continue;
            }

            if (! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $token;
            }
        }

        return null;
    }

    /** @return array<int, string> */
    private function keywords(string $language): array
    {
        $common = [
            'as', 'async', 'await', 'break', 'case', 'catch', 'class', 'const',
            'continue', 'default', 'delete', 'do', 'else', 'export', 'extends',
            'false', 'finally', 'for', 'from', 'function', 'if', 'implements',
            'import', 'in', 'instanceof', 'interface', 'let', 'new', 'null',
            'of', 'return', 'static', 'super', 'switch', 'this', 'throw', 'true',
            'try', 'typeof', 'undefined', 'var', 'void', 'while', 'with', 'yield',
        ];

        if ($language === 'typescript') {
            return array_values(array_unique(array_merge($common, [
                'abstract', 'any', 'boolean', 'declare', 'enum', 'keyof', 'namespace',
                'never', 'number', 'private', 'protected', 'public', 'readonly',
                'string', 'type', 'unknown',
            ])));
        }

        if ($language === 'blade') {
            return array_values(array_unique(array_merge($common, ['echo'])));
        }

        return $common;
    }

    /** @param array<int, string> $lines */
    private function append(array &$lines, string $text, ?string $class): void
    {
        $parts = explode("\n", str_replace("\r", '', $text));
        foreach ($parts as $index => $part) {
            if ($index > 0) {
                $lines[] = '';
            }
            if ($part !== '') {
                $lines[array_key_last($lines)] .= $this->span($part, $class);
            }
        }
    }

    private function span(string $text, ?string $class): string
    {
        $escaped = $this->escape($text);

        return $class ? '<span class="'.$class.'">'.$escaped.'</span>' : $escaped;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
