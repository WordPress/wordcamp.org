<?php

namespace WordCamp\Script_Translations;

defined( 'WPINC' ) || die();

/*
 * Serve JavaScript translations for the `wordcamporg` text domain from its mofile.
 *
 * Core loads a script's translations from per-script JSON files, but WordCamp.org only installs one
 * `wordcamporg-{locale}.mo` per locale (see `wcorg_load_wordcamp_textdomain()`). Those JSON files never exist, so
 * `wp_set_script_translations()` found nothing and JS strings stayed in English. The mofile already holds the JS
 * strings, so this builds the JSON from it, keeping only the strings that appear in the script's source.
 *
 * @see https://github.com/WordPress/wordcamp.org/issues/1096
 */
add_filter( 'pre_load_script_translations', __NAMESPACE__ . '\load_from_mofile', 10, 4 );

const DOMAIN      = 'wordcamporg';
const CACHE_GROUP = 'wcorg-script-translations';

/**
 * Build a script's `wordcamporg` translations from the loaded mofile.
 *
 * @param string|false|null $translations JSON-encoded translations, or null to let Core look for a file.
 * @param string|false      $file         Path to the translation file Core is about to look for.
 * @param string            $handle       Script handle.
 * @param string            $domain       Text domain.
 *
 * @return string|false|null
 */
function load_from_mofile( $translations, $file, $handle, $domain ) {
	if ( null !== $translations || DOMAIN !== $domain || ! is_textdomain_loaded( DOMAIN ) ) {
		return $translations;
	}

	$script_path = get_script_path( $handle );

	if ( ! $script_path ) {
		return $translations;
	}

	$mo        = get_translations_for_domain( DOMAIN );
	$headers   = $mo->headers;
	$cache_key = array(
		$script_path,
		filemtime( $script_path ),
		determine_locale(),
		$headers['Po-Revision-Date'] ?? '',
	);
	$cache_key = md5( implode( '|', $cache_key ) );

	$json = wp_cache_get( $cache_key, CACHE_GROUP );

	if ( false === $json ) {
		$json = build_json( $mo, file_get_contents( $script_path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.

		// An empty string caches "nothing to translate", so the script isn't scanned again.
		wp_cache_set( $cache_key, $json, CACHE_GROUP, DAY_IN_SECONDS );
	}

	return '' === $json ? $translations : $json;
}

/**
 * Map a script handle to its file under `wp-content`, or an empty string.
 *
 * @param string $handle
 *
 * @return string
 */
function get_script_path( $handle ) {
	$scripts = wp_scripts();

	if ( empty( $scripts->registered[ $handle ]->src ) || ! is_string( $scripts->registered[ $handle ]->src ) ) {
		return '';
	}

	$src         = wp_parse_url( $scripts->registered[ $handle ]->src );
	$content_url = wp_parse_url( content_url() );

	if ( empty( $src['path'] ) || empty( $content_url['path'] ) ) {
		return '';
	}

	if ( isset( $src['host'], $content_url['host'] ) && $src['host'] !== $content_url['host'] ) {
		return '';
	}

	$content_path = trailingslashit( $content_url['path'] );

	if ( ! str_starts_with( $src['path'], $content_path ) ) {
		return '';
	}

	$content_dir = realpath( WP_CONTENT_DIR );
	$path        = realpath( WP_CONTENT_DIR . '/' . substr( $src['path'], strlen( $content_path ) ) );

	if ( ! $content_dir || ! $path || ! str_starts_with( $path, trailingslashit( $content_dir ) ) || ! str_ends_with( $path, '.js' ) ) {
		return '';
	}

	return $path;
}

/**
 * Encode the mofile entries whose original string appears in the script, in the format `wp.i18n` expects.
 *
 * @param \WP_Translations $mo
 * @param string           $source Script source.
 *
 * @return string JSON, or an empty string when nothing in the script is translated.
 */
function build_json( $mo, $source ) {
	$headers  = $mo->headers;
	$messages = array();

	foreach ( $mo->entries as $entry ) {
		// A `.l10n.php` file keys plurals as "singular\0plural"; a mofile keys them by the singular.
		$singular = explode( "\0", $entry->singular )[0];

		if ( ! appears_in_source( $singular, $source ) ) {
			continue;
		}

		$key              = $entry->context ? $entry->context . "\4" . $singular : $singular;
		$messages[ $key ] = $entry->translations;
	}

	if ( ! $messages ) {
		return '';
	}

	$messages = array(
		'' => array(
			'domain'       => 'messages',
			'lang'         => $headers['Language'] ?? determine_locale(),
			'plural-forms' => $headers['Plural-Forms'] ?? 'nplurals=2; plural=(n != 1);',
		),
	) + $messages;

	return wp_json_encode( array(
		'domain'      => 'messages',
		'locale_data' => array( 'messages' => $messages ),
	) );
}

/**
 * Whether a string appears in the script as a quoted literal.
 *
 * Built bundles use double quotes, and may escape non-ASCII characters; unbuilt scripts tend to use single quotes.
 * A literal that isn't a translation call only adds an unused entry.
 *
 * @param string $text
 * @param string $source
 *
 * @return bool
 */
function appears_in_source( $text, $source ) {
	if ( '' === $text ) {
		return false;
	}

	$candidates = array(
		wp_json_encode( $text, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
		wp_json_encode( $text, JSON_UNESCAPED_SLASHES ),
		"'" . addcslashes( $text, "'\\\n" ) . "'",
	);

	foreach ( array_unique( $candidates ) as $candidate ) {
		if ( str_contains( $source, $candidate ) ) {
			return true;
		}
	}

	return false;
}
