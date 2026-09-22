<?php
/**
 * The language an event is run in.
 *
 * A group's country is site meta and identical for every event on its archive,
 * but the language is not: a group can run some sessions in Spanish and some in
 * English, and testers asked to narrow the archive by that rather than by where
 * the group is (#2035). So this belongs to the event, not to the group.
 *
 * @package WordCamp\Groups\Frontend
 */

namespace WordCamp\Groups\Frontend\Event_Language;

use GatherPress\Core\Event\Event;

defined( 'WPINC' ) || die();

/**
 * Post meta holding one CLDR language subtag, e.g. `es`.
 *
 * Underscore-prefixed so it stays out of the Custom Fields box: the front-end
 * form and the REST schema are the write paths, and both validate.
 */
const META_KEY = '_event_language';

/** Registers the event language meta. */
function bootstrap(): void {
	add_action( 'init', __NAMESPACE__ . '\register_meta' );
}

/**
 * Register the language meta so the block editor and REST can read it.
 *
 * Mirrors `_event_speakers`: readable by anyone who can read the event, writable
 * only by someone who can edit it.
 */
function register_meta(): void {
	register_post_meta(
		Event::POST_TYPE,
		META_KEY,
		array(
			'type'              => 'string',
			'single'            => true,
			'default'           => '',
			'show_in_rest'      => true,
			'sanitize_callback' => __NAMESPACE__ . '\sanitize_code',
			'auth_callback'     => static function ( $allowed, $meta_key, $post_id ) {
				return current_user_can( 'edit_post', (int) $post_id );
			},
		)
	);
}

/**
 * Every language a group may pick from, as code => localized name.
 *
 * CLDR carries 622 entries, 29 of which are regional variants (`pt-BR`,
 * `en-GB`, `es-ES`) and one a display alias (`az-alt-short`). Those are dropped:
 * the whole point of the request was that Spanish speakers in Spain, Mexico and
 * Argentina share one language, so offering three Spanishes would defeat it.
 * What is left is the plain language subtags, which is also what the filter
 * groups on.
 *
 * Sorted by localized name the way `wcorg_get_countries()` sorts territories,
 * through the same ASCII transliteration so accents do not sink a language to
 * the bottom of the list.
 *
 * @return array<string, string> Language subtag => localized language name.
 */
function get_options(): array {
	static $options = null;

	if ( null !== $options ) {
		return $options;
	}

	require_once WP_PLUGIN_DIR . '/wp-cldr/class-wp-cldr.php';

	$cldr      = new \WP_CLDR();
	$languages = $cldr->get_languages();

	$options = array_filter(
		$languages,
		static fn( string $code ): bool => (bool) preg_match( '/^[a-z]{2,3}$/', $code ),
		ARRAY_FILTER_USE_KEY
	);

	// ASCII transliteration doesn't work if the LC_CTYPE is 'C' or 'POSIX'.
	// See https://www.php.net/manual/en/function.iconv.php#74101.
	$orig_locale = setlocale( LC_CTYPE, 0 );
	setlocale( LC_CTYPE, 'en_US.UTF-8' );

	uasort(
		$options,
		static function ( string $first, string $second ): int {
			return strcasecmp( transliterate( $first ), transliterate( $second ) );
		}
	);

	setlocale( LC_CTYPE, $orig_locale );

	return $options;
}

/**
 * Fold a language name down to ASCII so the sort ignores its accents.
 *
 * Returns the name unchanged when it holds nothing `iconv()` can transliterate,
 * which keeps the comparison total rather than collapsing to the empty string.
 *
 * @param string $name Localized language name.
 */
function transliterate( string $name ): string {
	$encoding       = mb_detect_encoding( $name ) ?: 'UTF-8';
	$transliterated = iconv( $encoding, 'ascii//TRANSLIT', $name );

	return false === $transliterated ? $name : $transliterated;
}

/**
 * Normalize a submitted language code to one this site recognizes.
 *
 * Anything unknown becomes the empty string, which is how an event with no
 * declared language is stored. Deliberately lossy: a code that does not resolve
 * to a name could never be labelled in the filter or on the event page, so
 * keeping it would only produce a blank option.
 *
 * @param mixed $code Submitted language code.
 * @return string A known language subtag, or '' when the code is not one.
 */
function sanitize_code( $code ): string {
	$code = strtolower( trim( (string) $code ) );

	return isset( get_options()[ $code ] ) ? $code : '';
}

/**
 * The localized name of a language code, or '' when it is not a known one.
 *
 * @param string $code Language subtag.
 */
function get_name( string $code ): string {
	return (string) ( get_options()[ strtolower( $code ) ] ?? '' );
}

/**
 * The language an event is run in, or '' when the organizer left it unset.
 *
 * @param int $event_id Event post ID.
 */
function get_event_language( int $event_id ): string {
	return sanitize_code( get_post_meta( $event_id, META_KEY, true ) );
}

/**
 * Write an event's language, clearing the meta when the code is empty.
 *
 * @param int    $event_id Event post ID.
 * @param string $code     Language subtag, or '' to clear.
 */
function set_event_language( int $event_id, string $code ): void {
	$code = sanitize_code( $code );

	if ( '' === $code ) {
		delete_post_meta( $event_id, META_KEY );
		return;
	}

	update_post_meta( $event_id, META_KEY, $code );
}

/**
 * The language to prefill a brand-new event's form with.
 *
 * The site's own locale, reduced to its language subtag: a group running on a
 * Spanish site is overwhelmingly likely to hold its next meetup in Spanish, and
 * an organizer who disagrees changes one select. Falls back to '' rather than
 * guessing English when the locale names a language CLDR does not know.
 */
function get_default(): string {
	return sanitize_code( strtok( get_locale(), '_-' ) );
}
