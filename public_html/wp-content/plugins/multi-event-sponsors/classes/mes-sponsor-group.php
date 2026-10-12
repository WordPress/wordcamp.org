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
	 * Term meta holding a group's camera kit wrangler address, the group-side counterpart of the
	 * `mes_region_camera_wranglers` option.
	 */
	public const CAMERA_WRANGLER_META = 'mes_camera_wrangler_email';

	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'init',               array( $this, 'create_taxonomy' ) );
		add_action( 'wcpt_metabox_value', array( $this, 'render_group_picker' ), 10, 3 );
		add_action( 'wcpt_metabox_save',  array( $this, 'save_group_picker' ),   10, 3 );
		add_filter( 'wcpt_admin_meta_keys', array( $this, 'register_wcpt_field' ), 10, 2 );

		add_action( self::TAXONOMY_SLUG . '_add_form_fields',  array( $this, 'markup_camera_wrangler_field' ) );
		add_action( self::TAXONOMY_SLUG . '_edit_form_fields', array( $this, 'markup_camera_wrangler_field' ) );
		add_action( 'create_' . self::TAXONOMY_SLUG,           array( $this, 'save_camera_wrangler' ) );
		add_action( 'edited_' . self::TAXONOMY_SLUG,           array( $this, 'save_camera_wrangler' ) );
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
	 * once the migration has run. It also decides whether groups count at all: while it's off,
	 * get_camp_groups() and MES_Sponsor::get_group_sponsorships() return nothing, so sponsors
	 * come from the regions alone, and switching it off again is a rollback.
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

		/*
		 * `''` is the group wcpt's own save loop asks for -- `metabox_save()`
		 * calls `meta_keys()` with no argument, which falls through to the
		 * `all` list. Without it the field renders but is never saved.
		 */
		if ( ! in_array( $meta_group, array( 'wordcamp', 'all', '' ), true ) ) {
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
	 * Get the group term IDs a WordCamp belongs to, for deciding its sponsors.
	 *
	 * Empty while the flag is off, so groups only count once they're switched on, and switching
	 * them off goes back to the regions. Use get_stored_camp_groups() for the saved value itself.
	 *
	 * @param int $wordcamp_id WordCamp post ID (on central).
	 *
	 * @return int[] Unique, non-zero group term IDs.
	 */
	public static function get_camp_groups( $wordcamp_id ) {
		if ( ! self::is_enabled() ) {
			return array();
		}

		return self::get_stored_camp_groups( $wordcamp_id );
	}

	/**
	 * Get the group term IDs saved for a WordCamp, whether or not the flag is on.
	 *
	 * @param int $wordcamp_id WordCamp post ID (on central).
	 *
	 * @return int[] Unique, non-zero group term IDs.
	 */
	public static function get_stored_camp_groups( $wordcamp_id ) {
		$raw = get_post_meta( $wordcamp_id, self::CAMP_META_KEY, true );

		if ( ! is_array( $raw ) ) {
			return array();
		}

		return array_values( array_unique( array_filter( array_map( 'absint', $raw ) ) ) );
	}

	/**
	 * The camera kit wrangler addresses for a WordCamp, from the groups it's in.
	 *
	 * The region's wrangler lives in the `mes_region_camera_wranglers` option and is read by
	 * `MES_Region::get_camera_wranger_from_region()`; this is the same thing for groups, so a
	 * group-only camp's reminders reach someone. Empty while the flag is off, like the groups.
	 *
	 * @param int $wordcamp_id WordCamp post ID (on central).
	 *
	 * @return string[] Distinct, valid addresses, in the order of the camp's groups.
	 */
	public static function get_camera_wranglers_for_camp( $wordcamp_id ) {
		$addresses = array();

		foreach ( self::get_camp_groups( $wordcamp_id ) as $group_id ) {
			$address = get_term_meta( $group_id, self::CAMERA_WRANGLER_META, true );

			if ( is_email( $address ) ) {
				$addresses[] = $address;
			}
		}

		return array_values( array_unique( $addresses ) );
	}

	/**
	 * Render the camera kit wrangler field on the group's add and edit screens.
	 *
	 * @param string|WP_Term $term_or_taxonomy The term on the edit screen, the taxonomy slug on the add screen.
	 */
	public function markup_camera_wrangler_field( $term_or_taxonomy ) {
		$term                  = $term_or_taxonomy instanceof WP_Term ? $term_or_taxonomy : null;
		$camera_wrangler_email = $term ? get_term_meta( $term->term_id, self::CAMERA_WRANGLER_META, true ) : '';

		require dirname( __DIR__ ) . '/views/taxonomy-meta-group.php';
	}

	/**
	 * Save the camera kit wrangler posted from the group's add or edit screen.
	 *
	 * A valid address is stored, an empty field clears it, and anything else leaves the stored
	 * value alone, the same rules `MES_Region::save_meta_fields()` applies.
	 *
	 * @param int $term_id
	 */
	public function save_camera_wrangler( $term_id ) {
		$taxonomy = get_taxonomy( self::TAXONOMY_SLUG );
		$nonce    = $_POST['mes_group_camera_wrangler_nonce'] ?? '';
		$is_valid = wp_verify_nonce( $nonce, 'mes_group_camera_wrangler_' . $term_id ) || wp_verify_nonce( $nonce, 'mes_group_camera_wrangler_new' );

		if ( ! $is_valid || ! $taxonomy || ! current_user_can( $taxonomy->cap->edit_terms ) || ! isset( $_POST['camera-wrangler-email'] ) ) {
			return;
		}

		$address = trim( sanitize_text_field( wp_unslash( $_POST['camera-wrangler-email'] ) ) );

		if ( '' === $address ) {
			delete_term_meta( $term_id, self::CAMERA_WRANGLER_META );
		} elseif ( is_email( $address ) ) {
			update_term_meta( $term_id, self::CAMERA_WRANGLER_META, $address );
		}
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

		update_post_meta( $post_id, self::CAMP_META_KEY, self::sanitize_group_ids( $selected ) );
	}

	/**
	 * Reduce a posted list of group IDs to the ones that are sponsor groups.
	 *
	 * The picker only offers real groups, but the request can carry anything. A made-up ID or a
	 * term from another taxonomy would otherwise be stored as a group, and count as one when the
	 * camp is scheduled.
	 *
	 * @param array $ids Raw IDs, as posted.
	 *
	 * @return int[] Unique, existing group term IDs, in the order posted.
	 */
	public static function sanitize_group_ids( array $ids ) {
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );

		return array_values( array_filter(
			$ids,
			function ( $id ) {
				return (bool) term_exists( $id, self::TAXONOMY_SLUG );
			}
		) );
	}
} // end MES_Sponsor_Group
