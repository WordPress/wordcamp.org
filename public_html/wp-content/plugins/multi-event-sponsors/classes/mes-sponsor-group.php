<?php
/*
 * Register the Sponsor Groups taxonomy and manage camp-side group membership.
 *
 * A group is a curated set of WordCamps a sponsor can target — e.g. a region,
 * "Flagships", or "Top 5 WPCC". Generalizes the legacy single-region model:
 * a camp can belong to multiple groups, and a sponsor maps each group to a
 * sponsorship level (see MES_Sponsor::get_group_sponsorships()).
 *
 * Camp-side membership is stored as post meta on the WordCamp post (like the
 * legacy region), NOT as assigned terms — the WordCamp post lives on the
 * central site while the taxonomy belongs to the mes post type.
 */

class MES_Sponsor_Group {
	public const TAXONOMY_SLUG = 'mes_sponsor_group';
	public const CAMP_META_KEY = 'mes_sponsor_groups';
	public const WCPT_FIELD    = 'Multi-Event Sponsor Groups';

	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'init',               array( $this, 'create_taxonomy' ) );
		add_action( 'wcpt_metabox_value', array( $this, 'render_group_picker' ), 10, 3 );
		add_action( 'wcpt_metabox_save',  array( $this, 'save_group_picker' ),   10, 3 );
		add_filter( 'wcpt_admin_meta_keys', array( $this, 'register_wcpt_field' ), 10, 2 );
	}

	/**
	 * Whether the camp-side group UI is switched on.
	 *
	 * Off by default, so merging the group model changes nothing an organizer or
	 * deputy can see: the field is not offered on the WordCamp screen, saves are
	 * ignored, and the taxonomy has no admin menu. Flip it with
	 *
	 *     add_filter( 'mes_sponsor_groups_enabled', '__return_true' );
	 *
	 * once the read path is deployed and the migration has been dry-run.
	 *
	 * Deliberately checked inside each callback rather than around the
	 * `add_action()` calls, so the answer doesn't depend on whether a filter was
	 * registered before this plugin loaded.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return (bool) apply_filters( 'mes_sponsor_groups_enabled', false );
	}

	/**
	 * Offer the camp-side group field on the WordCamp admin screen.
	 *
	 * Registered from here rather than hardcoded into wcpt's own list so the
	 * field appears and disappears with `is_enabled()`. wcpt renders and saves
	 * unrecognised field types through the `wcpt_metabox_value` and
	 * `wcpt_metabox_save` actions, which this class already handles, so no
	 * change to wcpt is needed for the field itself.
	 *
	 * @param array  $keys       Field name => field type.
	 * @param string $meta_group Which group of fields wcpt is asking for.
	 *
	 * @return array
	 */
	public function register_wcpt_field( $keys, $meta_group ) {
		if ( ! self::is_enabled() ) {
			return $keys;
		}

		if ( ! in_array( $meta_group, array( 'wordcamp', 'all' ), true ) ) {
			return $keys;
		}

		$keys[ self::WCPT_FIELD ] = 'mes-groups';

		return $keys;
	}

	/**
	 * Registers the sponsor groups taxonomy
	 */
	public function create_taxonomy() {
		$params = array(
			'label'        => __( 'Sponsor Group', 'wordcamporg' ),
			'labels'       => array(
				'name'          => __( 'Sponsor Groups', 'wordcamporg' ),
				'singular_name' => __( 'Sponsor Group', 'wordcamporg' ),
			),
			'hierarchical' => false,
			'rewrite'      => array( 'slug' => self::TAXONOMY_SLUG ),

			/*
			 * Always registered so the data model and queries are stable, but
			 * it stays out of the menu until the group UI is switched on.
			 */
			'show_ui'      => self::is_enabled(),
			'show_in_menu' => self::is_enabled(),
		);

		if ( ! taxonomy_exists( self::TAXONOMY_SLUG ) ) {
			register_taxonomy( self::TAXONOMY_SLUG, MES_Sponsor::POST_TYPE_SLUG, $params );
		}
	}

	/**
	 * Get the group term IDs a WordCamp belongs to.
	 *
	 * @param int $wordcamp_id WordCamp post ID (on central).
	 *
	 * @return int[] Unique, non-zero group term IDs.
	 */
	public static function get_camp_groups( $wordcamp_id ) {
		$raw = get_post_meta( $wordcamp_id, self::CAMP_META_KEY, true );

		if ( ! is_array( $raw ) ) {
			return array();
		}

		return array_values( array_unique( array_filter( array_map( 'absint', $raw ) ) ) );
	}

	/**
	 * Render the group multi-select for the WordCamp Post Type plugin
	 *
	 * @param string $key
	 * @param string $value
	 * @param string $field_name
	 */
	public function render_group_picker( $key, $value, $field_name ) {
		if ( self::WCPT_FIELD !== $key || ! self::is_enabled() ) {
			return;
		}

		global $post;

		$groups    = get_terms( array(
			'taxonomy'   => self::TAXONOMY_SLUG,
			'hide_empty' => false,
		) );
		$selected  = self::get_camp_groups( $post->ID );
		$protected = WordCamp_Admin::is_protected_field( $key );

		require dirname( __DIR__ ) . '/views/template-group-picker.php';
	}

	/**
	 * Save the group multi-select for the WordCamp Post Type plugin
	 *
	 * @param string $key
	 * @param string $value
	 * @param int    $post_id
	 */
	public function save_group_picker( $key, $value, $post_id ) {
		if ( self::WCPT_FIELD !== $key || ! self::is_enabled() ) {
			return;
		}

		if ( WordCamp_Admin::is_protected_field( $key ) ) {
			return;
		}

		$post_key = wcpt_key_to_str( $key, 'wcpt_' );
		$selected = isset( $_POST[ $post_key ] ) ? (array) $_POST[ $post_key ] : array();
		$selected = array_values( array_unique( array_filter( array_map( 'absint', $selected ) ) ) );

		update_post_meta( $post_id, self::CAMP_META_KEY, $selected );
	}
} // end MES_Sponsor_Group
