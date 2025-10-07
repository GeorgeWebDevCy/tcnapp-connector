<?php
namespace TCN\Platform\Rest;

use TCN\Platform\Auth\TokenAuthenticator;
use WP_Comment;
use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_User;

class ProfileEndpoints {
    /**
     * @var TokenAuthenticator|null
     */
    protected $token_authenticator;

    public function __construct( ?TokenAuthenticator $token_authenticator = null ) {
        $this->token_authenticator = $token_authenticator;
    }

    /**
     * Register WordPress hooks.
     */
    public function register(): void {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
        add_filter( 'rest_prepare_user', array( $this, 'filter_user_response' ), 20, 3 );
        add_filter( 'get_avatar_data', array( $this, 'filter_get_avatar_data' ), 20, 2 );
        add_filter( 'get_avatar_url', array( $this, 'filter_get_avatar_url' ), 20, 3 );
    }

    /**
     * Register the avatar upload endpoint.
     */
    public function register_routes(): void {
        register_rest_route(
            'gn/v1',
            '/profile/avatar',
            array(
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array( $this, 'handle_avatar_upload' ),
                    'permission_callback' => array( $this, 'permissions_check' ),
                    'args'                => array(),
                ),
            )
        );
    }

    /**
     * Ensure the requester is authenticated and can upload files.
     */
    public function permissions_check( WP_REST_Request $request ) {
        $user_id = get_current_user_id();

        $this->log_debug(
            'permissions_check invoked',
            array(
                'initial_user_id'           => $user_id,
                'has_authorization_header' => '' !== trim( (string) $request->get_header( 'authorization' ) ),
            )
        );

        if ( $user_id <= 0 ) {
            $authenticated = $this->authenticate_with_token( $request );

            if ( is_wp_error( $authenticated ) ) {
                $this->log_debug(
                    'permissions_check authentication failed',
                    array(
                        'error_code'    => $authenticated->get_error_code(),
                        'error_message' => $authenticated->get_error_message(),
                    )
                );

                return $authenticated;
            }

            $user_id = (int) $authenticated;

            $this->log_debug(
                'permissions_check authenticated via token',
                array(
                    'authenticated_user_id' => $user_id,
                )
            );
        }

        if ( $user_id <= 0 ) {
            $this->log_debug( 'permissions_check returning unauthorized due to empty user ID' );

            return new WP_Error(
                'tcn_rest_unauthorized',
                __( 'Authentication is required to upload an avatar.', 'tcnapp-connector' ),
                array( 'status' => rest_authorization_required_code() )
            );
        }

        if ( method_exists( $request, 'set_attribute' ) ) {
            $request->set_attribute( 'tcn_authenticated_user_id', $user_id );
        } else {
            $request->set_param( 'tcn_authenticated_user_id', $user_id );
        }

        if ( current_user_can( 'upload_files' ) ) {
            $this->log_debug(
                'permissions_check current user can upload files',
                array(
                    'user_id' => $user_id,
                )
            );

            return true;
        }

        $target_user_id = $this->determine_target_user_id( $request, $user_id );

        $this->log_debug(
            'permissions_check evaluated target user',
            array(
                'request_user_id' => $user_id,
                'target_user_id'  => $target_user_id,
            )
        );

        if ( $target_user_id !== $user_id ) {
            $this->log_debug( 'permissions_check rejecting upload for mismatched user IDs' );

            return new WP_Error(
                'tcn_rest_forbidden',
                __( 'You can only upload an avatar for your own profile.', 'tcnapp-connector' ),
                array( 'status' => 403 )
            );
        }

        return true;
    }

    /**
     * Handle the avatar upload and return the updated user payload.
     */
    public function handle_avatar_upload( WP_REST_Request $request ) {
        if ( method_exists( $request, 'get_attribute' ) ) {
            $user_id = (int) $request->get_attribute( 'tcn_authenticated_user_id' );
        } else {
            $user_id = (int) $request->get_param( 'tcn_authenticated_user_id' );
        }

        if ( $user_id <= 0 ) {
            $user_id = get_current_user_id();
        }

        $files = array();
        if ( method_exists( $request, 'get_file_params' ) ) {
            $files = (array) $request->get_file_params();
        }

        if ( empty( $files['avatar'] ) && isset( $_FILES['avatar'] ) ) {
            $files['avatar'] = $_FILES['avatar'];
        }

        $this->log_debug(
            'handle_avatar_upload invoked',
            array(
                'resolved_user_id' => $user_id,
                'files_present'    => isset( $files['avatar'] ),
            )
        );

        if ( $user_id <= 0 ) {
            $this->log_debug( 'handle_avatar_upload returning unauthorized because no user ID was resolved' );

            return new WP_Error(
                'tcn_rest_unauthorized',
                __( 'Authentication is required to upload an avatar.', 'tcnapp-connector' ),
                array( 'status' => rest_authorization_required_code() )
            );
        }

        if ( empty( $files['avatar'] ) ) {
            $this->log_debug( 'handle_avatar_upload missing avatar file data' );

            return new WP_Error(
                'tcn_avatar_missing',
                __( 'Upload an image file using the avatar field.', 'tcnapp-connector' ),
                array( 'status' => 400 )
            );
        }

        $file = $files['avatar'];

        if ( ! is_array( $file ) ) {
            $this->log_debug(
                'handle_avatar_upload received unexpected file payload',
                array(
                    'file_type' => gettype( $file ),
                )
            );

            return new WP_Error(
                'tcn_avatar_missing',
                __( 'Upload an image file using the avatar field.', 'tcnapp-connector' ),
                array( 'status' => 400 )
            );
        }

        if ( ! isset( $file['tmp_name'] ) || '' === $file['tmp_name'] ) {
            $this->log_debug(
                'handle_avatar_upload missing temporary file',
                array(
                    'file_keys' => array_keys( $file ),
                )
            );

            return new WP_Error(
                'tcn_avatar_missing',
                __( 'Upload an image file using the avatar field.', 'tcnapp-connector' ),
                array( 'status' => 400 )
            );
        }

        if ( ! empty( $file['error'] ) && UPLOAD_ERR_OK !== (int) $file['error'] ) {
            $this->log_debug(
                'handle_avatar_upload received file upload error',
                array(
                    'file_error' => (int) $file['error'],
                )
            );

            return new WP_Error(
                'tcn_avatar_upload_error',
                $this->describe_upload_error( (int) $file['error'] ),
                array( 'status' => 400 )
            );
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/post.php';

        $upload = wp_handle_upload(
            $file,
            array(
                'test_form' => false,
            )
        );

        if ( isset( $upload['error'] ) ) {
            $this->log_debug(
                'handle_avatar_upload wp_handle_upload failed',
                array(
                    'upload_error' => $upload['error'],
                )
            );

            return new WP_Error(
                'tcn_avatar_upload_error',
                $upload['error'],
                array( 'status' => $this->determine_upload_error_status( $upload['error'] ) )
            );
        }

        $file_path = isset( $upload['file'] ) ? (string) $upload['file'] : '';
        $file_type = isset( $upload['type'] ) ? (string) $upload['type'] : '';

        $this->log_debug(
            'handle_avatar_upload processed wp_handle_upload result',
            array(
                'file_path_present' => ! empty( $file_path ),
                'file_type'         => $file_type,
            )
        );

        if ( empty( $file_path ) ) {
            $this->log_debug( 'handle_avatar_upload missing file path after upload handling' );

            return new WP_Error(
                'tcn_avatar_upload_error',
                __( 'Unable to process the uploaded file.', 'tcnapp-connector' ),
                array( 'status' => 500 )
            );
        }

        if ( empty( $file_type ) || 0 !== strpos( $file_type, 'image/' ) ) {
            $this->log_debug(
                'handle_avatar_upload rejecting non-image upload',
                array(
                    'file_type' => $file_type,
                )
            );

            wp_delete_file( $file_path );

            return new WP_Error(
                'tcn_avatar_invalid_type',
                __( 'The uploaded file must be a valid image.', 'tcnapp-connector' ),
                array( 'status' => 415 )
            );
        }

        $attachment = array(
            'post_mime_type' => $file_type,
            'post_title'     => sanitize_file_name( wp_basename( $file_path ) ),
            'post_content'   => '',
            'post_status'    => 'inherit',
            'post_author'    => $user_id,
        );

        $attachment_id = wp_insert_attachment( $attachment, $file_path );

        if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
            $this->log_debug(
                'handle_avatar_upload failed to insert attachment',
                array(
                    'attachment_error' => is_wp_error( $attachment_id ) ? $attachment_id->get_error_message() : 'unknown',
                )
            );

            wp_delete_file( $file_path );

            return new WP_Error(
                'tcn_avatar_store_failed',
                __( 'Unable to save the avatar to the Media Library.', 'tcnapp-connector' ),
                array( 'status' => 500 )
            );
        }

        $metadata = wp_generate_attachment_metadata( $attachment_id, $file_path );
        if ( ! empty( $metadata ) ) {
            wp_update_attachment_metadata( $attachment_id, $metadata );
        }

        $avatar_urls = $this->prepare_avatar_urls( $attachment_id );

        $this->log_debug(
            'handle_avatar_upload stored avatar metadata',
            array(
                'attachment_id' => $attachment_id,
                'avatar_sizes'  => array_keys( $avatar_urls ),
            )
        );

        update_user_meta( $user_id, '_gn_profile_avatar_id', $attachment_id );
        update_user_meta( $user_id, '_gn_profile_avatar_urls', $avatar_urls );
        update_user_meta(
            $user_id,
            'simple_local_avatar',
            array(
                'full'     => isset( $avatar_urls['full'] ) ? $avatar_urls['full'] : wp_get_attachment_url( $attachment_id ),
                'media_id' => $attachment_id,
            )
        );

        clean_user_cache( $user_id );

        $user_response = $this->prepare_user_response( $request );
        if ( is_wp_error( $user_response ) ) {
            $this->log_debug(
                'handle_avatar_upload failed to prepare user response',
                array(
                    'error_code'    => $user_response->get_error_code(),
                    'error_message' => $user_response->get_error_message(),
                )
            );

            return $user_response;
        }

        $this->log_debug(
            'handle_avatar_upload completed successfully',
            array(
                'user_id'       => $user_id,
                'attachment_id' => $attachment_id,
            )
        );

        return $user_response;
    }

    /**
     * Inject stored avatar URLs into REST user responses.
     */
    public function filter_user_response( $response, $user, $request ) {
        if ( ! $response instanceof WP_REST_Response ) {
            return $response;
        }

        $avatar_id = (int) get_user_meta( $user->ID, '_gn_profile_avatar_id', true );
        if ( $avatar_id <= 0 ) {
            return $response;
        }

        $avatar_urls = $this->prepare_avatar_urls( $avatar_id );
        if ( empty( $avatar_urls ) ) {
            return $response;
        }

        $data = $response->get_data();
        $data['avatar_urls'] = array_merge( isset( $data['avatar_urls'] ) && is_array( $data['avatar_urls'] ) ? $data['avatar_urls'] : array(), $avatar_urls );
        $response->set_data( $data );

        return $response;
    }

    /**
     * Replace the default avatar data with the uploaded attachment when available.
     */
    public function filter_get_avatar_data( array $args, $id_or_email ): array {
        if ( ! empty( $args['force_default'] ) ) {
            return $args;
        }

        $custom_url = $this->resolve_uploaded_avatar_url( $id_or_email, $args );

        if ( $custom_url ) {
            $args['url']          = $custom_url;
            $args['found_avatar'] = true;
        }

        return $args;
    }

    /**
     * Replace the default avatar URL with the uploaded attachment when available.
     */
    public function filter_get_avatar_url( string $url, $id_or_email, array $args ): string {
        if ( ! empty( $args['force_default'] ) ) {
            return $url;
        }

        $custom_url = $this->resolve_uploaded_avatar_url( $id_or_email, $args );

        if ( $custom_url ) {
            return $custom_url;
        }

        return $url;
    }

    protected function authenticate_with_token( WP_REST_Request $request ) {
        if ( ! $this->token_authenticator ) {
            return 0;
        }

        return $this->token_authenticator->authenticate_request( $request );
    }

    /**
     * Prepare avatar URLs for the response payload.
     */
    protected function prepare_avatar_urls( int $attachment_id ): array {
        $urls  = array();
        $sizes = array( 24, 48, 96, 192, 256 );

        foreach ( $sizes as $size ) {
            $image = wp_get_attachment_image_src( $attachment_id, array( $size, $size ) );
            if ( is_array( $image ) && ! empty( $image[0] ) ) {
                $urls[ (string) $size ] = $image[0];
            }
        }

        $full = wp_get_attachment_url( $attachment_id );
        if ( $full ) {
            $urls['full'] = $full;
        }

        return $urls;
    }

    /**
     * Resolve the best uploaded avatar URL for the given user reference.
     *
     * @param mixed $id_or_email Avatar reference argument passed by WordPress.
     */
    protected function resolve_uploaded_avatar_url( $id_or_email, array $args ): string {
        $user_id = $this->resolve_avatar_user_id( $id_or_email );

        if ( $user_id <= 0 ) {
            return '';
        }

        $attachment_id = (int) get_user_meta( $user_id, '_gn_profile_avatar_id', true );

        if ( $attachment_id <= 0 ) {
            return '';
        }

        $urls = get_user_meta( $user_id, '_gn_profile_avatar_urls', true );

        if ( ! is_array( $urls ) || empty( $urls ) ) {
            $urls = $this->prepare_avatar_urls( $attachment_id );

            if ( ! empty( $urls ) ) {
                update_user_meta( $user_id, '_gn_profile_avatar_urls', $urls );
            }
        }

        if ( empty( $urls ) ) {
            return '';
        }

        $size = isset( $args['size'] ) ? (int) $args['size'] : 0;

        if ( $size > 0 ) {
            $key = (string) $size;

            if ( isset( $urls[ $key ] ) && is_string( $urls[ $key ] ) && '' !== $urls[ $key ] ) {
                return $this->maybe_apply_avatar_scheme( $urls[ $key ], $args );
            }

            $closest = $this->find_closest_avatar_url( $urls, $size );

            if ( $closest ) {
                return $this->maybe_apply_avatar_scheme( $closest, $args );
            }
        }

        if ( isset( $urls['full'] ) && is_string( $urls['full'] ) && '' !== $urls['full'] ) {
            return $this->maybe_apply_avatar_scheme( $urls['full'], $args );
        }

        foreach ( $urls as $candidate ) {
            if ( is_string( $candidate ) && '' !== $candidate ) {
                return $this->maybe_apply_avatar_scheme( $candidate, $args );
            }
        }

        return '';
    }

    /**
     * Determine the most appropriate avatar URL for the requested size.
     */
    protected function find_closest_avatar_url( array $urls, int $target_size ): string {
        $closest_url  = '';
        $closest_diff = PHP_INT_MAX;

        foreach ( $urls as $size => $candidate ) {
            if ( 'full' === $size ) {
                continue;
            }

            if ( ! is_string( $candidate ) || '' === $candidate ) {
                continue;
            }

            $numeric_size = (int) $size;

            if ( $numeric_size <= 0 ) {
                continue;
            }

            $difference = abs( $numeric_size - $target_size );

            if ( $difference < $closest_diff ) {
                $closest_diff = $difference;
                $closest_url  = $candidate;
            }
        }

        return $closest_url;
    }

    /**
     * Apply the requested scheme to a resolved avatar URL.
     */
    protected function maybe_apply_avatar_scheme( string $url, array $args ): string {
        if ( '' === $url ) {
            return '';
        }

        if ( isset( $args['scheme'] ) && '' !== $args['scheme'] ) {
            return set_url_scheme( $url, $args['scheme'] );
        }

        return $url;
    }

    /**
     * Resolve a user ID from the avatar reference.
     *
     * @param mixed $id_or_email Avatar reference argument passed by WordPress.
     */
    protected function resolve_avatar_user_id( $id_or_email ): int {
        if ( is_numeric( $id_or_email ) ) {
            return absint( $id_or_email );
        }

        if ( $id_or_email instanceof WP_User ) {
            return absint( $id_or_email->ID );
        }

        if ( $id_or_email instanceof WP_Post ) {
            return absint( $id_or_email->post_author );
        }

        if ( $id_or_email instanceof WP_Comment ) {
            if ( isset( $id_or_email->user_id ) && $id_or_email->user_id > 0 ) {
                return absint( $id_or_email->user_id );
            }

            if ( ! empty( $id_or_email->comment_author_email ) ) {
                $user = get_user_by( 'email', $id_or_email->comment_author_email );

                if ( $user instanceof WP_User ) {
                    return absint( $user->ID );
                }
            }

            return 0;
        }

        if ( is_object( $id_or_email ) && isset( $id_or_email->user_id ) ) {
            return absint( $id_or_email->user_id );
        }

        if ( is_string( $id_or_email ) && '' !== $id_or_email ) {
            $user = false;

            if ( false !== strpos( $id_or_email, '@' ) ) {
                $user = get_user_by( 'email', $id_or_email );
            }

            if ( ! $user ) {
                $user = get_user_by( 'login', $id_or_email );
            }

            if ( $user instanceof WP_User ) {
                return absint( $user->ID );
            }
        }

        return 0;
    }

    /**
     * Return a REST-ready user response for the authenticated user.
     */
    protected function prepare_user_response( WP_REST_Request $request ) {
        $sub_request = new WP_REST_Request( 'GET', '/wp/v2/users/me' );
        $context     = $request->get_param( 'context' );

        if ( $context ) {
            $sub_request->set_param( 'context', $context );
        }

        $response = rest_do_request( $sub_request );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        if ( $response instanceof WP_REST_Response ) {
            return rest_ensure_response( $response->get_data() );
        }

        return rest_ensure_response( $response );
    }

    /**
     * Provide friendly error messages for common upload failures.
     */
    protected function describe_upload_error( int $code ): string {
        switch ( $code ) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return __( 'The uploaded file exceeds the maximum allowed size.', 'tcnapp-connector' );
            case UPLOAD_ERR_PARTIAL:
                return __( 'The file was only partially uploaded. Please try again.', 'tcnapp-connector' );
            case UPLOAD_ERR_NO_FILE:
                return __( 'No file was uploaded. Choose an image to continue.', 'tcnapp-connector' );
            case UPLOAD_ERR_NO_TMP_DIR:
                return __( 'Missing a temporary folder on the server.', 'tcnapp-connector' );
            case UPLOAD_ERR_CANT_WRITE:
                return __( 'Failed to write the file to disk.', 'tcnapp-connector' );
            case UPLOAD_ERR_EXTENSION:
                return __( 'A PHP extension stopped the file upload.', 'tcnapp-connector' );
            default:
                return __( 'An unexpected error occurred while uploading the file.', 'tcnapp-connector' );
        }
    }

    /**
     * Map wp_handle_upload errors to HTTP status codes.
     */
    protected function determine_upload_error_status( string $message ): int {
        $message = strtolower( $message );

        if ( false !== strpos( $message, 'type' ) || false !== strpos( $message, 'mime' ) ) {
            return 415;
        }

        if ( false !== strpos( $message, 'size' ) ) {
            return 413;
        }

        return 400;
    }

    /**
     * Determine the profile ID targeted by the request.
     */
    protected function determine_target_user_id( WP_REST_Request $request, int $fallback ): int {
        $candidates = array(
            $request->get_param( 'user_id' ),
            $request->get_param( 'id' ),
            $request->get_param( 'user' ),
            $request->get_attribute( 'tcn_profile_user_id' ),
        );

        foreach ( $candidates as $candidate ) {
            if ( null === $candidate ) {
                continue;
            }

            $candidate = (int) $candidate;

            if ( $candidate > 0 ) {
                return $candidate;
            }
        }

        return $fallback;
    }

    /**
     * Write a debug log entry when WP_DEBUG is enabled.
     */
    protected function log_debug( string $message, array $context = array() ): void {
        if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
            return;
        }

        if ( ! empty( $context ) ) {
            $context = $this->sanitize_context( $context );
            $encoded = wp_json_encode( $context );
            if ( false !== $encoded ) {
                $message .= ' ' . $encoded;
            }
        }

        error_log( '[TCN ProfileEndpoints] ' . $message );
    }

    /**
     * Sanitize context data to avoid leaking sensitive information.
     */
    protected function sanitize_context( array $context ): array {
        foreach ( $context as $key => $value ) {
            if ( is_array( $value ) ) {
                $context[ $key ] = $this->sanitize_context( $value );
                continue;
            }

            if ( is_object( $value ) ) {
                $context[ $key ] = get_class( $value );
                continue;
            }

            if ( is_string( $value ) ) {
                if ( false !== stripos( $key, 'token' ) || false !== stripos( $key, 'authorization' ) ) {
                    $context[ $key ] = $this->mask_string( $value );
                    continue;
                }

                if ( strlen( $value ) > 180 ) {
                    $context[ $key ] = substr( $value, 0, 177 ) . '...';
                }
            }
        }

        return $context;
    }

    /**
     * Mask a potentially sensitive string before logging.
     */
    protected function mask_string( string $value ): string {
        if ( strlen( $value ) <= 8 ) {
            return str_repeat( '*', strlen( $value ) );
        }

        return substr( $value, 0, 4 ) . '...' . substr( $value, -4 );
    }
}
