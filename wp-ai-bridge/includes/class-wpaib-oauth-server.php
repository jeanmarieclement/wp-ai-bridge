<?php
/**
 * Logica core OAuth2: generazione e validazione codici e token.
 *
 * @package WPAIBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPAIB_OAuth_Server {

    /**
     * Crea un authorization code (usa e getta, TTL WPAIB_OAUTH_CODE_TTL).
     *
     * @param string $client_id   Client ID.
     * @param int    $user_id     User WP.
     * @param string $redirect_uri URI usata nella richiesta authorize.
     * @param string $scope        Scope richiesto.
     * @return string|WP_Error Plain code da passare al client.
     */
    public static function create_auth_code( $client_id, $user_id, $redirect_uri, $scope = '' ) {
        global $wpdb;

        try {
            $plain_code = 'wpaib_ac_' . bin2hex( random_bytes( 32 ) );
        } catch ( Exception $e ) {
            return new WP_Error( 'wpaib_random_failed', __( 'Cannot generate auth code.', 'wp-ai-bridge' ) );
        }

        $code_hash  = hash( 'sha256', $plain_code );
        $expires_at = gmdate( 'Y-m-d H:i:s', time() + WPAIB_OAUTH_CODE_TTL );

        $result = $wpdb->insert(
            $wpdb->prefix . 'wpaib_oauth_codes',
            array(
                'code_hash'    => $code_hash,
                'client_id'    => $client_id,
                'user_id'      => (int) $user_id,
                'redirect_uri' => $redirect_uri,
                'scope'        => substr( sanitize_text_field( $scope ), 0, 255 ),
                'expires_at'   => $expires_at,
            ),
            array( '%s', '%s', '%d', '%s', '%s', '%s' )
        );

        if ( false === $result ) {
            return new WP_Error( 'wpaib_db_error', __( 'Cannot save auth code.', 'wp-ai-bridge' ) );
        }

        return $plain_code;
    }

    /**
     * Consuma un authorization code (one-shot: lo marca come usato).
     *
     * @param string $plain_code   Codice in chiaro.
     * @param string $client_id    Deve corrispondere.
     * @param string $redirect_uri Deve corrispondere esattamente.
     * @return array|false Array con 'user_id' e 'scope', oppure false se invalido.
     */
    public static function consume_auth_code( $plain_code, $client_id, $redirect_uri ) {
        global $wpdb;

        $code_hash = hash( 'sha256', $plain_code );

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}wpaib_oauth_codes
                 WHERE code_hash = %s AND client_id = %s AND used_at IS NULL AND expires_at > UTC_TIMESTAMP()
                 LIMIT 1",
                $code_hash,
                $client_id
            )
        );

        if ( ! $row ) {
            return false;
        }

        // Confronto redirect_uri in tempo costante.
        if ( ! hash_equals( $row->redirect_uri, $redirect_uri ) ) {
            return false;
        }

        // Marca come usato (one-shot).
        $wpdb->update(
            $wpdb->prefix . 'wpaib_oauth_codes',
            array( 'used_at' => current_time( 'mysql', true ) ),
            array( 'id' => (int) $row->id ),
            array( '%s' ),
            array( '%d' )
        );

        return array(
            'user_id' => (int) $row->user_id,
            'scope'   => $row->scope,
        );
    }

    /**
     * Genera coppia access token + refresh token.
     *
     * @param string $client_id Client ID.
     * @param int    $user_id   User WP.
     * @param string $scope     Scope concesso.
     * @return array|WP_Error Array con 'access_token', 'refresh_token', 'expires_in', 'token_type', 'scope'.
     */
    public static function create_token_pair( $client_id, $user_id, $scope = '' ) {
        global $wpdb;

        try {
            $plain_at = 'wpaib_at_' . bin2hex( random_bytes( 32 ) );
            $plain_rt = 'wpaib_rt_' . bin2hex( random_bytes( 32 ) );
        } catch ( Exception $e ) {
            return new WP_Error( 'wpaib_random_failed', __( 'Cannot generate tokens.', 'wp-ai-bridge' ) );
        }

        $at_hash    = hash( 'sha256', $plain_at );
        $rt_hash    = hash( 'sha256', $plain_rt );
        $expires_at = gmdate( 'Y-m-d H:i:s', time() + WPAIB_OAUTH_TOKEN_TTL );
        $now        = current_time( 'mysql', true );

        $result = $wpdb->insert(
            $wpdb->prefix . 'wpaib_oauth_tokens',
            array(
                'access_token_hash'  => $at_hash,
                'refresh_token_hash' => $rt_hash,
                'client_id'          => $client_id,
                'user_id'            => (int) $user_id,
                'scope'              => substr( sanitize_text_field( $scope ), 0, 255 ),
                'expires_at'         => $expires_at,
                'created_at'         => $now,
            ),
            array( '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
        );

        if ( false === $result ) {
            return new WP_Error( 'wpaib_db_error', __( 'Cannot save tokens.', 'wp-ai-bridge' ) );
        }

        return array(
            'access_token'  => $plain_at,
            'refresh_token' => $plain_rt,
            'token_type'    => 'Bearer',
            'expires_in'    => WPAIB_OAUTH_TOKEN_TTL,
            'scope'         => $scope,
        );
    }

    /**
     * Valida un access token Bearer.
     *
     * @param string $plain_token Token in chiaro.
     * @return array|false Array con 'user_id', 'scope', oppure false.
     */
    public static function validate_access_token( $plain_token ) {
        global $wpdb;

        if ( empty( $plain_token ) ) {
            return false;
        }

        $at_hash = hash( 'sha256', $plain_token );

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}wpaib_oauth_tokens
                 WHERE access_token_hash = %s AND revoked_at IS NULL AND expires_at > UTC_TIMESTAMP()
                 LIMIT 1",
                $at_hash
            )
        );

        if ( ! $row ) {
            return false;
        }

        return array(
            'user_id' => (int) $row->user_id,
            'scope'   => $row->scope,
        );
    }

    /**
     * Consuma un refresh token e genera una nuova coppia (token rotation).
     *
     * @param string $plain_rt  Refresh token in chiaro.
     * @param string $client_id Deve corrispondere.
     * @return array|false Nuova coppia token oppure false.
     */
    public static function consume_refresh_token( $plain_rt, $client_id ) {
        global $wpdb;

        $rt_hash = hash( 'sha256', $plain_rt );

        $refresh_not_before = gmdate( 'Y-m-d H:i:s', time() - WPAIB_OAUTH_REFRESH_TTL );

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}wpaib_oauth_tokens
                 WHERE refresh_token_hash = %s AND client_id = %s AND revoked_at IS NULL AND created_at >= %s
                 LIMIT 1",
                $rt_hash,
                $client_id,
                $refresh_not_before
            )
        );

        if ( ! $row ) {
            return false;
        }

        // Revoca il vecchio token (refresh token rotation).
        $wpdb->update(
            $wpdb->prefix . 'wpaib_oauth_tokens',
            array( 'revoked_at' => current_time( 'mysql', true ) ),
            array( 'id' => (int) $row->id ),
            array( '%s' ),
            array( '%d' )
        );

        $new_pair = self::create_token_pair( $row->client_id, (int) $row->user_id, $row->scope );
        if ( is_wp_error( $new_pair ) ) {
            return false;
        }
        return $new_pair;
    }

    /**
     * Revoca un token (access o refresh) dato il valore in chiaro.
     * Verifica che il token appartenga al client specificato.
     *
     * @param string $plain_token Token in chiaro (access o refresh).
     * @param string $client_id   Client ID autenticato.
     * @return void
     */
    public static function revoke_token( $plain_token, $client_id ) {
        global $wpdb;

        $hash = hash( 'sha256', $plain_token );
        $now  = current_time( 'mysql', true );

        // Prova come access token.
        $wpdb->update(
            $wpdb->prefix . 'wpaib_oauth_tokens',
            array( 'revoked_at' => $now ),
            array( 'access_token_hash' => $hash, 'client_id' => $client_id ),
            array( '%s' ),
            array( '%s', '%s' )
        );

        // Prova come refresh token.
        $wpdb->update(
            $wpdb->prefix . 'wpaib_oauth_tokens',
            array( 'revoked_at' => $now ),
            array( 'refresh_token_hash' => $hash, 'client_id' => $client_id ),
            array( '%s' ),
            array( '%s', '%s' )
        );
    }

    /**
     * Cancella codici e token scaduti.
     *
     * @return void
     */
    public static function cleanup_expired() {
        global $wpdb;

        $wpdb->query(
            "DELETE FROM {$wpdb->prefix}wpaib_oauth_codes WHERE expires_at < UTC_TIMESTAMP() - INTERVAL 1 DAY"
        );
        $wpdb->query(
            "DELETE FROM {$wpdb->prefix}wpaib_oauth_tokens WHERE expires_at < UTC_TIMESTAMP() - INTERVAL 7 DAY AND revoked_at IS NOT NULL"
        );
    }
}
