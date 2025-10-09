<?php
namespace TCN\Platform\Support;

/**
 * Helper methods for managing account metadata such as vendor status and account type.
 */
class Accounts {
    public const META_ACCOUNT_TYPE            = '_tcn_account_type';
    public const META_ACCOUNT_STATUS          = '_tcn_account_status';
    public const META_VENDOR_STATUS           = '_tcn_vendor_status';
    public const META_VENDOR_REJECTION_REASON = '_tcn_vendor_rejection_reason';

    public const TYPE_MEMBER = 'member';
    public const TYPE_VENDOR = 'vendor';

    public const STATUS_ACTIVE    = 'active';
    public const STATUS_PENDING   = 'pending';
    public const STATUS_REJECTED  = 'rejected';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_INACTIVE  = 'inactive';

    /**
     * Seed initial metadata for a newly created account.
     */
    public static function bootstrap_new_account( int $user_id, string $type ): void {
        $type = self::normalise_account_type( $type );

        update_user_meta( $user_id, self::META_ACCOUNT_TYPE, $type );

        if ( self::TYPE_VENDOR === $type ) {
            update_user_meta( $user_id, self::META_ACCOUNT_STATUS, self::STATUS_PENDING );
            update_user_meta( $user_id, self::META_VENDOR_STATUS, self::STATUS_PENDING );
            delete_user_meta( $user_id, self::META_VENDOR_REJECTION_REASON );
            Roles::assign_vendor_role( $user_id );
            return;
        }

        update_user_meta( $user_id, self::META_ACCOUNT_STATUS, self::STATUS_ACTIVE );
        update_user_meta( $user_id, self::META_VENDOR_STATUS, self::STATUS_INACTIVE );
    }

    /**
     * Mark a vendor account as approved/active.
     */
    public static function approve_vendor( int $user_id ): void {
        update_user_meta( $user_id, self::META_ACCOUNT_STATUS, self::STATUS_ACTIVE );
        update_user_meta( $user_id, self::META_VENDOR_STATUS, self::STATUS_ACTIVE );
        delete_user_meta( $user_id, self::META_VENDOR_REJECTION_REASON );
    }

    /**
     * Mark a vendor account as rejected and store the optional reason.
     */
    public static function reject_vendor( int $user_id, string $reason = '' ): void {
        update_user_meta( $user_id, self::META_ACCOUNT_STATUS, self::STATUS_REJECTED );
        update_user_meta( $user_id, self::META_VENDOR_STATUS, self::STATUS_REJECTED );

        if ( '' !== $reason ) {
            update_user_meta( $user_id, self::META_VENDOR_REJECTION_REASON, $reason );
        } else {
            delete_user_meta( $user_id, self::META_VENDOR_REJECTION_REASON );
        }
    }

    /**
     * Suspend an account while leaving the existing type untouched.
     */
    public static function suspend_account( int $user_id ): void {
        update_user_meta( $user_id, self::META_ACCOUNT_STATUS, self::STATUS_SUSPENDED );
    }

    /**
     * Return normalised metadata describing the account profile.
     *
     * @return array{account_type:string,account_status:string,vendor_status:string,vendor_rejection_reason?:string}
     */
    public static function get_account_snapshot( int $user_id ): array {
        $type           = self::normalise_account_type( (string) get_user_meta( $user_id, self::META_ACCOUNT_TYPE, true ) );
        $account_status = self::normalise_status( (string) get_user_meta( $user_id, self::META_ACCOUNT_STATUS, true ), self::STATUS_ACTIVE );

        if ( self::TYPE_VENDOR === $type && self::STATUS_ACTIVE !== $account_status && self::STATUS_PENDING !== $account_status && self::STATUS_REJECTED !== $account_status && self::STATUS_SUSPENDED !== $account_status ) {
            $account_status = self::STATUS_PENDING;
        }

        $vendor_status = (string) get_user_meta( $user_id, self::META_VENDOR_STATUS, true );
        $vendor_status = self::normalise_vendor_status( $vendor_status, $type, $account_status );

        $snapshot = array(
            'account_type'   => $type,
            'account_status' => $account_status,
            'vendor_status'  => $vendor_status,
        );

        $reason = (string) get_user_meta( $user_id, self::META_VENDOR_REJECTION_REASON, true );
        if ( '' !== $reason ) {
            $snapshot['vendor_rejection_reason'] = $reason;
        }

        return $snapshot;
    }

    /**
     * Ensure metadata exists for legacy accounts that predate the new fields.
     */
    public static function ensure_defaults( int $user_id ): void {
        $snapshot = self::get_account_snapshot( $user_id );

        update_user_meta( $user_id, self::META_ACCOUNT_TYPE, $snapshot['account_type'] );
        update_user_meta( $user_id, self::META_ACCOUNT_STATUS, $snapshot['account_status'] );
        update_user_meta( $user_id, self::META_VENDOR_STATUS, $snapshot['vendor_status'] );
    }

    /**
     * Convert arbitrary input into a supported account type.
     */
    protected static function normalise_account_type( string $type ): string {
        $type = strtolower( sanitize_key( $type ) );

        if ( self::TYPE_VENDOR === $type ) {
            return self::TYPE_VENDOR;
        }

        return self::TYPE_MEMBER;
    }

    /**
     * Normalise the requested status.
     */
    protected static function normalise_status( string $status, string $default ): string {
        $status = strtolower( sanitize_key( $status ) );
        $allowed = array(
            self::STATUS_ACTIVE,
            self::STATUS_PENDING,
            self::STATUS_REJECTED,
            self::STATUS_SUSPENDED,
        );

        if ( in_array( $status, $allowed, true ) ) {
            return $status;
        }

        return $default;
    }

    /**
     * Normalise vendor status while accounting for non-vendor accounts.
     */
    protected static function normalise_vendor_status( string $status, string $type, string $account_status ): string {
        $status = strtolower( sanitize_key( $status ) );
        $allowed = array(
            self::STATUS_ACTIVE,
            self::STATUS_PENDING,
            self::STATUS_REJECTED,
            self::STATUS_SUSPENDED,
            self::STATUS_INACTIVE,
        );

        if ( self::TYPE_VENDOR !== $type ) {
            return self::STATUS_INACTIVE;
        }

        if ( in_array( $status, $allowed, true ) && self::STATUS_INACTIVE !== $status ) {
            return $status;
        }

        if ( self::STATUS_REJECTED === $account_status ) {
            return self::STATUS_REJECTED;
        }

        if ( self::STATUS_SUSPENDED === $account_status ) {
            return self::STATUS_SUSPENDED;
        }

        if ( self::STATUS_ACTIVE === $account_status ) {
            return self::STATUS_ACTIVE;
        }

        return self::STATUS_PENDING;
    }
}
