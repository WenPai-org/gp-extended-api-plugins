<?php
/**
 *  Expands the GP API by adding extended Translation endpoints.
 *  Ultimate goal here being inclusion in the appropriate parts of GP core.
 */


class GP_Route_Translation_Extended extends GP_Route_Main {

	function __construct() {
		// Add CORS headers
		header( 'Access-Control-Allow-Origin: https://wenpai.org' );
		header( 'Access-Control-Allow-Methods: POST, GET, OPTIONS, DELETE, PUT' );
		header( 'Access-Control-Allow-Credentials: true' );
		$this->template_path = dirname( __FILE__ ) . '/templates/';
	}

	function translations_options_ok() {
		$this->tmpl( 'status-ok' );
	}

	/**
	 * Gets translation set by project and locale slug, and returns counts and
	 * an array of untranslated strings (up to the number defined in GP::$translation->per_page)
	 * Example GET string: https://translate.wordpress.com/api/translations/-untranslated-by-locale?translation_set_slug=default&locale_slug=ta&project=wpcom&view=calypso
	 *
	 */
	function translations_get_untranslated_strings_by_locale() {
		if ( ! $this->api ) {
			$this->die_with_error( __( "Yer not 'spose ta be here." ), 403 );
		}

		$project_path          	= gp_get( 'project' );
		$locale_slug           	= gp_get( 'locale_slug' );
		$project_view           = gp_get( 'view', null );
		$translation_set_slug  	= gp_get( 'translation_set_slug', 'default' );

		if ( ! $project_path || ! $locale_slug || ! $translation_set_slug ) {
			$this->die_with_404();
		}

		$filters = array(
			'status' 	=> 'untranslated',
		);

		$sort = array(
			'by' => 'priority',
			'how' => 'desc',
		);
		$page = 1;
		$locale = GP_Locales::by_slug( $locale_slug );

		$project = GP::$project->by_path( $project_path );
		$translation_set = GP::$translation_set->by_project_id_slug_and_locale( $project->id, $translation_set_slug, $locale_slug );
		$translations = GP::$translation->for_translation( $project, $translation_set, $page, $filters, $sort );

		if ( $project_view && class_exists( 'GP_Views' ) ) {
			$gp_plugin_views = GP_Views::get_instance();
			$gp_plugin_views->set_project_id( $project->id );
		}

		$result = new stdClass();
		$result->all_count 					= $translation_set->all_count();
		$result->country_code 				= $locale->country_code;
		$result->current_count 				= $translation_set->current_count();
		$result->fuzzy_count 				= $translation_set->fuzzy_count();
		$result->language_name 				= $locale->native_name;
		$result->language_name_en 			= $locale->english_name;
		$result->last_modified 				= $translation_set->current_count ? $translation_set->last_modified() : false;
		$result->percent_translated 		= $translation_set->percent_translated();
		$result->slug 						= $locale->slug;
		$result->untranslated_strings		= $translations;
		$result->untranslated_count 		= $translation_set->untranslated_count();
		$result->waiting_count 				= $translation_set->waiting_count();
		$result->wp_locale					= $locale->wp_locale;

		$translations = $result;
		$this->tmpl( 'translations-extended', get_defined_vars(), true );
	}

	function translations_get_by_originals() {
		if ( ! $this->api ) {
			$this->die_with_error( __( "Yer not 'spose ta be here." ), 403 );
		}

		$project_path         = gp_post( 'project' );
		$locale_slug          = gp_post( 'locale_slug' );
		$translation_set_slug = gp_post( 'translation_set_slug', 'default' );
		//$original_strings     = json_decode( gp_post( 'original_strings', true ) );
		$original_ids = json_decode( gp_post( 'original_ids', true ) );

		if ( ! $project_path || ! $locale_slug || ! $translation_set_slug || ! $original_ids ) {
			$this->die_with_404();
		}

		$project         = GP::$project->by_path( $project_path );
		$translation_set = GP::$translation_set->by_project_id_slug_and_locale( $project->id, $translation_set_slug, $locale_slug );

		if ( ! $project || ! $translation_set ) {
			$this->die_with_404();
		}

		foreach ( $original_ids as $original_id ) {
			//$original_record = GP::$original->by_project_id_and_entry( $project->id, $original );
			$original_record = GP::$original->find_one( array( 'id' => $original_id ) );
			if ( ! $original_record ) {
				$translations['originals_not_found'][] = $original_id;
				continue;
			}
			$query_result                   = new stdClass();
			$query_result->original_id      = $original_record->id;
			$query_result->original         = $original_record->singular;
			$query_result->original_comment = $original_record->comment;

			$query_result->translations = GP::$translation->find_many( "original_id = '{$query_result->original_id}' AND translation_set_id = '{$translation_set->id}' AND ( status = 'waiting' OR status = 'fuzzy' OR status = 'current' )" );

			foreach ( $query_result->translations as $key => $current_translation ) {
				$query_result->translations[ $key ]                   = GP::$translation->prepare_fields_for_save( $current_translation );
				$query_result->translations[ $key ]['translation_id'] = $current_translation->id;
			}

			$translations[] = $query_result;
		}


		$this->tmpl( 'translations-extended', get_defined_vars(), true );
	}

	function save_translation() {
		if ( ! $this->api ) {
			$this->die_with_error( __( "Yer not 'spose ta be here." ), 403 );
		}

		$this->logged_in_or_forbidden();

		$project_path         = gp_post( 'project' );
		$locale_slug          = gp_post( 'locale_slug' );
		$translation_set_slug = gp_post( 'transla tion_set_slug', 'default' );

		if ( ! $project_path || ! $locale_slug || ! $translation_set_slug ) {
			$this->die_with_404();
		}

		$project = GP::$project->by_path( $project_path );
		$locale  = GP_Locales::by_slug( $locale_slug );
		if ( ! $project || ! $locale ) {
			$this->die_with_404();
		}

		$translation_set = GP::$translation_set->by_project_id_slug_and_locale( $project->id, $translation_set_slug, $locale_slug );
		if ( ! $translation_set ) {
			$this->die_with_404();
		}

		$output      = array();
		$translation = json_decode( gp_post( 'translation', array() ) );
		foreach ( $translation as $original_id => $translations ) {
			$data                       = compact( 'original_id' );
			$data['user_id']            = get_current_user_id();
			$data['translation_set_id'] = $translation_set->id;

			foreach ( range( 0, GP::$translation->get_static( 'number_of_plural_translations' ) ) as $i ) {
				if ( isset( $translations[ $i ] ) ) {
					$data["translation_$i"] = $translations[ $i ];
				}
			}

			$original         = GP::$original->get( $original_id );
			$data['warnings'] = GP::$translation_warnings->check( $original->singular, $original->plural, $translations, $locale );

			if ( empty( $data['warnings'] ) && ( $this->can( 'approve', 'translation-set', $translation_set->id ) || $this->can( 'write', 'project', $project->id ) ) ) {
				$data['status'] = 'current';
			} else {
				$data['status'] = 'waiting';
			}

			$existing_translations = GP::$translation->for_translation( $project, $translation_set, 'no-limit', array(
				'original_id' => $original_id,
				'status'      => 'current_or_waiting'
			), array() );
			foreach ( $existing_translations as $e ) {
				if ( array_pad( $translations, $locale->nplurals, null ) == $e->translations ) {
					return $this->die_with_error( __( 'Identical current or waiting translation already exists.' ), 409 );
				}
			}

			$translation = GP::$translation->create( $data );
			if ( ! $translation->validate() ) {
				$error_output = $translation->errors;
				$translation->delete();
				$this->die_with_error( $error_output, 422 );
			}

			if ( 'current' == $data['status'] ) {
				$translation->set_status( 'current' );
			}

			gp_clean_translation_set_cache( $translation_set->id );
			$translations = GP::$translation->for_translation( $project, $translation_set, 'no-limit', array( 'translation_id' => $translation->id ), array() );

			if ( ! $translations ) {
				$output[ $original_id ] = false;
			}

			$output[ $original_id ] = $translations[0];
		}

		$translations = $output;
		$this->tmpl( 'translations-extended', get_defined_vars(), true );
	}

	function set_status( $translation_id ) {
		if ( ! $this->api ) {
			$this->die_with_error( __( "Yer not 'spose ta be here." ), 403 );
		}

		$translation = GP::$translation->get( $translation_id );
		if ( ! $translation ) {
			$this->die_with_error( 'Translation doesn&#8217;t exist!', 404 );
		}

		$this->can_approve_translation_or_forbidden( $translation );

		$result = $translation->set_status( gp_post( 'status' ) );
		if ( ! $result ) {
			$this->die_with_error( 'Error in saving the translation status!', 409 );
		}

		$translations = $this->translation_record_by_id( $translation_id );
		if ( ! $translations ) {
			$this->die_with_error( 'Error in retrieving translation record!', 409 );
		}

		$this->tmpl( 'translations-extended', get_defined_vars() );
	}

	private function can_approve_translation_or_forbidden( $translation ) {
		$can_reject_self = ( get_current_user_id() == $translation->user_id && $translation->status == "waiting" );
		if ( $can_reject_self ) {
			return;
		}
		$this->can_or_forbidden( 'approve', 'translation-set', $translation->translation_set_id );
	}

	private function translation_record_by_id( $translation_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $wpdb->translations WHERE id = %d", $translation_id ) );
	}

	// A slightly modified version og GP_Original->by_project_id_and_entry without the BINARY search keyword
	// to make sure the index on the gp_originals table is used
	private function by_project_id_and_entry( $project_id, $entry, $status = "+active" ) {
		global $wpdb;

		$entry->plural  = isset( $entry->plural ) ? $entry->plural : null;
		$entry->context = isset( $entry->context ) ? $entry->context : null;

		$where = array();

		$where[] = is_null( $entry->context ) ? '(context IS NULL OR %s IS NULL)' : 'context = %s';
		$where[] = 'singular = %s';
		$where[] = is_null( $entry->plural ) ? '(plural IS NULL OR %s IS NULL)' : 'plural = %s';
		$where[] = 'project_id = %d';
		$where[] = $wpdb->prepare( 'status = %s', $status );

		$where = implode( ' AND ', $where );

		$query = "SELECT * FROM gp_originals WHERE $where";
		$result = GP::$original->one( $query, $entry->context, $entry->singular, $entry->plural, $project_id );
		if ( ! $result ) {
			return null;
		}
		// we want case sensitive matching but this can't be done with MySQL while continuing to use the index
		// therefore we do an additional check here
		if ( $result->singular === $entry->singular ) {
			return $result;
		}

		// and get the whole result set here and check each entry manually
		$results = GP::$original->many( $query . ' AND id != %d', $entry->context, $entry->singular, $entry->plural, $project_id, $result->id );
		foreach ( $results as $result ) {
			if ( $result->singular === $entry->singular ) {
				return $result;
			}
		}

		return null;
	}
}

class GP_Translation_Extended_API_Loader {
	function init() {
		$this->init_new_routes();
	}

	function init_new_routes() {
		GP::$router->add( '/translations/-new', array( 'GP_Route_Translation_Extended', 'save_translation' ), 'post' );
		GP::$router->add( '/translations/-new', array( 'GP_Route_Translation_Extended', 'translations_options_ok' ), 'options' );
		GP::$router->add( '/translations/(\d+)/-set-status', array( 'GP_Route_Translation_Extended', 'set_status' ), 'post' );
		GP::$router->add( '/translations/(\d+)/-set-status', array( 'GP_Route_Translation_Extended', 'translations_options_ok' ), 'options' );
		GP::$router->add( '/translations/-query-by-originals', array( 'GP_Route_Translation_Extended', 'translations_get_by_originals' ), 'post' );
		GP::$router->add( '/translations/-query-by-originals', array( 'GP_Route_Translation_Extended', 'translations_options_ok' ), 'options' );
		GP::$router->add( '/translations/-untranslated-by-locale', array( 'GP_Route_Translation_Extended', 'translations_get_untranslated_strings_by_locale' ), 'get' );
		GP::$router->add( '/translations/-untranslated-by-locale', array( 'GP_Route_Translation_Extended', 'translations_get_untranslated_strings_by_locale' ), 'options' );
	}
}

$gp_translation_extended_api = new GP_Translation_Extended_API_Loader();
add_action( 'gp_init', array( $gp_translation_extended_api, 'init' ) );

