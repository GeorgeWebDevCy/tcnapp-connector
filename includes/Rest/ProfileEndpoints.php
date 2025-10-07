<?php
namespace TCN\Platform\Rest;

use TCN\Platform\Auth\TokenAuthenticator;
use TCN\Platform\Support\Options;
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

    /**
     * @var array<string, mixed>
     */
    protected $login_settings;

    public function __construct( ?TokenAuthenticator $token_authenticator = null, array $login_settings = array() ) {
        $this->token_authenticator = $token_authenticator;
        $this->login_settings      = ! empty( $login_settings ) ? $login_settings : Options::get_login_settings();
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
                array(
                    'methods'             => WP_REST_Server::DELETABLE,
                    'callback'            => array( $this, 'handle_avatar_delete' ),
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
        $https = $this->maybe_enforce_https( $request );
        if ( is_wp_error( $https ) ) {
            return $https;
        }

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

        if ( $user_id > 0 && get_current_user_id() !== $user_id ) {
            $user = get_user_by( 'id', $user_id );

            if ( $user instanceof WP_User ) {
                wp_set_current_user( $user_id );
            }
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

        $files           = array();
        $avatar_url      = '';
        $avatar_base64   = '';
        $avatar_mime     = '';
        $avatar_filename = '';
        $upload          = null;
        $using_remote    = false;

        if ( method_exists( $request, 'get_file_params' ) ) {
            $files = (array) $request->get_file_params();
        }

        if ( empty( $files['avatar'] ) && isset( $_FILES['avatar'] ) ) {
            $files['avatar'] = $_FILES['avatar'];
        }

        if ( method_exists( $request, 'get_param' ) ) {
            $avatar_url      = trim( (string) $request->get_param( 'avatar_url' ) );
            $avatar_base64   = trim( (string) $request->get_param( 'avatar_base64' ) );
            $avatar_mime     = trim( (string) $request->get_param( 'avatar_mime' ) );
            $avatar_filename = trim( (string) $request->get_param( 'avatar_filename' ) );
        } else {
            if ( isset( $_POST['avatar_url'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
                $avatar_url = trim( (string) wp_unslash( $_POST['avatar_url'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
            }

            if ( isset( $_POST['avatar_base64'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
                $avatar_base64 = trim( (string) wp_unslash( $_POST['avatar_base64'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
            }

            if ( isset( $_POST['avatar_mime'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
                $avatar_mime = trim( (string) wp_unslash( $_POST['avatar_mime'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
            }

            if ( isset( $_POST['avatar_filename'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
                $avatar_filename = trim( (string) wp_unslash( $_POST['avatar_filename'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
            }
        }

        $this->log_debug(
            'handle_avatar_upload invoked',
            array(
                'resolved_user_id' => $user_id,
                'files_present'    => isset( $files['avatar'] ),
                'remote_avatar'    => '' !== $avatar_url,
                'base64_avatar'    => '' !== $avatar_base64,
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

        $file = isset( $files['avatar'] ) ? $files['avatar'] : null;

        if ( is_array( $file ) ) {
            $has_tmp_name = isset( $file['tmp_name'] ) && '' !== $file['tmp_name'];
            $file_error   = isset( $file['error'] ) ? (int) $file['error'] : UPLOAD_ERR_OK;

            if ( UPLOAD_ERR_OK !== $file_error ) {
                if ( '' !== $avatar_url ) {
                    $file = null;
                } else {
                    $this->log_debug(
                        'handle_avatar_upload received file upload error',
                        array(
                            'file_error' => $file_error,
                        )
                    );

                    return new WP_Error(
                        'tcn_avatar_upload_error',
                        $this->describe_upload_error( $file_error ),
                        array( 'status' => 400 )
                    );
                }
            } elseif ( ! $has_tmp_name ) {
                if ( '' !== $avatar_url ) {
                    $file = null;
                } else {
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
            }
        } elseif ( null !== $file && '' === $avatar_url ) {
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

        if ( '' !== $avatar_url ) {
            $upload = $this->download_remote_avatar( $avatar_url );

            if ( is_wp_error( $upload ) ) {
                return $upload;
            }

            $using_remote = true;
        } elseif ( '' !== $avatar_base64 ) {
            $upload = $this->store_base64_avatar( $avatar_base64, $avatar_mime, $avatar_filename );

            if ( is_wp_error( $upload ) ) {
                return $upload;
            }

            $using_remote = true;
        }

        if ( ! $using_remote ) {
            if ( empty( $file ) || ! is_array( $file ) ) {
                $this->log_debug( 'handle_avatar_upload missing avatar file data' );

                return new WP_Error(
                    'tcn_avatar_missing',
                    __( 'Upload an image file using the avatar field.', 'tcnapp-connector' ),
                    array( 'status' => 400 )
                );
            }

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
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/post.php';

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

        $simple_local_avatar = $this->build_simple_local_avatar_meta( $attachment_id, $avatar_urls );

        if ( ! empty( $simple_local_avatar ) ) {
            update_user_meta( $user_id, 'simple_local_avatar', $simple_local_avatar );
        }

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
     * Remove a user's uploaded avatar and return the refreshed profile payload.
     */
    public function handle_avatar_delete( WP_REST_Request $request ) {
        if ( method_exists( $request, 'get_attribute' ) ) {
            $user_id = (int) $request->get_attribute( 'tcn_authenticated_user_id' );
        } else {
            $user_id = (int) $request->get_param( 'tcn_authenticated_user_id' );
        }

        if ( $user_id <= 0 ) {
            $user_id = get_current_user_id();
        }

        $this->log_debug(
            'handle_avatar_delete invoked',
            array(
                'resolved_user_id' => $user_id,
            )
        );

        if ( $user_id <= 0 ) {
            $this->log_debug( 'handle_avatar_delete returning unauthorized because no user ID was resolved' );

            return new WP_Error(
                'tcn_rest_unauthorized',
                __( 'Authentication is required to delete an avatar.', 'tcnapp-connector' ),
                array( 'status' => rest_authorization_required_code() )
            );
        }

        $this->clear_avatar_metadata( $user_id );

        clean_user_cache( $user_id );

        $user_response = $this->prepare_user_response( $request );
        if ( is_wp_error( $user_response ) ) {
            $this->log_debug(
                'handle_avatar_delete failed to prepare user response',
                array(
                    'error_code'    => $user_response->get_error_code(),
                    'error_message' => $user_response->get_error_message(),
                )
            );

            return $user_response;
        }

        $this->log_debug(
            'handle_avatar_delete completed successfully',
            array(
                'user_id' => $user_id,
            )
        );

        return $user_response;
    }

    /**
     * Store an avatar supplied as a base64 encoded string in the uploads directory.
     */
    protected function store_base64_avatar( string $data, string $mime = '', string $file_name = '' ) {
        $data      = trim( $data );
        $mime      = trim( $mime );
        $file_name = sanitize_file_name( $file_name );

        if ( '' === $data ) {
            return new WP_Error(
                'tcn_avatar_missing',
                __( 'Upload an image file using the avatar field.', 'tcnapp-connector' ),
                array( 'status' => 400 )
            );
        }

        if ( preg_match( '#^data:(.*?);base64,(.*)$#', $data, $matches ) ) {
            if ( empty( $mime ) && ! empty( $matches[1] ) ) {
                $mime = trim( (string) $matches[1] );
            }

            $data = (string) $matches[2];
        }

        $stripped = preg_replace( '/\s+/', '', $data );

        if ( is_string( $stripped ) ) {
            $data = $stripped;
        }

        $data = trim( $data );

        $decoded = base64_decode( $data, true );

        if ( false === $decoded || '' === $decoded ) {
            $this->log_debug( 'store_base64_avatar failed to decode data' );

            return new WP_Error(
                'tcn_avatar_upload_error',
                __( 'Unable to decode the provided avatar image.', 'tcnapp-connector' ),
                array( 'status' => 400 )
            );
        }

        if ( '' === $file_name ) {
            if ( '' !== $mime && 0 === strpos( $mime, 'image/' ) ) {
                $extension = sanitize_key( substr( $mime, 6 ) );

                if ( '' !== $extension ) {
                    $file_name = sanitize_file_name( 'base64-avatar.' . $extension );
                }
            }

            if ( '' === $file_name ) {
                $file_name = 'base64-avatar';
            }
        }

        $wp_filetype = wp_check_filetype( $file_name, null );

        if ( '' === $mime && ! empty( $wp_filetype['type'] ) ) {
            $mime = $wp_filetype['type'];
        }

        $upload = wp_upload_bits( $file_name, '', $decoded );

        if ( ! empty( $upload['error'] ) ) {
            $this->log_debug(
                'store_base64_avatar failed to write file',
                array(
                    'error' => $upload['error'],
                )
            );

            return new WP_Error(
                'tcn_avatar_upload_error',
                $upload['error'],
                array( 'status' => 500 )
            );
        }

        $file_path = isset( $upload['file'] ) ? (string) $upload['file'] : '';

        if ( '' === $file_path ) {
            return new WP_Error(
                'tcn_avatar_upload_error',
                __( 'Unable to process the uploaded file.', 'tcnapp-connector' ),
                array( 'status' => 500 )
            );
        }

        if ( '' === $mime ) {
            $checked = wp_check_filetype( $file_path );

            if ( ! empty( $checked['type'] ) ) {
                $mime = $checked['type'];
            }
        }

        if ( '' === $mime || 0 !== strpos( $mime, 'image/' ) ) {
            wp_delete_file( $file_path );

            return new WP_Error(
                'tcn_avatar_invalid_type',
                __( 'The uploaded file must be a valid image.', 'tcnapp-connector' ),
                array( 'status' => 415 )
            );
        }

        $filesize = filesize( $file_path );

        if ( ! $filesize ) {
            wp_delete_file( $file_path );

            return new WP_Error(
                'tcn_avatar_upload_error',
                __( 'Unable to process the uploaded file.', 'tcnapp-connector' ),
                array( 'status' => 500 )
            );
        }

        $upload['type'] = $mime;

        $this->log_debug(
            'store_base64_avatar stored decoded image',
            array(
                'file_path' => $file_path,
                'mime_type' => $mime,
            )
        );

        return $upload;
    }

    /**
     * Download an avatar from a remote URL and save it to the uploads directory.
     */
    protected function download_remote_avatar( string $url ) {
        $url = trim( $url );

        if ( '' === $url ) {
            return new WP_Error(
                'tcn_avatar_missing',
                __( 'Upload an image file using the avatar field.', 'tcnapp-connector' ),
                array( 'status' => 400 )
            );
        }

        $parsed = wp_parse_url( $url );

        if ( ! is_array( $parsed ) || empty( $parsed['host'] ) || empty( $parsed['scheme'] ) ) {
            $this->log_debug(
                'download_remote_avatar rejecting invalid URL',
                array(
                    'avatar_url' => $url,
                )
            );

            return new WP_Error(
                'tcn_avatar_invalid_url',
                __( 'Provide a valid image URL for the avatar.', 'tcnapp-connector' ),
                array( 'status' => 400 )
            );
        }

        $scheme = strtolower( (string) $parsed['scheme'] );

        if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
            $this->log_debug(
                'download_remote_avatar rejecting unsupported URL scheme',
                array(
                    'avatar_url' => $url,
                    'scheme'     => $scheme,
                )
            );

            return new WP_Error(
                'tcn_avatar_invalid_url',
                __( 'Provide a valid image URL for the avatar.', 'tcnapp-connector' ),
                array( 'status' => 400 )
            );
        }

        $file_name   = ! empty( $parsed['path'] ) ? wp_basename( $parsed['path'] ) : '';
        $file_name   = sanitize_file_name( $file_name );
        $wp_filetype = wp_check_filetype( $file_name, null );

        $response = wp_safe_remote_get(
            $url,
            array(
                'timeout' => 10,
            )
        );

        if ( is_wp_error( $response ) ) {
            $this->log_debug(
                'download_remote_avatar request failed',
                array(
                    'avatar_url' => $url,
                    'error'      => $response->get_error_message(),
                )
            );

            return new WP_Error(
                'tcn_avatar_upload_error',
                __( 'Unable to download the remote avatar image.', 'tcnapp-connector' ),
                array( 'status' => 400 )
            );
        }

        $status_code = (int) wp_remote_retrieve_response_code( $response );

        if ( 200 !== $status_code ) {
            $this->log_debug(
                'download_remote_avatar received unexpected status',
                array(
                    'avatar_url'  => $url,
                    'status_code' => $status_code,
                )
            );

            return new WP_Error(
                'tcn_avatar_upload_error',
                __( 'Unable to download the remote avatar image.', 'tcnapp-connector' ),
                array( 'status' => 400 )
            );
        }

        $body = wp_remote_retrieve_body( $response );

        if ( '' === $body ) {
            $this->log_debug(
                'download_remote_avatar empty response body',
                array(
                    'avatar_url' => $url,
                )
            );

            return new WP_Error(
                'tcn_avatar_upload_error',
                __( 'Unable to download the remote avatar image.', 'tcnapp-connector' ),
                array( 'status' => 400 )
            );
        }

        $headers = wp_remote_retrieve_headers( $response );

        if ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
            $headers = $headers->getAll();
        }

        if ( ! $wp_filetype['type'] && ! empty( $headers ) && is_array( $headers ) ) {
            if ( isset( $headers['content-disposition'] ) ) {
                $disposition = $headers['content-disposition'];

                if ( is_array( $disposition ) ) {
                    $disposition = end( $disposition );
                }

                if ( is_string( $disposition ) && false !== strpos( $disposition, 'filename=' ) ) {
                    $filename_parts = explode( 'filename=', $disposition );
                    $candidate      = end( $filename_parts );
                    $candidate      = trim( $candidate, "\"'" );
                    $candidate      = sanitize_file_name( $candidate );

                    if ( '' !== $candidate ) {
                        $file_name   = $candidate;
                        $wp_filetype = wp_check_filetype( $file_name, null );
                    }
                }
            }

            if ( ! $wp_filetype['type'] && isset( $headers['content-type'] ) ) {
                $content_type = $headers['content-type'];

                if ( is_array( $content_type ) ) {
                    $content_type = end( $content_type );
                }

                if ( is_string( $content_type ) && 0 === strpos( $content_type, 'image/' ) ) {
                    $extension   = sanitize_key( substr( $content_type, 6 ) );
                    $file_name   = 'remote-avatar.' . $extension;
                    $wp_filetype = wp_check_filetype( $file_name, null );
                }
            }
        }

        if ( '' === $file_name ) {
            $file_name = 'remote-avatar';
        }

        $upload = wp_upload_bits( $file_name, '', $body );

        if ( ! empty( $upload['error'] ) ) {
            $this->log_debug(
                'download_remote_avatar failed to write file',
                array(
                    'avatar_url' => $url,
                    'error'      => $upload['error'],
                )
            );

            return new WP_Error(
                'tcn_avatar_upload_error',
                $upload['error'],
                array( 'status' => 500 )
            );
        }

        $file_path = isset( $upload['file'] ) ? (string) $upload['file'] : '';

        if ( '' === $file_path ) {
            return new WP_Error(
                'tcn_avatar_upload_error',
                __( 'Unable to process the uploaded file.', 'tcnapp-connector' ),
                array( 'status' => 500 )
            );
        }

        $mime_type = $wp_filetype['type'];

        if ( ! $mime_type ) {
            $checked = wp_check_filetype( $file_path );

            if ( ! empty( $checked['type'] ) ) {
                $mime_type = $checked['type'];
            }
        }

        if ( ! $mime_type || 0 !== strpos( $mime_type, 'image/' ) ) {
            wp_delete_file( $file_path );

            return new WP_Error(
                'tcn_avatar_invalid_type',
                __( 'The uploaded file must be a valid image.', 'tcnapp-connector' ),
                array( 'status' => 415 )
            );
        }

        $filesize = filesize( $file_path );

        if ( ! $filesize ) {
            wp_delete_file( $file_path );

            return new WP_Error(
                'tcn_avatar_upload_error',
                __( 'Unable to process the uploaded file.', 'tcnapp-connector' ),
                array( 'status' => 500 )
            );
        }

        $upload['type'] = $mime_type;

        $this->log_debug(
            'download_remote_avatar stored remote image',
            array(
                'avatar_url' => $url,
                'file_path'  => $file_path,
                'mime_type'  => $mime_type,
            )
        );

        return $upload;
    }

    /**
     * Mirror avatar metadata expected by the WP User Avatar plugin.
     */
    protected function sync_wp_user_avatar_meta( int $user_id, int $attachment_id, array $avatar_urls ): void {
        if ( $user_id <= 0 || $attachment_id <= 0 ) {
            return;
        }

        global $wpdb;

        $meta_key = $wpdb->get_blog_prefix() . 'user_avatar';

        update_user_meta( $user_id, $meta_key, $attachment_id );
        update_user_meta( $user_id, 'wp_user_avatar', $attachment_id );

        if ( empty( $avatar_urls ) ) {
            return;
        }

        $meta = array( 'media_id' => $attachment_id );

        foreach ( $avatar_urls as $size => $url ) {
            if ( ! is_string( $url ) || '' === $url ) {
                continue;
            }

            if ( ! is_string( $size ) ) {
                $size = (string) $size;
            }

            $meta[ $size ] = $url;
        }

        update_user_meta( $user_id, 'wp_user_avatar_meta', $meta );
    }

    /**
     * Inject stored avatar URLs into REST user responses.
     */
    public function filter_user_response( $response, $user, $request ) {
        if ( ! $response instanceof WP_REST_Response ) {
            return $response;
        }

        $avatar_id   = (int) get_user_meta( $user->ID, '_gn_profile_avatar_id', true );
        $avatar_urls = array();

        if ( $avatar_id > 0 ) {
            $avatar_urls = $this->prepare_avatar_urls( $avatar_id );
        }

        if ( empty( $avatar_urls ) ) {
            $simple_avatar = get_user_meta( $user->ID, 'simple_local_avatar', true );
            $avatar_urls   = $this->normalise_simple_local_avatar_urls( $simple_avatar );

            if ( $avatar_id <= 0 && is_array( $simple_avatar ) && ! empty( $simple_avatar['media_id'] ) ) {
                $avatar_id   = (int) $simple_avatar['media_id'];
                $avatar_urls = $this->prepare_avatar_urls( $avatar_id );
            }

            if ( $avatar_id > 0 && ! empty( $avatar_urls ) ) {
                update_user_meta( $user->ID, '_gn_profile_avatar_id', $avatar_id );
                update_user_meta( $user->ID, '_gn_profile_avatar_urls', $avatar_urls );
            }
        }

        if ( empty( $avatar_urls ) ) {
            $wp_user_avatar = $this->resolve_wp_user_avatar_data( $user->ID );
            $avatar_id      = $wp_user_avatar['attachment_id'];
            $avatar_urls    = $wp_user_avatar['urls'];

            if ( $avatar_id > 0 && ! empty( $avatar_urls ) ) {
                update_user_meta( $user->ID, '_gn_profile_avatar_id', $avatar_id );
                update_user_meta( $user->ID, '_gn_profile_avatar_urls', $avatar_urls );
            }
        }

        if ( empty( $avatar_urls ) ) {
            return $response;
        }

        $data = $response->get_data();
        $existing = ( isset( $data['avatar_urls'] ) && is_array( $data['avatar_urls'] ) ) ? $data['avatar_urls'] : array();
        $data['avatar_urls'] = array_replace( $existing, $avatar_urls );
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
     * Block non-HTTPS requests unless development overrides allow it.
     *
     * @return true|WP_Error
     */
    protected function maybe_enforce_https( WP_REST_Request $request ) {
        $allow_dev = ! empty( $this->login_settings['allow_dev_http'] ) && defined( 'WP_DEBUG' ) && WP_DEBUG;
        $allow_dev = apply_filters( 'gn_password_api_allow_dev_http', $allow_dev, $request );

        if ( is_ssl() || $allow_dev ) {
            return true;
        }

        return new WP_Error(
            'gn_https_required',
            __( 'HTTPS is required to access this endpoint.', 'tcnapp-connector' ),
            array( 'status' => 403 )
        );
    }

    /**
     * Remove stored avatar metadata for a user.
     */
    protected function clear_avatar_metadata( int $user_id ): void {
        if ( $user_id <= 0 ) {
            return;
        }

        $this->log_debug(
            'clear_avatar_metadata removing avatar meta',
            array(
                'user_id' => $user_id,
            )
        );

        delete_user_meta( $user_id, '_gn_profile_avatar_id' );
        delete_user_meta( $user_id, '_gn_profile_avatar_urls' );
        delete_user_meta( $user_id, 'simple_local_avatar' );
        delete_user_meta( $user_id, 'wp_user_avatar' );
        delete_user_meta( $user_id, 'wp_user_avatar_meta' );

        global $wpdb;

        if ( isset( $wpdb ) && method_exists( $wpdb, 'get_blog_prefix' ) ) {
            $blog_meta_key = $wpdb->get_blog_prefix() . 'user_avatar';
            delete_user_meta( $user_id, $blog_meta_key );
        }
    }

    /**
     * Prepare avatar URLs for the response payload.
     */
    protected function prepare_avatar_urls( int $attachment_id ): array {
        if ( $attachment_id <= 0 ) {
            return array();
        }

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
     * Build metadata compatible with the Simple Local Avatars plugin.
     */
    protected function build_simple_local_avatar_meta( int $attachment_id, array $avatar_urls ): array {
        if ( $attachment_id <= 0 || empty( $avatar_urls ) ) {
            return array();
        }

        $meta = array(
            'media_id' => $attachment_id,
        );

        foreach ( $avatar_urls as $size => $url ) {
            if ( 'media_id' === $size ) {
                continue;
            }

            if ( ! is_string( $size ) ) {
                $size = (string) $size;
            }

            if ( ! is_string( $url ) || '' === $url ) {
                continue;
            }

            $meta[ $size ] = $url;

            if ( substr( $size, -5 ) === '_path' ) {
                continue;
            }

            if ( 'full' === $size ) {
                continue;
            }

            $path_key = $size . '_path';

            if ( isset( $avatar_urls[ $path_key ] ) && is_string( $avatar_urls[ $path_key ] ) ) {
                $meta[ $path_key ] = $avatar_urls[ $path_key ];
                continue;
            }

            $intermediate = image_get_intermediate_size( $attachment_id, is_numeric( $size ) ? array( (int) $size, (int) $size ) : $size );

            if ( is_array( $intermediate ) && ! empty( $intermediate['file'] ) ) {
                $full_path = get_attached_file( $attachment_id );

                if ( $full_path ) {
                    $meta[ $path_key ] = path_join( dirname( $full_path ), $intermediate['file'] );
                }
            }
        }

        if ( ! isset( $meta['full'] ) ) {
            $full = wp_get_attachment_url( $attachment_id );

            if ( $full ) {
                $meta['full'] = $full;
            }
        }

        if ( ! isset( $meta['full_path'] ) ) {
            $full_path = get_attached_file( $attachment_id );

            if ( $full_path ) {
                $meta['full_path'] = $full_path;
            }
        }

        return $meta;
    }

    /**
     * Normalise metadata saved by the Simple Local Avatars plugin into a URL map.
     *
     * @param mixed $meta Stored user meta value.
     */
    protected function normalise_simple_local_avatar_urls( $meta ): array {
        if ( ! is_array( $meta ) || empty( $meta ) ) {
            return array();
        }

        $urls = array();

        foreach ( $meta as $key => $value ) {
            if ( ! is_string( $key ) ) {
                $key = (string) $key;
            }

            if ( 'media_id' === $key ) {
                continue;
            }

            if ( is_string( $value ) && '' !== $value ) {
                $urls[ $key ] = $value;
            }
        }

        if ( empty( $urls ) && ! empty( $meta['media_id'] ) ) {
            $attachment_id = (int) $meta['media_id'];

            if ( $attachment_id > 0 ) {
                return $this->prepare_avatar_urls( $attachment_id );
            }
        }

        return $urls;
    }

    /**
     * Resolve avatar data saved by the WP User Avatar plugin.
     */
    protected function resolve_wp_user_avatar_data( int $user_id ): array {
        if ( $user_id <= 0 ) {
            return array(
                'attachment_id' => 0,
                'urls'          => array(),
            );
        }

        global $wpdb;

        $attachment_id = 0;
        $urls          = array();

        $meta_keys = array(
            $wpdb->get_blog_prefix() . 'user_avatar',
            'wp_user_avatar',
            'wp_user_avatar_meta',
        );

        foreach ( $meta_keys as $meta_key ) {
            $meta_value = get_user_meta( $user_id, $meta_key, true );

            if ( is_array( $meta_value ) ) {
                if ( isset( $meta_value['media_id'] ) ) {
                    $candidate = (int) $meta_value['media_id'];

                    if ( $candidate > 0 ) {
                        $attachment_id = $candidate;
                    }
                }

                if ( empty( $urls ) ) {
                    foreach ( $meta_value as $key => $value ) {
                        if ( 'media_id' === $key ) {
                            continue;
                        }

                        if ( ! is_string( $key ) ) {
                            $key = (string) $key;
                        }

                        if ( is_string( $value ) && '' !== $value ) {
                            $urls[ $key ] = $value;
                        }
                    }
                }

                if ( $attachment_id > 0 && ! empty( $urls ) ) {
                    break;
                }
            } elseif ( is_scalar( $meta_value ) ) {
                $candidate = absint( $meta_value );

                if ( $candidate > 0 ) {
                    $attachment_id = $candidate;

                    if ( 'wp_user_avatar_meta' !== $meta_key ) {
                        break;
                    }
                }
            }
        }

        if ( $attachment_id > 0 && empty( $urls ) ) {
            $urls = $this->prepare_avatar_urls( $attachment_id );
        }

        return array(
            'attachment_id' => $attachment_id,
            'urls'          => $urls,
        );
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
        $urls          = array();

        if ( $attachment_id > 0 ) {
            $urls = get_user_meta( $user_id, '_gn_profile_avatar_urls', true );

            if ( ! is_array( $urls ) || empty( $urls ) ) {
                $urls = $this->prepare_avatar_urls( $attachment_id );

                if ( ! empty( $urls ) ) {
                    update_user_meta( $user_id, '_gn_profile_avatar_urls', $urls );
                }
            }
        }

        if ( empty( $urls ) ) {
            $simple_avatar = get_user_meta( $user_id, 'simple_local_avatar', true );
            $urls          = $this->normalise_simple_local_avatar_urls( $simple_avatar );

            if ( $attachment_id <= 0 && is_array( $simple_avatar ) && ! empty( $simple_avatar['media_id'] ) ) {
                $attachment_id = (int) $simple_avatar['media_id'];
                $urls          = $this->prepare_avatar_urls( $attachment_id );
            }

            if ( $attachment_id > 0 && ! empty( $urls ) ) {
                update_user_meta( $user_id, '_gn_profile_avatar_id', $attachment_id );
                update_user_meta( $user_id, '_gn_profile_avatar_urls', $urls );
            }
        }

        if ( empty( $urls ) ) {
            $wp_user_avatar = $this->resolve_wp_user_avatar_data( $user_id );
            $attachment_id  = $wp_user_avatar['attachment_id'];
            $urls           = $wp_user_avatar['urls'];

            if ( $attachment_id > 0 && ! empty( $urls ) ) {
                update_user_meta( $user_id, '_gn_profile_avatar_id', $attachment_id );
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

        foreach ( $urls as $key => $candidate ) {
            if ( 'media_id' === $key ) {
                continue;
            }

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
