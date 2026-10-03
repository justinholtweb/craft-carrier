<?php

namespace justinholtweb\carrier\base;

/**
 * The declarative form vocabulary a connector uses to describe its credentials.
 *
 * Carriers ship no templates. They return a list of these from `settingsFields()` and Carrier
 * renders the connection screen, handles autosuggest of environment variables, redacts secrets in
 * the log and validates what is required. An add-on that needs a text box does not need to know
 * what a Craft macro is.
 */
abstract class Field
{
    public static function text(string $name, string $label, array $config = []): array
    {
        return array_merge([
            'type' => 'text',
            'name' => $name,
            'label' => $label,
            'instructions' => '',
            'required' => false,
            'default' => '',
            'placeholder' => '',
            'secret' => false,
            'suggestEnvVars' => true,
        ], $config);
    }

    /**
     * A credential. Rendered as a password field, never echoed back to the browser once saved,
     * and redacted everywhere it could reach the log.
     */
    public static function secret(string $name, string $label, array $config = []): array
    {
        return self::text($name, $label, array_merge([
            'secret' => true,
            'instructions' => 'Store this in an environment variable rather than in the database.',
        ], $config));
    }

    public static function url(string $name, string $label, array $config = []): array
    {
        return self::text($name, $label, array_merge(['type' => 'url'], $config));
    }

    public static function select(string $name, string $label, array $options, array $config = []): array
    {
        return array_merge([
            'type' => 'select',
            'name' => $name,
            'label' => $label,
            'options' => $options,
            'instructions' => '',
            'required' => false,
            'default' => array_key_first($options) ?? '',
            'secret' => false,
            'suggestEnvVars' => false,
        ], $config);
    }

    public static function boolean(string $name, string $label, array $config = []): array
    {
        return array_merge([
            'type' => 'boolean',
            'name' => $name,
            'label' => $label,
            'instructions' => '',
            'required' => false,
            'default' => false,
            'secret' => false,
            'suggestEnvVars' => false,
        ], $config);
    }

    public static function number(string $name, string $label, array $config = []): array
    {
        return array_merge([
            'type' => 'number',
            'name' => $name,
            'label' => $label,
            'instructions' => '',
            'required' => false,
            'default' => 0,
            'min' => null,
            'max' => null,
            'secret' => false,
            'suggestEnvVars' => false,
        ], $config);
    }

    /**
     * A read-only value Carrier computes and the merchant pastes into the carrier's portal — a
     * webhook endpoint, say.
     */
    public static function copyable(string $name, string $label, string $value, array $config = []): array
    {
        return array_merge([
            'type' => 'copyable',
            'name' => $name,
            'label' => $label,
            'value' => $value,
            'instructions' => '',
            'required' => false,
            'secret' => false,
            'suggestEnvVars' => false,
        ], $config);
    }

    /**
     * The production / sandbox switch. Every carrier with a test environment uses this one, so
     * `Carrier::isSandbox()` and the connection list's "TEST" badge work without each add-on
     * re-inventing the setting.
     */
    public static function environment(array $config = []): array
    {
        return self::select('environment', 'Environment', [
            'production' => 'Production',
            'sandbox' => 'Sandbox / test',
        ], array_merge([
            'instructions' => 'Sandbox labels are not valid for shipping. Most carriers issue separate credentials for each.',
        ], $config));
    }

    /**
     * A heading with optional prose, for breaking a long credential list into the sections the
     * carrier's own documentation uses.
     */
    public static function heading(string $label, string $instructions = ''): array
    {
        return [
            'type' => 'heading',
            'name' => '',
            'label' => $label,
            'instructions' => $instructions,
            'required' => false,
            'secret' => false,
            'suggestEnvVars' => false,
        ];
    }

    /**
     * Every field name in a schema that holds a secret. Used to redact the connection log and to
     * keep credentials out of the "copy this connection" export.
     */
    public static function secretNames(array $fields): array
    {
        $names = [];

        foreach ($fields as $field) {
            if (($field['secret'] ?? false) && ($field['name'] ?? '') !== '') {
                $names[] = $field['name'];
            }
        }

        return $names;
    }

    /**
     * Field names that must be filled in before a connection can be enabled.
     */
    public static function requiredNames(array $fields): array
    {
        $names = [];

        foreach ($fields as $field) {
            if (($field['required'] ?? false) && ($field['name'] ?? '') !== '') {
                $names[] = $field['name'];
            }
        }

        return $names;
    }

    public static function defaults(array $fields): array
    {
        $out = [];

        foreach ($fields as $field) {
            $name = $field['name'] ?? '';

            if ($name === '' || ($field['type'] ?? '') === 'copyable') {
                continue;
            }

            $out[$name] = $field['default'] ?? '';
        }

        return $out;
    }
}
