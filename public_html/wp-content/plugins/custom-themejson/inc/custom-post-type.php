<?php
namespace WordCamp\CustomThemeJSON;

defined( 'WPINC' ) || die();

/**
 * Class Resister
 *
 * This class is responsible for registering post type for saving data and related variables.
 */
class CustomPostType {

	const CUSTOM_POST_TYPE = 'custom-themejson';

	/**
	 * Register the custom post type.
	 */
	public static function register() {
		$labels = array(
			'name'          => __( 'Custom Theme.json', 'wordcamporg' ),
			'singular_name' => __( 'Custom Theme.json', 'wordcamporg' ),
		);

		$capabilities = array(
			'delete_posts'           => 'edit_theme_options',
			'delete_post'            => 'edit_theme_options',
			'delete_published_posts' => 'edit_theme_options',
			'delete_private_posts'   => 'edit_theme_options',
			'delete_others_posts'    => 'edit_theme_options',
			'edit_post'              => 'edit_css',
			'edit_posts'             => 'edit_css',
			'edit_others_posts'      => 'edit_css',
			'edit_published_posts'   => 'edit_css',
			'read_post'              => 'read',
			'read_private_posts'     => 'read',
			'publish_posts'          => 'edit_theme_options',
		);

		$params = array(
			'labels'           => $labels,
			'public'           => false,
			'hierarchical'     => false,
			'rewrite'          => false,
			'query_var'        => false,
			'delete_with_user' => false,
			'can_export'       => true,
			'supports'         => array( 'title', 'revisions' ),
			'capabilities'     => $capabilities,
		);

		register_post_type( self::CUSTOM_POST_TYPE, $params );
	}
}
