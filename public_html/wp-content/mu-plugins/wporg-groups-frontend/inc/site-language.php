<?php
/**
 * Group-level site language helpers.
 *
 * WordPress core exposes `WPLANG` through Settings > General and `/wp/v2/settings`,
 * both of which require `manage_options` that group organizers do not have. New
 * group sites provisioned from Network Admin also start without a site language,
 * leaving non-English groups in English for anonymous visitors.
 *
 * This module wraps reading, listing, validating, and saving `WPLANG` so both
 * site provisioning and the front-end Group Settings UI share one allowlist of
 * installed language packs.
 *
 * @package WordCamp\Groups\Frontend
 */

namespace WordCamp\Groups\Frontend\Site_Language;

use WP_Error;

defined( 'WPINC' ) || die();

/**
 * Core's fallback locale when `WPLANG` is empty.
 */
const DEFAULT_LOCALE = 'en_US';

/**
 * Display label for the default US English option, matching `wp_dropdown_languages()`.
 */
const DEFAULT_LABEL = 'English (United States)';

/**
 * Installed WordPress locales on this network, excluding US English.
 *
 * `get_available_languages()` scans `WP_LANG_DIR` for installed `.mo`/`.l10n.php`
 * core files and returns locale codes such as `pl_PL` or `pt_BR`.
 *
 * @return string[] Locale codes.
 */
function get_installed_locales(): array {
	$locales = array();

	foreach ( get_available_languages() as $locale ) {
		$locale = trim( (string) $locale );

		if ( '' !== $locale && DEFAULT_LOCALE !== $locale ) {
			$locales[] = $locale;
		}
	}

	return array_values( array_unique( $locales ) );
}

/**
 * Map locale codes to native display names, falling back to English or raw codes.
 *
 * Skips any external API lookup when `$locales` is empty (for instance in test
 * environments with no extra language packs installed).
 *
 * @param string[] $locales Locale codes to resolve.
 *
 * @return array<string, string> Locale code => display name.
 */
function get_locale_names( array $locales ): array {
	if ( empty( $locales ) ) {
		return array();
	}

	$names = array();

	if ( defined( 'GLOTPRESS_LOCALES_PATH' ) && file_exists( GLOTPRESS_LOCALES_PATH ) ) {
		require_once GLOTPRESS_LOCALES_PATH;

		foreach ( \GP_Locales::locales() as $locale ) {
			if ( ! empty( $locale->wp_locale ) ) {
				$names[ $locale->wp_locale ] = ! empty( $locale->native_name )
					? $locale->native_name
					: $locale->english_name;
			}
		}
	} else {
		if ( ! function_exists( 'wp_get_available_translations' ) ) {
			require_once ABSPATH . 'wp-admin/includes/translation-install.php';
		}

		$translations = wp_get_available_translations();

		if ( is_array( $translations ) ) {
			foreach ( $translations as $code => $data ) {
				if ( ! empty( $data['native_name'] ) ) {
					$names[ $code ] = $data['native_name'];
				} elseif ( ! empty( $data['english_name'] ) ) {
					$names[ $code ] = $data['english_name'];
				}
			}
		}
	}

	$resolved = array();

	foreach ( $locales as $code ) {
		$resolved[ $code ] = $names[ $code ] ?? $code;
	}

	return $resolved;
}

/**
 * Build the list of selectable site languages for dropdowns.
 *
 * US English is always first with an empty string value, matching how core stores
 * the default locale in `WPLANG`, followed by installed locales sorted alphabetically
 * by native name.
 *
 * @return array<int, array{code: string, name: string}> Option items.
 */
function get_options(): array {
	$locales = get_installed_locales();
	$names   = get_locale_names( $locales );
	$options = array();

	foreach ( $locales as $code ) {
		$options[] = array(
			'code' => $code,
			'name' => $names[ $code ] ?? $code,
		);
	}

	// ASCII transliteration doesn't work if the LC_CTYPE is 'C' or 'POSIX'.
	// See https://www.php.net/manual/en/function.iconv.php#74101.
	$orig_locale = setlocale( LC_CTYPE, 0 );
	setlocale( LC_CTYPE, 'en_US.UTF-8' );

	usort(
		$options,
		static function ( array $first, array $second ): int {
			$first_name  = function_exists( '\WordCamp\Groups\Frontend\Event_Language\transliterate' )
				? \WordCamp\Groups\Frontend\Event_Language\transliterate( $first['name'] )
				: $first['name'];
			$second_name = function_exists( '\WordCamp\Groups\Frontend\Event_Language\transliterate' )
				? \WordCamp\Groups\Frontend\Event_Language\transliterate( $second['name'] )
				: $second['name'];

			return strcasecmp( $first_name, $second_name );
		}
	);

	setlocale( LC_CTYPE, $orig_locale );

	array_unshift(
		$options,
		array(
			'code' => '',
			'name' => DEFAULT_LABEL,
		)
	);

	return $options;
}

/**
 * Read the group site's stored `WPLANG` option, normalizing `en_US` to `''`.
 *
 * @return string Locale code, or `''` for US English.
 */
function get_site_language(): string {
	$locale = trim( (string) get_option( 'WPLANG', '' ) );

	return DEFAULT_LOCALE === $locale ? '' : $locale;
}

/**
 * Validate and normalize a submitted site locale against installed languages.
 *
 * Empty string and `en_US` both normalize to `''`, matching WordPress core's
 * General Settings convention.
 *
 * @param string $locale Submitted locale code.
 *
 * @return string|WP_Error Normalized locale code, or `WP_Error` if not installed.
 */
function validate_locale( string $locale ) {
	$locale = trim( $locale );

	if ( '' === $locale || DEFAULT_LOCALE === $locale ) {
		return '';
	}

	if ( ! in_array( $locale, get_installed_locales(), true ) ) {
		return new WP_Error(
			'wporg_groups_invalid_site_language',
			__( 'Choose a valid site language.', 'wordcamporg' ),
			array( 'status' => 400 )
		);
	}

	return $locale;
}

/**
 * Persist the group site's `WPLANG` option, normalizing `en_US` to `''`.
 *
 * @param string $locale Validated locale code, or `''` for US English.
 */
function save_site_language( string $locale ): void {
	$locale = trim( $locale );

	if ( DEFAULT_LOCALE === $locale ) {
		$locale = '';
	}

	update_option( 'WPLANG', $locale );
}
