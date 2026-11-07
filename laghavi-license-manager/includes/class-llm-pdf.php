<?php
/**
 * A small PDF writer for invoices: A4 pages, the standard PDF fonts (Helvetica, Times), text with wrapping
 * and alignment, lines, boxes, colours, rotation and JPEG/PNG images. No external library needed.
 *
 * @package LaghaviLicenseManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Minimal PDF document.
 */
class LLM_PDF {

	const W = 595.28;
	const H = 841.89;

	/**
	 * Character widths (1/1000 em) for codes 32-255 in Windows-1252.
	 *
	 * @var array<string, string>
	 */
	private static $widths = array(
		'Helvetica' => '278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500,334,260,334,584,0,556,0,222,556,333,1000,556,556,333,1000,667,333,1000,0,611,0,0,222,222,333,333,350,556,1000,333,1000,500,333,944,0,500,667,278,333,556,556,556,556,260,556,333,737,370,556,584,333,737,552,400,549,333,333,333,576,537,333,333,333,365,556,834,834,834,611,667,667,667,667,667,667,1000,722,667,667,667,667,278,278,278,278,722,722,778,778,778,778,778,584,778,722,722,722,722,667,667,611,556,556,556,556,556,556,889,500,556,556,556,556,278,278,278,278,556,556,556,556,556,556,556,549,611,556,556,556,556,500,556,500',
		'Helvetica-Bold' => '278,333,474,556,556,889,722,238,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,333,333,584,584,584,611,975,722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,667,778,722,667,611,722,667,944,667,667,611,333,278,333,584,556,333,556,611,556,611,556,333,611,611,278,278,556,278,889,611,611,611,611,389,556,333,611,556,778,556,556,500,389,280,389,584,0,556,0,278,556,500,1000,556,556,333,1000,667,333,1000,0,611,0,0,278,278,500,500,350,556,1000,333,1000,556,333,944,0,500,667,278,333,556,556,556,556,280,556,333,737,370,556,584,333,737,552,400,549,333,333,333,576,556,333,333,333,365,556,834,834,834,611,722,722,722,722,722,722,1000,722,667,667,667,667,278,278,278,278,722,722,778,778,778,778,778,584,778,722,722,722,722,667,667,611,556,556,556,556,556,556,889,556,556,556,556,556,278,278,278,278,611,611,611,611,611,611,611,549,611,611,611,611,611,556,611,556',
		'Times-Roman' => '250,333,408,500,500,833,778,180,333,333,500,564,250,333,250,278,500,500,500,500,500,500,500,500,500,500,278,278,564,564,564,444,921,722,667,667,722,611,556,722,722,333,389,722,611,889,722,722,556,722,667,556,611,722,722,944,722,722,611,333,278,333,469,500,333,444,500,444,500,444,333,500,500,278,278,500,278,778,500,500,500,500,333,389,278,500,500,722,500,500,444,480,200,480,541,0,500,0,333,500,444,1000,500,500,333,1000,556,333,889,0,611,0,0,333,333,444,444,350,500,1000,333,980,389,333,722,0,444,722,250,333,500,500,500,500,200,500,333,760,276,500,564,333,760,500,400,549,300,300,333,576,453,333,333,300,310,500,750,750,750,444,722,722,722,722,722,722,889,667,611,611,611,611,333,333,333,333,722,722,722,722,722,722,722,564,722,722,722,722,722,722,556,500,444,444,444,444,444,444,667,444,444,444,444,444,278,278,278,278,500,500,500,500,500,500,500,549,500,500,500,500,500,500,500,500',
		'Times-Italic' => '250,333,420,500,500,833,778,214,333,333,500,675,250,333,250,278,500,500,500,500,500,500,500,500,500,500,333,333,675,675,675,500,920,611,611,667,722,611,611,722,722,333,444,667,556,833,667,722,611,722,611,500,556,722,611,833,611,556,556,389,278,389,422,500,333,500,500,444,500,444,278,500,500,278,278,444,278,722,500,500,500,500,389,389,278,500,444,667,444,444,389,400,275,400,541,0,500,0,333,500,556,889,500,500,333,1000,500,333,944,0,556,0,0,333,333,556,556,350,500,889,333,980,389,333,667,0,389,556,250,389,500,500,500,500,275,500,333,760,276,500,675,333,760,500,400,549,300,300,333,576,523,250,333,300,310,500,750,750,750,500,611,611,611,611,611,611,889,667,611,611,611,611,333,333,333,333,722,667,722,722,722,722,722,675,722,722,722,722,722,556,611,500,500,500,500,500,500,500,667,444,444,444,444,444,278,278,278,278,500,500,500,500,500,500,500,549,500,500,500,500,500,444,500,444',
	);

	/**
	 * PDF resource names of the fonts.
	 *
	 * @var array<string, string>
	 */
	private static $font_ids = array(
		'Helvetica'      => 'F1',
		'Helvetica-Bold' => 'F2',
		'Times-Roman'    => 'F3',
		'Times-Italic'   => 'F4',
	);

	/**
	 * Page content streams.
	 *
	 * @var string[]
	 */
	private $pages = array();

	/**
	 * Images: name => array( data, width, height ).
	 *
	 * @var array
	 */
	private $images = array();

	/**
	 * Current font.
	 *
	 * @var string
	 */
	private $font = 'Helvetica';

	/**
	 * Current size.
	 *
	 * @var float
	 */
	private $size = 10;

	/**
	 * Parsed widths cache.
	 *
	 * @var array
	 */
	private $cache = array();

	/**
	 * Document title.
	 *
	 * @var string
	 */
	public $title = '';

	/**
	 * Start a new page.
	 */
	public function add_page() {
		$this->pages[] = '';
	}

	/**
	 * Append to the current page.
	 *
	 * @param string $ops PDF operators.
	 */
	private function out( $ops ) {
		$this->pages[ count( $this->pages ) - 1 ] .= $ops . "\n";
	}

	/**
	 * Number with at most 2 decimals.
	 *
	 * @param float $n Number.
	 * @return string
	 */
	private static function n( $n ) {
		return rtrim( rtrim( number_format( (float) $n, 2, '.', '' ), '0' ), '.' );
	}

	/**
	 * Text in Windows-1252, the encoding of the standard fonts. Characters it cannot hold are left out.
	 *
	 * @param string $text UTF-8 text.
	 * @return string
	 */
	public static function encode( $text ) {
		$text = str_replace( array( "\u{2212}", "\u{2010}", "\u{00A0}", "\u{202F}" ), array( '-', '-', ' ', ' ' ), (string) $text );
		if ( function_exists( 'iconv' ) ) {
			$out = @iconv( 'UTF-8', 'CP1252//TRANSLIT//IGNORE', $text ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Characters outside the font are dropped on purpose.
			if ( false !== $out ) {
				return $out;
			}
		}
		return (string) mb_convert_encoding( $text, 'ISO-8859-1', 'UTF-8' );
	}

	/**
	 * Whether a text can be shown in full with the standard fonts.
	 *
	 * @param string $text UTF-8 text.
	 * @return bool
	 */
	public static function can_encode( $text ) {
		return function_exists( 'iconv' ) && false !== @iconv( 'UTF-8', 'CP1252', (string) $text ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A failed conversion is the answer.
	}

	/**
	 * Choose the font.
	 *
	 * @param string $font Helvetica, Helvetica-Bold, Times-Roman or Times-Italic.
	 * @param float  $size Size in points.
	 */
	public function font( $font, $size ) {
		$this->font = isset( self::$font_ids[ $font ] ) ? $font : 'Helvetica';
		$this->size = (float) $size;
	}

	/**
	 * Width of a text in the current font, in points.
	 *
	 * @param string $text UTF-8 text.
	 * @return float
	 */
	public function width( $text ) {
		if ( ! isset( $this->cache[ $this->font ] ) ) {
			$this->cache[ $this->font ] = array_map( 'intval', explode( ',', self::$widths[ $this->font ] ) );
		}
		$w     = 0;
		$bytes = self::encode( $text );
		$len   = strlen( $bytes );
		for ( $i = 0; $i < $len; $i++ ) {
			$c  = ord( $bytes[ $i ] );
			$w += $c >= 32 ? $this->cache[ $this->font ][ $c - 32 ] : 0;
		}
		return $w * $this->size / 1000;
	}

	/**
	 * Fill colour.
	 *
	 * @param string $hex Colour like #1a6cf0.
	 */
	public function fill( $hex ) {
		$this->out( self::rgb( $hex ) . ' rg' );
	}

	/**
	 * Line colour.
	 *
	 * @param string $hex Colour.
	 */
	public function stroke( $hex ) {
		$this->out( self::rgb( $hex ) . ' RG' );
	}

	/**
	 * "r g b" from a hex colour.
	 *
	 * @param string $hex Colour.
	 * @return string
	 */
	public static function rgb( $hex ) {
		$hex = ltrim( (string) $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( ! preg_match( '/^[0-9a-fA-F]{6}$/', $hex ) ) {
			$hex = '000000';
		}
		return self::n( hexdec( substr( $hex, 0, 2 ) ) / 255 ) . ' ' . self::n( hexdec( substr( $hex, 2, 2 ) ) / 255 ) . ' ' . self::n( hexdec( substr( $hex, 4, 2 ) ) / 255 );
	}

	/**
	 * Mix a colour with white.
	 *
	 * @param string $hex    Colour.
	 * @param float  $amount Share of the colour (0-1).
	 * @return string Hex colour.
	 */
	public static function tint( $hex, $amount ) {
		$hex = ltrim( (string) $hex, '#' );
		$out = '#';
		for ( $i = 0; $i < 3; $i++ ) {
			$c    = hexdec( substr( $hex, $i * 2, 2 ) );
			$out .= str_pad( dechex( (int) round( 255 - ( 255 - $c ) * $amount ) ), 2, '0', STR_PAD_LEFT );
		}
		return $out;
	}

	/**
	 * Write one line of text. y is measured from the top of the page, at the text's baseline.
	 *
	 * @param float  $x     Left (or right/centre, see $align).
	 * @param float  $y     Baseline from the top.
	 * @param string $text  UTF-8 text.
	 * @param string $align L, R or C.
	 */
	public function text( $x, $y, $text, $align = 'L' ) {
		if ( '' === (string) $text ) {
			return;
		}
		if ( 'R' === $align ) {
			$x -= $this->width( $text );
		} elseif ( 'C' === $align ) {
			$x -= $this->width( $text ) / 2;
		}
		$s = str_replace( array( '\\', '(', ')', "\r", "\n" ), array( '\\\\', '\\(', '\\)', '', ' ' ), self::encode( $text ) );
		$this->out( 'BT /' . self::$font_ids[ $this->font ] . ' ' . self::n( $this->size ) . ' Tf ' . self::n( $x ) . ' ' . self::n( self::H - $y ) . ' Td (' . $s . ') Tj ET' );
	}

	/**
	 * Split text into lines that fit a width (existing line breaks are kept).
	 *
	 * @param string $text  UTF-8 text.
	 * @param float  $width Maximum width.
	 * @return string[]
	 */
	public function wrap( $text, $width ) {
		$lines = array();
		foreach ( preg_split( '/\r\n|\r|\n/', trim( (string) $text ) ) as $paragraph ) {
			$line = '';
			foreach ( preg_split( '/ +/', $paragraph ) as $word ) {
				$try = '' === $line ? $word : $line . ' ' . $word;
				if ( '' !== $line && $this->width( $try ) > $width ) {
					$lines[] = $line;
					$line    = $word;
				} else {
					$line = $try;
				}
			}
			$lines[] = $line;
		}
		return $lines;
	}

	/**
	 * Write wrapped text and return the y below it.
	 *
	 * @param float  $x       Position (see $align).
	 * @param float  $y       First baseline from the top.
	 * @param float  $width   Maximum width.
	 * @param string $text    UTF-8 text.
	 * @param float  $leading Line height.
	 * @param string $align   L, R or C.
	 * @return float
	 */
	public function paragraph( $x, $y, $width, $text, $leading = 0, $align = 'L' ) {
		$leading = $leading ? $leading : $this->size * 1.35;
		foreach ( $this->wrap( $text, $width ) as $line ) {
			$this->text( $x, $y, $line, $align );
			$y += $leading;
		}
		return $y;
	}

	/**
	 * Rectangle (top-left corner, measured from the top).
	 *
	 * @param float  $x    Left.
	 * @param float  $y    Top.
	 * @param float  $w    Width.
	 * @param float  $h    Height.
	 * @param string $mode F (fill), S (stroke) or B (both).
	 */
	public function rect( $x, $y, $w, $h, $mode = 'F' ) {
		$this->out( self::n( $x ) . ' ' . self::n( self::H - $y - $h ) . ' ' . self::n( $w ) . ' ' . self::n( $h ) . ' re ' . ( 'S' === $mode ? 'S' : ( 'B' === $mode ? 'B' : 'f' ) ) );
	}

	/**
	 * Straight line.
	 *
	 * @param float $x1    Start x.
	 * @param float $y1    Start y (from the top).
	 * @param float $x2    End x.
	 * @param float $y2    End y.
	 * @param float $width Line width.
	 */
	public function line( $x1, $y1, $x2, $y2, $width = 0.75 ) {
		$this->out( self::n( $width ) . ' w ' . self::n( $x1 ) . ' ' . self::n( self::H - $y1 ) . ' m ' . self::n( $x2 ) . ' ' . self::n( self::H - $y2 ) . ' l S' );
	}

	/**
	 * Line width for the next shapes.
	 *
	 * @param float $width Width in points.
	 */
	public function out_line_width( $width ) {
		$this->out( self::n( $width ) . ' w' );
	}

	/**
	 * Start drawing rotated around a point (end with end_rotate()).
	 *
	 * @param float $degrees Angle, counter-clockwise.
	 * @param float $x       Centre x.
	 * @param float $y       Centre y (from the top).
	 */
	public function rotate( $degrees, $x, $y ) {
		$a  = deg2rad( $degrees );
		$cy = self::H - $y;
		$c  = cos( $a );
		$s  = sin( $a );
		$this->out( 'q ' . self::n( $c ) . ' ' . self::n( $s ) . ' ' . self::n( -$s ) . ' ' . self::n( $c ) . ' ' . self::n( $x - $c * $x + $s * $cy ) . ' ' . self::n( $cy - $s * $x - $c * $cy ) . ' cm' );
	}

	/**
	 * End a rotation.
	 */
	public function end_rotate() {
		$this->out( 'Q' );
	}

	/**
	 * Draw an image (JPEG, PNG, GIF or WebP; others are skipped) inside a box, keeping its proportions.
	 *
	 * @param string $file  Local file path.
	 * @param float  $x     Left.
	 * @param float  $y     Top.
	 * @param float  $max_w Box width.
	 * @param float  $max_h Box height.
	 * @param string $align L or R inside the box.
	 * @return float Width drawn (0 when skipped).
	 */
	public function image( $file, $x, $y, $max_w, $max_h, $align = 'L' ) {
		$jpeg = self::jpeg( $file );
		if ( ! $jpeg ) {
			return 0;
		}
		list( $data, $iw, $ih ) = $jpeg;
		$scale                  = min( $max_w / $iw, $max_h / $ih );
		$w                      = $iw * $scale;
		$h                      = $ih * $scale;
		if ( 'R' === $align ) {
			$x += $max_w - $w;
		}
		$name                  = 'Im' . ( count( $this->images ) + 1 );
		$this->images[ $name ] = array( $data, $iw, $ih );
		$this->out( 'q ' . self::n( $w ) . ' 0 0 ' . self::n( $h ) . ' ' . self::n( $x ) . ' ' . self::n( self::H - $y - $h ) . ' cm /' . $name . ' Do Q' );
		return $w;
	}

	/**
	 * An image file as JPEG data (other formats are converted on a white background).
	 *
	 * @param string $file File.
	 * @return array|null array( data, width, height ).
	 */
	private static function jpeg( $file ) {
		if ( ! $file || ! is_readable( $file ) ) {
			return null;
		}
		$info = @getimagesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Unreadable images are skipped.
		if ( ! $info ) {
			return null;
		}
		if ( IMAGETYPE_JPEG === $info[2] && ( ! isset( $info['channels'] ) || 3 === (int) $info['channels'] ) ) {
			return array( (string) file_get_contents( $file ), $info[0], $info[1] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
		}
		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			return null;
		}
		$loaders = array(
			IMAGETYPE_PNG  => 'imagecreatefrompng',
			IMAGETYPE_GIF  => 'imagecreatefromgif',
			IMAGETYPE_JPEG => 'imagecreatefromjpeg',
		);
		if ( defined( 'IMAGETYPE_WEBP' ) ) {
			$loaders[ IMAGETYPE_WEBP ] = 'imagecreatefromwebp';
		}
		if ( ! isset( $loaders[ $info[2] ] ) || ! function_exists( $loaders[ $info[2] ] ) ) {
			return null;
		}
		$src = @call_user_func( $loaders[ $info[2] ], $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Broken images are skipped.
		if ( ! $src ) {
			return null;
		}
		$w   = imagesx( $src );
		$h   = imagesy( $src );
		$dst = imagecreatetruecolor( $w, $h );
		imagefill( $dst, 0, 0, imagecolorallocate( $dst, 255, 255, 255 ) );
		imagecopy( $dst, $src, 0, 0, 0, 0, $w, $h );
		ob_start();
		imagejpeg( $dst, null, 92 );
		$data = (string) ob_get_clean();
		imagedestroy( $src );
		imagedestroy( $dst );
		return array( $data, $w, $h );
	}

	/**
	 * The finished PDF file.
	 *
	 * @return string
	 */
	public function output() {
		$objects = array();
		$add     = static function ( $body ) use ( &$objects ) {
			$objects[] = $body;
			return count( $objects );
		};

		$catalog = $add( '' );
		$pages   = $add( '' );
		$fonts   = array();
		foreach ( self::$font_ids as $name => $id ) {
			$fonts[ $id ] = $add( '<< /Type /Font /Subtype /Type1 /BaseFont /' . $name . ' /Encoding /WinAnsiEncoding >>' );
		}
		$xobjects = array();
		foreach ( $this->images as $name => $img ) {
			$xobjects[ $name ] = $add( "<< /Type /XObject /Subtype /Image /Width {$img[1]} /Height {$img[2]} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length " . strlen( $img[0] ) . " >>\nstream\n" . $img[0] . "\nendstream" );
		}
		$res = '<< /Font << ';
		foreach ( $fonts as $id => $num ) {
			$res .= '/' . $id . ' ' . $num . ' 0 R ';
		}
		$res .= '>>';
		if ( $xobjects ) {
			$res .= ' /XObject << ';
			foreach ( $xobjects as $name => $num ) {
				$res .= '/' . $name . ' ' . $num . ' 0 R ';
			}
			$res .= '>>';
		}
		$res .= ' >>';

		$kids = array();
		foreach ( $this->pages as $content ) {
			$stream = function_exists( 'gzcompress' ) ? gzcompress( $content ) : $content;
			$filter = function_exists( 'gzcompress' ) ? ' /Filter /FlateDecode' : '';
			$cnum   = $add( '<< /Length ' . strlen( $stream ) . $filter . " >>\nstream\n" . $stream . "\nendstream" );
			$kids[] = $add( '<< /Type /Page /Parent ' . $pages . ' 0 R /MediaBox [0 0 ' . self::n( self::W ) . ' ' . self::n( self::H ) . '] /Resources ' . $res . ' /Contents ' . $cnum . ' 0 R >>' );
		}
		$objects[ $pages - 1 ]   = '<< /Type /Pages /Kids [' . implode( ' ', array_map( static function ( $k ) {
			return $k . ' 0 R';
		}, $kids ) ) . '] /Count ' . count( $kids ) . ' >>';
		$objects[ $catalog - 1 ] = '<< /Type /Catalog /Pages ' . $pages . ' 0 R >>';
		$info                    = $add( '<< /Title (' . str_replace( array( '\\', '(', ')' ), array( '\\\\', '\\(', '\\)' ), self::encode( $this->title ) ) . ') /Producer (Laghavi) /CreationDate (D:' . gmdate( 'YmdHis' ) . 'Z) >>' );

		$pdf     = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
		$offsets = array();
		foreach ( $objects as $i => $body ) {
			$offsets[ $i ] = strlen( $pdf );
			$pdf          .= ( $i + 1 ) . " 0 obj\n" . $body . "\nendobj\n";
		}
		$xref = strlen( $pdf );
		$pdf .= "xref\n0 " . ( count( $objects ) + 1 ) . "\n0000000000 65535 f \n";
		foreach ( $offsets as $offset ) {
			$pdf .= sprintf( "%010d 00000 n \n", $offset );
		}
		$pdf .= 'trailer << /Size ' . ( count( $objects ) + 1 ) . ' /Root ' . $catalog . ' 0 R /Info ' . $info . " 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
		return $pdf;
	}
}
