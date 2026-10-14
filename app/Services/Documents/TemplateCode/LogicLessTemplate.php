<?php

namespace App\Services\Documents\TemplateCode;

use InvalidArgumentException;

/**
 * A logic-less (Mustache style) template engine for the invoice template code editor. It can only do four things:
 *
 *   {{path.to.value}}              the value, HTML-escaped
 *   {{#name}} ... {{/name}}         a list: repeat for every item; anything else that is truthy: show once
 *   {{^name}} ... {{/name}}         show when the value is empty / false / missing
 *   {{! a comment }}                dropped
 *
 * A value is only ever read from the array it is given (dotted keys; no method call, no function, no expression, no include), so a
 * template cannot run PHP, Blade or JavaScript and cannot reach anything the caller did not put into the data. `{{.}}` is the current
 * item of a list of plain values. Unknown names print nothing. Size and work are bounded.
 */
final class LogicLessTemplate
{
    public const MAX_BYTES = 100 * 1024;

    public const MAX_DEPTH = 8;

    public const MAX_LOOP_ITEMS = 3000;

    private int $iterations = 0;

    /**
     * @param  array<string, mixed>  $data
     */
    public function render(string $template, array $data): string
    {
        if (strlen($template) > self::MAX_BYTES) {
            throw new InvalidArgumentException('The template is larger than '.(self::MAX_BYTES / 1024).' KB.');
        }

        $this->iterations = 0;
        $tokens = $this->tokenise($template);
        $tree = $this->parse($tokens);

        return $this->output($tree, [$data], 0);
    }

    /**
     * The placeholder names a template uses, for the editor's "unknown placeholder" warnings.
     *
     * @return list<string>
     */
    public function placeholders(string $template): array
    {
        preg_match_all('/\{\{\s*[#^]?\s*([A-Za-z0-9_.]+)\s*\}\}/', $template, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * @return list<array{type: string, value: string}>
     */
    private function tokenise(string $template): array
    {
        $parts = preg_split('/(\{\{.*?\}\})/s', $template, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
        $tokens = [];

        foreach ($parts as $part) {
            if (! (str_starts_with($part, '{{') && str_ends_with($part, '}}'))) {
                $tokens[] = ['type' => 'text', 'value' => $part];

                continue;
            }

            $inner = trim(substr($part, 2, -2));

            // {{{x}}} (unescaped) is not offered: a value is always escaped
            if (str_starts_with($inner, '{') || str_starts_with($inner, '&') || str_starts_with($inner, '>') || str_starts_with($inner, '=')) {
                throw new InvalidArgumentException('Only {{name}}, {{#list}}...{{/list}}, {{^name}}...{{/name}} and {{! comment}} are available.');
            }

            if (str_starts_with($inner, '!')) {
                continue;
            }

            $type = 'var';

            if (str_starts_with($inner, '#')) {
                $type = 'open';
                $inner = trim(substr($inner, 1));
            } elseif (str_starts_with($inner, '^')) {
                $type = 'invert';
                $inner = trim(substr($inner, 1));
            } elseif (str_starts_with($inner, '/')) {
                $type = 'close';
                $inner = trim(substr($inner, 1));
            }

            if ($inner !== '.' && preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)*$/', $inner) !== 1) {
                throw new InvalidArgumentException("The placeholder {{{$inner}}} is not a plain name.");
            }

            $tokens[] = ['type' => $type, 'value' => $inner];
        }

        return $tokens;
    }

    /**
     * @param  list<array{type: string, value: string}>  $tokens
     * @return list<array<string, mixed>>
     */
    private function parse(array $tokens): array
    {
        $position = 0;

        return $this->block($tokens, $position, null, 0);
    }

    /**
     * @param  list<array{type: string, value: string}>  $tokens
     * @return list<array<string, mixed>>
     */
    private function block(array $tokens, int &$position, ?string $closing, int $depth): array
    {
        $nodes = [];

        while ($position < count($tokens)) {
            $token = $tokens[$position++];

            if ($token['type'] === 'close') {
                if ($closing === null) {
                    throw new InvalidArgumentException('{{/'.$token['value'].'}} closes a section that was never opened.');
                }

                if ($token['value'] !== $closing) {
                    throw new InvalidArgumentException('{{/'.$token['value'].'}} does not match the open {{#'.$closing.'}}.');
                }

                return $nodes;
            }

            if ($token['type'] === 'open' || $token['type'] === 'invert') {
                if ($depth >= self::MAX_DEPTH) {
                    throw new InvalidArgumentException('Sections are nested more than '.self::MAX_DEPTH.' deep.');
                }

                $nodes[] = ['type' => $token['type'], 'name' => $token['value'], 'children' => $this->block($tokens, $position, $token['value'], $depth + 1)];

                continue;
            }

            $nodes[] = $token;
        }

        if ($closing !== null) {
            throw new InvalidArgumentException('{{#'.$closing.'}} is never closed.');
        }

        return $nodes;
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<mixed>  $context  innermost last
     */
    private function output(array $nodes, array $context, int $depth): string
    {
        $out = '';

        foreach ($nodes as $node) {
            switch ($node['type']) {
                case 'text':
                    $out .= $node['value'];
                    break;

                case 'var':
                    $value = $this->lookup($node['value'], $context);
                    $out .= is_scalar($value) || $value === null ? htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '';
                    break;

                case 'invert':
                    if ($this->isEmpty($this->lookup($node['name'], $context))) {
                        $out .= $this->output($node['children'], $context, $depth + 1);
                    }
                    break;

                case 'open':
                    $value = $this->lookup($node['name'], $context);

                    if ($this->isEmpty($value)) {
                        break;
                    }

                    if (is_array($value) && array_is_list($value)) {
                        foreach ($value as $item) {
                            if (++$this->iterations > self::MAX_LOOP_ITEMS) {
                                throw new InvalidArgumentException('The template repeats more than '.self::MAX_LOOP_ITEMS.' times.');
                            }

                            $out .= $this->output($node['children'], [...$context, $item], $depth + 1);
                        }
                    } else {
                        $out .= $this->output($node['children'], [...$context, $value], $depth + 1);
                    }
                    break;
            }
        }

        return $out;
    }

    /**
     * @param  list<mixed>  $context
     */
    private function lookup(string $name, array $context): mixed
    {
        if ($name === '.') {
            return end($context);
        }

        $segments = explode('.', $name);

        // the innermost context that has the first segment wins, as in Mustache
        for ($i = count($context) - 1; $i >= 0; $i--) {
            $scope = $context[$i];

            if (is_array($scope) && array_key_exists($segments[0], $scope)) {
                $value = $scope;

                foreach ($segments as $segment) {
                    if (! is_array($value) || ! array_key_exists($segment, $value)) {
                        return null;
                    }

                    $value = $value[$segment];
                }

                return $value;
            }
        }

        return null;
    }

    private function isEmpty(mixed $value): bool
    {
        return $value === null || $value === false || $value === '' || $value === [] || $value === 0 || $value === '0' || $value === 0.0;
    }
}
