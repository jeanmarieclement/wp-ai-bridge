<?php
/**
 * Plugin Name:       WP AI Bridge
 * Plugin URI:        https://jmclement.net
 * Description:       Espone endpoint REST sicuri per gestione contenuti tramite API key per utente. Pensato per integrazione con servizi AI esterni (Claude, ChatGPT, automazioni).
 * Version:           1.2.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Jean-Marie Clément
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-ai-bridge
 *
 * @package WPAIBridge
 */

// Blocca accesso diretto al file.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Costanti del plugin.
define( 'WPAIB_VERSION', '1.2.0' );
define( 'WPAIB_PLUGIN_FILE', __FILE__ );
define( 'WPAIB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPAIB_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WPAIB_API_NAMESPACE', 'wpaib/v1' );
define( 'WPAIB_RATE_LIMIT_REQUESTS', 300 );
define( 'WPAIB_RATE_LIMIT_WINDOW', 60 );
define( 'WPAIB_KEY_LENGTH_BYTES', 32 );
define( 'WPAIB_OAUTH_CODE_TTL',    600 );      // secondi: 10 minuti
define( 'WPAIB_OAUTH_TOKEN_TTL',   3600 );     // secondi: 1 ora
define( 'WPAIB_OAUTH_REFRESH_TTL', 2592000 );  // secondi: 30 giorni

// Autoloader minimale (no Composer, zero dipendenze esterne).
require_once WPAIB_PLUGIN_DIR . 'includes/class-wpaib-installer.php';
require_once WPAIB_PLUGIN_DIR . 'includes/class-wpaib-logger.php';
require_once WPAIB_PLUGIN_DIR . 'includes/class-wpaib-rate-limiter.php';
require_once WPAIB_PLUGIN_DIR . 'includes/class-wpaib-api-key-manager.php';
require_once WPAIB_PLUGIN_DIR . 'includes/class-wpaib-auth.php';
require_once WPAIB_PLUGIN_DIR . 'includes/endpoints/class-wpaib-posts-controller.php';
require_once WPAIB_PLUGIN_DIR . 'includes/endpoints/class-wpaib-media-controller.php';
require_once WPAIB_PLUGIN_DIR . 'includes/endpoints/class-wpaib-taxonomy-controller.php';
require_once WPAIB_PLUGIN_DIR . 'includes/endpoints/class-wpaib-pages-controller.php';
require_once WPAIB_PLUGIN_DIR . 'includes/endpoints/class-wpaib-site-controller.php';
require_once WPAIB_PLUGIN_DIR . 'includes/endpoints/class-wpaib-search-controller.php';
require_once WPAIB_PLUGIN_DIR . 'includes/endpoints/class-wpaib-mcp-controller.php';
require_once WPAIB_PLUGIN_DIR . 'includes/endpoints/class-wpaib-mcp-http-controller.php';
require_once WPAIB_PLUGIN_DIR . 'includes/endpoints/class-wpaib-openapi-controller.php';
require_once WPAIB_PLUGIN_DIR . 'admin/class-wpaib-admin.php';
require_once WPAIB_PLUGIN_DIR . 'includes/class-wpaib-oauth-client-manager.php';
require_once WPAIB_PLUGIN_DIR . 'includes/class-wpaib-oauth-server.php';
require_once WPAIB_PLUGIN_DIR . 'includes/class-wpaib-oauth-authorize.php';
require_once WPAIB_PLUGIN_DIR . 'includes/endpoints/class-wpaib-oauth-controller.php';
require_once WPAIB_PLUGIN_DIR . 'includes/class-wpaib-oauth-discovery.php';
require_once WPAIB_PLUGIN_DIR . 'includes/class-wpaib-plugin.php';

// Hook di attivazione e disattivazione.
register_activation_hook( __FILE__, array( 'WPAIB_Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WPAIB_Installer', 'deactivate' ) );

// Migrazione DB se la versione è cambiata.
add_action( 'plugins_loaded', array( 'WPAIB_Installer', 'maybe_upgrade' ), 5 );

// Bootstrap del plugin.
add_action( 'plugins_loaded', array( 'WPAIB_Plugin', 'init' ) );
