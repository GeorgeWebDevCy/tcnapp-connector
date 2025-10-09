<?php
namespace TCN\Platform\Support;

use DateTimeImmutable;
use DateTimeZone;
use wpdb;

/**
 * Centralised helper methods for discount token lookups and transaction storage.
 */
class Discounts {
    const OPTION_TOKENS = 'tcn_discount_tokens';
    const TABLE_NAME    = 'tcn_discount_transactions';

    /**
     * Ensure the discount transaction table exists.
     */
    public static function activate(): void {
        global $wpdb;

        $table           = self::get_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $schema = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            member_id bigint(20) unsigned NOT NULL,
            vendor_id bigint(20) unsigned NOT NULL,
            plan_tier varchar(32) NOT NULL DEFAULT '',
            qr_token varchar(191) NOT NULL,
            gross_amount decimal(12,2) NOT NULL DEFAULT 0,
            discount_amount decimal(12,2) NOT NULL DEFAULT 0,
            net_amount decimal(12,2) NOT NULL DEFAULT 0,
            currency char(3) NOT NULL DEFAULT '',
            metadata longtext NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY qr_token (qr_token),
            KEY member_id (member_id),
            KEY vendor_id (vendor_id),
            KEY created_at (created_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        \dbDelta( $schema );
    }

    /**
     * Retrieve a stored discount token definition.
     *
     * @return array<string, mixed>|null
     */
    public static function get_token( string $qr_token ) {
        $qr_token = trim( $qr_token );
        if ( '' === $qr_token ) {
            return null;
        }

        $token = apply_filters( 'tcn_discount_lookup_token', null, $qr_token );
        if ( is_array( $token ) && ! empty( $token ) ) {
            $token['token'] = $token['token'] ?? $qr_token;

            return self::normalise_token( $token );
        }

        $stored = get_option( self::OPTION_TOKENS, array() );
        if ( is_array( $stored ) && isset( $stored[ $qr_token ] ) && is_array( $stored[ $qr_token ] ) ) {
            $record              = $stored[ $qr_token ];
            $record['token']     = $qr_token;
            $record['max_uses']  = isset( $record['max_uses'] ) ? (int) $record['max_uses'] : 0;
            $record['metadata']  = $record['metadata'] ?? array();
            $record['plan_tier'] = $record['plan_tier'] ?? '';

            return self::normalise_token( $record );
        }

        return null;
    }

    /**
     * Persist or update a discount token definition in the options table.
     *
     * @param array<string, mixed> $token
     */
    public static function save_token( array $token ): void {
        $token  = self::normalise_token( $token );
        $tokens = get_option( self::OPTION_TOKENS, array() );

        if ( ! is_array( $tokens ) ) {
            $tokens = array();
        }

        $tokens[ $token['token'] ] = $token;

        update_option( self::OPTION_TOKENS, $tokens, false );
    }

    /**
     * Delete a stored discount token.
     */
    public static function delete_token( string $qr_token ): void {
        $tokens = get_option( self::OPTION_TOKENS, array() );
        if ( ! is_array( $tokens ) || ! isset( $tokens[ $qr_token ] ) ) {
            return;
        }

        unset( $tokens[ $qr_token ] );
        update_option( self::OPTION_TOKENS, $tokens, false );
    }

    /**
     * Record a completed discount transaction.
     *
     * @param array<string, mixed> $data
     */
    public static function record_transaction( array $data ): int {
        global $wpdb;

        $table = self::get_table_name();
        $insert = array(
            'member_id'       => (int) $data['member_id'],
            'vendor_id'       => (int) $data['vendor_id'],
            'plan_tier'       => sanitize_text_field( (string) ( $data['plan_tier'] ?? '' ) ),
            'qr_token'        => substr( sanitize_text_field( (string) $data['qr_token'] ), 0, 191 ),
            'gross_amount'    => self::format_decimal( $data['gross_amount'] ?? 0 ),
            'discount_amount' => self::format_decimal( $data['discount_amount'] ?? 0 ),
            'net_amount'      => self::format_decimal( $data['net_amount'] ?? 0 ),
            'currency'        => strtoupper( sanitize_text_field( (string) ( $data['currency'] ?? '' ) ) ),
            'metadata'        => self::encode_metadata( $data['metadata'] ?? array() ),
            'created_at'      => gmdate( 'Y-m-d H:i:s' ),
        );

        $formats = array( '%d', '%d', '%s', '%s', '%f', '%f', '%f', '%s', '%s', '%s' );

        $wpdb->insert( $table, $insert, $formats );

        return (int) $wpdb->insert_id;
    }

    /**
     * Retrieve a paginated list of transactions.
     *
     * @param array<string, mixed> $args
     * @return array{rows: array<int, array<string, mixed>>, total: int, totals: array<string, float>, total_pages: int, page: int, per_page: int}
     */
    public static function get_history( array $args ): array {
        global $wpdb;

        $defaults = array(
            'page'       => 1,
            'per_page'   => 25,
            'member_id'  => null,
            'vendor_id'  => null,
            'plan_tier'  => '',
            'date_start' => null,
            'date_end'   => null,
        );

        $args = wp_parse_args( $args, $defaults );

        $conditions = array();
        $values     = array();

        if ( $args['member_id'] ) {
            $conditions[] = 'member_id = %d';
            $values[]     = (int) $args['member_id'];
        }

        if ( $args['vendor_id'] ) {
            $conditions[] = 'vendor_id = %d';
            $values[]     = (int) $args['vendor_id'];
        }

        if ( ! empty( $args['plan_tier'] ) ) {
            $conditions[] = 'plan_tier = %s';
            $values[]     = sanitize_text_field( (string) $args['plan_tier'] );
        }

        if ( $args['date_start'] ) {
            $start = self::normalize_datetime( (string) $args['date_start'] );
            if ( $start ) {
                $conditions[] = 'created_at >= %s';
                $values[]     = $start;
            }
        }

        if ( $args['date_end'] ) {
            $end = self::normalize_datetime( (string) $args['date_end'], true );
            if ( $end ) {
                $conditions[] = 'created_at <= %s';
                $values[]     = $end;
            }
        }

        $where = '';
        if ( $conditions ) {
            $where = 'WHERE ' . implode( ' AND ', array_map( 'trim', $conditions ) );
        }

        $table = self::get_table_name();

        $total = (int) $wpdb->get_var( $values ? $wpdb->prepare( "SELECT COUNT(*) FROM {$table} {$where}", $values ) : "SELECT COUNT(*) FROM {$table} {$where}" );

        $totals_row = $values ?
            $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT COUNT(*) as count, COALESCE(SUM(gross_amount),0) as gross_sum, COALESCE(SUM(discount_amount),0) as discount_sum, COALESCE(SUM(net_amount),0) as net_sum FROM {$table} {$where}",
                    $values
                ),
                ARRAY_A
            ) :
            $wpdb->get_row(
                "SELECT COUNT(*) as count, COALESCE(SUM(gross_amount),0) as gross_sum, COALESCE(SUM(discount_amount),0) as discount_sum, COALESCE(SUM(net_amount),0) as net_sum FROM {$table} {$where}",
                ARRAY_A
            );

        $per_page = max( 1, min( 100, (int) $args['per_page'] ) );
        $page     = max( 1, (int) $args['page'] );
        $offset   = ( $page - 1 ) * $per_page;

        $rows = $values ?
            $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$table} {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d",
                    array_merge( $values, array( $per_page, $offset ) )
                ),
                ARRAY_A
            ) :
            $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$table} {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d",
                    array( $per_page, $offset )
                ),
                ARRAY_A
            );

        return array(
            'rows'        => is_array( $rows ) ? $rows : array(),
            'total'       => $total,
            'total_pages' => $per_page ? (int) ceil( $total / $per_page ) : 1,
            'totals'      => array(
                'count'        => isset( $totals_row['count'] ) ? (int) $totals_row['count'] : 0,
                'gross_sum'    => isset( $totals_row['gross_sum'] ) ? (float) $totals_row['gross_sum'] : 0.0,
                'discount_sum' => isset( $totals_row['discount_sum'] ) ? (float) $totals_row['discount_sum'] : 0.0,
                'net_sum'      => isset( $totals_row['net_sum'] ) ? (float) $totals_row['net_sum'] : 0.0,
            ),
            'page'        => $page,
            'per_page'    => $per_page,
        );
    }

    /**
     * Retrieve usage totals for a discount token.
     *
     * @return array{uses_total: int, uses_today: int}
     */
    public static function get_usage( string $qr_token, ?int $vendor_id = null ): array {
        global $wpdb;

        $qr_token = trim( $qr_token );
        if ( '' === $qr_token ) {
            return array(
                'uses_total' => 0,
                'uses_today' => 0,
            );
        }

        $table = self::get_table_name();
        $where = 'qr_token = %s';
        $args  = array( $qr_token );

        if ( $vendor_id ) {
            $where .= ' AND vendor_id = %d';
            $args[] = (int) $vendor_id;
        }

        $total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where}", $args ) );

        $today = gmdate( 'Y-m-d' );
        $args_with_today = array_merge( $args, array( $today . ' 00:00:00', $today . ' 23:59:59' ) );

        $uses_today = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE {$where} AND created_at BETWEEN %s AND %s",
                $args_with_today
            )
        );

        return array(
            'uses_total' => $total,
            'uses_today' => $uses_today,
        );
    }

    /**
     * Normalise and sanitise a discount token array.
     *
     * @param array<string, mixed> $token
     * @return array<string, mixed>
     */
    protected static function normalise_token( array $token ): array {
        $defaults = array(
            'token'             => '',
            'member_id'         => 0,
            'plan_tier'         => '',
            'label'             => '',
            'type'              => 'percentage',
            'value'             => 0,
            'max_uses'          => 0,
            'max_uses_per_day'  => 0,
            'expires_at'        => null,
            'metadata'          => array(),
        );

        $token = wp_parse_args( $token, $defaults );

        $token['token']            = sanitize_text_field( (string) $token['token'] );
        $token['member_id']        = (int) $token['member_id'];
        $token['plan_tier']        = sanitize_text_field( (string) $token['plan_tier'] );
        $token['label']            = sanitize_text_field( (string) $token['label'] );
        $token['type']             = sanitize_text_field( (string) $token['type'] );
        $token['value']            = (float) $token['value'];
        $token['max_uses']         = (int) $token['max_uses'];
        $token['max_uses_per_day'] = (int) $token['max_uses_per_day'];
        $token['expires_at']       = self::normalize_datetime( $token['expires_at'] );
        $token['metadata']         = is_array( $token['metadata'] ) ? $token['metadata'] : array();

        return $token;
    }

    protected static function format_decimal( $value ): float {
        if ( is_string( $value ) ) {
            $value = (float) $value;
        }

        if ( is_numeric( $value ) ) {
            return round( (float) $value, 2 );
        }

        return 0.0;
    }

    protected static function encode_metadata( $metadata ): string {
        if ( is_array( $metadata ) || is_object( $metadata ) ) {
            $encoded = wp_json_encode( $metadata );
            if ( false !== $encoded ) {
                return $encoded;
            }
        }

        return '';
    }

    protected static function normalize_datetime( $value, bool $end_of_day = false ): ?string {
        if ( empty( $value ) ) {
            return null;
        }

        try {
            $date = new DateTimeImmutable( (string) $value, new DateTimeZone( 'UTC' ) );
        } catch ( \Exception $e ) {
            return null;
        }

        if ( $end_of_day ) {
            $date = $date->setTime( 23, 59, 59 );
        }

        return $date->format( 'Y-m-d H:i:s' );
    }

    protected static function get_table_name(): string {
        global $wpdb;

        return $wpdb->prefix . self::TABLE_NAME;
    }
}
