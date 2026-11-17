<?php

namespace WordCamp\Groups\Tests;

defined( 'WPINC' ) || die();

require_once __DIR__ . '/../../wporg-groups-frontend/tests/class-groups-testcase.php';

/**
 * Coverage for translating the groups-site theme.
 *
 * Switching a group site to another locale left most of the theme in English
 * (#2376): the `.html` templates and parts had their copy typed straight in,
 * and most patterns didn't wrap theirs, so none of it reached the
 * `wordcamporg` translation project. The copy now lives in PHP patterns that
 * the templates reference.
 *
 * @group groups
 */
class Test_Groups_Site_I18n extends Groups_TestCase {

	const THEME_DIR = SUT_WP_CONTENT_DIR . 'themes/groups-site/';

	/**
	 * Block attributes that hold copy a visitor sees.
	 */
	const TEXT_ATTRIBUTES = array( 'buttonText', 'heading', 'label', 'linkText', 'moreText', 'placeholder', 'text' );

	/**
	 * Characters the theme puts next to its copy that don't need translating.
	 */
	const DECORATION = " \t\n\r\0\x0B\u{00A0}+→↗✎";

	/**
	 * Every theme template and template part.
	 */
	public function data_templates(): array {
		$templates = array();

		foreach ( array_merge( glob( self::THEME_DIR . 'templates/*.html' ), glob( self::THEME_DIR . 'parts/*.html' ) ) as $file ) {
			$templates[ basename( dirname( $file ) ) . '/' . basename( $file ) ] = array( $file );
		}

		return $templates;
	}

	/**
	 * The text nodes of some markup, ignoring block comments and decoration.
	 *
	 * @param string $markup Block markup or rendered HTML.
	 *
	 * @return string[]
	 */
	private function get_text_nodes( string $markup ): array {
		$markup = preg_replace( '/<!--.*?-->/s', '', $markup );
		$nodes  = array();

		foreach ( preg_split( '/<[^>]*>/', $markup ) as $node ) {
			$node = trim( html_entity_decode( $node, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), self::DECORATION );

			if ( '' !== $node ) {
				$nodes[] = $node;
			}
		}

		return $nodes;
	}

	/**
	 * Templates and parts hold no copy of their own: an `.html` file can't
	 * call `__()`.
	 *
	 * @dataProvider data_templates
	 *
	 * @param string $file The template file.
	 */
	public function test_templates_have_no_hard_coded_text( string $file ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a template file from disk.
		$markup = file_get_contents( $file );

		$this->assertSame( array(), $this->get_text_nodes( $markup ) );
	}

	/**
	 * Nor do the block attributes in them.
	 *
	 * GatherPress's venue-detail placeholders are left out: they only show in
	 * the editor, and the venue block they sit in passes them the venue's
	 * context, which a pattern reference would drop.
	 *
	 * @dataProvider data_templates
	 *
	 * @param string $file The template file.
	 */
	public function test_template_block_attributes_have_no_hard_coded_text( string $file ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a template file from disk.
		$blocks = parse_blocks( file_get_contents( $file ) );
		$found  = array();

		while ( $blocks ) {
			$block  = array_shift( $blocks );
			$blocks = array_merge( $blocks, $block['innerBlocks'] );

			if ( 'gatherpress/venue-detail' === $block['blockName'] ) {
				continue;
			}

			foreach ( self::TEXT_ATTRIBUTES as $attribute ) {
				if ( ! empty( $block['attrs'][ $attribute ] ) ) {
					$found[] = "{$block['blockName']} {$attribute}: {$block['attrs'][ $attribute ]}";
				}
			}
		}

		$this->assertSame( array(), $found );
	}

	/**
	 * Every pattern, as an organizer and as a visitor (some patterns only show
	 * for one of them).
	 */
	public function data_patterns(): array {
		$patterns = array();

		foreach ( glob( self::THEME_DIR . 'patterns/*.php' ) as $file ) {
			foreach ( array( 'administrator', '' ) as $role ) {
				$patterns[ basename( $file, '.php' ) . ' as ' . ( $role ? $role : 'visitor' ) ] = array( $file, $role );
			}
		}

		return $patterns;
	}

	/**
	 * Every piece of copy a pattern renders goes through the `wordcamporg`
	 * text domain.
	 *
	 * @dataProvider data_patterns
	 *
	 * @param string $file The pattern file.
	 * @param string $role The user's role, or empty for a logged-out visitor.
	 */
	public function test_patterns_translate_their_text( string $file, string $role ) {
		if ( $role ) {
			wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );
		}

		// The edit-event button only renders on an event.
		$event_id = self::factory()->post->create(
			array(
				'post_type'   => 'gatherpress_event',
				'post_status' => 'publish',
			)
		);
		$this->go_to( get_permalink( $event_id ) );
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Standing the event up as the current post; `wp_reset_postdata()` below puts it back.
		$GLOBALS['post'] = get_post( $event_id );

		// Mark every string the theme translates.
		add_filter(
			'gettext',
			static function ( $translation, $text, $domain ) {
				return 'wordcamporg' === $domain ? "[{$translation}]" : $translation;
			},
			10,
			3
		);

		ob_start();
		include $file;
		$markup = ob_get_clean();

		wp_reset_postdata();

		$nodes  = $this->get_text_nodes( $markup );
		$blocks = parse_blocks( $markup );

		// Copy in block attributes, like the search field's placeholder or the
		// share links' labels, doesn't show up as a text node.
		while ( $blocks ) {
			$block  = array_shift( $blocks );
			$blocks = array_merge( $blocks, $block['innerBlocks'] );

			foreach ( self::TEXT_ATTRIBUTES as $attribute ) {
				if ( isset( $block['attrs'][ $attribute ] ) && is_string( $block['attrs'][ $attribute ] ) ) {
					$nodes = array_merge( $nodes, $this->get_text_nodes( $block['attrs'][ $attribute ] ) );
				}
			}
		}

		$untranslated = array_filter(
			$nodes,
			static function ( $node ) {
				return ! preg_match( '/^\[.*\]$/s', $node );
			}
		);

		$this->assertSame( array(), array_values( $untranslated ) );
	}
}
