<?php
/**
 * Helper condivisi per gli endpoint di lettura (export completo del sito).
 *
 * @package WPAIBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Paginazione stabile per cursore (after_id) e rendering del contenuto.
 *
 * WP_Query, WP_Comment_Query e WP_User_Query non supportano nativamente un
 * cursore "ID > n": ogni metodo qui sotto aggiunge un filtro scoped su una
 * query var custom, esegue la query e rimuove subito il filtro.
 */
class WPAIB_Rest_Helper {

	/**
	 * Limite massimo di record per pagina.
	 */
	const MAX_PER_PAGE = 100;

	/**
	 * Normalizza il parametro per_page dentro i limiti consentiti.
	 *
	 * @param mixed $value   Valore grezzo.
	 * @param int   $default Default se il valore è assente o non valido.
	 * @return int
	 */
	public static function per_page( $value, $default = 10 ) {
		$per_page = (int) $value;
		if ( $per_page < 1 ) {
			$per_page = $default;
		}
		return min( self::MAX_PER_PAGE, $per_page );
	}

	/**
	 * Normalizza il parametro after_id.
	 *
	 * Null (parametro assente dalla richiesta) significa "nessun cursore":
	 * paginazione classica per pagina. Un valore esplicito, incluso 0, attiva
	 * la modalità cursore: 0 è il cursore iniziale legittimo di un export che
	 * riparte da zero, e va distinto dall'assenza del parametro.
	 *
	 * @param mixed $value Valore grezzo, o null se il parametro non è stato inviato.
	 * @return int|null
	 */
	public static function after_id( $value ) {
		if ( null === $value ) {
			return null;
		}
		return max( 0, (int) $value );
	}

	/**
	 * Esegue una WP_Query applicando, se richiesto, il cursore after_id.
	 *
	 * Con after_id l'ordinamento è forzato a ID ASC: è l'unica combinazione che
	 * garantisce a un export lungo di non saltare né duplicare record quando il
	 * contenuto cambia mentre la lettura è in corso.
	 *
	 * @param array $args     Argomenti WP_Query.
	 * @param int   $after_id Restituisce solo i record con ID maggiore di questo.
	 * @return WP_Query
	 */
	public static function query_posts( array $args, $after_id = null ) {
		$after_id = self::after_id( $after_id );

		if ( null === $after_id ) {
			return new WP_Query( $args );
		}

		$args['wpaib_after_id'] = $after_id;
		$args['orderby']        = 'ID';
		$args['order']          = 'ASC';
		unset( $args['paged'] );

		add_filter( 'posts_where', array( __CLASS__, 'filter_posts_where' ), 10, 2 );
		$query = new WP_Query( $args );
		remove_filter( 'posts_where', array( __CLASS__, 'filter_posts_where' ), 10 );

		return $query;
	}

	/**
	 * Aggiunge la clausola ID > after_id alla sola query che porta la query var.
	 *
	 * @param string   $where Clausola WHERE corrente.
	 * @param WP_Query $query Query in esecuzione.
	 * @return string
	 */
	public static function filter_posts_where( $where, $query ) {
		global $wpdb;

		$after_id = $query instanceof WP_Query ? (int) $query->get( 'wpaib_after_id' ) : 0;
		if ( $after_id < 1 ) {
			return $where;
		}

		return $where . $wpdb->prepare( " AND {$wpdb->posts}.ID > %d", $after_id );
	}

	/**
	 * Esegue una WP_Comment_Query applicando, se richiesto, il cursore after_id.
	 *
	 * @param array $args     Argomenti WP_Comment_Query.
	 * @param int   $after_id Restituisce solo i commenti con ID maggiore di questo.
	 * @return array Lista di WP_Comment.
	 */
	public static function query_comments( array $args, $after_id = null ) {
		$after_id = self::after_id( $after_id );

		if ( null === $after_id ) {
			$query = new WP_Comment_Query();
			return $query->query( $args );
		}

		$args['wpaib_after_id'] = $after_id;
		$args['orderby']        = 'comment_ID';
		$args['order']          = 'ASC';
		unset( $args['offset'], $args['paged'] );

		add_filter( 'comments_clauses', array( __CLASS__, 'filter_comments_clauses' ), 10, 2 );
		$query    = new WP_Comment_Query();
		$comments = $query->query( $args );
		remove_filter( 'comments_clauses', array( __CLASS__, 'filter_comments_clauses' ), 10 );

		return $comments;
	}

	/**
	 * Aggiunge la clausola comment_ID > after_id alla sola query marcata.
	 *
	 * @param array            $clauses Clausole SQL.
	 * @param WP_Comment_Query $query   Query in esecuzione.
	 * @return array
	 */
	public static function filter_comments_clauses( $clauses, $query ) {
		global $wpdb;

		$after_id = isset( $query->query_vars['wpaib_after_id'] ) ? (int) $query->query_vars['wpaib_after_id'] : 0;
		if ( $after_id < 1 ) {
			return $clauses;
		}

		$clauses['where'] .= $wpdb->prepare( " AND {$wpdb->comments}.comment_ID > %d", $after_id );

		return $clauses;
	}

	/**
	 * Esegue una WP_User_Query applicando, se richiesto, il cursore after_id.
	 *
	 * @param array $args     Argomenti WP_User_Query.
	 * @param int   $after_id Restituisce solo gli utenti con ID maggiore di questo.
	 * @return WP_User_Query
	 */
	public static function query_users( array $args, $after_id = null ) {
		$after_id = self::after_id( $after_id );

		if ( null === $after_id ) {
			return new WP_User_Query( $args );
		}

		$args['wpaib_after_id'] = $after_id;
		$args['orderby']        = 'ID';
		$args['order']          = 'ASC';
		unset( $args['offset'], $args['paged'] );

		add_action( 'pre_user_query', array( __CLASS__, 'filter_user_query' ) );
		$query = new WP_User_Query( $args );
		remove_action( 'pre_user_query', array( __CLASS__, 'filter_user_query' ) );

		return $query;
	}

	/**
	 * Aggiunge la clausola ID > after_id alla sola query marcata.
	 *
	 * @param WP_User_Query $query Query in esecuzione.
	 * @return void
	 */
	public static function filter_user_query( $query ) {
		global $wpdb;

		$after_id = isset( $query->query_vars['wpaib_after_id'] ) ? (int) $query->query_vars['wpaib_after_id'] : 0;
		if ( $after_id < 1 ) {
			return;
		}

		$query->query_where .= $wpdb->prepare( " AND {$wpdb->users}.ID > %d", $after_id );
	}

	/**
	 * Applica il cursore after_id a una lista di termini.
	 *
	 * WP_Term_Query espone term_id come chiave di ordinamento, quindi basta il
	 * filtro sulle clausole: nessun ordinamento "name" viene toccato se after_id
	 * non è usato.
	 *
	 * @param array $args     Argomenti get_terms().
	 * @param int   $after_id Restituisce solo i termini con ID maggiore di questo.
	 * @return array|WP_Error
	 */
	public static function query_terms( array $args, $after_id = null ) {
		$after_id = self::after_id( $after_id );

		if ( null === $after_id ) {
			return get_terms( $args );
		}

		$args['wpaib_after_id'] = $after_id;
		$args['orderby']        = 'term_id';
		$args['order']          = 'ASC';
		unset( $args['offset'] );

		add_filter( 'terms_clauses', array( __CLASS__, 'filter_terms_clauses' ), 10, 3 );
		$terms = get_terms( $args );
		remove_filter( 'terms_clauses', array( __CLASS__, 'filter_terms_clauses' ), 10 );

		return $terms;
	}

	/**
	 * Aggiunge la clausola t.term_id > after_id alla sola query marcata.
	 *
	 * @param array $clauses    Clausole SQL.
	 * @param array $taxonomies Tassonomie interrogate.
	 * @param array $args       Argomenti della query.
	 * @return array
	 */
	public static function filter_terms_clauses( $clauses, $taxonomies, $args ) {
		global $wpdb;

		$after_id = isset( $args['wpaib_after_id'] ) ? (int) $args['wpaib_after_id'] : 0;
		if ( $after_id < 1 ) {
			return $clauses;
		}

		$clauses['where'] .= $wpdb->prepare( ' AND t.term_id > %d', $after_id );

		return $clauses;
	}

	/**
	 * Restituisce l'ID più alto di una lista già ordinata, come cursore successivo.
	 *
	 * @param array  $items Elementi preparati per la risposta.
	 * @param string $key   Chiave che contiene l'ID.
	 * @return int|null Null se la lista è vuota.
	 */
	public static function next_cursor( array $items, $key = 'id' ) {
		if ( empty( $items ) ) {
			return null;
		}

		$last = end( $items );

		return isset( $last[ $key ] ) ? (int) $last[ $key ] : null;
	}

	/**
	 * Renderizza il contenuto di un post applicando i filtri di WordPress.
	 *
	 * Blocchi riutilizzabili, query loop, gallerie dinamiche e shortcode non
	 * hanno HTML interno in post_content: solo the_content li risolve. Il post
	 * globale viene impostato e ripristinato a mano perché in contesto REST
	 * $wp_query è vuota e wp_reset_postdata() non avrebbe nulla da ripristinare.
	 *
	 * @param WP_Post $post Post da renderizzare.
	 * @return string HTML renderizzato.
	 */
	public static function render_content( $post ) {
		if ( ! $post instanceof WP_Post ) {
			return '';
		}

		$previous = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;

		$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		setup_postdata( $post );

		$rendered = apply_filters( 'the_content', $post->post_content );

		$GLOBALS['post'] = $previous; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		if ( $previous instanceof WP_Post ) {
			setup_postdata( $previous );
		}

		return is_string( $rendered ) ? $rendered : '';
	}

	/**
	 * Espande lo stato richiesto in una lista di post_status per WP_Query.
	 *
	 * `any` mantiene il significato storico (tutto tranne il cestino); `trash`
	 * va richiesto esplicitamente.
	 *
	 * @param string $status Stato richiesto.
	 * @return string|array
	 */
	public static function post_status( $status ) {
		$status = sanitize_key( $status );

		$allowed = array( 'any', 'publish', 'draft', 'pending', 'private', 'future', 'trash' );
		if ( ! in_array( $status, $allowed, true ) ) {
			$status = 'any';
		}

		if ( 'any' === $status ) {
			// get_post_stati() include anche gli stati custom registrati da CPT
			// (es. plugin di e-commerce o workflow editoriali): un elenco fisso
			// li escluderebbe in silenzio dall'export. `trash` e `auto-draft`
			// restano fuori perché non fanno parte del significato storico di "any".
			$stati = array_keys( get_post_stati( array( 'internal' => false ) ) );
			$stati = array_diff( $stati, array( 'trash', 'auto-draft' ) );

			return array_values( $stati );
		}

		return $status;
	}
}
