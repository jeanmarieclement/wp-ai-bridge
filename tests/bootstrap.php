<?php
/**
 * Base comune alle suite: percorsi del plugin e funzioni di asserzione.
 *
 * Volutamente NON definisce stub di WordPress. Le suite hanno bisogno dello
 * stesso nome con forme diverse — get_post_stati() serve con i flag
 * internal/exclude_from_search in una e public nell'altra — e condividerne una
 * sola versione farebbe passare un test mentre ne verifica un'altra cosa.
 * Ogni suite dichiara i propri, e gira nel proprio processo.
 *
 * @package WPAIBridge
 */

define( 'WPAIB_TEST_PLUGIN_DIR', dirname( __DIR__ ) . '/wp-ai-bridge' );

// Costanti che il plugin si aspetta già definite quando i file vengono inclusi.
define( 'ABSPATH', sys_get_temp_dir() . '/' );
define( 'WPAIB_API_NAMESPACE', 'wpaib/v1' );

if ( ! defined( 'WPAIB_VERSION' ) ) {
	// Letta dall'intestazione del plugin, così la suite non la duplica.
	$wpaib_main = file_get_contents( WPAIB_TEST_PLUGIN_DIR . '/wp-ai-bridge.php' );
	preg_match( "/define\(\s*'WPAIB_VERSION',\s*'([^']+)'/", $wpaib_main, $wpaib_m );
	define( 'WPAIB_VERSION', isset( $wpaib_m[1] ) ? $wpaib_m[1] : '0.0.0' );
}

$GLOBALS['wpaib_failures'] = 0;
$GLOBALS['wpaib_checks']   = 0;

/**
 * Confronta due valori in identità stretta e stampa l'esito.
 *
 * @param string $label    Cosa si sta verificando.
 * @param mixed  $actual   Valore ottenuto.
 * @param mixed  $expected Valore atteso.
 * @return void
 */
function wpaib_check( $label, $actual, $expected ) {
	++$GLOBALS['wpaib_checks'];
	$ok = $actual === $expected;

	if ( ! $ok ) {
		++$GLOBALS['wpaib_failures'];
	}

	printf(
		"%-62s %s\n",
		$label,
		$ok ? 'ok' : 'FAIL (got ' . var_export( $actual, true ) . ', want ' . var_export( $expected, true ) . ')'
	);
}

/**
 * Registra un fallimento che non nasce da un confronto diretto.
 *
 * @param string $message Descrizione.
 * @return void
 */
function wpaib_fail( $message ) {
	++$GLOBALS['wpaib_checks'];
	++$GLOBALS['wpaib_failures'];
	printf( "%-62s %s\n", $message, 'FAIL' );
}

/**
 * Stampa il riepilogo e termina con exit code diverso da zero se qualcosa è rotto.
 *
 * @param string $suite Nome della suite.
 * @return void
 */
function wpaib_finish( $suite ) {
	printf(
		"\n%s: %d checks, %d failures\n",
		$suite,
		$GLOBALS['wpaib_checks'],
		$GLOBALS['wpaib_failures']
	);

	exit( $GLOBALS['wpaib_failures'] > 0 ? 1 : 0 );
}
