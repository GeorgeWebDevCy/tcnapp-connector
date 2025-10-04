<?php
namespace TCN\Platform\Support;

/**
 * Lightweight helpers for WPML string registration and translation.
 */
class WPML {
    const STRING_CONTEXT = 'tcnapp-connector';

    /**
     * Register membership level strings with WPML so administrators can translate them.
     *
     * @param array<string, array<string, mixed>> $levels
     */
    public static function register_membership_levels( array $levels ): void {
        if ( ! self::is_active() ) {
            return;
        }

        foreach ( $levels as $level ) {
            if ( empty( $level['slug'] ) ) {
                continue;
            }

            $slug = sanitize_key( (string) $level['slug'] );

            if ( isset( $level['name'] ) && is_string( $level['name'] ) ) {
                self::register_string( sprintf( 'membership_level_%s_name', $slug ), $level['name'] );
            }

            if ( ! empty( $level['benefits'] ) && is_array( $level['benefits'] ) ) {
                $index = 0;

                foreach ( $level['benefits'] as $benefit ) {
                    if ( ! is_string( $benefit ) || '' === trim( $benefit ) ) {
                        continue;
                    }

                    self::register_string(
                        sprintf( 'membership_level_%s_benefit_%d', $slug, $index ),
                        $benefit
                    );

                    $index++;
                }
            }
        }
    }

    /**
     * Translate membership level strings registered with WPML.
     *
     * @param array<string, array<string, mixed>> $levels
     *
     * @return array<string, array<string, mixed>>
     */
    public static function translate_membership_levels( array $levels ): array {
        if ( ! self::is_active() ) {
            return $levels;
        }

        foreach ( $levels as $key => $level ) {
            if ( empty( $level['slug'] ) ) {
                continue;
            }

            $slug = sanitize_key( (string) $level['slug'] );

            if ( isset( $level['name'] ) && is_string( $level['name'] ) ) {
                $levels[ $key ]['name'] = self::translate_string(
                    sprintf( 'membership_level_%s_name', $slug ),
                    $level['name']
                );
            }

            if ( empty( $level['benefits'] ) || ! is_array( $level['benefits'] ) ) {
                continue;
            }

            $translated_benefits = array();
            $index               = 0;

            foreach ( $level['benefits'] as $benefit ) {
                if ( ! is_string( $benefit ) ) {
                    $translated_benefits[] = $benefit;
                    continue;
                }

                $translated_benefits[] = self::translate_string(
                    sprintf( 'membership_level_%s_benefit_%d', $slug, $index ),
                    $benefit
                );

                $index++;
            }

            $levels[ $key ]['benefits'] = $translated_benefits;
        }

        return $levels;
    }

    /**
     * Determine whether WPML (or the String Translation module) is active.
     */
    protected static function is_active(): bool {
        return defined( 'ICL_SITEPRESS_VERSION' )
            || defined( 'WPML_ST_VERSION' )
            || has_filter( 'wpml_translate_single_string' );
    }

    protected static function register_string( string $name, string $value ): void {
        do_action( 'wpml_register_single_string', self::STRING_CONTEXT, $name, $value );
    }

    protected static function translate_string( string $name, string $value ): string {
        $translated = apply_filters( 'wpml_translate_single_string', $value, self::STRING_CONTEXT, $name );

        return is_string( $translated ) ? $translated : $value;
    }
}
