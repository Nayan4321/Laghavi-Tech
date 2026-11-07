<?php
/**
 * The three invoice templates, drawn as PDF.
 *
 * @package LaghaviLicenseManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Invoice PDF layouts.
 */
class LLM_Invoice_PDF {

	const M  = 42;
	const R  = 553.28;
	const CW = 511.28;

	/**
	 * Document.
	 *
	 * @var LLM_PDF
	 */
	private $pdf;

	/**
	 * View data.
	 *
	 * @var array
	 */
	private $d;

	/**
	 * Accent colour.
	 *
	 * @var string
	 */
	private $ac;

	/**
	 * Local path of the logo, or ''.
	 *
	 * @var string
	 */
	private $logo;

	/**
	 * Build a PDF.
	 *
	 * @param array  $d        View data (see LLM_Invoices::view_data()).
	 * @param string $template classic, bold or modern.
	 * @param string $color    Accent colour.
	 * @return string PDF bytes.
	 */
	public static function build( $d, $template, $color ) {
		$self        = new self();
		$self->d     = $d;
		$self->ac    = $color;
		$self->logo  = self::logo_path( $d['seller']['logo'] );
		$self->pdf   = new LLM_PDF();
		$self->pdf->title = $d['invoice']['number'];
		$self->pdf->add_page();
		$method = 'draw_' . ( in_array( $template, array( 'classic', 'bold', 'modern' ), true ) ? $template : 'classic' );
		$self->$method();
		return $self->pdf->output();
	}

	/**
	 * A logo address on this site as a file path.
	 *
	 * @param string $url Address.
	 * @return string
	 */
	private static function logo_path( $url ) {
		if ( '' === (string) $url ) {
			return '';
		}
		$id = attachment_url_to_postid( $url );
		if ( $id && get_attached_file( $id ) ) {
			return (string) get_attached_file( $id );
		}
		$up = wp_get_upload_dir();
		if ( 0 === strpos( $url, $up['baseurl'] ) ) {
			$path = $up['basedir'] . substr( $url, strlen( $up['baseurl'] ) );
			return is_readable( $path ) ? $path : '';
		}
		return '';
	}

	/*
	 * Shared pieces.
	 */

	/**
	 * Logo, or the company name when there is none.
	 *
	 * @param float $x Left.
	 * @param float $y Top.
	 * @return float Bottom of what was drawn.
	 */
	private function logo( $x, $y ) {
		if ( '' !== $this->logo && $this->pdf->image( $this->logo, $x, $y, 150, 54 ) ) {
			return $y + 54;
		}
		$this->pdf->fill( '#1d1f23' );
		$this->pdf->font( 'Helvetica-Bold', 16 );
		$this->pdf->paragraph( $x, $y + 16, 230, $this->d['seller']['name'], 19 );
		return $y + 40;
	}

	/**
	 * Lines of the seller's details.
	 *
	 * @param bool $with_name Include the name.
	 * @return array array( text, bold ).
	 */
	private function seller_lines( $with_name = true ) {
		$s     = $this->d['seller'];
		$lines = array();
		if ( $with_name ) {
			$lines[] = array( $s['name'], true );
		}
		foreach ( preg_split( '/\r\n|\r|\n/', trim( $s['address'] ) ) as $l ) {
			$lines[] = array( $l, false );
		}
		foreach ( array( 'email', 'phone', 'website' ) as $key ) {
			if ( '' !== (string) $s[ $key ] ) {
				$lines[] = array( $s[ $key ], false );
			}
		}
		if ( '' !== $s['trn'] ) {
			$lines[] = array( $s['tax_label'] . ': ' . $s['trn'], true );
		}
		return $lines;
	}

	/**
	 * Lines of the customer's details.
	 *
	 * @return array array( text, bold ).
	 */
	private function buyer_lines() {
		$b     = $this->d['buyer'];
		$lines = array( array( $b['name'], true ) );
		if ( '' !== $b['contact'] ) {
			$lines[] = array( $b['contact'], false );
		}
		foreach ( preg_split( '/\r\n|\r|\n/', trim( $b['address'] ) ) as $l ) {
			if ( '' !== trim( $l ) ) {
				$lines[] = array( $l, false );
			}
		}
		foreach ( array( 'email', 'phone' ) as $key ) {
			if ( '' !== (string) $b[ $key ] ) {
				$lines[] = array( $b[ $key ], false );
			}
		}
		if ( '' !== $b['trn'] ) {
			$lines[] = array( $this->d['seller']['tax_label'] . ': ' . $b['trn'], true );
		}
		return $lines;
	}

	/**
	 * Write a block of lines (wrapped) and return the y below it.
	 *
	 * @param array  $lines Lines from *_lines().
	 * @param float  $x     Position.
	 * @param float  $y     First baseline.
	 * @param float  $width Width.
	 * @param string $align L or R.
	 * @param float  $size  Font size.
	 * @return float
	 */
	private function block( $lines, $x, $y, $width, $align = 'L', $size = 9.5 ) {
		$this->pdf->fill( '#1d1f23' );
		foreach ( $lines as $line ) {
			$this->pdf->font( $line[1] ? 'Helvetica-Bold' : 'Helvetica', $size );
			$y = $this->pdf->paragraph( $x, $y, $width, $line[0], $size * 1.4, $align );
		}
		return $y;
	}

	/**
	 * Small caps-style label.
	 *
	 * @param float  $x     Position.
	 * @param float  $y     Baseline.
	 * @param string $text  Text.
	 * @param string $color Colour.
	 * @param string $align L or R.
	 */
	private function label( $x, $y, $text, $color = '#1d1f23', $align = 'L' ) {
		$this->pdf->fill( $color );
		$this->pdf->font( 'Helvetica-Bold', 8.5 );
		$this->pdf->text( $x, $y, function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $text ) : strtoupper( $text ), $align );
	}

	/**
	 * Column layout of the items table.
	 *
	 * @return array array( key, label, width, align ).
	 */
	private function columns( $labels ) {
		$cols = array( array( 'name', $labels[0], 0, 'L' ), array( 'qty', $labels[1], 46, 'R' ), array( 'unit', $labels[2], 86, 'R' ) );
		if ( $this->d['has_tax'] ) {
			$cols[] = array( 'tax', $labels[3], 74, 'R' );
		}
		$cols[]  = array( 'total', $labels[4], 90, 'R' );
		$fixed   = array_sum( array_column( $cols, 2 ) );
		$cols[0][2] = self::CW - $fixed;
		return $cols;
	}

	/**
	 * The items table. Callbacks draw the header row and each row's background.
	 *
	 * @param float    $y          Top.
	 * @param array    $cols       Columns.
	 * @param callable $header     function( $y ): float, draws the header and returns the y below it.
	 * @param callable $row_bg     function( $y, $h, $index ): void.
	 * @param callable $row_after  function( $y ): void, after each row (lines).
	 * @return float y below the table.
	 */
	private function items( $y, $cols, $header, $row_bg, $row_after ) {
		$pdf = $this->pdf;
		$y   = $header( $y );
		foreach ( $this->d['lines'] as $i => $line ) {
			$pdf->font( 'Helvetica', 9.5 );
			$name_lines = $pdf->wrap( $line['name'], $cols[0][2] - 12 );
			$pdf->font( 'Helvetica', 8 );
			$meta_lines = '' !== $line['meta'] ? $pdf->wrap( $line['meta'], $cols[0][2] - 12 ) : array();
			$h          = 12 + count( $name_lines ) * 13 + count( $meta_lines ) * 11 + 4;
			if ( $y + $h > 780 ) {
				$pdf->add_page();
				$y = $header( self::M );
			}
			$row_bg( $y, $h, $i );
			$pdf->fill( '#1d1f23' );
			$pdf->font( 'Helvetica', 9.5 );
			$ty = $y + 16;
			foreach ( $name_lines as $nl ) {
				$pdf->text( self::M + 6, $ty, $nl );
				$ty += 13;
			}
			$pdf->fill( '#6b7280' );
			$pdf->font( 'Helvetica', 8 );
			foreach ( $meta_lines as $ml ) {
				$pdf->text( self::M + 6, $ty - 2, $ml );
				$ty += 11;
			}
			$pdf->fill( '#1d1f23' );
			$pdf->font( 'Helvetica', 9.5 );
			$x = self::M + $cols[0][2];
			foreach ( array_slice( $cols, 1 ) as $col ) {
				$x += $col[2];
				$pdf->text( $x - 6, $y + 16, (string) $line[ $col[0] ], 'R' );
			}
			$y += $h;
			$row_after( $y );
		}
		return $y;
	}

	/**
	 * Header cells of the items table.
	 *
	 * @param array  $cols  Columns.
	 * @param float  $y     Baseline.
	 * @param string $color Text colour.
	 * @param bool   $upper Uppercase.
	 */
	private function header_cells( $cols, $y, $color, $upper ) {
		$this->pdf->fill( $color );
		$this->pdf->font( 'Helvetica-Bold', $upper ? 8.5 : 9.5 );
		$x = self::M;
		foreach ( $cols as $col ) {
			$label = $upper ? ( function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $col[1] ) : strtoupper( $col[1] ) ) : $col[1];
			if ( 'L' === $col[3] ) {
				$this->pdf->text( $x + 6, $y, $label );
			} else {
				$this->pdf->text( $x + $col[2] - 6, $y, $label, 'R' );
			}
			$x += $col[2];
		}
	}

	/**
	 * Start a new page when there is not enough room left.
	 *
	 * @param float $y      Current y.
	 * @param float $needed Room needed.
	 * @return float
	 */
	private function room( $y, $needed ) {
		if ( $y + $needed > 800 ) {
			$this->pdf->add_page();
			return self::M;
		}
		return $y;
	}

	/**
	 * Text for the payment info and note area.
	 *
	 * @param float  $x       Left.
	 * @param float  $y       Top baseline.
	 * @param float  $width   Width.
	 * @param string $title   Label.
	 * @param string $text    Text.
	 * @param string $color   Label colour.
	 * @return float
	 */
	private function note( $x, $y, $width, $title, $text, $color = '#1d1f23' ) {
		if ( '' === trim( (string) $text ) ) {
			return $y;
		}
		$this->label( $x, $y, $title, $color );
		$this->pdf->fill( '#1d1f23' );
		$this->pdf->font( 'Helvetica', 9.5 );
		return $this->pdf->paragraph( $x, $y + 15, $width, $text, 13.5 ) + 6;
	}

	/*
	 * Template 1: Classic.
	 */

	/**
	 * Draw the Classic template.
	 */
	private function draw_classic() {
		$pdf = $this->pdf;
		$d   = $this->d;
		$y   = $this->logo( self::M, self::M );
		$pdf->fill( '#1d1f23' );
		$pdf->font( 'Times-Roman', 38 );
		$pdf->text( self::R, self::M + 38, $d['title'], 'R' );

		$top = max( $y, self::M + 50 ) + 34;
		$this->label( self::M, $top, __( 'Billed to', 'laghavi-license-manager' ) );
		$yl = $this->block( $this->buyer_lines(), self::M, $top + 16, 260 );
		$pdf->fill( '#1d1f23' );
		$pdf->font( 'Helvetica', 9.5 );
		$pdf->text( self::R, $top, __( 'Invoice No.', 'laghavi-license-manager' ) . ' ' . $d['invoice']['number'], 'R' );
		$pdf->text( self::R, $top + 14, $d['date'], 'R' );
		$pdf->fill( '#6b7280' );
		/* translators: %s: order number. */
		$pdf->text( self::R, $top + 28, sprintf( __( 'Order #%s', 'laghavi-license-manager' ), $d['order']->get_order_number() ), 'R' );

		$cols = $this->columns( array( __( 'Item', 'laghavi-license-manager' ), __( 'Quantity', 'laghavi-license-manager' ), __( 'Unit price', 'laghavi-license-manager' ), __( 'Tax', 'laghavi-license-manager' ), __( 'Total', 'laghavi-license-manager' ) ) );
		$y    = $this->items(
			max( $yl, $top + 40 ) + 22,
			$cols,
			function ( $y ) use ( $cols, $pdf ) {
				$this->header_cells( $cols, $y + 14, '#1d1f23', false );
				$pdf->stroke( '#1d1f23' );
				$pdf->line( self::M, $y + 22, self::R, $y + 22, 1.4 );
				return $y + 22;
			},
			static function () {},
			static function ( $y ) use ( $pdf ) {
				$pdf->stroke( '#c9ccd2' );
				$pdf->line( self::M, $y, self::R, $y, 0.6 );
			}
		);

		$y  = $this->room( $y, 140 ) + 22;
		$x1 = 330;
		foreach ( $d['totals'] as $row ) {
			$pdf->fill( '#1d1f23' );
			$pdf->font( 'Helvetica-Bold', 9.5 );
			$pdf->text( $x1 + 14, $y, $row[0] );
			$pdf->font( 'Helvetica', 9.5 );
			$pdf->text( self::R - 6, $y, $row[1], 'R' );
			$y += 17;
		}
		$pdf->fill( $this->ac );
		$pdf->rect( $x1, $y - 4, LLM_PDF::W - $x1, 32 );
		$pdf->fill( '#ffffff' );
		$pdf->font( 'Helvetica-Bold', 14 );
		$pdf->text( $x1 + 14, $y + 17, __( 'Total', 'laghavi-license-manager' ) );
		$pdf->text( self::R - 6, $y + 17, $d['total'], 'R' );
		$pdf->fill( '#6b7280' );
		$pdf->font( 'Helvetica', 8.5 );
		$pdf->text( self::R - 6, $y + 44, $d['paid_text'], 'R' );
		$y += 70;

		if ( '' !== trim( $d['seller']['footer'] ) ) {
			$y = $this->room( $y, 50 );
			$pdf->fill( '#1d1f23' );
			$pdf->font( 'Times-Italic', 24 );
			$y = $pdf->paragraph( self::M, $y + 20, self::CW, $d['seller']['footer'], 28 ) + 8;
		}

		$y  = $this->room( $y, 110 ) + 10;
		$yl = $this->note( self::M, $y, 250, __( 'Payment information', 'laghavi-license-manager' ), $d['seller']['payment'] );
		$pdf->fill( '#1d1f23' );
		$pdf->font( 'Times-Roman', 15 );
		$pdf->text( self::R, $y + 2, $d['seller']['name'], 'R' );
		$this->block( $this->seller_lines( false ), self::R, $y + 18, 240, 'R', 9 );
		unset( $yl );
	}

	/*
	 * Template 2: Bold.
	 */

	/**
	 * Draw the Bold template.
	 */
	private function draw_bold() {
		$pdf = $this->pdf;
		$d   = $this->d;
		$ac  = $this->ac;
		$y   = $this->logo( self::M, self::M );
		$pdf->fill( '#111111' );
		$pdf->font( 'Helvetica-Bold', 26 );
		$pdf->text( self::R, self::M + 24, function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $d['title'] ) : strtoupper( $d['title'] ), 'R' );
		$kv = array(
			array( __( 'Invoice no.:', 'laghavi-license-manager' ), $d['invoice']['number'] ),
			array( __( 'Billing date:', 'laghavi-license-manager' ), $d['date'] ),
			array( __( 'Order:', 'laghavi-license-manager' ), '#' . $d['order']->get_order_number() ),
		);
		$ky = self::M + 44;
		foreach ( $kv as $pair ) {
			$pdf->font( 'Helvetica', 9 );
			$pdf->fill( '#1d1f23' );
			$pdf->text( self::R, $ky, $pair[1], 'R' );
			$this->label( self::R - 110, $ky, $pair[0], $ac, 'R' );
			$ky += 14;
		}

		$top  = max( $y, $ky ) + 26;
		$half = ( self::CW - 30 ) / 2;
		$this->label( self::M, $top, __( 'Our information', 'laghavi-license-manager' ), $ac );
		$this->label( self::M + $half + 30, $top, __( 'Billing to', 'laghavi-license-manager' ), $ac );
		$pdf->stroke( $ac );
		$pdf->line( self::M, $top + 7, self::M + $half, $top + 7, 0.8 );
		$pdf->line( self::M + $half + 30, $top + 7, self::R, $top + 7, 0.8 );
		$y1 = $this->block( $this->seller_lines(), self::M, $top + 24, $half );
		$y2 = $this->block( $this->buyer_lines(), self::M + $half + 30, $top + 24, $half );

		$cols = $this->columns( array( __( 'Product', 'laghavi-license-manager' ), __( 'Qty', 'laghavi-license-manager' ), __( 'Price', 'laghavi-license-manager' ), __( 'VAT', 'laghavi-license-manager' ), __( 'Total', 'laghavi-license-manager' ) ) );
		$y    = $this->items(
			max( $y1, $y2 ) + 20,
			$cols,
			function ( $y ) use ( $cols, $pdf, $ac ) {
				$this->header_cells( $cols, $y + 14, '#1d1f23', true );
				$pdf->stroke( $ac );
				$pdf->line( self::M, $y + 22, self::R, $y + 22, 1.4 );
				return $y + 25;
			},
			static function ( $y, $h ) use ( $pdf ) {
				$pdf->fill( '#f1f1f1' );
				$pdf->rect( self::M, $y, self::CW, $h - 3 );
			},
			static function () {}
		);

		$y   = $this->room( $y, 150 ) + 4;
		$tx  = self::R - 230;
		$ty0 = $y;
		$rows   = $d['totals'];
		$rows[] = array( __( 'Payment', 'laghavi-license-manager' ), (string) $d['order']->get_payment_method_title() );
		foreach ( $rows as $row ) {
			$pdf->fill( '#f1f1f1' );
			$pdf->rect( $tx, $y, 230, 22 );
			$pdf->fill( '#1d1f23' );
			$pdf->font( 'Helvetica-Bold', 9 );
			$pdf->text( $tx + 8, $y + 14.5, $row[0] );
			$pdf->font( 'Helvetica', 9 );
			$pdf->text( self::R - 8, $y + 14.5, $row[1], 'R' );
			$y += 25;
		}
		$pdf->fill( $ac );
		$pdf->rect( $tx, $y, 230, 26 );
		$pdf->fill( '#ffffff' );
		$pdf->font( 'Helvetica-Bold', 10.5 );
		$pdf->text( $tx + 8, $y + 17, $d['paid'] ? __( 'Total paid', 'laghavi-license-manager' ) : __( 'Total due', 'laghavi-license-manager' ) );
		$pdf->text( self::R - 8, $y + 17, $d['total'], 'R' );
		$y += 50;

		if ( $d['paid'] ) {
			$cx = self::M + 95;
			$cy = $ty0 + 40;
			$pdf->rotate( 14, $cx, $cy );
			$pdf->font( 'Helvetica-Bold', 26 );
			$w = $pdf->width( __( 'PAID', 'laghavi-license-manager' ) ) + 30;
			$pdf->stroke( $ac );
			$pdf->out_line_width( 3 );
			$pdf->rect( $cx - $w / 2, $cy - 20, $w, 40, 'S' );
			$pdf->fill( $ac );
			$pdf->text( $cx, $cy + 9, __( 'PAID', 'laghavi-license-manager' ), 'C' );
			$pdf->end_rotate();
		}

		$y = $this->room( $y, 90 );
		if ( '' !== trim( $d['seller']['payment'] ) ) {
			$this->label( self::M, $y, __( 'Payment information', 'laghavi-license-manager' ), $ac );
			$pdf->stroke( $ac );
			$pdf->line( self::M, $y + 7, self::R, $y + 7, 0.8 );
			$pdf->fill( '#1d1f23' );
			$pdf->font( 'Helvetica', 9.5 );
			$y = $pdf->paragraph( self::M, $y + 24, self::CW, $d['seller']['payment'], 13.5 ) + 10;
		}
		$pdf->fill( '#1d1f23' );
		$pdf->font( 'Helvetica', 8.5 );
		$pdf->paragraph( self::M, $y + 6, self::CW, $d['paid_text'] . ( '' !== trim( $d['seller']['footer'] ) ? ' · ' . $d['seller']['footer'] : '' ), 12 );
	}

	/*
	 * Template 3: Modern.
	 */

	/**
	 * Draw the Modern template.
	 */
	private function draw_modern() {
		$pdf = $this->pdf;
		$d   = $this->d;
		$ac  = $this->ac;
		$y   = $this->logo( self::M, self::M );
		$pdf->fill( '#111111' );
		$pdf->font( 'Helvetica-Bold', 22 );
		$pdf->text( self::R, self::M + 22, $d['title'], 'R' );
		$kv = array(
			array( __( 'Invoice no.:', 'laghavi-license-manager' ), $d['invoice']['number'] ),
			array( __( 'Invoice date:', 'laghavi-license-manager' ), $d['date'] ),
			array( __( 'Order:', 'laghavi-license-manager' ), '#' . $d['order']->get_order_number() ),
		);
		$ky = self::M + 42;
		foreach ( $kv as $pair ) {
			$pdf->font( 'Helvetica', 8.5 );
			$pdf->fill( '#1d1f23' );
			$pdf->text( self::R, $ky, $pair[1], 'R' );
			$pdf->fill( '#8a8f98' );
			$pdf->text( self::R - 110, $ky, $pair[0], 'R' );
			$ky += 13;
		}

		$top  = max( $y, $ky ) + 30;
		$half = ( self::CW - 30 ) / 2;
		$this->label( self::M, $top, __( 'From', 'laghavi-license-manager' ) );
		$this->label( self::R, $top, __( 'Bill to', 'laghavi-license-manager' ), '#1d1f23', 'R' );
		$y1 = $this->block( $this->seller_lines(), self::M, $top + 18, $half );
		$y2 = $this->block( $this->buyer_lines(), self::R, $top + 18, $half, 'R' );

		$cur  = $d['currency'];
		/* translators: %s: currency code. */
		$cols = $this->columns( array( __( 'Description', 'laghavi-license-manager' ), __( 'Qty', 'laghavi-license-manager' ), sprintf( __( 'Rate, %s', 'laghavi-license-manager' ), $cur ), __( 'Tax', 'laghavi-license-manager' ), sprintf( /* translators: %s: currency code. */ __( 'Amount, %s', 'laghavi-license-manager' ), $cur ) ) );
		$tint = LLM_PDF::tint( $ac, 0.07 );
		$y    = $this->items(
			max( $y1, $y2 ) + 22,
			$cols,
			function ( $y ) use ( $cols, $pdf, $ac ) {
				$pdf->fill( $ac );
				$pdf->rect( self::M, $y, self::CW, 24 );
				$this->header_cells( $cols, $y + 15.5, '#ffffff', true );
				return $y + 24;
			},
			static function ( $y, $h, $i ) use ( $pdf, $tint ) {
				if ( 1 === $i % 2 ) {
					$pdf->fill( $tint );
					$pdf->rect( self::M, $y, self::CW, $h );
				}
			},
			static function () {}
		);

		$y   = $this->room( $y, 170 ) + 26;
		$half = ( self::CW - 30 ) / 2;
		$tx  = self::M + $half + 30;
		$yl  = $this->note( self::M, $y, $half, __( 'Payment instructions', 'laghavi-license-manager' ), $d['seller']['payment'] );
		$this->note( self::M, $yl + 6, $half, __( 'Notes', 'laghavi-license-manager' ), $d['paid_text'] . ( '' !== trim( $d['seller']['footer'] ) ? "\n" . $d['seller']['footer'] : '' ) );

		$rows   = $d['totals'];
		$rows[] = array( __( 'Total', 'laghavi-license-manager' ), $d['total'], true );
		$rows[] = array( __( 'Amount paid', 'laghavi-license-manager' ), $d['paid'] ? $d['total'] : $d['due'] );
		$ty     = $y;
		foreach ( $rows as $row ) {
			$pdf->fill( '#1d1f23' );
			$pdf->font( ! empty( $row[2] ) ? 'Helvetica-Bold' : 'Helvetica', 9.5 );
			$pdf->text( $tx, $ty, $row[0] );
			$pdf->text( self::R, $ty, $row[1], 'R' );
			$ty += 17;
		}
		$pdf->fill( LLM_PDF::tint( $ac, 0.1 ) );
		$pdf->rect( $tx - 8, $ty - 4, self::R - $tx + 8, 30 );
		$pdf->stroke( $ac );
		$pdf->line( $tx - 8, $ty - 4, self::R, $ty - 4, 1.6 );
		$pdf->fill( '#1d1f23' );
		$pdf->font( 'Helvetica-Bold', 12 );
		$pdf->text( $tx, $ty + 15, __( 'Balance due', 'laghavi-license-manager' ) );
		$pdf->text( self::R - 6, $ty + 15, $d['due'], 'R' );
	}
}
