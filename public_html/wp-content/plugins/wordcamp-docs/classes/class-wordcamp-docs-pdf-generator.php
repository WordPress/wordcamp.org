<?php
use Dompdf\Dompdf;

require_once __DIR__ . '/class-wordcamp-docs-template.php';
require_once __DIR__ . '/class-wordcamp-docs-arabic-shaper.php';

/**
 * Helper class to generate PDFs from HTML strings or files.
 *
 * Thanks to Viper007Bond ( http://www.viper007bond.com )
 */
class WordCamp_Docs_PDF_Generator {

	/**
	 * Full path to your tmp folder where files should be created. Include a trailing slash.
	 * You should probably just leave this as "/tmp/" as you should move your PDF on your own.
	 *
	 * @var string
	 */
	public $tmp_folder_base = '/tmp/';

	/**
	 * Stores a unique folder name that the class instance will work out of.
	 * Generated dynamically using uniqid().
	 *
	 * @var string
	 */
	public $working_folder_name;

	/**
	 * Why the last PDF couldn't be generated, if it couldn't.
	 *
	 * @var string
	 */
	public $last_error = '';

	/**
	 * The only hosts DomPDF may fetch from: Google Fonts' stylesheets and font files.
	 */
	const FONT_HOSTS = array( 'fonts.googleapis.com', 'fonts.gstatic.com' );

	/**
	 * Constructor. No parameters.
	 */
	function __construct() {
		$this->working_folder_name = uniqid( 'wcdocs_' );
	}

	/**
	 * Generates a PDF from a string of HTML.
	 *
	 * @param string $string   A string of HTML.
	 * @param string $filename Filename of the PDF, including the extension.
	 * @param array  $args     Optional. An associative array of additional parameters.
	 *                         assets:  optional array of full paths to local assets (images for example).
	 *
	 * @return string The path to the generated PDF.
	 */
	public function generate_pdf_from_string( $string, $filename, $args = array() ) {
		$file = $this->write_file( $filename . '.html', $string );
		return $this->generate_pdf_from_file( $file, $filename, $args );
	}

	/**
	 * Generates a PDF from an HTML file.
	 *
	 * @param string  $source_file Full path to the HTML file.
	 * @param  string $filename    Filename of the PDF, including the extension.
	 * @param array   $args        Optional. An associative array of additional parameters.
	 *                             assets:  optional array of full paths to local assets (images for example).
	 *
	 * @return string The path to the generated PDF.
	 */
	public function generate_pdf_from_file( $source_file, $filename, $args = array() ) {
		if ( ! empty( $args['assets'] ) && is_array( $args['assets'] ) ) {
			foreach ( $args['assets'] as $asset ) {
				copy( $asset, $this->get_tmp_folder( basename( $asset ) ) );
			}
		}

		if ( ! is_readable( $source_file ) ) {
			$this->last_error = "Can't read $source_file.";

			return $this->get_tmp_folder( $filename );
		}

		// DomPDF comes from Composer, so a deploy that skipped it would otherwise be a fatal error at checkout.
		if ( ! class_exists( Dompdf::class ) ) {
			$this->last_error = 'DomPDF is not installed.';

			return $this->get_tmp_folder( $filename );
		}

		$font_dir = self::get_font_cache_folder();

		$dompdf = new Dompdf( [
			'dpi'                  => 120,

			'isHtml5ParserEnabled' => true,
			'isJavascriptEnabled'  => false,
			'isPhpEnabled'         => false,

			// Only the web fonts are fetched remotely. Images are copied into the working
			// folder as assets, so markup that reaches a template can't make the server
			// request any other URL.
			'isRemoteEnabled'      => true,
			'allowedRemoteHosts'   => self::FONT_HOSTS,

			'httpContext'          => stream_context_create( [
				'http' => [
					'user_agent' => 'WordCamp Docs PDF Generator; ' . home_url( '/' ),
					'timeout'    => 30,
				],
			] ),

			'fontCache'            => $font_dir,
			'fontDir'              => $font_dir,
			// Local files can only be read from this instance's working folder and the font cache.
			'chroot'               => [
				$this->get_tmp_folder(),
				$font_dir,
			],
			'tempDir'              => $this->get_tmp_folder(),
		] );

		// A rendering error leaves no PDF behind, which callers already treat as a failure.
		try {
			// DomPDF can't join Arabic letters or lay them out right to left, so shape them first.
			$html   = file_get_contents( $source_file );
			$shaped = WordCamp_Docs_Arabic_Shaper::shape( $html );
			if ( $shaped !== $html ) {
				$source_file = $this->write_file( basename( $source_file ), $shaped );
			}

			$dompdf->loadHtmlFile( $source_file );
			$dompdf->render();
			$this->write_file( $filename, $dompdf->output() );
		} catch ( Throwable $e ) {
			$this->last_error = $e->getMessage();
		}

		return $this->get_tmp_folder( $filename );
	}

	/**
	 * Download the web fonts into the font cache, unless they're already there.
	 *
	 * The first PDF rendered against an empty cache downloads about 80MB of fonts, which takes
	 * several seconds. Invoices are rendered during checkout, so warm the cache ahead of time.
	 *
	 * @return bool Whether the cache was already warm.
	 */
	public static function warm_font_cache() {
		$installed = json_decode( (string) @file_get_contents( self::get_font_cache_folder() . 'installed-fonts.json' ), true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A missing file just means the cache is cold.
		$missing   = array_diff( WordCamp_Docs_Template::FONT_FAMILIES, array_keys( (array) $installed ) );

		if ( ! $missing ) {
			return true;
		}

		ob_start();
		WordCamp_Docs_Template::font_header_html();
		$html = '<!DOCTYPE html><html><head><meta charset="UTF-8">' . ob_get_clean() . '</head><body><p>Aa <strong>Aa</strong> 中文 한국어 العربية</p></body></html>';

		$generator = new self();
		$generator->generate_pdf_from_string( $html, 'warm-font-cache.pdf' );
		$generator->delete_tmp_folder();

		return false;
	}

	/**
	 * Serves a file from the tmp folder up to the browser.
	 * Does NOT exit when finished so that you can still call
	 * WordCamp_PDF_Generator::delete_tmp_folder() when you're all done.
	 *
	 * @param string $filename The filename within this instance's temporary folder to serve up.
	 * @param bool $download Whether to prompt downloading or serve it natively within the browser.
	 */
	public function serve_pdf_to_browser( $filename, $download = false ) {
		$filename = basename( $filename );

		$file = $this->get_tmp_folder( $filename );

		nocache_headers();

		if ( ! file_exists( $file ) ) {
			status_header( 404 );
			echo 'File not found.';
		}

		header( 'Content-Type: application/pdf' );

		if ( $download ) {
			header( 'Content-Disposition: attachment; filename="' . esc_attr( $filename ) . '"' );
		}

		echo file_get_contents( $file );
	}

	/**
	 * Writes out a file to this instance's temporary file.
	 *
	 * @param string $filename The filename (not the path) that should be written to.
	 * @param string $data The contents of the file, such as HTML.
	 *
	 * @return string The full path to the newly created file.
	 */
	public function write_file( $filename, $data ) {
		$path = $this->get_tmp_folder( $filename );

		file_put_contents( $path, $data );

		return $path;
	}

	/**
	 * Returns the path to the shared font cache folder.
	 *
	 * It lives outside /tmp so it survives reboots and is shared by every site. With ms-files that's
	 * `blogs.dir`, which every web server shares, so one warm-up covers all of them. It sits beside the
	 * sites' `<id>/files` folders, not inside one, so `/files/` URLs can't serve it.
	 *
	 * @return string Full path to the font cache folder, with a trailing slash.
	 */
	public static function get_font_cache_folder() {
		/**
		 * Filters where DomPDF caches the fonts it downloads.
		 *
		 * @param string $font_dir Full path to the font cache folder.
		 */
		// Not `wp_upload_dir()`: that's per site, and the main site's is `uploads` on local disk.
		$uploads  = WP_CONTENT_DIR . ( get_site_option( 'ms_files_rewriting' ) ? '/blogs.dir' : '/uploads' );
		$font_dir = trailingslashit( apply_filters( 'wordcamp_docs_font_cache_folder', $uploads . '/wordcamp-docs-fonts' ) );

		if ( ! is_dir( $font_dir ) ) {
			wp_mkdir_p( $font_dir );
		}

		return $font_dir;
	}

	/**
	 * Returns the path to this instance's temporary folder.
	 * Optionally you can include a filename that will be relative to that path.
	 *
	 * @param string $filename Optional. Filename to be appended onto the end of the path.
	 *
	 * @return string Full path to the temporary folder or file.
	 */
	public function get_tmp_folder( $filename = '' ) {
		$path = trailingslashit( $this->tmp_folder_base ) . $this->working_folder_name . '/';

		if ( ! is_dir( $path ) ) {
			mkdir( $path, 0777, true );
		}

		return $path . $filename;
	}

	/**
	 * Run this when you're all done. Deletes the instance's temporary folder and all files within it.
	 * You should make sure to move the generated PDF out of the folder first.
	 *
	 * @return bool True on success or false on failure.
	 */
	public function delete_tmp_folder() {
		$tmp_folder = $this->get_tmp_folder();

		$files = array_diff( scandir( $tmp_folder ), array( '.', '..' ) );

		foreach ( $files as $file ) {
			unlink( $tmp_folder . $file );
		}

		return rmdir( $tmp_folder );
	}
}
