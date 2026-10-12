<?php

namespace App\Modules\AI\Services\Tools;

/**
 * The small part of JSON Schema the tools use: an object with typed properties (string, integer, number, boolean), `required`,
 * `enum`, `pattern`, `maxLength`, `minimum`, `maximum`. Anything not declared is refused: a model cannot smuggle in extra fields.
 */
final class SchemaValidator
{
    /**
     * @param  array<string, mixed>  $schema
     * @return list<string> problems in plain words; empty when the input is acceptable
     */
    public function problems(array $schema, mixed $input): array
    {
        if (! is_array($input) || ($input !== [] && array_is_list($input))) {
            return ['The input must be an object.'];
        }
        $props = (array) ($schema['properties'] ?? []);
        $problems = [];

        foreach ((array) ($schema['required'] ?? []) as $name) {
            if (! array_key_exists($name, $input)) {
                $problems[] = "'{$name}' is required.";
            }
        }
        foreach ($input as $name => $value) {
            if (! array_key_exists($name, $props)) {
                $problems[] = "'{$name}' is not an accepted field.";

                continue;
            }
            array_push($problems, ...$this->check((string) $name, (array) $props[$name], $value));
        }

        return $problems;
    }

    /**
     * @param  array<string, mixed>  $rule
     * @return list<string>
     */
    private function check(string $name, array $rule, mixed $value): array
    {
        $type = $rule['type'] ?? 'string';
        $ok = match ($type) {
            'string' => is_string($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'array' => is_array($value) && array_is_list($value),
            default => false,
        };
        if (! $ok) {
            $label = ['string' => 'text', 'integer' => 'a whole number', 'number' => 'a number', 'boolean' => 'true or false', 'array' => 'a list'][$type] ?? $type;

            return ["'{$name}' must be {$label}."];
        }
        if (isset($rule['enum']) && ! in_array($value, $rule['enum'], true)) {
            return ["'{$name}' must be one of: ".implode(', ', $rule['enum']).'.'];
        }
        if ($type === 'array') {
            if (isset($rule['minItems']) && count($value) < $rule['minItems']) {
                return ["'{$name}' needs at least {$rule['minItems']} entries."];
            }
            if (isset($rule['maxItems']) && count($value) > $rule['maxItems']) {
                return ["'{$name}' may have at most {$rule['maxItems']} entries."];
            }
            $problems = [];
            foreach ($value as $i => $entry) {
                foreach ($this->problems((array) ($rule['items'] ?? []), $entry) as $p) {
                    $problems[] = "{$name}[".($i + 1).']: '.$p;
                }
            }

            return $problems;
        }
        if ($type === 'string') {
            if (isset($rule['maxLength']) && mb_strlen($value) > $rule['maxLength']) {
                return ["'{$name}' is too long."];
            }
            if (isset($rule['pattern']) && preg_match('/'.$rule['pattern'].'/uD', $value) !== 1) {
                return ["'{$name}' is not in the expected form."];
            }
        }
        if (in_array($type, ['integer', 'number'], true)) {
            if (isset($rule['minimum']) && $value < $rule['minimum']) {
                return ["'{$name}' must be at least {$rule['minimum']}."];
            }
            if (isset($rule['maximum']) && $value > $rule['maximum']) {
                return ["'{$name}' must be at most {$rule['maximum']}."];
            }
        }

        return [];
    }
}
