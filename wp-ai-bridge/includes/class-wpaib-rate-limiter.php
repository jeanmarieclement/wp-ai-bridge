<?php
/**
 * Rate limiter basato su transient.
 *
 * @package WPAIBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Limita il numero di richieste per identificatore in una finestra temporale.
 */
class WPAIB_Rate_Limiter {

	/**
	 * Verifica se l'identificatore ha superato il limite.
	 * Se non l'ha superato, incrementa il contatore.
	 *
	 * @param string $identifier Identificatore (hash chiave o IP).
	 * @return bool True se permesso, false se bloccato.
	 */
	public static function check( $identifier ) {
		if ( empty( $identifier ) ) {
			return false;
		}

		$key  = 'wpaib_rl_' . md5( $identifier );
		$data = get_transient( $key );

		$now = time();

		// Se non esiste il transient o è scaduto (caso fallback per cache persistenti)
		if ( false === $data || ! is_array( $data ) || $now > $data['expires'] ) {
			$data = array(
				'count'   => 1,
				'expires' => $now + WPAIB_RATE_LIMIT_WINDOW,
			);
			set_transient( $key, $data, WPAIB_RATE_LIMIT_WINDOW );
			return true;
		}

		if ( $data['count'] >= WPAIB_RATE_LIMIT_REQUESTS ) {
			return false;
		}

		$data['count']++;
		$ttl = $data['expires'] - $now;
		if ( $ttl < 1 ) {
			$ttl = 1;
		}

		set_transient( $key, $data, $ttl );
		return true;
	}
}
