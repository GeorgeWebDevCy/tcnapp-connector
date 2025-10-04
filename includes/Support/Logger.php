<?php
namespace TCN\Platform\Support;

use function current_time;
use function delete_option;
use function get_option;
use function sanitize_text_field;
use function update_option;

class Logger {
    const OPTION_KEY   = 'tcn_platform_activity_log';
    const MAX_ENTRIES  = 200;

    /**
     * Record a new log entry.
     */
    public static function log( string $source, string $message, array $context = array() ): void {
        $entry = array(
            'time'    => self::now(),
            'source'  => self::sanitize_key( $source ),
            'message' => self::sanitize_text( $message ),
            'context' => self::sanitize_context( $context ),
        );

        $logs = get_option( self::OPTION_KEY, array() );
        if ( ! is_array( $logs ) ) {
            $logs = array();
        }

        array_unshift( $logs, $entry );
        if ( count( $logs ) > self::MAX_ENTRIES ) {
            $logs = array_slice( $logs, 0, self::MAX_ENTRIES );
        }

        update_option( self::OPTION_KEY, $logs, false );
    }

    /**
     * Retrieve all log entries.
     */
    public static function get_logs(): array {
        $logs = get_option( self::OPTION_KEY, array() );
        if ( ! is_array( $logs ) ) {
            return array();
        }

        return $logs;
    }

    /**
     * Remove all log entries.
     */
    public static function clear(): void {
        delete_option( self::OPTION_KEY );
    }

    protected static function sanitize_context( array $context ): array {
        $sanitized = array();

        foreach ( $context as $key => $value ) {
            $clean_key = is_string( $key ) ? self::sanitize_key( $key ) : $key;
            $sanitized[ $clean_key ] = self::sanitize_value( $value );
        }

        return $sanitized;
    }

    protected static function sanitize_value( $value ) {
        if ( is_array( $value ) ) {
            $result = array();
            foreach ( $value as $key => $item ) {
                $clean_key         = is_string( $key ) ? self::sanitize_key( $key ) : $key;
                $result[ $clean_key ] = self::sanitize_value( $item );
            }
            return $result;
        }

        if ( is_object( $value ) ) {
            $value = (array) $value;
            return self::sanitize_value( $value );
        }

        if ( is_bool( $value ) ) {
            return $value;
        }

        if ( is_numeric( $value ) ) {
            return 0 + $value;
        }

        if ( null === $value ) {
            return null;
        }

        return self::sanitize_text( (string) $value );
    }

    protected static function sanitize_key( string $key ): string {
        $key = strtolower( $key );
        return preg_replace( '/[^a-z0-9_\-\.]/', '_', $key );
    }

    protected static function sanitize_text( string $text ): string {
        return sanitize_text_field( $text );
    }

    protected static function now(): int {
        return (int) current_time( 'timestamp', true );
    }
}
