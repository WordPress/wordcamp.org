<?php

namespace WordCamp\Groups\Tests;

use function WordCamp\Groups\Frontend\Event_Language\set_event_language;
use function WordCamp\Groups\GatherPress_Tweaks\normalize_event_time_filter;

defined( 'WPINC' ) || die();

require_once dirname( __DIR__, 2 ) . '/wporg-groups-frontend/tests/class-groups-testcase.php';

/**
 * @group groups
 */
class Test_Groups_GatherPress_Tweaks extends Groups_TestCase {

	/**
	 * Groups-network sites should never show a timezone suffix or offer
	 * anonymous RSVP, regardless of GatherPress's own defaults.
	 */
	public function test_gatherpress_settings_overridden() {
		$settings = get_option( 'gatherpress_settings' );

		$this->assertSame( 0, $settings['show_timezone'] );
		$this->assertSame( 0, $settings['enable_anonymous_rsvp'] );
		$this->assertSame( 0, $settings['enable_open_rsvp'] );
	}

	/**
	 * Unrelated GatherPress settings saved on a group site must survive the
	 * forced overrides: the filter overlays the forced keys, it does not
	 * replace the whole option.
	 */
	public function test_gatherpress_settings_preserved() {
		update_option(
			'gatherpress_settings',
			array(
				'max_guest_limit'  => 5,
				'enable_open_rsvp' => 1,
				'show_timezone'    => 1,
			)
		);

		$settings = get_option( 'gatherpress_settings' );

		// Unrelated stored setting is preserved.
		$this->assertSame( 5, $settings['max_guest_limit'] );

		// Forced keys win regardless of what was stored.
		$this->assertSame( 0, $settings['show_timezone'] );
		$this->assertSame( 0, $settings['enable_open_rsvp'] );
	}

	/**
	 * Login is required to comment on the groups network.
	 */
	public function test_comment_registration_required_on_groups_network() {
		$this->assertSame( '1', get_option( 'comment_registration' ) );
	}

	/**
	 * Editors ("Organizers") are granted `edit_theme_options` so they can use
	 * the Site Editor to customise their group site — but nothing broader.
	 * See the `promote_users` regression test in test-capabilities.php for
	 * the capability that must NOT be granted this way.
	 */
	public function test_editor_has_edit_theme_options() {
		$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		$this->assertTrue( user_can( $editor_id, 'edit_theme_options' ) );
	}

	/**
	 * The `edit_theme_options` grant is scoped to editors only.
	 */
	public function test_subscriber_does_not_have_edit_theme_options() {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$this->assertFalse( user_can( $subscriber_id, 'edit_theme_options' ) );
	}

	/**
	 * `_event_speakers` is registered on `init`, which only fires once for
	 * the whole test run; `WP_UnitTestCase::tearDown()` unregisters all meta
	 * keys after every test (a known core testing quirk), so by the time
	 * this test runs the registration from bootstrap is long gone. Re-fire
	 * the real registration function directly (rather than
	 * `do_action( 'init' )`, which would also re-run block registration and
	 * trip "already registered" `_doing_it_wrong` notices) so there's only
	 * one place the args can drift from.
	 */
	public function test_event_speakers_meta_registered_with_array_default() {
		\WordCamp\Groups\GatherPress_Tweaks\register_event_speakers_meta();

		$registered = get_registered_meta_keys( 'post', 'gatherpress_event' );

		$this->assertArrayHasKey( '_event_speakers', $registered );
		$this->assertSame( array(), $registered['_event_speakers']['default'] );
		$this->assertSame( 'array', $registered['_event_speakers']['type'] );
	}

	/**
	 * Build a `wporg/query-total` block carrying a query context.
	 *
	 * The block itself lives in the wporg design system, outside this repo,
	 * so it isn't registered here — the filter under test only reads
	 * `$block->context`, which is public and can be set directly.
	 *
	 * @param string $post_type The post type the surrounding query loop runs.
	 */
	private function make_query_total_block( string $post_type ): \WP_Block {
		$block = new \WP_Block(
			array(
				'blockName'   => 'wporg/query-total',
				'attrs'       => array(),
				'innerBlocks' => array(),
			)
		);

		$block->context = array( 'query' => array( 'postType' => $post_type ) );

		return $block;
	}

	/**
	 * The events archive counts events, not "items" — on a page whose only
	 * content is events, the generic label reads like placeholder copy.
	 */
	public function test_query_total_label_counts_events_on_event_queries() {
		$block = $this->make_query_total_block( 'gatherpress_event' );

		$this->assertSame(
			'%s event',
			apply_filters( 'wporg_query_total_label', '%s item', 1, $block )
		);
		$this->assertSame(
			'%s events',
			apply_filters( 'wporg_query_total_label', '%s items', 12, $block )
		);
	}

	/**
	 * Every other query loop keeps the design system's own label.
	 */
	public function test_query_total_label_is_untouched_on_other_queries() {
		$block = $this->make_query_total_block( 'post' );

		$this->assertSame(
			'%s item',
			apply_filters( 'wporg_query_total_label', '%s item', 1, $block )
		);
	}

	/**
	 * Read the archive's Time filter options as the query-filter block would.
	 *
	 * @param string|null $event_time The `event_time` query arg to simulate.
	 */
	private function get_event_time_filter( ?string $event_time ): array {
		if ( null === $event_time ) {
			unset( $_GET['event_time'] );
		} else {
			$_GET['event_time'] = $event_time;
		}

		$filter = apply_filters( 'wporg_query_filter_options_event_time', array() );

		unset( $_GET['event_time'] );

		return $filter;
	}

	/**
	 * Single-select filters get no count badge from the wporg block, so the
	 * toggle itself has to say which view is applied.
	 */
	public function test_event_time_filter_names_the_applied_choice() {
		$filter = $this->get_event_time_filter( 'past' );

		$this->assertSame( 'Time: Past', $filter['label'] );
		$this->assertSame( array( 'past' ), $filter['selected'] );
	}

	/**
	 * The default view names itself too. A bare "Time" told the reader which
	 * axis the control filters on but nothing about what picking it would
	 * offer, or that a view was already applied (#2059).
	 */
	public function test_event_time_filter_names_the_default_view_as_well() {
		$filter = $this->get_event_time_filter( null );

		$this->assertSame( 'Time: Upcoming', $filter['label'] );
		$this->assertSame( array( 'upcoming' ), $filter['selected'] );
	}

	/**
	 * Every view names itself, so the toggle reads the same way whichever one
	 * is applied. "All" had no coverage at all before this.
	 */
	public function test_event_time_filter_names_every_view() {
		$this->assertSame( 'Time: Upcoming', $this->get_event_time_filter( 'upcoming' )['label'] );
		$this->assertSame( 'Time: Past', $this->get_event_time_filter( 'past' )['label'] );
		$this->assertSame( 'Time: All', $this->get_event_time_filter( 'all' )['label'] );
	}

	/**
	 * A hand-typed `event_time` that isn't one of the three views falls back
	 * to the default, and the toggle names that fallback rather than the
	 * value the reader typed.
	 */
	public function test_event_time_filter_ignores_an_unknown_value() {
		$filter = $this->get_event_time_filter( 'whenever' );

		$this->assertSame( 'Time: Upcoming', $filter['label'] );
		$this->assertSame( array( 'upcoming' ), $filter['selected'] );
	}

	/**
	 * Read the archive's Format filter options as the query-filter block would.
	 *
	 * @param string|null $event_format The `event_format` query arg to simulate.
	 */
	private function get_event_format_filter( ?string $event_format ): array {
		if ( null === $event_format ) {
			unset( $_GET['event_format'] );
		} else {
			$_GET['event_format'] = $event_format;
		}

		$filter = apply_filters( 'wporg_query_filter_options_event_format', array() );

		unset( $_GET['event_format'] );

		return $filter;
	}

	/**
	 * The Format toggle names its applied choice, exactly as Time does — same
	 * control, same reason (#2059).
	 */
	public function test_event_format_filter_names_every_view() {
		$this->assertSame( 'Format: All', $this->get_event_format_filter( null )['label'] );
		$this->assertSame( 'Format: All', $this->get_event_format_filter( 'all' )['label'] );
		$this->assertSame( 'Format: In person', $this->get_event_format_filter( 'in-person' )['label'] );
		$this->assertSame( 'Format: Online', $this->get_event_format_filter( 'online' )['label'] );
	}

	/**
	 * A hand-typed value that isn't one of the three widens the view back to
	 * "All" rather than emptying the archive.
	 */
	public function test_event_format_filter_ignores_an_unknown_value() {
		$filter = $this->get_event_format_filter( 'hybrid' );

		$this->assertSame( 'Format: All', $filter['label'] );
		$this->assertSame( array( 'all' ), $filter['selected'] );
	}

	/**
	 * Build the tax query the archive's Query Loop would run.
	 *
	 * @param string|null $event_format   The `event_format` query arg to simulate.
	 * @param string|null $event_language The `event_language` query arg to simulate.
	 */
	private function get_archive_query_vars( ?string $event_format, ?string $event_language = null ): array {
		if ( null === $event_format ) {
			unset( $_GET['event_format'] );
		} else {
			$_GET['event_format'] = $event_format;
		}

		if ( null === $event_language ) {
			unset( $_GET['event_language'] );
		} else {
			$_GET['event_language'] = $event_language;
		}

		$block = new \WP_Block(
			array(
				'blockName'   => 'core/query',
				'attrs'       => array(),
				'innerBlocks' => array(),
			)
		);

		$block->context = array(
			'query' => array(
				'postType'                => 'gatherpress_event',
				'gatherpress_event_query' => 'upcoming',
			),
		);

		$query_vars = apply_filters(
			'query_loop_block_query_vars',
			array( 'post_type' => 'gatherpress_event' ),
			$block
		);

		unset( $_GET['event_format'], $_GET['event_language'] );

		return $query_vars;
	}

	/**
	 * Create a published event, optionally marked online.
	 *
	 * @param string $title     Event title.
	 * @param bool   $is_online Whether to give it the `online-event` term.
	 *
	 * @return int The event post ID.
	 */
	private function make_format_event( string $title, bool $is_online ): int {
		$event_id = self::factory()->post->create(
			array(
				'post_type'   => 'gatherpress_event',
				'post_status' => 'publish',
				'post_title'  => $title,
			)
		);

		if ( $is_online ) {
			$taxonomy = \GatherPress\Core\Venue\Setup::get_instance()->taxonomy_for_event_post_type(
				\GatherPress\Core\Event\Event::POST_TYPE
			);

			wp_set_object_terms( $event_id, 'online-event', $taxonomy );
		}

		return $event_id;
	}

	/**
	 * "Online" narrows to the events carrying GatherPress's `online-event`
	 * venue term.
	 */
	public function test_event_format_filter_narrows_to_online_events() {
		$online = $this->make_format_event( 'Online office hours', true );
		$this->make_format_event( 'Hall meetup', false );

		$query_vars = $this->get_archive_query_vars( 'online' );

		$this->assertSame( array( $online ), $query_vars['post__in'] );
		$this->assertArrayNotHasKey( 'post__not_in', $query_vars );
	}

	/**
	 * "In person" is the absence of that term, so an event with no venue at
	 * all counts as in person — it is certainly not online.
	 */
	public function test_event_format_filter_treats_a_venueless_event_as_in_person() {
		$online = $this->make_format_event( 'Online office hours', true );
		$this->make_format_event( 'Event with no venue', false );

		$query_vars = $this->get_archive_query_vars( 'in-person' );

		$this->assertSame( array( $online ), $query_vars['post__not_in'] );
		$this->assertArrayNotHasKey( 'post__in', $query_vars );
	}

	/**
	 * A group that runs nothing online should show an empty "Online" view, not
	 * its whole archive. WP_Query ignores an empty `post__in`, so the filter
	 * has to say "no posts" explicitly.
	 */
	public function test_event_format_filter_shows_nothing_when_no_events_are_online() {
		$this->make_format_event( 'Hall meetup', false );

		$this->assertSame( array( 0 ), $this->get_archive_query_vars( 'online' )['post__in'] );
	}

	/**
	 * The filter must never put a tax query on the archive's own query.
	 *
	 * A tax query joins `term_relationships`, which makes WP_Query select
	 * `DISTINCT` — and that collapses the duplicate rows
	 * `gatherpress-recurring-events` adds in `Query::clauses()` to turn a
	 * series into one row per date. Filtering by format would then show a
	 * weekly series once instead of on each of its dates, silently and only
	 * while a filter is applied.
	 */
	public function test_event_format_filter_keeps_a_tax_query_off_the_archive_query() {
		$this->make_format_event( 'Online office hours', true );

		foreach ( array( 'online', 'in-person' ) as $format ) {
			$this->assertArrayNotHasKey(
				'tax_query',
				$this->get_archive_query_vars( $format ),
				"A tax query on the {$format} view would collapse recurring occurrences."
			);
		}
	}

	/**
	 * "All" is the absence of a constraint, not a third thing to match.
	 */
	public function test_event_format_filter_constrains_nothing_when_showing_all() {
		foreach ( array( null, 'all' ) as $format ) {
			$query_vars = $this->get_archive_query_vars( $format );

			$this->assertArrayNotHasKey( 'post__in', $query_vars );
			$this->assertArrayNotHasKey( 'post__not_in', $query_vars );
			$this->assertArrayNotHasKey( 'tax_query', $query_vars );
		}
	}

	/**
	 * Each filter's form holds only its own control, so without this the two
	 * filters would reset each other and the search term on every submit.
	 */
	public function test_filter_forms_carry_the_other_view_state() {
		$_GET['event_time']   = 'past';
		$_GET['event_format'] = 'online';
		$_GET['s']            = 'meetup';

		ob_start();
		do_action( 'wporg_query_filter_in_form', 'event_format' );
		$format_form = ob_get_clean();

		ob_start();
		do_action( 'wporg_query_filter_in_form', 'event_time' );
		$time_form = ob_get_clean();

		unset( $_GET['event_time'], $_GET['event_format'], $_GET['s'] );

		// Each form carries the sibling filter and the search, never its own
		// key -- the control itself already submits that.
		$this->assertStringContainsString( 'name="event_time" value="past"', $format_form );
		$this->assertStringContainsString( 'name="s" value="meetup"', $format_form );
		$this->assertStringNotContainsString( 'name="event_format"', $format_form );

		$this->assertStringContainsString( 'name="event_format" value="online"', $time_form );
		$this->assertStringNotContainsString( 'name="event_time"', $time_form );
	}

	/**
	 * Nothing applied, nothing carried: a default view should not litter the
	 * form with hidden inputs restating the default.
	 */
	public function test_filter_forms_carry_nothing_on_the_default_view() {
		ob_start();
		do_action( 'wporg_query_filter_in_form', 'event_time' );

		$this->assertSame( '', ob_get_clean() );
	}

	/**
	 * Create a published event in a language, optionally marked online.
	 *
	 * @param string $title     Event title.
	 * @param string $language  Language subtag, or '' to leave it unset.
	 * @param bool   $is_online Whether to give it the `online-event` term.
	 *
	 * @return int The event post ID.
	 */
	private function make_language_event( string $title, string $language, bool $is_online = false ): int {
		$event_id = $this->make_format_event( $title, $is_online );

		if ( '' !== $language ) {
			set_event_language( $event_id, $language );
		}

		return $event_id;
	}

	/**
	 * Read the language filter's registered options.
	 *
	 * @param string|null $event_language The `event_language` query arg to simulate.
	 */
	private function get_event_language_filter( ?string $event_language ): array {
		if ( null === $event_language ) {
			unset( $_GET['event_language'] );
		} else {
			$_GET['event_language'] = $event_language;
		}

		$filter = apply_filters( 'wporg_query_filter_options_event_language', array() );

		unset( $_GET['event_language'] );

		return $filter;
	}

	/**
	 * The control offers the languages this group actually runs events in,
	 * not the 593 CLDR knows about.
	 */
	public function test_event_language_filter_offers_only_languages_in_use() {
		$this->make_language_event( 'Charla mensual', 'es' );
		$this->make_language_event( 'Monthly talk', 'en' );

		$options = $this->get_event_language_filter( null )['options'];

		$this->assertSame( array( 'all', 'en', 'es' ), array_keys( $options ) );
		$this->assertSame( 'English', $options['en'] );
		$this->assertSame( 'Spanish', $options['es'] );
	}

	/**
	 * A group that runs everything in one language gets no control at all:
	 * "All" and that language would select the same events.
	 */
	public function test_event_language_filter_is_hidden_without_a_choice() {
		$this->assertSame( array(), $this->get_event_language_filter( null ) );

		$this->make_language_event( 'Charla mensual', 'es' );
		$this->make_language_event( 'Otra charla', 'es' );
		$this->make_language_event( 'Untagged meetup', '' );

		$this->assertSame( array(), $this->get_event_language_filter( null ) );
	}

	/**
	 * The toggle names the applied view, the way Time and Format do.
	 */
	public function test_event_language_filter_names_every_view() {
		$this->make_language_event( 'Charla mensual', 'es' );
		$this->make_language_event( 'Monthly talk', 'en' );

		$this->assertSame( 'Language: All', $this->get_event_language_filter( null )['label'] );
		$this->assertSame( 'Language: Spanish', $this->get_event_language_filter( 'es' )['label'] );
	}

	/**
	 * A language the group does not run events in widens the archive rather
	 * than emptying it.
	 */
	public function test_event_language_filter_ignores_an_unknown_value() {
		$this->make_language_event( 'Charla mensual', 'es' );
		$this->make_language_event( 'Monthly talk', 'en' );

		$filter = $this->get_event_language_filter( 'ja' );

		$this->assertSame( 'Language: All', $filter['label'] );
		$this->assertSame( array( 'all' ), $filter['selected'] );
	}

	/**
	 * Picking a language narrows the archive to the events run in it.
	 */
	public function test_event_language_filter_narrows_to_one_language() {
		$spanish = $this->make_language_event( 'Charla mensual', 'es' );
		$this->make_language_event( 'Monthly talk', 'en' );
		$this->make_language_event( 'Untagged meetup', '' );

		$this->assertSame( array( $spanish ), $this->get_archive_query_vars( null, 'es' )['post__in'] );
	}

	/**
	 * Language and format have to narrow each other. WP_Query treats
	 * `post__in` and `post__not_in` as an if/elseif, so the in-person filter's
	 * `post__not_in` would be dropped without a word if both were left to set
	 * their own query var.
	 */
	public function test_event_language_and_format_filters_narrow_together() {
		$spanish_online    = $this->make_language_event( 'Charla en linea', 'es', true );
		$spanish_in_person = $this->make_language_event( 'Charla en el bar', 'es', false );
		$this->make_language_event( 'Online talk', 'en', true );

		$online = $this->get_archive_query_vars( 'online', 'es' );
		$this->assertSame( array( $spanish_online ), $online['post__in'] );
		$this->assertArrayNotHasKey( 'post__not_in', $online );

		$in_person = $this->get_archive_query_vars( 'in-person', 'es' );
		$this->assertSame( array( $spanish_in_person ), $in_person['post__in'] );
		$this->assertArrayNotHasKey( 'post__not_in', $in_person );
	}

	/**
	 * An empty `post__in` is ignored by WP_Query, so a combination that
	 * matches nothing has to say "no posts" explicitly rather than falling
	 * back to the whole archive.
	 */
	public function test_event_language_filter_shows_nothing_when_the_combination_is_empty() {
		$this->make_language_event( 'Charla en el bar', 'es', false );
		$this->make_language_event( 'Online talk', 'en', true );

		$this->assertSame( array( 0 ), $this->get_archive_query_vars( 'online', 'es' )['post__in'] );
	}

	/**
	 * The language filter must stay off the archive's own query for the same
	 * reason the format filter does: a join that makes WP_Query select
	 * `DISTINCT` collapses a recurring series back into a single row.
	 */
	public function test_event_language_filter_keeps_a_meta_query_off_the_archive_query() {
		$this->make_language_event( 'Charla mensual', 'es' );
		$this->make_language_event( 'Monthly talk', 'en' );

		$query_vars = $this->get_archive_query_vars( null, 'es' );

		$this->assertArrayNotHasKey( 'meta_query', $query_vars );
		$this->assertArrayNotHasKey( 'meta_key', $query_vars );
	}

	/**
	 * Each filter's form carries the applied language, so submitting one does
	 * not reset it.
	 */
	public function test_filter_forms_carry_the_applied_language() {
		$this->make_language_event( 'Charla mensual', 'es' );
		$this->make_language_event( 'Monthly talk', 'en' );

		$_GET['event_language'] = 'es';

		ob_start();
		do_action( 'wporg_query_filter_in_form', 'event_time' );
		$time_form = ob_get_clean();

		ob_start();
		do_action( 'wporg_query_filter_in_form', 'event_language' );
		$language_form = ob_get_clean();

		unset( $_GET['event_language'] );

		$this->assertStringContainsString( 'name="event_language" value="es"', $time_form );
		$this->assertStringNotContainsString( 'name="event_language"', $language_form );
	}

	/**
	 * Searching narrows the language already in view rather than resetting it.
	 */
	public function test_search_form_carries_the_applied_language() {
		global $wp_query;

		$this->make_language_event( 'Charla mensual', 'es' );
		$this->make_language_event( 'Monthly talk', 'en' );

		// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited
		$original_query                 = $wp_query;
		$wp_query                       = new \WP_Query();
		$wp_query->is_post_type_archive = true;
		$wp_query->set( 'post_type', 'gatherpress_event' );

		$_GET['event_language'] = 'es';

		$search_form = '<form role="search" method="get" action="https://example.org"><input type="search" name="s" /></form>';
		// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Core's own block filter name.
		$output = apply_filters( 'render_block_core/search', $search_form );

		unset( $_GET['event_language'] );
		$wp_query = $original_query;
		// phpcs:enable WordPress.WP.GlobalVariablesOverride.Prohibited

		$this->assertStringContainsString( 'name="event_language" value="es"', $output );
	}

	/**
	 * Venues are metadata on events, not their own front-end destination -
	 * confirm the post type stays non-public even though GatherPress itself
	 * registers it.
	 */
	public function test_gatherpress_venue_post_type_is_non_public() {
		$post_type_object = get_post_type_object( 'gatherpress_venue' );

		$this->assertNotNull( $post_type_object, 'GatherPress must be active for this assertion to be meaningful.' );
		$this->assertFalse( $post_type_object->public );
		$this->assertFalse( $post_type_object->publicly_queryable );
		$this->assertFalse( $post_type_object->has_archive );
	}

	/**
	 * Search block on the events archive rewrites form action, removes required,
	 * adds the event_time hidden input, and marks the form for events search clear.
	 */
	public function test_search_block_removes_required_and_handles_clearing() {
		global $wp_query;

		// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.ValidHookName.UseUnderscores
		$original_query                 = $wp_query;
		$wp_query                       = new \WP_Query();
		$wp_query->is_post_type_archive = true;
		$wp_query->set( 'post_type', 'gatherpress_event' );

		$input_html = '<form role="search" method="get" action="https://example.org">'
			. '<input type="search" name="s" required />'
			. '</form>';

		$output = apply_filters( 'render_block_core/search', $input_html );

		$archive_url = get_post_type_archive_link( 'gatherpress_event' );
		$this->assertStringContainsString( 'action="' . esc_url( $archive_url ) . '"', $output );
		$this->assertStringContainsString( 'data-events-search-form="1"', $output );
		$this->assertStringNotContainsString( 'required', $output );
		$this->assertStringContainsString( '<input type="hidden" name="event_time" value="all" />', $output );
		$this->assertStringNotContainsString( '<script', $output );

		$processor = new \WP_HTML_Tag_Processor( $output );
		$this->assertTrue( $processor->next_tag( 'form' ) );
		$this->assertSame( '1', $processor->get_attribute( 'data-events-search-form' ) );
		$this->assertTrue( $processor->next_tag( 'input' ) );
		$this->assertNull( $processor->get_attribute( 'required' ) );

		$wp_query = $original_query;
		// phpcs:enable
	}

	/**
	 * Search block on other pages remains untouched.
	 */
	public function test_search_block_untouched_outside_event_archive() {
		global $wp_query;

		// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.ValidHookName.UseUnderscores
		$original_query                 = $wp_query;
		$wp_query                       = new \WP_Query();
		$wp_query->is_post_type_archive = false;

		$input_html = '<form role="search" method="get" action="https://example.org">'
			. '<input type="search" name="s" required />'
			. '</form>';

		$output = apply_filters( 'render_block_core/search', $input_html );

		$this->assertSame( $input_html, $output );

		$wp_query = $original_query;
		// phpcs:enable
	}

	/**
	 * Empty search query parameter reverts the default all-time view back to upcoming.
	 */
	public function test_event_time_filter_reverts_to_upcoming_on_empty_search() {
		$_GET['s'] = '';
		$filter    = $this->get_event_time_filter( 'all' );
		unset( $_GET['s'] );

		$this->assertSame( 'Time: Upcoming', $filter['label'] );
		$this->assertSame( array( 'upcoming' ), $filter['selected'] );
	}

	/**
	 * Normalizing event time filter reverts 'all' to 'upcoming' on empty search query.
	 */
	public function test_normalize_event_time_filter() {
		// When search query is empty string or whitespace and time is 'all'.
		$this->assertSame( 'upcoming', normalize_event_time_filter( 'all', '' ) );
		$this->assertSame( 'upcoming', normalize_event_time_filter( 'all', '   ' ) );

		// When search query has text, 'all' is preserved.
		$this->assertSame( 'all', normalize_event_time_filter( 'all', 'wordcamp' ) );

		// When search is not set (null), 'all' is preserved.
		unset( $_GET['s'] );
		$this->assertSame( 'all', normalize_event_time_filter( 'all' ) );

		// When reading from $_GET['s'].
		$_GET['s'] = '';
		$this->assertSame( 'upcoming', normalize_event_time_filter( 'all' ) );
		$_GET['s'] = 'community';
		$this->assertSame( 'all', normalize_event_time_filter( 'all' ) );
		unset( $_GET['s'] );

		// When time is not 'all', it is preserved.
		$this->assertSame( 'past', normalize_event_time_filter( 'past', '' ) );
		$this->assertSame( 'upcoming', normalize_event_time_filter( 'upcoming', '' ) );
	}

	/**
	 * RSVPs should never leak into general comment queries, even when
	 * no standard comments exist yet on the site.
	 */
	public function test_rsvps_excluded_from_general_comment_query() {
		$query                     = new \WP_Comment_Query();
		$query->query_vars['type'] = array();

		\WordCamp\Groups\GatherPress_Tweaks\exclude_rsvps_from_general_comment_queries( $query );

		$this->assertContains( 'gatherpress_rsvp', (array) ( $query->query_vars['type__not_in'] ?? array() ) );
	}

	/**
	 * Queries specifically requesting RSVPs should not have them excluded.
	 */
	public function test_rsvps_not_excluded_when_explicitly_requested() {
		$query                     = new \WP_Comment_Query();
		$query->query_vars['type'] = 'gatherpress_rsvp';

		\WordCamp\Groups\GatherPress_Tweaks\exclude_rsvps_from_general_comment_queries( $query );

		$this->assertEmpty( $query->query_vars['type__not_in'] ?? array() );
	}

	/**
	 * Caller's explicit RSVP intent should be captured before GatherPress priority 10 filter runs.
	 */
	public function test_capture_explicit_rsvp_query_records_intent() {
		$query_single                     = new \WP_Comment_Query();
		$query_single->query_vars['type'] = 'gatherpress_rsvp';

		\WordCamp\Groups\GatherPress_Tweaks\capture_explicit_rsvp_query( $query_single );
		$this->assertTrue( $query_single->query_vars['_gatherpress_rsvp_explicit'] );

		$query_in                         = new \WP_Comment_Query();
		$query_in->query_vars['type__in'] = array( 'gatherpress_rsvp' );

		\WordCamp\Groups\GatherPress_Tweaks\capture_explicit_rsvp_query( $query_in );
		$this->assertTrue( $query_in->query_vars['_gatherpress_rsvp_explicit'] );

		$query_general                     = new \WP_Comment_Query();
		$query_general->query_vars['type'] = '';

		\WordCamp\Groups\GatherPress_Tweaks\capture_explicit_rsvp_query( $query_general );
		$this->assertArrayNotHasKey( '_gatherpress_rsvp_explicit', $query_general->query_vars );
	}

	/**
	 * GatherPress RSVP exclusion filter should opt out when query explicitly asks for RSVPs.
	 */
	public function test_skip_rsvp_exclusion_for_explicit_queries() {
		$query_explicit = new \WP_Comment_Query();
		$query_explicit->query_vars['_gatherpress_rsvp_explicit'] = true;

		$should_exclude = \WordCamp\Groups\GatherPress_Tweaks\skip_rsvp_exclusion_for_explicit_queries( true, $query_explicit );
		$this->assertFalse( $should_exclude );

		$query_general          = new \WP_Comment_Query();
		$should_exclude_general = \WordCamp\Groups\GatherPress_Tweaks\skip_rsvp_exclusion_for_explicit_queries( true, $query_general );
		$this->assertTrue( $should_exclude_general );
	}
}
