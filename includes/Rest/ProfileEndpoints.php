<?php
namespace TCN\Platform\Rest;

use TCN\Platform\Auth\TokenAuthenticator;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

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

        if ( $user_id <= 0 ) {
            $authenticated = $this->authenticate_with_token( $request );

            if ( is_wp_error( $authenticated ) ) {
                return $authenticated;
            }

            $user_id = (int) $authenticated;
        }

        if ( $user_id <= 0 ) {
            return new WP_Error(
                'tcn_rest_unauthorized',
                __( 'Authentication is required to upload an avatar.', 'tcnapp-connector' ),
                array( 'status' => rest_authorization_required_code() )
            );
        }

        $request->set_attribute( 'tcn_authenticated_user_id', $user_id );

        if ( ! current_user_can( 'upload_files' ) ) {
            return new WP_Error(
                'tcn_rest_forbidden',
                __( 'You do not have permission to upload files.', 'tcnapp-connector' ),
                array( 'status' => 403 )
            );
        }

        return true;
    }

    /**
     * Handle the avatar upload and return the updated user payload.
     */
    public function handle_avatar_upload( WP_REST_Request $request ) {
        $user_id = (int) $request->get_attribute( 'tcn_authenticated_user_id' );

        if ( $user_id <= 0 ) {
            $user_id = get_current_user_id();
        }

        if ( $user_id <= 0 ) {
            return new WP_Error(
                'tcn_rest_unauthorized',
                __( 'Authentication is required to upload an avatar.', 'tcnapp-connector' ),
                array( 'status' => rest_authorization_required_code() )
            );
        }

        if ( empty( $_FILES['avatar'] ) ) {
            return new WP_Error(
                'tcn_avatar_missing',
                __( 'Upload an image file using the avatar field.', 'tcnapp-connector' ),
                array( 'status' => 400 )
            );
        }

        $file = $_FILES['avatar'];

        if ( ! isset( $file['tmp_name'] ) || '' === $file['tmp_name'] ) {
            return new WP_Error(
                'tcn_avatar_missing',
                __( 'Upload an image file using the avatar field.', 'tcnapp-connector' ),
                array( 'status' => 400 )
            );
        }

        if ( ! empty( $file['error'] ) && UPLOAD_ERR_OK !== (int) $file['error'] ) {
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
            return new WP_Error(
                'tcn_avatar_upload_error',
                $upload['error'],
                array( 'status' => $this->determine_upload_error_status( $upload['error'] ) )
            );
        }

        $file_path = isset( $upload['file'] ) ? (string) $upload['file'] : '';
        $file_type = isset( $upload['type'] ) ? (string) $upload['type'] : '';

        if ( empty( $file_path ) ) {
            return new WP_Error(
                'tcn_avatar_upload_error',
                __( 'Unable to process the uploaded file.', 'tcnapp-connector' ),
                array( 'status' => 500 )
            );
        }

        if ( empty( $file_type ) || 0 !== strpos( $file_type, 'image/' ) ) {
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
            return $user_response;
        }

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
}
