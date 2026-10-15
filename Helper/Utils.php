<?php
namespace Magezon\Core\Helper;

class Utils {
    // /**
    //  * Finds the first item matching a condition
    //  */
    // public static function find(\Iterator $items, callable $callback) {
    //     foreach ($items as $item) {
    //         if ($callback($item)) {
    //             return $item;
    //         }
    //     }
    //     return null;
    // }

    // /**
    //  * Finds the first item matching a condition
    //  */
    // public static function keyBy(\Iterator $items, callable $keyGenerator) {
    //     $result = [];
    //     foreach ($items as $item) {
    //         $key = $keyGenerator($item);
    //         $result[$key] = $item;
    //     }
    //     return $result;
    // }

    /**
     * Merges user defined arguments into defaults array.
     *
     * This function is used throughout WordPress to allow for both string or array
     * to be merged into another array.
     *
     * @since 2.2.0
     * @since 2.3.0 `$args` can now also be an object.
     *
     * @param string|array|object $args     Value to merge with $defaults.
     * @param array               $defaults Optional. Array that serves as the defaults.
     *                                      Default empty array.
     * @return array Merged user defined values with defaults.
     */
    static function parseArgs( $args, $defaults = array() ) {
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