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
		$args     = self::restrict_to_visible( $args );

		if ( null !== $after_id ) {
			$args['wpaib_after_id'] = $after_id;
			$args['orderby']        = 'ID';
			$args['order']          = 'ASC';
			unset( $args['paged'] );

			// Senza paged ogni pagina del cursore è "pagina 1" e WP_Query, che per una
			// query su 'post' si considera is_home, ripescherebbe gli articoli in
			// evidenza mettendoli in testa a ogni pagina — duplicati, ordine per ID
			// rotto, e per giunta forzati a post_status 'publish' anche quando si
			// stanno chiedendo le bozze.
			$args['ignore_sticky_posts'] = true;
		}

		add_filter( 'posts_where', array( __CLASS__, 'filter_posts_where' ), 10, 2 );
		$query = new WP_Query( $args );
		remove_filter( 'posts_where', array( __CLASS__, 'filter_posts_where' ), 10 );

		return $query;
	}

	/**
	 * Limita una query ai contenuti che l'utente corrente può davvero vedere.
	 *
	 * Le rotte singole verificano `edit_post` sull'ID richiesto, ma un elenco non
	 * ha un ID su cui chiamarla: senza questa restrizione una chiave con la sola
	 * `edit_posts` — un Autore, un Collaboratore — leggerebbe da `/posts` le
	 * bozze e i contenuti privati di chiunque altro, cioè esattamente quelli che
	 * la rotta singola le nega con un 403.
	 *
	 * La regola è quella del backend WordPress: gli stati pubblici sono di tutti,
	 * tutto il resto solo se l'utente ne è l'autore. Chi ha `edit_others_posts`
	 * per quel tipo di contenuto (Editore, Amministratore) non viene toccato, ed
	 * è il caso di un export completo.
	 *
	 * `perm => 'readable'` di WP_Query non basta: filtra gli stati privati ma
	 * lascia passare le bozze altrui.
	 *
	 * @param array $args Argomenti WP_Query.
	 * @return array
	 */
	public static function restrict_to_visible( array $args ) {
		$post_type = isset( $args['post_type'] ) ? $args['post_type'] : 'post';
		if ( is_array( $post_type ) ) {
			$post_type = reset( $post_type );
		}

		$object = get_post_type_object( $post_type );
		$cap    = $object && isset( $object->cap->edit_others_posts )
			? $object->cap->edit_others_posts
			: 'edit_others_posts';

		if ( current_user_can( $cap ) ) {
			return $args;
		}

		$args['wpaib_visible_for'] = get_current_user_id();

		return $args;
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

		if ( ! $query instanceof WP_Query ) {
			return $where;
		}

		$after_id = (int) $query->get( 'wpaib_after_id' );
		if ( $after_id > 0 ) {
			$where .= $wpdb->prepare( " AND {$wpdb->posts}.ID > %d", $after_id );
		}

		$visible_for = $query->get( 'wpaib_visible_for' );
		if ( '' !== $visible_for && null !== $visible_for ) {
			$public = array_keys( get_post_stati( array( 'public' => true ) ) );

			// Un utente non autenticato non possiede nulla: restano i soli
			// contenuti pubblici, mai un OR su post_author = 0.
			$clauses = array();
			if ( ! empty( $public ) ) {
				$placeholders = implode( ', ', array_fill( 0, count( $public ), '%s' ) );
				$clauses[]    = $wpdb->prepare( "{$wpdb->posts}.post_status IN ( {$placeholders} )", $public ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			}
			if ( (int) $visible_for > 0 ) {
				$clauses[] = $wpdb->prepare( "{$wpdb->posts}.post_author = %d", (int) $visible_for );
			}

			$where .= $clauses ? ' AND ( ' . implode( ' OR ', $clauses ) . ' )' : ' AND 1=0';
		}

		return $where;
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

		// WP_Comment_Query costruisce la chiave di cache solo dalle proprie query
		// var note, e senza includere l'SQL: 'wpaib_after_id' verrebbe scartato e
		// con un object cache persistente ogni pagina del cursore restituirebbe di
		// nuovo la prima. 'cache_domain' è una query var supportata che invece
		// entra nella chiave, quindi ci si aggancia il cursore.
		$args['cache_domain'] = 'wpaib_after_' . $after_id;

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
	 * Normalizza lo stato richiesto in un post_status accettato da WP_Query.
	 *
	 * `any` mantiene il significato storico (tutto tranne il cestino); `trash`
	 * va richiesto esplicitamente. Uno stato non riconosciuto ricade su `any`.
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
			// Il letterale 'any' di WP_Query non basta: esclude anche gli stati
			// registrati con exclude_from_search, che è come vengono dichiarati
			// gli stati di workflow riservati di parecchi plugin editoriali.
			// Quei contenuti sparirebbero in silenzio da un export "completo".
			// La lista esplicita parte invece dagli stati non interni — che già
			// lasciano fuori trash, auto-draft e inherit — e li tiene tutti.
			$stati = array_keys( get_post_stati( array( 'internal' => false ) ) );
			$stati = array_diff( $stati, array( 'trash', 'auto-draft' ) );

			return array_values( $stati );
		}

		return $status;
	}
}
