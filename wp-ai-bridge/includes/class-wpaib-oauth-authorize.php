<?php
/**
 * Endpoint di autorizzazione OAuth2 (pagina browser con consenso).
 *
 * URL: <home>/wpaib/oauth/authorize?response_type=code&client_id=...&redirect_uri=...&state=...
 *
 * @package WPAIBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPAIB_OAuth_Authorize {

    public static function init_hooks() {
        add_action( 'init', array( __CLASS__, 'add_rewrite_rule' ) );
        add_filter( 'query_vars', array( __CLASS__, 'register_query_var' ) );
        add_action( 'template_redirect', array( __CLASS__, 'handle' ) );
    }

    public static function add_rewrite_rule() {
        add_rewrite_rule( '^wpaib/oauth/authorize/?$', 'index.php?wpaib_oauth_action=authorize', 'top' );
        add_rewrite_rule( '^authorize/?$', 'index.php?wpaib_oauth_action=authorize', 'top' );
    }

    public static function register_query_var( $vars ) {
        $vars[] = 'wpaib_oauth_action';
        return $vars;
    }

    public static function handle() {
        if ( get_query_var( 'wpaib_oauth_action' ) !== 'authorize' ) {
            return;
        }

        nocache_headers();

        if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) ) {
            self::handle_post();
        } else {
            self::handle_get();
        }

        exit;
    }

    private static function handle_get() {
        $params = self::get_authorize_params();
        $error  = self::validate_authorize_params( $params );

        if ( $error ) {
            if ( 'invalid_redirect_uri' === $error || 'unknown_client' === $error ) {
                wp_die( esc_html__( 'OAuth2 Error: invalid client or redirect URI.', 'wp-ai-bridge' ), 400 );
            }
            wp_redirect(
                add_query_arg(
                    array( 'error' => $error, 'state' => $params['state'] ),
                    $params['redirect_uri']
                )
            );
            exit;
        }

        if ( ! is_user_logged_in() ) {
            $authorize_url = home_url( '/wpaib/oauth/authorize' ) . '?' . http_build_query( array_filter( $params ) );
            wp_safe_redirect( wp_login_url( $authorize_url ) );
            exit;
        }

        $client = WPAIB_OAuth_Client_Manager::get( $params['client_id'] );
        self::render_consent_page( $client, wp_get_current_user(), $params );
    }

    private static function handle_post() {
        $nonce     = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
        $client_id = isset( $_POST['client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['client_id'] ) ) : '';

        if ( ! wp_verify_nonce( $nonce, 'wpaib_oauth_consent_' . $client_id ) ) {
            wp_die( esc_html__( 'Security check failed.', 'wp-ai-bridge' ), 403 );
        }

        if ( ! is_user_logged_in() ) {
            wp_die( esc_html__( 'Authentication required.', 'wp-ai-bridge' ), 401 );
        }

        $redirect_uri           = isset( $_POST['redirect_uri'] )          ? esc_url_raw( wp_unslash( $_POST['redirect_uri'] ) ) : '';
        $state                  = isset( $_POST['state'] )                 ? sanitize_text_field( wp_unslash( $_POST['state'] ) ) : '';
        $scope                  = isset( $_POST['scope'] )                 ? sanitize_text_field( wp_unslash( $_POST['scope'] ) ) : '';
        $decision               = isset( $_POST['decision'] )              ? sanitize_key( $_POST['decision'] ) : '';
        $code_challenge         = isset( $_POST['code_challenge'] )        ? sanitize_text_field( wp_unslash( $_POST['code_challenge'] ) ) : '';
        $code_challenge_method  = isset( $_POST['code_challenge_method'] ) ? sanitize_key( $_POST['code_challenge_method'] ) : '';

        // Valida redirect_uri PRIMA di usarla in qualsiasi redirect (sia allow che deny).
        if ( ! WPAIB_OAuth_Client_Manager::validate_redirect_uri( $client_id, $redirect_uri ) ) {
            wp_die( esc_html__( 'Invalid redirect URI.', 'wp-ai-bridge' ), 400 );
        }

        if ( 'deny' === $decision ) {
            wp_redirect( add_query_arg( array( 'error' => 'access_denied', 'state' => $state ), $redirect_uri ) );
            exit;
        }

        if ( 'allow' !== $decision ) {
            wp_die( esc_html__( 'Invalid decision.', 'wp-ai-bridge' ), 400 );
        }

        $user_id = get_current_user_id();
        $code    = WPAIB_OAuth_Server::create_auth_code( $client_id, $user_id, $redirect_uri, $scope, $code_challenge, $code_challenge_method );

        if ( is_wp_error( $code ) ) {
            wp_die( esc_html( $code->get_error_message() ), 500 );
        }

        wp_redirect(
            add_query_arg(
                array_filter( array(
                    'code'  => $code,
                    'state' => $state ?: null,
                ) ),
                $redirect_uri
            )
        );
        exit;
    }

    private static function get_authorize_params() {
        return array(
            'response_type'         => isset( $_GET['response_type'] )         ? sanitize_key( $_GET['response_type'] ) : '',
            'client_id'             => isset( $_GET['client_id'] )             ? sanitize_text_field( wp_unslash( $_GET['client_id'] ) ) : '',
            'redirect_uri'          => isset( $_GET['redirect_uri'] )          ? esc_url_raw( wp_unslash( $_GET['redirect_uri'] ) ) : '',
            'scope'                 => isset( $_GET['scope'] )                 ? sanitize_text_field( wp_unslash( $_GET['scope'] ) ) : '',
            'state'                 => isset( $_GET['state'] )                 ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '',
            'code_challenge'        => isset( $_GET['code_challenge'] )        ? sanitize_text_field( wp_unslash( $_GET['code_challenge'] ) ) : '',
            'code_challenge_method' => isset( $_GET['code_challenge_method'] ) ? sanitize_key( $_GET['code_challenge_method'] ) : '',
        );
    }

    private static function validate_authorize_params( $params ) {
        if ( 'code' !== $params['response_type'] ) {
            return 'unsupported_response_type';
        }

        if ( empty( $params['client_id'] ) ) {
            return 'unknown_client';
        }

        $client = WPAIB_OAuth_Client_Manager::get( $params['client_id'] );
        if ( ! $client ) {
            return 'unknown_client';
        }

        if ( empty( $params['redirect_uri'] ) || ! WPAIB_OAuth_Client_Manager::validate_redirect_uri( $params['client_id'], $params['redirect_uri'] ) ) {
            return 'invalid_redirect_uri';
        }

        return null;
    }

    private static function render_consent_page( $client, $user, $params ) {
        include WPAIB_PLUGIN_DIR . 'includes/views/oauth-consent.php';
    }
}
