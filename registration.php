<?php
/**
 * Magezon
 *
 * This source file is subject to the Magezon Software License, which is available at https://www.magezon.com/license
 * Do not edit or add to this file if you wish to upgrade the to newer versions in the future.
 * If you wish to customize this module for your needs.
 * Please refer to https://www.magezon.com for more information.
 *
 * @category  Magezon
 * @package   Magezon_Core
 * @copyright Copyright (C) 2019 Magezon (https://www.magezon.com)
 */

/**
 * Standard-library functions added in PHP 7.3-8.1, polyfilled so the Magezon
 * modules run on PHP 7.4.
 *
 * They live in registration.php itself rather than in a file pulled in with
 * require_once, for two reasons that together leave no other option: Magento
 * registers an app/code module through the ComponentRegistrar and does NOT
 * honour a module's composer.json `autoload.files` there, so registration.php
 * is the only hook that fires early enough for both install methods; and
 * Magento2.Security.IncludeFile rejects the require outright on Marketplace.
 * Every definition is function_exists()-guarded, so on PHP 8 this defines
 * nothing.
 *
 * Magezon_Editor ships the same polyfills inside its WordPress-derived
 * lib/utils/compat.php, but that file is only reached via a require_once in
 * Model/Block.php — call sites outside that path (Helper/Block.php,
 * Block/Adminhtml/ServerBlocks.php, and all of Magezon_Core) would otherwise
 * fatal with "Call to undefined function".
 */

if (!function_exists('str_contains')) {
    /**
     * Polyfill for `str_contains()` added in PHP 8.0.
     *
     * @param  string $haystack The string to search in.
     * @param  string $needle   The substring to search for in the `$haystack`.
     * @return bool True if `$needle` is in `$haystack`, otherwise false.
     */
    function str_contains($haystack, $needle)
    {
        if ('' === $needle) {
            return true;
        }

        return false !== strpos($haystack, $needle);
    }
}

if (!function_exists('str_starts_with')) {
    /**
     * Polyfill for `str_starts_with()` added in PHP 8.0.
     *
     * @param  string $haystack The string to search in.
     * @param  string $needle   The substring to search for in the `$haystack`.
     * @return bool True if `$haystack` starts with `$needle`, otherwise false.
     */
    function str_starts_with($haystack, $needle)
    {
        if ('' === $needle) {
            return true;
        }

        return 0 === strpos($haystack, $needle);
    }
}

if (!function_exists('str_ends_with')) {
    /**
     * Polyfill for `str_ends_with()` added in PHP 8.0.
     *
     * @param  string $haystack The string to search in.
     * @param  string $needle   The substring to search for in the `$haystack`.
     * @return bool True if `$haystack` ends with `$needle`, otherwise false.
     */
    function str_ends_with($haystack, $needle)
    {
        if ('' === $haystack) {
            return '' === $needle;
        }

        $len = strlen($needle);

        return substr($haystack, -$len, $len) === $needle;
    }
}

if (!function_exists('array_is_list')) {
    /**
     * Polyfill for `array_is_list()` added in PHP 8.1.
     *
     * An array is a list when its keys are consecutive integers from 0 to
     * count($arr) - 1.
     *
     * @param  array $arr The array being evaluated.
     * @return bool True if the array is a list, false otherwise.
     */
    function array_is_list($arr)
    {
        if ((array() === $arr) || (array_values($arr) === $arr)) {
            return true;
        }

        $nextKey = -1;

        foreach ($arr as $k => $v) {
            if (++$nextKey !== $k) {
                return false;
            }
        }

        return true;
    }
}

if (!function_exists('array_key_first')) {
    /**
     * Polyfill for `array_key_first()` added in PHP 7.3.
     *
     * Gets the first key without moving the internal array pointer.
     *
     * @param  array $array An array.
     * @return string|int|null The first key, or null when the array is empty.
     */
    function array_key_first(array $array)
    {
        if (empty($array)) {
            return null;
        }

        foreach ($array as $key => $value) {
            return $key;
        }
    }
}

if (!function_exists('array_key_last')) {
    /**
     * Polyfill for `array_key_last()` added in PHP 7.3.
     *
     * Gets the last key without moving the caller's internal array pointer
     * (the parameter is by value, so end() advances a copy).
     *
     * @param  array $array An array.
     * @return string|int|null The last key, or null when the array is empty.
     */
    function array_key_last(array $array)
    {
        if (empty($array)) {
            return null;
        }

        end($array);

        return key($array);
    }
}

if (!function_exists('get_debug_type')) {
    /**
     * Polyfill for `get_debug_type()` added in PHP 8.0.
     *
     * @see https://github.com/symfony/polyfill-php80
     *
     * @param  mixed $value The value to describe.
     * @return string The type name as PHP 8 would report it.
     */
    function get_debug_type($value)
    {
        switch (true) {
            case null === $value:
                return 'null';
            case is_bool($value):
                return 'bool';
            case is_string($value):
                return 'string';
            case is_array($value):
                return 'array';
            case is_int($value):
                return 'int';
            case is_float($value):
                return 'float';
            case is_object($value):
                break;
            case $value instanceof \__PHP_Incomplete_Class:
                return '__PHP_Incomplete_Class';
            default:
                // gettype() distinguishes an open resource from a closed one
                // since PHP 7.2, so the resource type can be read without
                // silencing get_resource_type()'s error on a non-resource.
                $type = gettype($value);

                if ('resource' === $type) {
                    return 'resource (' . get_resource_type($value) . ')';
                }

                if ('resource (closed)' === $type) {
                    return 'resource (closed)';
                }

                return 'unknown';
        }

        $class = get_class($value);

        if (false === strpos($class, '@')) {
            return $class;
        }

        return (get_parent_class($class) ?: key(class_implements($class)) ?: 'class') . '@anonymous';
    }
}

\Magento\Framework\Component\ComponentRegistrar::register(
    \Magento\Framework\Component\ComponentRegistrar::MODULE,
    'Magezon_Core',
    __DIR__
);
