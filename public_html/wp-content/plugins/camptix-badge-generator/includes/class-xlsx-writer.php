<?php

namespace CampTix\Badge_Generator;
use Exception;
use ZipArchive;

defined( 'WPINC' ) || die();

/**
 * A minimal XLSX writer with images anchored to cells.
 *
 * Canva's Bulk Create reads images that are placed inside spreadsheet cells, which a CSV can't carry. This
 * writes just enough of the Office Open XML format for that: one sheet of text cells, a bold header row,
 * column widths, and pictures that move and size with their cells.
 */
class XLSX_Writer {
	/**
	 * EMUs per pixel. EMUs are the unit drawings are positioned in.
	 */
	private const EMU_PER_PIXEL = 9525;

	/**
	 * Padding between a cell's top-left corner and its image, in pixels.
	 */
	private const IMAGE_PADDING = 2;

	/**
	 * @var array Each row's cells and whether it's bold.
	 */
	private $rows = array();

	/**
	 * @var array Column widths in characters, keyed by 0-based column index.
	 */
	private $column_widths = array();

	/**
	 * @var int The width and height that images are scaled to fit within, in pixels.
	 */
	private $image_box;

	/**
	 * @param int $image_box The width and height that images are scaled to fit within, in pixels.
	 */
	public function __construct( int $image_box = 96 ) {
		$this->image_box = $image_box;
	}

	/**
	 * Add a row
	 *
	 * @param array $cells Strings, or `array( 'image' => '/path/to/file.png' )` for an image. Images must be
	 *                     PNG, JPEG, or GIF files, and must still exist when save() is called.
	 * @param bool  $bold
	 */
	public function add_row( array $cells, bool $bold = false ): void {
		$this->rows[] = array(
			'cells' => array_values( $cells ),
			'bold'  => $bold,
		);
	}

	/**
	 * Set a column's width
	 *
	 * @param int   $column 0-based column index.
	 * @param float $width  Width in characters.
	 */
	public function set_column_width( int $column, float $width ): void {
		$this->column_widths[ $column ] = $width;
	}

	/**
	 * Write the workbook to a file
	 *
	 * @param string $filename
	 *
	 * @throws Exception When the file can't be written.
	 */
	public function save( string $filename ): void {
		if ( ! class_exists( 'ZipArchive' ) ) {
			throw new Exception( esc_html__( 'The Zip extension for PHP is not installed.', 'wordcamporg' ) );
		}

		$zip = new ZipArchive();

		if ( true !== $zip->open( $filename, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			throw new Exception( esc_html__( 'The spreadsheet could not be created.', 'wordcamporg' ) );
		}

		$strings  = array();
		$images   = array();
		$sheet    = $this->get_sheet_xml( $strings, $images );
		$has_pics = ! empty( $images );

		$package_relationships = array(
			'rId1' => array( 'officeDocument', 'xl/workbook.xml' ),
		);

		$workbook_relationships = array(
			'rId1' => array( 'worksheet', 'worksheets/sheet1.xml' ),
			'rId2' => array( 'styles', 'styles.xml' ),
			'rId3' => array( 'sharedStrings', 'sharedStrings.xml' ),
		);

		$zip->addFromString( '[Content_Types].xml', $this->get_content_types_xml( $has_pics ) );
		$zip->addFromString( '_rels/.rels', $this->get_relationships_xml( $package_relationships ) );
		$zip->addFromString( 'xl/workbook.xml', $this->get_workbook_xml() );
		$zip->addFromString( 'xl/_rels/workbook.xml.rels', $this->get_relationships_xml( $workbook_relationships ) );
		$zip->addFromString( 'xl/styles.xml', $this->get_styles_xml() );
		$zip->addFromString( 'xl/sharedStrings.xml', $this->get_shared_strings_xml( $strings ) );
		$zip->addFromString( 'xl/worksheets/sheet1.xml', $sheet );

		if ( $has_pics ) {
			$sheet_relationships = array(
				'rId1' => array( 'drawing', '../drawings/drawing1.xml' ),
			);

			$zip->addFromString( 'xl/worksheets/_rels/sheet1.xml.rels', $this->get_relationships_xml( $sheet_relationships ) );

			$image_relationships = array();

			foreach ( $images as $index => $image ) {
				$media_name = sprintf( 'image%d.%s', $index + 1, $image['extension'] );

				$image_relationships[ 'rId' . ( $index + 1 ) ] = array( 'image', '../media/' . $media_name );
				$zip->addFile( $image['path'], 'xl/media/' . $media_name );
			}

			$zip->addFromString( 'xl/drawings/drawing1.xml', $this->get_drawing_xml( $images ) );
			$zip->addFromString( 'xl/drawings/_rels/drawing1.xml.rels', $this->get_relationships_xml( $image_relationships ) );
		}

		if ( ! $zip->close() ) {
			throw new Exception( esc_html__( 'The spreadsheet could not be saved.', 'wordcamporg' ) );
		}
	}

	/**
	 * Get a column's letters from its 0-based index
	 *
	 * @param int $column
	 *
	 * @return string For example, `A` for 0 and `AA` for 26.
	 */
	public static function get_column_letters( int $column ): string {
		$letters = '';

		for ( $column++; $column > 0; $column = intdiv( $column - 1, 26 ) ) {
			$letters = chr( 65 + ( ( $column - 1 ) % 26 ) ) . $letters;
		}

		return $letters;
	}

	/**
	 * Build the worksheet, collecting the strings and images it references
	 *
	 * @param array $strings Filled with the shared strings, keyed by their value.
	 * @param array $images  Filled with the images to anchor, in order.
	 *
	 * @return string
	 */
	private function get_sheet_xml( array &$strings, array &$images ): string {
		$columns = '';

		ksort( $this->column_widths );

		foreach ( $this->column_widths as $column => $width ) {
			$columns .= sprintf( '<col min="%1$d" max="%1$d" width="%2$s" customWidth="1"/>', $column + 1, $width );
		}

		$rows = '';

		foreach ( $this->rows as $row_index => $row ) {
			$cells      = '';
			$has_images = false;

			foreach ( $row['cells'] as $column => $value ) {
				$reference = self::get_column_letters( $column ) . ( $row_index + 1 );

				if ( is_array( $value ) ) {
					$image = $this->get_image( $value['image'] ?? '', $row_index, $column );

					if ( $image ) {
						$images[]   = $image;
						$has_images = true;
					}

					continue;
				}

				$value = (string) $value;

				if ( '' === $value ) {
					continue;
				}

				if ( ! isset( $strings[ $value ] ) ) {
					$strings[ $value ] = count( $strings );
				}

				$cells .= sprintf(
					'<c r="%s" t="s"%s><v>%d</v></c>',
					$reference,
					$row['bold'] ? ' s="1"' : '',
					$strings[ $value ]
				);
			}

			// Give rows with images enough room, in points.
			$height = $has_images ? sprintf( ' ht="%s" customHeight="1"', ( $this->image_box + 2 * self::IMAGE_PADDING ) * 0.75 ) : '';
			$rows  .= sprintf( '<row r="%d"%s>%s</row>', $row_index + 1, $height, $cells );
		}

		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
			. ( $columns ? "<cols>$columns</cols>" : '' )
			. "<sheetData>$rows</sheetData>"
			. ( $images ? '<drawing r:id="rId1"/>' : '' )
			. '</worksheet>';
	}

	/**
	 * Get the details needed to anchor an image in a cell
	 *
	 * @param string $path
	 * @param int    $row
	 * @param int    $column
	 *
	 * @return array|null `null` when the file isn't a supported image.
	 */
	private function get_image( string $path, int $row, int $column ): ?array {
		$signatures = array(
			'png'  => "\x89PNG\r\n\x1a\n",
			'jpeg' => "\xFF\xD8\xFF",
			'gif'  => 'GIF8',
		);

		// Check the signature first, because getimagesize() raises a notice on files that aren't images.
		$header    = is_readable( $path ) ? (string) file_get_contents( $path, false, null, 0, 8 ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$extension = '';

		foreach ( $signatures as $type => $signature ) {
			if ( str_starts_with( $header, $signature ) ) {
				$extension = $type;
				break;
			}
		}

		if ( ! $extension ) {
			return null;
		}

		$size = getimagesize( $path );

		if ( ! $size || ! $size[0] || ! $size[1] ) {
			return null;
		}

		$scale = $this->image_box / max( $size[0], $size[1] );

		return array(
			'path'      => $path,
			'extension' => $extension,
			'row'       => $row,
			'column'    => $column,
			'width'     => (int) round( $size[0] * $scale ),
			'height'    => (int) round( $size[1] * $scale ),
		);
	}

	/**
	 * @param array $images
	 *
	 * @return string
	 */
	private function get_drawing_xml( array $images ): string {
		$anchors = '';
		$padding = self::IMAGE_PADDING * self::EMU_PER_PIXEL;

		foreach ( $images as $index => $image ) {
			$width  = $image['width'] * self::EMU_PER_PIXEL;
			$height = $image['height'] * self::EMU_PER_PIXEL;

			// Both corners sit in the same cell, so the picture moves and sizes with it.
			$anchors .= sprintf(
				'<xdr:twoCellAnchor>'
				. '<xdr:from><xdr:col>%1$d</xdr:col><xdr:colOff>%3$d</xdr:colOff><xdr:row>%2$d</xdr:row><xdr:rowOff>%3$d</xdr:rowOff></xdr:from>'
				. '<xdr:to><xdr:col>%1$d</xdr:col><xdr:colOff>%4$d</xdr:colOff><xdr:row>%2$d</xdr:row><xdr:rowOff>%5$d</xdr:rowOff></xdr:to>'
				. '<xdr:pic><xdr:nvPicPr><xdr:cNvPr id="%6$d" name="Picture %7$d"/><xdr:cNvPicPr><a:picLocks noChangeAspect="1"/></xdr:cNvPicPr></xdr:nvPicPr>'
				. '<xdr:blipFill><a:blip r:embed="rId%7$d"/><a:stretch><a:fillRect/></a:stretch></xdr:blipFill>'
				. '<xdr:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="%8$d" cy="%9$d"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></xdr:spPr></xdr:pic>'
				. '<xdr:clientData/></xdr:twoCellAnchor>',
				$image['column'],
				$image['row'],
				$padding,
				$padding + $width,
				$padding + $height,
				$index + 2,
				$index + 1,
				$width,
				$height
			);
		}

		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
			. $anchors
			. '</xdr:wsDr>';
	}

	/**
	 * @param array $strings Shared strings, keyed by their value.
	 *
	 * @return string
	 */
	private function get_shared_strings_xml( array $strings ): string {
		$items = '';

		foreach ( array_keys( $strings ) as $value ) {
			$items .= '<si><t xml:space="preserve">' . self::escape( (string) $value ) . '</t></si>';
		}

		return sprintf(
			'<?xml version="1.0" encoding="UTF-8" standalone="yes"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="%1$d" uniqueCount="%1$d">%2$s</sst>',
			count( $strings ),
			$items
		);
	}

	/**
	 * @param bool $has_pictures
	 *
	 * @return string
	 */
	private function get_content_types_xml( bool $has_pictures ): string {
		$type = 'application/vnd.openxmlformats-officedocument.';

		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
			. '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
			. '<Default Extension="xml" ContentType="application/xml"/>'
			. '<Default Extension="png" ContentType="image/png"/>'
			. '<Default Extension="jpeg" ContentType="image/jpeg"/>'
			. '<Default Extension="gif" ContentType="image/gif"/>'
			. '<Override PartName="/xl/workbook.xml" ContentType="' . $type . 'spreadsheetml.sheet.main+xml"/>'
			. '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="' . $type . 'spreadsheetml.worksheet+xml"/>'
			. '<Override PartName="/xl/styles.xml" ContentType="' . $type . 'spreadsheetml.styles+xml"/>'
			. '<Override PartName="/xl/sharedStrings.xml" ContentType="' . $type . 'spreadsheetml.sharedStrings+xml"/>'
			. ( $has_pictures ? '<Override PartName="/xl/drawings/drawing1.xml" ContentType="' . $type . 'drawing+xml"/>' : '' )
			. '</Types>';
	}

	/**
	 * @return string
	 */
	private function get_workbook_xml(): string {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
			. '<sheets><sheet name="Badges" sheetId="1" r:id="rId1"/></sheets>'
			. '</workbook>';
	}

	/**
	 * Get the styles, where style 0 is the default and style 1 is bold
	 *
	 * @return string
	 */
	private function get_styles_xml(): string {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
			. '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
			. '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
			. '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
			. '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
			. '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs>'
			. '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
			. '</styleSheet>';
	}

	/**
	 * @param array $relationships Each relationship's type and target, keyed by ID.
	 *
	 * @return string
	 */
	private function get_relationships_xml( array $relationships ): string {
		$items = '';

		foreach ( $relationships as $id => list( $type, $target ) ) {
			$items .= sprintf(
				'<Relationship Id="%s" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/%s" Target="%s"/>',
				$id,
				$type,
				self::escape( $target )
			);
		}

		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. $items
			. '</Relationships>';
	}

	/**
	 * Escape text for XML, dropping the control characters XML 1.0 doesn't allow
	 *
	 * @param string $text
	 *
	 * @return string
	 */
	private static function escape( string $text ): string {
		$text = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text );

		return htmlspecialchars( $text, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8' );
	}
}
