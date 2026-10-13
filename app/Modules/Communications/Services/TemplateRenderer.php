<?php

namespace App\Modules\Communications\Services;

use App\Modules\Communications\Exceptions\CommunicationsException;

/**
 * Fills `{name}` placeholders in a template. Values are plain text: control characters and line breaks are removed and each is
 * cut to 300 characters, so a value (a customer name, a note) can never add lines, layout or a second message to the template.
 * A placeholder without a value is an error, never an empty gap in a letter to a customer.
 */
final class TemplateRenderer
{
    /** @return list<string> placeholder names, in order of first appearance */
    public function placeholders(string $text): array
    {
        preg_match_all('/\{([a-z][a-z0-9_]*)\}/', $text, $m);

        return array_values(array_unique($m[1]));
    }

    /**
     * @param  array<string, scalar|null>  $params
     *
     * @throws CommunicationsException comm.param_missing
     */
    public function render(string $text, array $params): string
    {
        foreach ($this->placeholders($text) as $name) {
            if (! array_key_exists($name, $params) || trim((string) $params[$name]) === '') {
                throw CommunicationsException::make('comm.param_missing', "The value for {{$name}} is missing.", 422, "params.{$name}");
            }
        }

        return (string) preg_replace_callback('/\{([a-z][a-z0-9_]*)\}/', fn (array $m) => $this->clean((string) $params[$m[1]]), $text);
    }

    public function clean(string $value): string
    {
        $v = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/\p{C}+/u', ' ', $value)));

        return mb_strlen($v) > 300 ? mb_substr($v, 0, 300).'…' : $v;
    }
}
