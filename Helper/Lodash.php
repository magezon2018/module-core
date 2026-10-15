<?php
namespace Magezon\Core\Helper;

function wp_parse_str( $input_string, &$result ) {
	parse_str( (string) $input_string, $result );
}

class Lodash
{
    // _wp_array_get
    static function get($input_array, $path, $default_value = null)
    {
        // MAGEZON_CUSTOM
        if (is_string($path)) {
            $path = explode(".", $path);
        }
        // MAGEZON_CUSTOM

        // Confirm $path is valid.
        if ( ! is_array( $path ) || 0 === count( $path ) ) {
            return $default_value;
        }

        foreach ( $path as $path_element ) {
            if ( ! is_array( $input_array ) ) {
                return $default_value;
            }

            if ( is_string( $path_element )
                || is_integer( $path_element )
                || null === $path_element
            ) {
                /*
                * Check if the path element exists in the input array.
                * We check with `isset()` first, as it is a lot faster
                * than `array_key_exists()`.
                */
                if ( isset( $path_element, $input_array[ $path_element ] ) ) {
                    $input_array = $input_array[ $path_element ];
                    continue;
                }

                /*
                * If `isset()` returns false, we check with `array_key_exists()`,
                * which also checks for `null` values.
                */
                if ( isset( $path_element ) && array_key_exists( $path_element, $input_array ) ) {
                    $input_array = $input_array[ $path_element ];
                    continue;
                }
            }

            return $default_value;
        }

        return $input_array;
    }

    // _wp_array_set
    static function set(&$input_array, $path, $value = null)
    {
        // MAGEZON_CUSTOM
        if (is_string($path)) {
            $path = explode(".", $path);
        }
        // MAGEZON_CUSTOM
        
        // Confirm $input_array is valid.
        if ( ! is_array( $input_array ) ) {
            return;
        }

        // Confirm $path is valid.
        if ( ! is_array( $path ) ) {
            return;
        }

        $path_length = count( $path );

        if ( 0 === $path_length ) {
            return;
        }

        foreach ( $path as $path_element ) {
            if (
                ! is_string( $path_element ) && ! is_integer( $path_element ) &&
                ! is_null( $path_element )
            ) {
                return;
            }
        }

        for ( $i = 0; $i < $path_length - 1; ++$i ) {
            $path_element = $path[ $i ];
            if (
                ! array_key_exists( $path_element, $input_array ) ||
                ! is_array( $input_array[ $path_element ] )
            ) {
                $input_array[ $path_element ] = array();
            }
            $input_array = &$input_array[ $path_element ];
        }

        $input_array[ $path[ $i ] ] = $value;
    }

    // _wp_to_kebab_case
    static function kebabCase($input_string)
    {
        // Ignore the camelCase names for variables so the names are the same as lodash so comparing and porting new changes is easier.
        // phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

        /*
        * Some notable things we've removed compared to the lodash version are:
        *
        * - non-alphanumeric characters: rsAstralRange, rsEmoji, etc
        * - the groups that processed the apostrophe, as it's removed before passing the string to preg_match: rsApos, rsOptContrLower, and rsOptContrUpper
        *
        */

        /** Used to compose unicode character classes. */
        $rsLowerRange       = 'a-z\\xdf-\\xf6\\xf8-\\xff';
        $rsNonCharRange     = '\\x00-\\x2f\\x3a-\\x40\\x5b-\\x60\\x7b-\\xbf';
        $rsPunctuationRange = '\\x{2000}-\\x{206f}';
        $rsSpaceRange       = ' \\t\\x0b\\f\\xa0\\x{feff}\\n\\r\\x{2028}\\x{2029}\\x{1680}\\x{180e}\\x{2000}\\x{2001}\\x{2002}\\x{2003}\\x{2004}\\x{2005}\\x{2006}\\x{2007}\\x{2008}\\x{2009}\\x{200a}\\x{202f}\\x{205f}\\x{3000}';
        $rsUpperRange       = 'A-Z\\xc0-\\xd6\\xd8-\\xde';
        $rsBreakRange       = $rsNonCharRange . $rsPunctuationRange . $rsSpaceRange;

        /** Used to compose unicode capture groups. */
        $rsBreak  = '[' . $rsBreakRange . ']';
        $rsDigits = '\\d+'; // The last lodash version in GitHub uses a single digit here and expands it when in use.
        $rsLower  = '[' . $rsLowerRange . ']';
        $rsMisc   = '[^' . $rsBreakRange . $rsDigits . $rsLowerRange . $rsUpperRange . ']';
        $rsUpper  = '[' . $rsUpperRange . ']';

        /** Used to compose unicode regexes. */
        $rsMiscLower = '(?:' . $rsLower . '|' . $rsMisc . ')';
        $rsMiscUpper = '(?:' . $rsUpper . '|' . $rsMisc . ')';
        $rsOrdLower  = '\\d*(?:1st|2nd|3rd|(?![123])\\dth)(?=\\b|[A-Z_])';
        $rsOrdUpper  = '\\d*(?:1ST|2ND|3RD|(?![123])\\dTH)(?=\\b|[a-z_])';

        $regexp = '/' . implode(
            '|',
            array(
                $rsUpper . '?' . $rsLower . '+' . '(?=' . implode( '|', array( $rsBreak, $rsUpper, '$' ) ) . ')',
                $rsMiscUpper . '+' . '(?=' . implode( '|', array( $rsBreak, $rsUpper . $rsMiscLower, '$' ) ) . ')',
                $rsUpper . '?' . $rsMiscLower . '+',
                $rsUpper . '+',
                $rsOrdUpper,
                $rsOrdLower,
                $rsDigits,
            )
        ) . '/u';

        preg_match_all( $regexp, str_replace( "'", '', $input_string ), $matches );
        return strtolower( implode( '-', $matches[0] ) );
        // phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
    }

    static function join(array $paths, string $separator)
    {
        
    }

    /**
     * Finds the first item matching a condition
     */
    public static function find(\Iterator $items, callable $callback) {
        foreach ($items as $item) {
            if ($callback($item)) {
                return $item;
            }
        }
        return null;
    }

    /**
     * Finds the first item matching a condition
     */
    public static function keyBy(\Iterator $items, callable $keyGenerator) {
        $result = [];
        foreach ($items as $item) {
            $key = $keyGenerator($item);
            $result[$key] = $item;
        }
        return $result;
    }

    public static function defaults($args, $defaults = array())
    {
        if ( is_object( $args ) ) {
            $parsed_args = get_object_vars( $args );
        } elseif ( is_array( $args ) ) {
            $parsed_args =& $args;
        } else {
            wp_parse_str( $args, $parsed_args );
        }

        if ( is_array( $defaults ) && $defaults ) {
            return array_merge( $defaults, $parsed_args );
        }
        return $parsed_args;
    }
}