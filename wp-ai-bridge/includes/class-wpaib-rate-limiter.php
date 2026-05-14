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

		$key   = 'wpaib_rl_' . md5( $identifier );
		$count = get_transient( $key );

		if ( false === $count ) {
			set_transient( $key, 1, WPAIB_RATE_LIMIT_WINDOW );
			return true;
		}

		if ( (int) $count >= WPAIB_RATE_LIMIT_REQUESTS ) {
			return false;
		}

		set_transient( $key, (int) $count + 1, WPAIB_RATE_LIMIT_WINDOW );
		return true;
	}
}
