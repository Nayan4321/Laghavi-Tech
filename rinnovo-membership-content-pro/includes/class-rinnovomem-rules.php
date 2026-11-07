<?php
/**
 * Access rules: who may see a post, page or custom post type, worked out from (strongest first):
 * the item's own setting, its parent (child pages, bbPress topics and replies under their forum, LearnDash
 * and LifterLMS lessons under their course), its categories, tags and other terms, and the post type rule.
 * Each rule names the lowest tier allowed, and can drip: unlock N days after the member joined, or on a date.
 *
 * @package RinnovoMembership
 */

defined( 'ABSPATH' ) || exit;

/**
 * Rules.
 */
class RINNOVOMEM_Rules {

	const TD   = 'rinnovo-membership-content-pro';
	const TIER = '_rinnovomem_tier';
	const DRIP = '_rinnovomem_drip';
	const DATE = '_rinnovomem_date';

	/**
	 * Requirements of this request.
	 *
	 * @var array
	 */
	private static $cache = array();

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_boxes' ) );
		add_action( 'save_post', array( __CLASS__, 'save_post' ), 10, 2 );
		add_action( 'admin_init', array( __CLASS__, 'term_hooks' ) );
		add_action( 'created_term', array( __CLASS__, 'save_term' ), 10, 3 );
		add_action( 'edited_term', array( __CLASS__, 'save_term' ), 10, 3 );
	}

	/**
	 * Post types rules can be set on.
	 *
	 * @return string[]
	 */
	public static function post_types() {
		$types = get_post_types( array( 'public' => true ), 'names' );
		foreach ( array( 'forum', 'topic', 'reply', 'sfwd-courses', 'sfwd-lessons', 'sfwd-topic', 'course', 'lesson' ) as $extra ) {
			if ( post_type_exists( $extra ) ) {
				$types[ $extra ] = $extra;
			}
		}
		unset( $types['attachment'] );
		return array_values( $types );
	}

	/**
	 * A rule from stored values.
	 *
	 * @param string $tier Tier.
	 * @param mixed  $drip Days.
	 * @param mixed  $date Y-m-d.
	 * @param string $from Where it comes from.
	 * @return array|null
	 */
	private static function rule( $tier, $drip, $date, $from ) {
		$tier = (string) $tier;
		if ( '' === $tier || 'public' === $tier || RINNOVOMEM_Tiers::rank( $tier ) < 0 ) {
			return null;
		}
		$ts = '' !== (string) $date ? strtotime( $date . ' 00:00:00 ' . wp_timezone_string() ) : 0;
		return array(
			'tier' => $tier,
			'drip' => max( 0, (int) $drip ),
			'date' => $ts ? (int) $ts : 0,
			'from' => $from,
		);
	}

	/**
	 * The parent whose rule an item follows.
	 *
	 * @param WP_Post $post Post.
	 * @return int
	 */
	private static function parent_of( $post ) {
		switch ( $post->post_type ) {
			case 'topic':
				return (int) ( get_post_meta( $post->ID, '_bbp_forum_id', true ) ? get_post_meta( $post->ID, '_bbp_forum_id', true ) : $post->post_parent );
			case 'reply':
				return (int) ( get_post_meta( $post->ID, '_bbp_topic_id', true ) ? get_post_meta( $post->ID, '_bbp_topic_id', true ) : $post->post_parent );
			case 'forum':
				return (int) $post->post_parent;
			case 'sfwd-lessons':
			case 'sfwd-topic':
			case 'sfwd-quiz':
				if ( function_exists( 'learndash_get_course_id' ) ) {
					return (int) learndash_get_course_id( $post->ID );
				}
				return (int) get_post_meta( $post->ID, 'course_id', true );
			case 'lesson':
				return (int) get_post_meta( $post->ID, '_llms_parent_course', true );
			case 'llms_quiz':
				return (int) get_post_meta( $post->ID, '_llms_lesson_id', true );
		}
		return is_post_type_hierarchical( $post->post_type ) && RINNOVOMEM_Settings::get( 'child_pages' ) ? (int) $post->post_parent : 0;
	}

	/**
	 * The rule that applies to a post (null = everyone can see it).
	 *
	 * @param int $post_id Post.
	 * @param int $depth   Parent depth (internal).
	 * @return array|null tier, drip, date, from.
	 */
	public static function requirement( $post_id, $depth = 0 ) {
		$post_id = (int) $post_id;
		if ( array_key_exists( $post_id, self::$cache ) ) {
			return self::$cache[ $post_id ];
		}
		$post = get_post( $post_id );
		if ( ! $post || $depth > 6 ) {
			return null;
		}
		$own = (string) get_post_meta( $post_id, self::TIER, true );
		if ( 'public' === $own ) {
			self::$cache[ $post_id ] = null;
			return null;
		}
		$rule = self::rule( $own, get_post_meta( $post_id, self::DRIP, true ), get_post_meta( $post_id, self::DATE, true ), get_the_title( $post ) );
		if ( $rule ) {
			self::$cache[ $post_id ] = $rule;
			return $rule;
		}

		$found  = array();
		$parent = self::parent_of( $post );
		if ( $parent && $parent !== $post_id ) {
			$found[] = self::requirement( $parent, $depth + 1 );
		}
		foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
			$terms = get_the_terms( $post_id, $taxonomy );
			foreach ( is_array( $terms ) ? $terms : array() as $term ) {
				$found[] = self::rule( get_term_meta( $term->term_id, self::TIER, true ), get_term_meta( $term->term_id, self::DRIP, true ), get_term_meta( $term->term_id, self::DATE, true ), $term->name );
			}
		}
		$types = (array) RINNOVOMEM_Settings::get( 'post_types' );
		if ( ! empty( $types[ $post->post_type ] ) ) {
			$object  = get_post_type_object( $post->post_type );
			$found[] = self::rule( $types[ $post->post_type ], 0, '', $object ? $object->labels->name : $post->post_type );
		}

		// The strictest rule wins.
		$best = null;
		foreach ( array_filter( $found ) as $candidate ) {
			if ( ! $best || RINNOVOMEM_Tiers::rank( $candidate['tier'] ) > RINNOVOMEM_Tiers::rank( $best['tier'] ) || ( $candidate['tier'] === $best['tier'] && $candidate['drip'] > $best['drip'] ) ) {
				$best = $candidate;
			}
		}
		self::$cache[ $post_id ] = $best;
		return $best;
	}

	/**
	 * Whether a user may see something under a rule.
	 *
	 * @param array|null $rule    Rule.
	 * @param int|null   $user_id User (default: current).
	 * @return array ok (bool), reason (login|tier|drip), tier, unlock (timestamp).
	 */
	public static function check( $rule, $user_id = null ) {
		$user_id = null === $user_id ? get_current_user_id() : (int) $user_id;
		$ok      = array(
			'ok'     => true,
			'reason' => '',
			'tier'   => $rule ? $rule['tier'] : '',
			'unlock' => 0,
		);
		if ( ! $rule ) {
			return $ok;
		}
		if ( $user_id && ( user_can( $user_id, 'edit_others_posts' ) || user_can( $user_id, 'manage_woocommerce' ) ) ) {
			return $ok;
		}
		if ( ! $user_id ) {
			$ok['ok']     = false;
			$ok['reason'] = 'login';
			return $ok;
		}
		if ( ! RINNOVOMEM_Members::has( $user_id, $rule['tier'] ) ) {
			$ok['ok']     = false;
			$ok['reason'] = 'tier';
			return $ok;
		}
		$unlock = 0;
		if ( $rule['drip'] ) {
			$unlock = RINNOVOMEM_Members::since( $user_id, $rule['tier'] ) + $rule['drip'] * DAY_IN_SECONDS;
		}
		if ( $rule['date'] ) {
			$unlock = max( $unlock, $rule['date'] );
		}
		if ( $unlock > time() ) {
			$ok['ok']     = false;
			$ok['reason'] = 'drip';
			$ok['unlock'] = $unlock;
		}
		return $ok;
	}

	/**
	 * Whether a user may see a post.
	 *
	 * @param int      $post_id Post.
	 * @param int|null $user_id User.
	 * @return array See check().
	 */
	public static function can_view( $post_id, $user_id = null ) {
		$result = self::check( self::requirement( $post_id ), $user_id );
		/**
		 * Whether a user may see a post.
		 *
		 * @param array    $result  ok, reason, tier, unlock.
		 * @param int      $post_id Post.
		 * @param int|null $user_id User.
		 */
		return (array) apply_filters( 'rinnovomem_can_view', $result, $post_id, $user_id );
	}

	// ---------------------------------------------------------------- Admin: posts.

	/**
	 * "Members only" box on every content type.
	 */
	public static function meta_boxes() {
		foreach ( self::post_types() as $type ) {
			add_meta_box( 'rinnovomem', __( 'Members only', self::TD ), array( __CLASS__, 'meta_box' ), $type, 'side', 'default' );
		}
	}

	/**
	 * Rule fields.
	 *
	 * @param string $tier  Tier.
	 * @param string $drip  Days.
	 * @param string $date  Date.
	 * @param bool   $inherit Offer "inherit" and "everyone".
	 */
	public static function fields( $tier, $drip, $date, $inherit = true ) {
		?>
		<p>
			<label for="rinnovomem_tier"><strong><?php esc_html_e( 'Who can see this', self::TD ); ?></strong></label><br />
			<select id="rinnovomem_tier" name="rinnovomem_tier" style="width:100%">
				<?php foreach ( RINNOVOMEM_Tiers::choices( $inherit ) as $id => $label ) : ?>
					<option value="<?php echo esc_attr( $id ); ?>" <?php selected( (string) $tier, (string) $id ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<p>
			<label for="rinnovomem_drip"><?php esc_html_e( 'Unlock after (days of membership)', self::TD ); ?></label><br />
			<input type="number" min="0" id="rinnovomem_drip" name="rinnovomem_drip" value="<?php echo esc_attr( $drip ); ?>" placeholder="0" style="width:100%" />
		</p>
		<p>
			<label for="rinnovomem_date"><?php esc_html_e( 'Or unlock on', self::TD ); ?></label><br />
			<input type="date" id="rinnovomem_date" name="rinnovomem_date" value="<?php echo esc_attr( $date ); ?>" style="width:100%" />
		</p>
		<?php
	}

	/**
	 * The box.
	 *
	 * @param WP_Post $post Post.
	 */
	public static function meta_box( $post ) {
		wp_nonce_field( 'rinnovomem_rule', 'rinnovomem_rule_nonce' );
		if ( ! RINNOVOMEM_Tiers::all() ) {
			echo '<p class="description">' . esc_html__( 'Create your membership tiers in WooCommerce > Membership first.', self::TD ) . '</p>';
		}
		self::fields( get_post_meta( $post->ID, self::TIER, true ), get_post_meta( $post->ID, self::DRIP, true ), get_post_meta( $post->ID, self::DATE, true ) );
		$own = (string) get_post_meta( $post->ID, self::TIER, true );
		if ( '' === $own ) {
			$rule = self::requirement( $post->ID );
			if ( $rule ) {
				/* translators: 1: tier, 2: where the rule comes from. */
				echo '<p class="description">' . esc_html( sprintf( __( 'Now: %1$s and higher, from "%2$s".', self::TD ), RINNOVOMEM_Tiers::name( $rule['tier'] ), $rule['from'] ) ) . '</p>';
			}
		}
		echo '<p class="description">' . esc_html__( 'Parts of a page can be made members-only with the "Members only" block or [members_only tier="gold"]…[/members_only].', self::TD ) . '</p>';
	}

	/**
	 * Save the box.
	 *
	 * @param int     $post_id Post.
	 * @param WP_Post $post    Post.
	 */
	public static function save_post( $post_id, $post ) {
		if ( ! isset( $_POST['rinnovomem_rule_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['rinnovomem_rule_nonce'] ) ), 'rinnovomem_rule' ) || ! current_user_can( 'edit_post', $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		self::store( 'post', $post_id );
	}

	/**
	 * Store posted rule fields on a post or term.
	 *
	 * @param string $type    post or term.
	 * @param int    $id      Object ID.
	 */
	private static function store( $type, $id ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked by the callers.
		$tier = isset( $_POST['rinnovomem_tier'] ) ? sanitize_key( wp_unslash( $_POST['rinnovomem_tier'] ) ) : '';
		$drip = isset( $_POST['rinnovomem_drip'] ) ? absint( $_POST['rinnovomem_drip'] ) : 0;
		$date = isset( $_POST['rinnovomem_date'] ) ? sanitize_text_field( wp_unslash( $_POST['rinnovomem_date'] ) ) : '';
		// phpcs:enable
		if ( ! array_key_exists( $tier, RINNOVOMEM_Tiers::choices( 'post' === $type ) ) ) {
			$tier = '';
		}
		$date   = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date : '';
		$update = 'post' === $type ? 'update_post_meta' : 'update_term_meta';
		$delete = 'post' === $type ? 'delete_post_meta' : 'delete_term_meta';
		foreach ( array(
			self::TIER => $tier,
			self::DRIP => $drip ? (string) $drip : '',
			self::DATE => $date,
		) as $key => $value ) {
			if ( '' === $value ) {
				$delete( $id, $key );
			} else {
				$update( $id, $key, $value );
			}
		}
		self::$cache = array();
	}

	// ---------------------------------------------------------------- Admin: categories, tags and other terms.

	/**
	 * Fields on the add and edit forms of every public taxonomy.
	 */
	public static function term_hooks() {
		foreach ( get_taxonomies( array( 'show_ui' => true ), 'names' ) as $taxonomy ) {
			add_action( $taxonomy . '_add_form_fields', array( __CLASS__, 'term_add' ) );
			add_action( $taxonomy . '_edit_form_fields', array( __CLASS__, 'term_edit' ) );
		}
	}

	/**
	 * Add form.
	 */
	public static function term_add() {
		wp_nonce_field( 'rinnovomem_rule', 'rinnovomem_rule_nonce' );
		echo '<div class="form-field">';
		self::fields( '', '', '', false );
		echo '<p class="description">' . esc_html__( 'Everything in this category is for these members (a post\'s own setting wins).', self::TD ) . '</p></div>';
	}

	/**
	 * Edit form.
	 *
	 * @param WP_Term $term Term.
	 */
	public static function term_edit( $term ) {
		echo '<tr class="form-field"><th scope="row">' . esc_html__( 'Members only', self::TD ) . '</th><td>';
		wp_nonce_field( 'rinnovomem_rule', 'rinnovomem_rule_nonce' );
		self::fields( get_term_meta( $term->term_id, self::TIER, true ), get_term_meta( $term->term_id, self::DRIP, true ), get_term_meta( $term->term_id, self::DATE, true ), false );
		echo '</td></tr>';
	}

	/**
	 * Save a term's rule.
	 *
	 * @param int    $term_id  Term.
	 * @param int    $tt_id    Term taxonomy.
	 * @param string $taxonomy Taxonomy.
	 */
	public static function save_term( $term_id, $tt_id, $taxonomy ) {
		$tax = get_taxonomy( $taxonomy );
		if ( ! isset( $_POST['rinnovomem_rule_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['rinnovomem_rule_nonce'] ) ), 'rinnovomem_rule' ) || ! $tax || ! current_user_can( $tax->cap->edit_terms ) ) {
			return;
		}
		self::store( 'term', $term_id );
	}
}
