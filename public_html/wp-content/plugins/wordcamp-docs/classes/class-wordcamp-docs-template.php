<?php
/**
 * Make sure your custom template implements this interface.
 */
abstract class WordCamp_Docs_Template {

	/**
	 * This is the name that will be displayed in the WordCamp Docs UI.
	 */
	abstract public function get_name();

	/**
	 * This is the PDF filename which will be used when serving the
	 * generated PDF file to the browser.
	 */
	abstract public function get_filename();

	/**
	 * This function will be called with POST data. It should render a
	 * form for the WordCamp Docs UI.
	 *
	 * @param array $data POST-ed data (if any)
	 */
	abstract public function form( $data );

	/**
	 * This function is called with the POST-ed data, should return
	 * clean input.
	 *
	 * @param array $input POST-ed data.
	 */
	abstract public function sanitize( $input );

	/**
	 * This function is called when generating a PDF, should return
	 * HTML and CSS. You can use ob_* functions for convenience.
	 *
	 * @param array $input POST-ed and self::sanitized() data.
	 */
	abstract public function render( $data );

	/**
	 * This function should return an array of absolute paths to assets.
	 */
	abstract public function get_assets();

	/**
	 * The font families font_header_html() loads, as DomPDF names them in its font cache.
	 */
	const FONT_FAMILIES = array( 'noto sans', 'noto sans sc', 'noto sans kr', 'noto sans arabic' );

	/**
	 * Output default font header HTML to be included in the PDF templates.
	 *
	 * Fonts included (weights: 300-600):
	 *  - Noto Sans
	 *  - Noto Sans SC
	 *  - Noto Sans KR
	 *  - Noto Sans Arabic
	 */
	public static function font_header_html() {
		?>
		<?php // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- DomPDF fetches the fonts itself, this isn't a page WordPress renders. ?>
		<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Arabic:wght@300..600&family=Noto+Sans+KR:wght@300..600&family=Noto+Sans+SC:wght@300..600&family=Noto+Sans:wght@300..600" rel="stylesheet" type="text/css"/>
		<style type="text/css">
		@page {
			size: a4;
			margin: 10mm;

			font-family: "Noto Sans", "Noto Sans SC", "Noto Sans KR", "Noto Sans Arabic", sans-serif;
		}

		body {
			font-family: "Noto Sans", "Noto Sans SC", "Noto Sans KR", "Noto Sans Arabic", sans-serif;
			font-weight: 300;
			line-height: 1;
		}

		<?php /* Bold is 700, which isn't loaded, and DomPDF falls back to Helvetica instead of the nearest weight. */ ?>
		b, strong, th, h1, h2, h3, h4, h5, h6 {
			font-weight: 600;
		}
		</style>
		<?php
	}
}
