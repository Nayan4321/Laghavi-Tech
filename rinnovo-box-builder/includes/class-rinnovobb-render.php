<?php
/**
 * The builder on the product page, in the [rinnovo_box_builder] shortcode, the block and page builder widgets.
 * Layouts only change how the same fields look, so switching layout never loses data.
 *
 * @package RinnovoBoxBuilder
 */

defined( 'ABSPATH' ) || exit;

/**
 * Front-end builder.
 */
class RINNOVOBB_Render {

	const TD = 'rinnovo-box-builder';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'woocommerce_before_add_to_cart_button', array( __CLASS__, 'product_page' ), 5 );
		add_shortcode( 'rinnovo_box_builder', array( __CLASS__, 'shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register' ) );
		add_action( 'init', array( __CLASS__, 'block' ) );
	}

	/**
	 * Styles and script (loaded when a builder is shown).
	 */
	public static function register() {
		wp_register_style( 'rinnovobb', RINNOVOBB_URL . 'assets/css/front.css', array(), RINNOVOBB_VERSION );
		wp_register_script( 'rinnovobb', RINNOVOBB_URL . 'assets/js/front.js', array(), RINNOVOBB_VERSION, true );
		wp_localize_script(
			'rinnovobb',
			'rinnovobbL10n',
			array(
				'currency' => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
				'decimals' => wc_get_price_decimals(),
				'dec'      => wc_get_price_decimal_separator(),
				'thousand' => wc_get_price_thousand_separator(),
				'format'   => get_woocommerce_price_format(),
				'empty'    => __( 'Nothing chosen yet.', self::TD ),
				/* translators: 1: chosen, 2: maximum. */
				'count'    => __( '%1$d of %2$d chosen', self::TD ),
				'next'     => __( 'Next', self::TD ),
				'back'     => __( 'Back', self::TD ),
			)
		);
	}

	/**
	 * Builder above the add to cart button.
	 */
	public static function product_page() {
		global $product;
		if ( ! $product instanceof WC_Product || ! RINNOVOBB_Config::active( $product ) ) {
			return;
		}
		$config = RINNOVOBB_Config::get( $product );
		if ( 'fixed' === $config['mode'] && ! $config['fixed'] ) {
			return;
		}
		// Keep what the customer chose when the page reloads with an error.
		$sel = null;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Add to cart has no nonce; read() cleans every value.
		if ( isset( $_POST['rinnovobb'] ) && is_array( $_POST['rinnovobb'] ) ) {
			$sel = RINNOVOBB_Engine::read( $config, wp_unslash( $_POST['rinnovobb'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}
		echo self::form( $product, $config, $sel ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped while built.
	}

	/**
	 * [rinnovo_box_builder product="123"]: the builder with its add to cart button anywhere.
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public static function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'product' => 0,
				'layout'  => '',
			),
			$atts,
			'rinnovo_box_builder'
		);
		$p    = wc_get_product( absint( $atts['product'] ) ? absint( $atts['product'] ) : get_the_ID() );
		if ( ! $p || ! RINNOVOBB_Config::active( $p ) ) {
			return current_user_can( 'edit_products' ) ? '<p>' . esc_html__( 'Box builder: choose a product that has a box builder (Product data › Box builder).', self::TD ) . '</p>' : '';
		}
		if ( '' !== $atts['layout'] && isset( RINNOVOBB_Config::layouts()[ $atts['layout'] ] ) ) {
			$force = sanitize_key( $atts['layout'] );
			add_filter(
				'rinnovobb_config',
				$cb = static function ( $c ) use ( $force ) {
					$c['layout']['type'] = $force;
					return $c;
				}
			);
		}
		global $product;
		$before  = $product;
		$product = $p;
		ob_start();
		echo '<div class="woocommerce rinnovobb-shortcode">';
		woocommerce_template_single_add_to_cart();
		echo '</div>';
		$product = $before;
		if ( isset( $cb ) ) {
			remove_filter( 'rinnovobb_config', $cb );
		}
		return (string) ob_get_clean();
	}

	/**
	 * Block for the block editor (server rendered).
	 */
	public static function block() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		wp_register_script( 'rinnovobb-block', RINNOVOBB_URL . 'assets/js/block.js', array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-server-side-render' ), RINNOVOBB_VERSION, true );
		register_block_type(
			'rinnovo/box-builder',
			array(
				'api_version'     => 2,
				'editor_script'   => 'rinnovobb-block',
				'attributes'      => array(
					'product' => array(
						'type'    => 'number',
						'default' => 0,
					),
					'layout'  => array(
						'type'    => 'string',
						'default' => '',
					),
				),
				'render_callback' => static function ( $attrs ) {
					return self::shortcode(
						array(
							'product' => isset( $attrs['product'] ) ? (int) $attrs['product'] : 0,
							'layout'  => isset( $attrs['layout'] ) ? (string) $attrs['layout'] : '',
						)
					);
				},
			)
		);
	}

	/**
	 * Price HTML for an item.
	 *
	 * @param float $amount Amount.
	 * @return string
	 */
	private static function money( $amount ) {
		return wp_kses_post( wc_price( rinnovo_convert_price( $amount ) ) );
	}

	/**
	 * The builder fields.
	 *
	 * @param WC_Product $product Box product.
	 * @param array      $config  Configuration.
	 * @param array|null $sel     Selection to show (a saved box), or null for the defaults.
	 * @param array      $args    name: field name prefix; context: product|account.
	 * @return string
	 */
	public static function form( $product, $config, $sel = null, $args = array() ) {
		wp_enqueue_style( 'rinnovobb' );
		wp_enqueue_script( 'rinnovobb' );
		$args   = wp_parse_args(
			$args,
			array(
				'name'    => 'rinnovobb',
				'context' => 'product',
				'time'    => 0,
			)
		);
		$sel    = is_array( $sel ) ? $sel : array();
		$sel    = wp_parse_args(
			$sel,
			array(
				'items' => array(),
				'prefs' => array(),
			)
		);
		$layout = $config['layout'];
		$parts  = RINNOVOBB_Config::parts( $config['mode'] );
		$type   = $layout['type'];
		$base   = 'product' === $config['pricing'] ? (float) $product->get_price( 'edit' ) : ( 'extras' === $config['pricing'] ? (float) $product->get_price( 'edit' ) : 0.0 );

		$html  = '<div class="rinnovobb rinnovobb--' . esc_attr( $type ) . '" data-pricing="' . esc_attr( $config['pricing'] ) . '" data-base="' . esc_attr( (string) rinnovo_convert_price( $base ) ) . '" data-max="' . (int) $config['rules']['max'] . '" data-min="' . (int) $config['rules']['min'] . '" style="--rinnovobb-cols:' . (int) $layout['columns'] . '">';
		if ( '' !== $layout['intro'] ) {
			$html .= '<div class="rinnovobb-intro">' . wp_kses_post( wpautop( $layout['intro'] ) ) . '</div>';
		}
		if ( 'wizard' === $type ) {
			$html .= '<ol class="rinnovobb-steps"></ol>';
		}
		foreach ( $layout['order'] as $section ) {
			if ( in_array( $section, $layout['hidden'], true ) ) {
				continue;
			}
			if ( 'summary' !== $section && ! in_array( $section, $parts, true ) ) {
				continue;
			}
			$inner = '';
			switch ( $section ) {
				case 'fixed':
					$inner = self::fixed( $config );
					break;
				case 'schedule':
					$inner = self::schedule( $config, $args['time'] );
					break;
				case 'groups':
					$inner = self::groups( $config, $sel, $args['name'] );
					break;
				case 'fields':
					$inner = self::fields( $config, $sel, $args['name'] );
					break;
				case 'summary':
					$inner = $layout['total'] || 'product' !== $config['pricing'] ? self::summary( $config ) : '';
					break;
			}
			if ( '' === $inner ) {
				continue;
			}
			$html .= '<section class="rinnovobb-section rinnovobb-section--' . esc_attr( $section ) . '" data-step="' . esc_attr( RINNOVOBB_Config::label( $config, $section ) ) . '"><h3 class="rinnovobb-title">' . esc_html( RINNOVOBB_Config::label( $config, $section ) ) . '</h3>' . $inner . '</section>';
		}
		$html .= '<input type="hidden" name="' . esc_attr( $args['name'] ) . '[on]" value="1" />';
		$html .= '</div>';
		return $html;
	}

	/**
	 * Items always in the box.
	 *
	 * @param array $config Configuration.
	 * @return string
	 */
	private static function fixed( $config ) {
		if ( ! $config['fixed'] ) {
			return '';
		}
		$html = '<ul class="rinnovobb-fixed">';
		foreach ( $config['fixed'] as $line ) {
			$p = wc_get_product( $line[1] ? $line[1] : $line[0] );
			if ( $p ) {
				$price = RINNOVOBB_Engine::item_price( $config, $p );
				$html .= '<li data-name="' . esc_attr( wp_strip_all_tags( $p->get_name() ) ) . '" data-qty="' . (int) $line[2] . '" data-price="' . esc_attr( (string) rinnovo_convert_price( $price['price'] ) ) . '" data-extra="' . esc_attr( (string) rinnovo_convert_price( $price['extra'] ) ) . '" data-fixed="1">' . ( $config['layout']['images'] ? $p->get_image( 'thumbnail' ) : '' ) . '<span>' . (int) $line[2] . ' × ' . esc_html( $p->get_name() ) . '</span></li>';
			}
		}
		return $html . '</ul>';
	}

	/**
	 * The menu of the coming weeks, day by day.
	 *
	 * @param array $config Configuration.
	 * @param int   $time   From.
	 * @return string
	 */
	private static function schedule( $config, $time ) {
		if ( ! $config['schedule']['weeks'] ) {
			return '';
		}
		$time  = $time ? $time : time();
		$days  = RINNOVOBB_Config::days();
		$shown = min( 2, count( $config['schedule']['weeks'] ) );
		$html  = '';
		for ( $w = 0; $w < $shown; $w++ ) {
			$at   = $time + $w * WEEK_IN_SECONDS;
			$i    = RINNOVOBB_Engine::week_index( $config, $at );
			$week = $config['schedule']['weeks'][ $i ];
			/* translators: 1: week name, 2: date. */
			$html .= '<div class="rinnovobb-week"><h4>' . esc_html( sprintf( __( '%1$s (week of %2$s)', self::TD ), $week['name'], wp_date( get_option( 'date_format' ), $at - ( (int) wp_date( 'N', $at ) - 1 ) * DAY_IN_SECONDS ) ) ) . '</h4><div class="rinnovobb-days">';
			foreach ( $config['schedule']['days'] as $day ) {
				$html .= '<div class="rinnovobb-day"><strong>' . esc_html( $days[ $day ] ) . '</strong><ul>';
				foreach ( $week['days'][ $day ] as $line ) {
					$p = wc_get_product( $line[1] ? $line[1] : $line[0] );
					if ( $p ) {
						$price = RINNOVOBB_Engine::item_price( $config, $p );
						$html .= '<li' . ( 0 === $w ? ' data-name="' . esc_attr( $days[ $day ] . ': ' . wp_strip_all_tags( $p->get_name() ) ) . '" data-qty="' . (int) $line[2] . '" data-price="' . esc_attr( (string) rinnovo_convert_price( $price['price'] ) ) . '" data-extra="' . esc_attr( (string) rinnovo_convert_price( $price['extra'] ) ) . '" data-scheduled="1"' : '' ) . '>' . (int) $line[2] . ' × ' . esc_html( $p->get_name() ) . ( $config['layout']['prices'] && 'items' === $config['pricing'] ? ' <span class="rinnovobb-price">' . self::money( $price['price'] ) . '</span>' : '' ) . '</li>';
					}
				}
				$html .= '</ul></div>';
			}
			$html .= '</div></div>';
		}
		return $html;
	}

	/**
	 * What customers choose from (per delivery day when the box is planned per day).
	 *
	 * @param array  $config Configuration.
	 * @param array  $sel    Selection.
	 * @param string $name   Field prefix.
	 * @return string
	 */
	private static function groups( $config, $sel, $name ) {
		if ( ! $config['groups'] ) {
			return '';
		}
		$days   = RINNOVOBB_Config::days();
		$chosen = array();
		$vars   = array();
		foreach ( $sel['items'] as $item ) {
			$k            = $item['day'] . '|' . $item['group'] . '|' . $item['product_id'];
			$chosen[ $k ] = (int) $item['qty'];
			$vars[ $k ]   = (int) $item['variation_id'];
		}
		$html  = '';
		$tabs  = RINNOVOBB_Engine::choice_days( $config );
		if ( count( $tabs ) > 1 ) {
			$html .= '<div class="rinnovobb-tabs" role="tablist">';
			foreach ( $tabs as $n => $day ) {
				$html .= '<button type="button" role="tab" class="rinnovobb-tab' . ( 0 === $n ? ' is-active' : '' ) . '" data-day="' . esc_attr( $day ) . '">' . esc_html( $days[ $day ] ) . ' <span class="rinnovobb-tab-count"></span></button>';
			}
			$html .= '</div>';
		}
		foreach ( $tabs as $n => $day ) {
			$prefix = '' === $day ? $name . '[items]' : $name . '[days][' . $day . ']';
			$html  .= '<div class="rinnovobb-dayset' . ( 0 === $n ? ' is-active' : '' ) . '" data-day="' . esc_attr( $day ) . '" data-label="' . esc_attr( '' === $day ? '' : $days[ $day ] ) . '">';
			foreach ( $config['groups'] as $group ) {
				$limit = $group['exact'] ? $group['exact'] : $group['max'];
				$html .= '<fieldset class="rinnovobb-group" data-group="' . esc_attr( $group['id'] ) . '" data-max="' . (int) $limit . '" data-min="' . (int) ( $group['exact'] ? $group['exact'] : $group['min'] ) . '"' . self::show_if( $group['show_if'] ) . '>';
				$html .= '<legend>' . esc_html( $group['label'] ) . ( $group['required'] ? ' <abbr class="required" title="' . esc_attr__( 'required', self::TD ) . '">*</abbr>' : '' ) . ( $limit ? ' <span class="rinnovobb-limit"></span>' : '' ) . '</legend>';
				if ( '' !== $group['desc'] ) {
					$html .= '<p class="rinnovobb-desc">' . esc_html( $group['desc'] ) . '</p>';
				}
				$html .= '<div class="rinnovobb-items">';
				foreach ( RINNOVOBB_Engine::group_products( $group, $config ) as $pid => $p ) {
					$k     = $day . '|' . $group['id'] . '|' . $pid;
					$qty   = isset( $chosen[ $k ] ) ? $chosen[ $k ] : 0;
					$max   = RINNOVOBB_Engine::item_max( $config, $pid );
					$stock = RINNOVOBB_Engine::in_stock( $p, 1 );
					$price = RINNOVOBB_Engine::item_price( $config, $p );
					$field = $prefix . '[' . $group['id'] . '][' . $pid . ']';
					$html .= '<div class="rinnovobb-item' . ( $stock ? '' : ' is-out' ) . ( $qty ? ' is-chosen' : '' ) . '" data-name="' . esc_attr( ( '' === $day ? '' : $days[ $day ] . ': ' ) . wp_strip_all_tags( $p->get_name() ) ) . '" data-price="' . esc_attr( (string) rinnovo_convert_price( $price['price'] ) ) . '" data-extra="' . esc_attr( (string) rinnovo_convert_price( $price['extra'] ) ) . '">';
					if ( $config['layout']['images'] && 'form' !== $config['layout']['type'] ) {
						$html .= '<span class="rinnovobb-img">' . $p->get_image( 'woocommerce_thumbnail' ) . '</span>';
					}
					$html .= '<span class="rinnovobb-name">' . esc_html( $p->get_name() ) . '</span>';
					if ( $config['layout']['desc'] && 'form' !== $config['layout']['type'] && $p->get_short_description() ) {
						$html .= '<span class="rinnovobb-text">' . esc_html( wp_trim_words( wp_strip_all_tags( $p->get_short_description() ), 18 ) ) . '</span>';
					}
					if ( $config['layout']['prices'] ) {
						if ( 'items' === $config['pricing'] ) {
							$html .= '<span class="rinnovobb-price">' . self::money( $price['price'] ) . ( $price['extra'] > 0 ? ' + ' . self::money( $price['extra'] ) : '' ) . '</span>';
						} elseif ( $price['extra'] > 0 ) {
							$html .= '<span class="rinnovobb-price">+ ' . self::money( $price['extra'] ) . '</span>';
						}
					}
					if ( $p->is_type( 'variable' ) ) {
						$html .= '<select class="rinnovobb-var" name="' . esc_attr( $name . '[var][' . ( '' === $day ? '' : $day . ':' ) . $group['id'] . ':' . $pid . ']' ) . '" aria-label="' . esc_attr__( 'Option', self::TD ) . '"><option value="">' . esc_html__( 'Choose an option', self::TD ) . '</option>';
						foreach ( $p->get_children() as $vid ) {
							$v = wc_get_product( $vid );
							if ( $v && $v->is_purchasable() && ( 'hide' !== $config['rules']['stock'] || RINNOVOBB_Engine::in_stock( $v, 1 ) ) ) {
								$vp    = RINNOVOBB_Engine::item_price( $config, $v );
								$html .= '<option value="' . (int) $vid . '" data-price="' . esc_attr( (string) rinnovo_convert_price( $vp['price'] ) ) . '"' . selected( isset( $vars[ $k ] ) ? $vars[ $k ] : 0, $vid, false ) . disabled( RINNOVOBB_Engine::in_stock( $v, 1 ), false, false ) . '>' . esc_html( wc_get_formatted_variation( $v, true, false, false ) ) . '</option>';
							}
						}
						$html .= '</select>';
					}
					if ( ! $stock ) {
						$html .= '<span class="rinnovobb-out">' . esc_html__( 'Out of stock', self::TD ) . '</span>';
					} elseif ( 1 === $max ) {
						$html .= '<label class="rinnovobb-check"><input type="checkbox" name="' . esc_attr( $field ) . '" value="1"' . checked( $qty > 0, true, false ) . ' /> ' . esc_html__( 'Add', self::TD ) . '</label>';
					} else {
						$html .= '<span class="rinnovobb-qty"><button type="button" class="rinnovobb-minus" aria-label="' . esc_attr__( 'One less', self::TD ) . '">−</button><input type="number" inputmode="numeric" min="0"' . ( $max ? ' max="' . (int) $max . '"' : '' ) . ' step="1" name="' . esc_attr( $field ) . '" value="' . (int) $qty . '" aria-label="' . esc_attr( sprintf( /* translators: %s: product. */ __( 'Quantity of %s', self::TD ), wp_strip_all_tags( $p->get_name() ) ) ) . '" /><button type="button" class="rinnovobb-plus" aria-label="' . esc_attr__( 'One more', self::TD ) . '">+</button></span>';
					}
					$html .= '</div>';
				}
				$html .= '</div></fieldset>';
			}
			$html .= '</div>';
		}
		return $html;
	}

	/**
	 * Preference questions.
	 *
	 * @param array  $config Configuration.
	 * @param array  $sel    Selection.
	 * @param string $name   Field prefix.
	 * @return string
	 */
	private static function fields( $config, $sel, $name ) {
		if ( ! $config['fields'] ) {
			return '';
		}
		$html = '<div class="rinnovobb-fields">';
		foreach ( $config['fields'] as $f ) {
			$id    = 'rinnovobb-' . $f['id'] . '-' . wp_rand( 100, 999 );
			$field = $name . '[prefs][' . $f['id'] . ']';
			$value = isset( $sel['prefs'][ $f['id'] ] ) ? $sel['prefs'][ $f['id'] ] : '';
			$req   = $f['required'] ? ' <abbr class="required" title="' . esc_attr__( 'required', self::TD ) . '">*</abbr>' : '';
			$html .= '<div class="rinnovobb-field rinnovobb-field--' . esc_attr( $f['type'] ) . '" data-field="' . esc_attr( $f['id'] ) . '"' . self::show_if( $f['show_if'] ) . '>';
			$multi = in_array( $f['type'], array( 'radio', 'checkbox', 'image' ), true );
			$html .= $multi ? '<fieldset><legend>' . esc_html( $f['label'] ) . $req . '</legend>' : '<label for="' . esc_attr( $id ) . '">' . esc_html( $f['label'] ) . $req . '</label>';
			switch ( $f['type'] ) {
				case 'textarea':
					$html .= '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $field ) . '" rows="3">' . esc_textarea( (string) $value ) . '</textarea>';
					break;
				case 'select':
				case 'multi':
					$is_multi = 'multi' === $f['type'];
					$html    .= '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $field . ( $is_multi ? '[]' : '' ) ) . '"' . ( $is_multi ? ' multiple' : '' ) . '>' . ( $is_multi ? '' : '<option value="">' . esc_html__( 'Choose…', self::TD ) . '</option>' );
					foreach ( $f['options'] as $o ) {
						$on    = $is_multi ? in_array( $o['label'], (array) $value, true ) : (string) $value === $o['label'];
						$html .= '<option value="' . esc_attr( $o['label'] ) . '"' . selected( $on, true, false ) . '>' . esc_html( $o['label'] ) . '</option>';
					}
					$html .= '</select>';
					break;
				case 'radio':
				case 'checkbox':
				case 'image':
					$is_box = 'checkbox' === $f['type'];
					foreach ( $f['options'] as $o ) {
						$on    = $is_box ? in_array( $o['label'], (array) $value, true ) : (string) $value === $o['label'];
						$img   = 'image' === $f['type'] && $o['image'] ? wp_get_attachment_image( $o['image'], 'thumbnail' ) : '';
						$html .= '<label class="rinnovobb-option' . ( $img ? ' has-image' : '' ) . '"><input type="' . ( $is_box ? 'checkbox' : 'radio' ) . '" name="' . esc_attr( $field . ( $is_box ? '[]' : '' ) ) . '" value="' . esc_attr( $o['label'] ) . '"' . checked( $on, true, false ) . ' />' . $img . '<span>' . esc_html( $o['label'] ) . '</span></label>';
					}
					break;
				case 'number':
					$html .= '<input id="' . esc_attr( $id ) . '" type="number" step="any" name="' . esc_attr( $field ) . '" value="' . esc_attr( (string) $value ) . '" />';
					break;
				case 'date':
					$html .= '<input id="' . esc_attr( $id ) . '" type="date" name="' . esc_attr( $field ) . '" value="' . esc_attr( (string) $value ) . '" />';
					break;
				default:
					$html .= '<input id="' . esc_attr( $id ) . '" type="text" name="' . esc_attr( $field ) . '" value="' . esc_attr( (string) $value ) . '" />';
			}
			if ( $multi ) {
				$html .= '</fieldset>';
			}
			if ( '' !== $f['desc'] ) {
				$html .= '<small class="rinnovobb-desc">' . esc_html( $f['desc'] ) . '</small>';
			}
			$html .= '</div>';
		}
		return $html . '</div>';
	}

	/**
	 * Live summary and total.
	 *
	 * @param array $config Configuration.
	 * @return string
	 */
	private static function summary( $config ) {
		$html  = '<ul class="rinnovobb-summary-list" aria-live="polite"></ul>';
		$html .= '<p class="rinnovobb-count" aria-live="polite"></p>';
		if ( $config['layout']['total'] ) {
			$html .= '<p class="rinnovobb-total">' . esc_html__( 'Box total:', self::TD ) . ' <strong class="rinnovobb-total-amount"></strong></p>';
		}
		return $html;
	}

	/**
	 * data attributes of a condition.
	 *
	 * @param array $cond Condition.
	 * @return string
	 */
	private static function show_if( $cond ) {
		if ( empty( $cond['field'] ) ) {
			return '';
		}
		return ' data-show-field="' . esc_attr( $cond['field'] ) . '" data-show-value="' . esc_attr( $cond['value'] ) . '"';
	}
}
