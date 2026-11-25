<?php
/**
 * Screens for the Rinnovo family, Vacanam and Sarala: a Rinnovo tab with a sub-tab per installed Rinnovo plugin,
 * a Languages tab (Vacanam) and a Logins tab (Sarala). Shown only for active plugins, to shop managers and admins.
 *
 * @package PerformanceInsight
 */

defined( 'ABSPATH' ) || exit;

/**
 * Family screens.
 */
class LGPI_Family_View {

	/**
	 * What each section shows: totals (key => [label, format]), chart (daily keys), tables (key => [title, columns]).
	 * Formats: n (number), m (money), p (percent), s (seconds), t (text), d (date).
	 *
	 * @return array
	 */
	public static function sections() {
		return array(
			'subscriptions' => array(
				'title'  => __( 'Subscriptions', 'laghavi-performance-insight-for-woocommerce' ),
				'groups' => array(
					__( 'Subscriptions', 'laghavi-performance-insight-for-woocommerce' ) => array(
						'active'    => array( __( 'Active subscriptions', 'laghavi-performance-insight-for-woocommerce' ), 'n' ),
						'new'       => array( __( 'New subscriptions', 'laghavi-performance-insight-for-woocommerce' ), 'n' ),
						'cancelled' => array( __( 'Cancellations', 'laghavi-performance-insight-for-woocommerce' ), 'n' ),
					),
					__( 'Revenue', 'laghavi-performance-insight-for-woocommerce' )       => array(
						'mrr'       => array( __( 'MRR', 'laghavi-performance-insight-for-woocommerce' ), 'm' ),
						'arr'       => array( __( 'ARR', 'laghavi-performance-insight-for-woocommerce' ), 'm' ),
						'churn_pct' => array( __( 'Churn', 'laghavi-performance-insight-for-woocommerce' ), 'p' ),
						'nrr_pct'   => array( __( 'Net revenue retention', 'laghavi-performance-insight-for-woocommerce' ), 'p' ),
					),
					__( 'Renewals', 'laghavi-performance-insight-for-woocommerce' )      => array(
						'renewals_ok'     => array( __( 'Successful renewals', 'laghavi-performance-insight-for-woocommerce' ), 'n' ),
						'renewals_failed' => array( __( 'Failed renewals', 'laghavi-performance-insight-for-woocommerce' ), 'n' ),
						'recovered'       => array( __( 'Recovered failed payments', 'laghavi-performance-insight-for-woocommerce' ), 'n' ),
						'recovered_value' => array( __( 'Recovered amount', 'laghavi-performance-insight-for-woocommerce' ), 'm' ),
					),
					__( 'Trials', 'laghavi-performance-insight-for-woocommerce' )        => array(
						'trials_started'       => array( __( 'Trials started', 'laghavi-performance-insight-for-woocommerce' ), 'n' ),
						'trial_conversion_pct' => array( __( 'Trial → paid', 'laghavi-performance-insight-for-woocommerce' ), 'p' ),
						'trial_abuse_blocked'  => array( __( 'Trial abuse blocked', 'laghavi-performance-insight-for-woocommerce' ), 'n' ),
						'trials_ending_7d'     => array( __( 'Trials ending this week', 'laghavi-performance-insight-for-woocommerce' ), 'n' ),
					),
					__( 'Refunds', 'laghavi-performance-insight-for-woocommerce' )       => array(
						'refunds'      => array( __( 'Refunds', 'laghavi-performance-insight-for-woocommerce' ), 'm' ),
						'credit_notes' => array( __( 'Credit notes issued', 'laghavi-performance-insight-for-woocommerce' ), 'n' ),
					),
				),
				'chart'  => array( 'new' => __( 'New subscriptions per day', 'laghavi-performance-insight-for-woocommerce' ) ),
				'tables' => array(
					'failed_renewals' => array( __( 'Failed renewals to follow up', 'laghavi-performance-insight-for-woocommerce' ), array( 'subscription' => __( 'Subscription', 'laghavi-performance-insight-for-woocommerce' ), 'product' => __( 'Product', 'laghavi-performance-insight-for-woocommerce' ), 'amount:m' => __( 'Amount', 'laghavi-performance-insight-for-woocommerce' ), 'failed_at:d' => __( 'Failed', 'laghavi-performance-insight-for-woocommerce' ), 'attempts:n' => __( 'Retries', 'laghavi-performance-insight-for-woocommerce' ) ) ),
					'trials_ending'   => array( __( 'Trials ending this week', 'laghavi-performance-insight-for-woocommerce' ), array( 'subscription' => __( 'Subscription', 'laghavi-performance-insight-for-woocommerce' ), 'product' => __( 'Product', 'laghavi-performance-insight-for-woocommerce' ), 'ends:d' => __( 'Ends', 'laghavi-performance-insight-for-woocommerce' ) ) ),
					'plans'           => array( __( 'Plans', 'laghavi-performance-insight-for-woocommerce' ), array( 'product' => __( 'Plan', 'laghavi-performance-insight-for-woocommerce' ), 'active:n' => __( 'Active', 'laghavi-performance-insight-for-woocommerce' ), 'mrr:m' => __( 'MRR', 'laghavi-performance-insight-for-woocommerce' ), 'churn_pct:p' => __( 'Churn', 'laghavi-performance-insight-for-woocommerce' ) ) ),
				),
			),
			'licenses'      => array(
				'title'  => __( 'Licenses', 'laghavi-performance-insight-for-woocommerce' ),
				'groups' => array(
					__( 'Licenses', 'laghavi-performance-insight-for-woocommerce' ) => array(
						'active'      => array( __( 'Active licenses', 'laghavi-performance-insight-for-woocommerce' ), 'n' ),
						'activations' => array( __( 'Activations', 'laghavi-performance-insight-for-woocommerce' ), 'n' ),
						'upgrades'    => array( __( 'Upgrades', 'laghavi-performance-insight-for-woocommerce' ), 'n' ),
						'downgrades'  => array( __( 'Downgrades', 'laghavi-performance-insight-for-woocommerce' ), 'n' ),
						'expired'     => array( __( 'Expired', 'laghavi-performance-insight-for-woocommerce' ), 'n' ),
					),
				),
				'chart'  => array( 'activations' => __( 'Activations per day', 'laghavi-performance-insight-for-woocommerce' ) ),
				'tables' => array(
					'products' => array( __( 'Licenses by product', 'laghavi-performance-insight-for-woocommerce' ), array( 'product' => __( 'Product', 'laghavi-performance-insight-for-woocommerce' ), 'active:n' => __( 'Active', 'laghavi-performance-insight-for-woocommerce' ), 'activations:n' => __( 'Activations', 'laghavi-performance-insight-for-woocommerce' ), 'sites:n' => __( 'Sites in use', 'laghavi-performance-insight-for-woocommerce' ) ) ),
				),
			),
			'quotes'        => array(
				'title'  => __( 'Ask for price', 'laghavi-performance-insight-for-woocommerce' ),
				'groups' => array(
					__( 'Quote enquiries', 'laghavi-performance-insight-for-woocommerce' ) => array(
						'new'            => array( __( 'New enquiries', 'laghavi-performance-insight-for-woocommerce' ), 'n' ),
						'won'            => array( __( 'Won', 'laghavi-performance-insight-for-woocommerce' ), 'n' ),
						'lost'           => array( __( 'Lost', 'laghavi-performance-insight-for-woocommerce' ), 'n' ),
						'conversion_pct' => array( __( 'Conversion', 'laghavi-performance-insight-for-woocommerce' ), 'p' ),
						'value_won'      => array( __( 'Value won', 'laghavi-performance-insight-for-woocommerce' ), 'm' ),
					),
				),
				'chart'  => array( 'new' => __( 'Enquiries per day', 'laghavi-performance-insight-for-woocommerce' ) ),
				'tables' => array(
					'products' => array( __( 'Enquiries by product', 'laghavi-performance-insight-for-woocommerce' ), array( 'product' => __( 'Product', 'laghavi-performance-insight-for-woocommerce' ), 'new:n' => __( 'Enquiries', 'laghavi-performance-insight-for-woocommerce' ), 'won:n' => __( 'Won', 'laghavi-performance-insight-for-woocommerce' ), 'conversion_pct:p' => __( 'Conversion', 'laghavi-performance-insight-for-woocommerce' ) ) ),
				),
			),
			'boxes'         => array(
				'title'  => __( 'Subscription boxes', 'laghavi-performance-insight-for-woocommerce' ),
				'groups' => array(
					__( 'Boxes', 'laghavi-performance-insight-for-woocommerce' )             => array(
						'active_subscribers' => array( __( 'Active box subscribers', 'laghavi-performance-insight-for-woocommerce' ), 'n' ),
						'boxes_shipped'      => array( __( 'Boxes shipped', 'laghavi-performance-insight-for-woocommerce' ), 'n' ),
						'skips'              => array( __( 'Skips', 'laghavi-performance-insight-for-woocommerce' ), 'n' ),
						'swaps'              => array( __( 'Swaps', 'laghavi-performance-insight-for-woocommerce' ), 'n' ),
					),
					__( 'Prepaid & gifts', 'laghavi-performance-insight-for-woocommerce' )   => array(
						'prepaid_active'      => array( __( 'Prepaid plans', 'laghavi-performance-insight-for-woocommerce' ), 'n' ),
						'gift_active'         => array( __( 'Gift plans', 'laghavi-performance-insight-for-woocommerce' ), 'n' ),
						'boxes_remaining'     => array( __( 'Boxes remaining', 'laghavi-performance-insight-for-woocommerce' ), 'n' ),
						'upcoming_deliveries' => array( __( 'Upcoming deliveries', 'laghavi-performance-insight-for-woocommerce' ), 'n' ),
						'next_ship_date'      => array( __( 'Next shipping day', 'laghavi-performance-insight-for-woocommerce' ), 'd' ),
					),
				),
				'chart'  => array( 'boxes_shipped' => __( 'Boxes shipped per day', 'laghavi-performance-insight-for-woocommerce' ) ),
				'tables' => array(
					'popular_items'  => array( __( 'Popular box items', 'laghavi-performance-insight-for-woocommerce' ), array( 'name' => __( 'Item', 'laghavi-performance-insight-for-woocommerce' ), 'count:n' => __( 'Chosen', 'laghavi-performance-insight-for-woocommerce' ) ) ),
					'expected_items' => array( __( 'Expected box quantities (next 30 days)', 'laghavi-performance-insight-for-woocommerce' ), array( 'name' => __( 'Item', 'laghavi-performance-insight-for-woocommerce' ), 'next_shipment_qty:n' => __( 'Next shipment', 'laghavi-performance-insight-for-woocommerce' ), 'qty_next_30d:n' => __( 'Next 30 days', 'laghavi-performance-insight-for-woocommerce' ), '_stock' => __( 'In stock', 'laghavi-performance-insight-for-woocommerce' ) ) ),
				),
			),
			'membership'    => array(
				'title'  => __( 'Membership content', 'laghavi-performance-insight-for-woocommerce' ),
				'groups' => array(
					__( 'Members', 'laghavi-performance-insight-for-woocommerce' )           => array(
						'members'       => array( __( 'Members', 'laghavi-performance-insight-for-woocommerce' ), 'n' ),
						'joins'         => array( __( 'Joins', 'laghavi-performance-insight-for-woocommerce' ), 'n' ),
						'cancellations' => array( __( 'Cancellations', 'laghavi-performance-insight-for-woocommerce' ), 'n' ),
					),
					__( 'Courses', 'laghavi-performance-insight-for-woocommerce' )           => array(
						'course_sales' => array( __( 'Course sales', 'laghavi-performance-insight-for-woocommerce' ), 'm' ),
						'enrolments'   => array( __( 'Enrolments', 'laghavi-performance-insight-for-woocommerce' ), 'n' ),
					),
					__( 'Download credits', 'laghavi-performance-insight-for-woocommerce' )  => array(
						'credits_used'      => array( __( 'Credits used', 'laghavi-performance-insight-for-woocommerce' ), 'n' ),
						'credits_available' => array( __( 'Credits available', 'laghavi-performance-insight-for-woocommerce' ), 'n' ),
						'topups_sold'       => array( __( 'Top-up packs sold', 'laghavi-performance-insight-for-woocommerce' ), 'n' ),
					),
				),
				'chart'  => array( 'joins' => __( 'Joins per day', 'laghavi-performance-insight-for-woocommerce' ) ),
				'tables' => array(
					'groups'        => array( __( 'Members by group and tier', 'laghavi-performance-insight-for-woocommerce' ), array( 'group' => __( 'Group', 'laghavi-performance-insight-for-woocommerce' ), 'tier' => __( 'Tier', 'laghavi-performance-insight-for-woocommerce' ), 'members:n' => __( 'Members', 'laghavi-performance-insight-for-woocommerce' ) ) ),
					'top_content'   => array( __( 'Top content viewed', 'laghavi-performance-insight-for-woocommerce' ), array( 'title' => __( 'Content', 'laghavi-performance-insight-for-woocommerce' ), 'views:n' => __( 'Views', 'laghavi-performance-insight-for-woocommerce' ) ) ),
					'top_downloads' => array( __( 'Top downloads', 'laghavi-performance-insight-for-woocommerce' ), array( 'title' => __( 'File', 'laghavi-performance-insight-for-woocommerce' ), 'downloads:n' => __( 'Downloads', 'laghavi-performance-insight-for-woocommerce' ) ) ),
				),
			),
			'media'         => array(
				'title'  => __( 'Media archives', 'laghavi-performance-insight-for-woocommerce' ),
				'groups' => array(
					__( 'Plays', 'laghavi-performance-insight-for-woocommerce' )  => array(
						'plays'         => array( __( 'Streams and plays', 'laghavi-performance-insight-for-woocommerce' ), 'n' ),
						'watch_seconds' => array( __( 'Watch & listen time', 'laghavi-performance-insight-for-woocommerce' ), 's' ),
					),
					__( 'Access', 'laghavi-performance-insight-for-woocommerce' ) => array(
						'active_devices'  => array( __( 'Active devices', 'laghavi-performance-insight-for-woocommerce' ), 'n' ),
						'security_alerts' => array( __( 'Security alerts', 'laghavi-performance-insight-for-woocommerce' ), 'n' ),
					),
				),
				'chart'  => array( 'plays' => __( 'Plays per day', 'laghavi-performance-insight-for-woocommerce' ) ),
				'tables' => array(
					'top_media' => array( __( 'Top media', 'laghavi-performance-insight-for-woocommerce' ), array( 'title' => __( 'Media', 'laghavi-performance-insight-for-woocommerce' ), 'plays:n' => __( 'Plays', 'laghavi-performance-insight-for-woocommerce' ), 'seconds:s' => __( 'Time', 'laghavi-performance-insight-for-woocommerce' ) ) ),
					'quota'     => array( __( 'Quota usage per plan', 'laghavi-performance-insight-for-woocommerce' ), array( 'plan' => __( 'Plan', 'laghavi-performance-insight-for-woocommerce' ), 'used:n' => __( 'Used', 'laghavi-performance-insight-for-woocommerce' ), 'included:n' => __( 'Included', 'laghavi-performance-insight-for-woocommerce' ), '_pct' => __( 'Used %', 'laghavi-performance-insight-for-woocommerce' ) ) ),
				),
			),
			'contracts'     => array(
				'title'  => __( 'Service contracts', 'laghavi-performance-insight-for-woocommerce' ),
				'groups' => array(
					__( 'Contracts', 'laghavi-performance-insight-for-woocommerce' ) => array(
						'active'                  => array( __( 'Active contracts', 'laghavi-performance-insight-for-woocommerce' ), 'n' ),
						'mrr'                     => array( __( 'Contract MRR', 'laghavi-performance-insight-for-woocommerce' ), 'm' ),
						'in_min_term'             => array( __( 'In minimum term', 'laghavi-performance-insight-for-woocommerce' ), 'n' ),
						'cancellations_scheduled' => array( __( 'Cancellations scheduled', 'laghavi-performance-insight-for-woocommerce' ), 'n' ),
					),
					__( 'Requests', 'laghavi-performance-insight-for-woocommerce' )  => array(
						'requests_open' => array( __( 'Requests open', 'laghavi-performance-insight-for-woocommerce' ), 'n' ),
						'requests_done' => array( __( 'Requests completed', 'laghavi-performance-insight-for-woocommerce' ), 'n' ),
					),
				),
				'chart'  => array( 'requests_done' => __( 'Requests completed per day', 'laghavi-performance-insight-for-woocommerce' ) ),
				'tables' => array(
					'allowances' => array( __( 'Quota used vs included', 'laghavi-performance-insight-for-woocommerce' ), array( 'contract' => __( 'Contract', 'laghavi-performance-insight-for-woocommerce' ), 'allowance' => __( 'Allowance', 'laghavi-performance-insight-for-woocommerce' ), 'used:n' => __( 'Used', 'laghavi-performance-insight-for-woocommerce' ), 'included:n' => __( 'Included', 'laghavi-performance-insight-for-woocommerce' ), '_pct' => __( 'Used %', 'laghavi-performance-insight-for-woocommerce' ) ) ),
				),
			),
			'sessions'      => array(
				'title'  => __( 'Session membership', 'laghavi-performance-insight-for-woocommerce' ),
				'groups' => array(
					__( 'Bookings', 'laghavi-performance-insight-for-woocommerce' ) => array(
						'bookings'       => array( __( 'Bookings', 'laghavi-performance-insight-for-woocommerce' ), 'n' ),
						'attendance_pct' => array( __( 'Attendance', 'laghavi-performance-insight-for-woocommerce' ), 'p' ),
						'no_shows'       => array( __( 'No-shows', 'laghavi-performance-insight-for-woocommerce' ), 'n' ),
						'late_cancels'   => array( __( 'Late cancellations', 'laghavi-performance-insight-for-woocommerce' ), 'n' ),
					),
					__( 'Credits', 'laghavi-performance-insight-for-woocommerce' )  => array(
						'credits_used'   => array( __( 'Credits used', 'laghavi-performance-insight-for-woocommerce' ), 'n' ),
						'credits_issued' => array( __( 'Credits issued', 'laghavi-performance-insight-for-woocommerce' ), 'n' ),
						'waitlist'       => array( __( 'On waitlists', 'laghavi-performance-insight-for-woocommerce' ), 'n' ),
					),
				),
				'chart'  => array( 'bookings' => __( 'Bookings per day', 'laghavi-performance-insight-for-woocommerce' ) ),
				'tables' => array(
					'classes'     => array( __( 'Class utilisation', 'laghavi-performance-insight-for-woocommerce' ), array( 'class' => __( 'Class', 'laghavi-performance-insight-for-woocommerce' ), 'instructor' => __( 'Instructor', 'laghavi-performance-insight-for-woocommerce' ), 'location' => __( 'Location', 'laghavi-performance-insight-for-woocommerce' ), 'booked:n' => __( 'Booked', 'laghavi-performance-insight-for-woocommerce' ), 'capacity:n' => __( 'Capacity', 'laghavi-performance-insight-for-woocommerce' ), '_util' => __( 'Full', 'laghavi-performance-insight-for-woocommerce' ) ) ),
					'instructors' => array( __( 'By instructor', 'laghavi-performance-insight-for-woocommerce' ), array( 'instructor' => __( 'Instructor', 'laghavi-performance-insight-for-woocommerce' ), 'classes:n' => __( 'Classes', 'laghavi-performance-insight-for-woocommerce' ), 'utilisation_pct:p' => __( 'Full', 'laghavi-performance-insight-for-woocommerce' ) ) ),
					'locations'   => array( __( 'By location', 'laghavi-performance-insight-for-woocommerce' ), array( 'location' => __( 'Location', 'laghavi-performance-insight-for-woocommerce' ), 'classes:n' => __( 'Classes', 'laghavi-performance-insight-for-woocommerce' ), 'utilisation_pct:p' => __( 'Full', 'laghavi-performance-insight-for-woocommerce' ) ) ),
				),
			),
			'bundles'       => array(
				'title'  => __( 'Bundles & variable subscriptions', 'laghavi-performance-insight-for-woocommerce' ),
				'groups' => array(
					__( 'Bundles', 'laghavi-performance-insight-for-woocommerce' ) => array(
						'bundle_subscriptions' => array( __( 'Bundle subscriptions', 'laghavi-performance-insight-for-woocommerce' ), 'n' ),
						'held_renewals'        => array( __( 'Renewals held (out of stock)', 'laghavi-performance-insight-for-woocommerce' ), 'n' ),
						'upgrades'             => array( __( 'Plan upgrades', 'laghavi-performance-insight-for-woocommerce' ), 'n' ),
						'downgrades'           => array( __( 'Plan downgrades', 'laghavi-performance-insight-for-woocommerce' ), 'n' ),
					),
				),
				'chart'  => array( 'bundle_subscriptions' => __( 'New bundle subscriptions per day', 'laghavi-performance-insight-for-woocommerce' ) ),
				'tables' => array(
					'components' => array( __( 'Most-chosen components', 'laghavi-performance-insight-for-woocommerce' ), array( 'name' => __( 'Component', 'laghavi-performance-insight-for-woocommerce' ), 'chosen:n' => __( 'Chosen', 'laghavi-performance-insight-for-woocommerce' ) ) ),
					'held'       => array( __( 'Renewals held for out-of-stock items', 'laghavi-performance-insight-for-woocommerce' ), array( 'subscription' => __( 'Subscription', 'laghavi-performance-insight-for-woocommerce' ), 'item' => __( 'Item', 'laghavi-performance-insight-for-woocommerce' ), 'since:d' => __( 'Held since', 'laghavi-performance-insight-for-woocommerce' ) ) ),
				),
			),
			'languages'     => array(
				'title'  => __( 'Languages', 'laghavi-performance-insight-for-woocommerce' ),
				'groups' => array(
					__( 'Languages', 'laghavi-performance-insight-for-woocommerce' ) => array(
						'language_changes'  => array( __( 'Language changes', 'laghavi-performance-insight-for-woocommerce' ), 'n' ),
						'country_redirects' => array( __( 'Country redirects', 'laghavi-performance-insight-for-woocommerce' ), 'n' ),
					),
				),
				'chart'  => array( 'language_changes' => __( 'Language changes per day', 'laghavi-performance-insight-for-woocommerce' ) ),
				'tables' => array(
					'languages' => array( __( 'By language', 'laghavi-performance-insight-for-woocommerce' ), array( 'language' => __( 'Language', 'laghavi-performance-insight-for-woocommerce' ), 'views:n' => __( 'Views', 'laghavi-performance-insight-for-woocommerce' ), 'orders:n' => __( 'Orders', 'laghavi-performance-insight-for-woocommerce' ), 'revenue:m' => __( 'Revenue', 'laghavi-performance-insight-for-woocommerce' ), '_conv' => __( 'Conversion', 'laghavi-performance-insight-for-woocommerce' ) ) ),
				),
			),
			'logins'        => array(
				'title'  => __( 'Logins', 'laghavi-performance-insight-for-woocommerce' ),
				'groups' => array(
					__( 'Sign-ins', 'laghavi-performance-insight-for-woocommerce' ) => array(
						'sign_ins' => array( __( 'Sign-ins', 'laghavi-performance-insight-for-woocommerce' ), 'n' ),
						'sign_ups' => array( __( 'Sign-ups', 'laghavi-performance-insight-for-woocommerce' ), 'n' ),
					),
					__( 'Security', 'laghavi-performance-insight-for-woocommerce' ) => array(
						'failed'   => array( __( 'Failed logins', 'laghavi-performance-insight-for-woocommerce' ), 'n' ),
						'lockouts' => array( __( 'Lockouts', 'laghavi-performance-insight-for-woocommerce' ), 'n' ),
					),
				),
				'chart'  => array( 'sign_ins' => __( 'Sign-ins per day', 'laghavi-performance-insight-for-woocommerce' ) ),
				'tables' => array(
					'providers' => array( __( 'By provider', 'laghavi-performance-insight-for-woocommerce' ), array( 'provider' => __( 'Provider', 'laghavi-performance-insight-for-woocommerce' ), 'sign_ins:n' => __( 'Sign-ins', 'laghavi-performance-insight-for-woocommerce' ), 'sign_ups:n' => __( 'Sign-ups', 'laghavi-performance-insight-for-woocommerce' ) ) ),
				),
			),
		);
	}

	/**
	 * Format a value.
	 *
	 * @param mixed  $v Value.
	 * @param string $f Format.
	 * @return string
	 */
	public static function fmt( $v, $f ) {
		if ( '' === $v || null === $v ) {
			return '–';
		}
		switch ( $f ) {
			case 'm':
				return LGPI_Admin::money( (float) $v );
			case 'p':
				return number_format_i18n( (float) $v, 1 ) . '%';
			case 's':
				return LGPI_Admin::duration( (float) $v );
			case 'd':
				$ts = strtotime( (string) $v );
				return $ts ? date_i18n( 'j M Y', $ts ) : (string) $v;
			case 'n':
				return is_numeric( $v ) ? number_format_i18n( (float) $v, floor( (float) $v ) == $v ? 0 : 1 ) : (string) $v; // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual
			default:
				return (string) $v;
		}
	}

	/**
	 * Rinnovo tab: a sub-tab per installed Rinnovo plugin.
	 */
	public static function page_rinnovo() {
		$tabs = array();
		$secs = LGPI_Family::sections();
		foreach ( array( 'subscriptions', 'boxes', 'membership', 'media', 'contracts', 'sessions', 'bundles' ) as $s ) {
			if ( isset( $secs[ $s ] ) && 'rinnovo' === $secs[ $s ] ) {
				$tabs[ $s ] = self::sections()[ $s ]['title'];
			}
		}
		if ( ! $tabs && LGPI_Family::has( 'rinnovo' ) ) {
			$tabs['subscriptions'] = self::sections()['subscriptions']['title'];
		}
		$cur = sanitize_key( LGPI_Admin::view_arg( 'rt' ) );
		$cur = isset( $tabs[ $cur ] ) ? $cur : (string) array_key_first( $tabs );
		echo '<div class="lgpi-head"><div><h2>Rinnovo</h2><p>' . esc_html__( 'Subscriptions, licenses and the Rinnovo extensions installed on this store. Read-only — nothing in Rinnovo is changed here.', 'laghavi-performance-insight-for-woocommerce' ) . '</p></div></div>';
		echo '<div class="lgpi-chips lgpi-subtabs">';
		foreach ( $tabs as $k => $l ) {
			echo '<a class="' . esc_attr( $k === $cur ? 'on' : '' ) . '" href="' . esc_url( LGPI_Admin::url( 'lgpi-rinnovo', array( 'rt' => $k ) ) ) . '">' . esc_html( $l ) . '</a>';
		}
		echo '</div>';
		if ( 'subscriptions' === $cur ) {
			self::section( 'subscriptions', 'rinnovo' );
			self::section( 'licenses', 'rinnovo', true );
			if ( isset( $secs['quotes'] ) ) {
				self::section( 'quotes', 'rinnovo_pro', true );
			}
		} else {
			self::section( $cur, $cur );
		}
		LGPI_Recommend::render( 'rinnovo' );
	}

	/**
	 * Languages tab (Vacanam).
	 */
	public static function page_languages() {
		echo '<div class="lgpi-head"><div><h2>' . esc_html__( 'Languages', 'laghavi-performance-insight-for-woocommerce' ) . '</h2><p>' . esc_html__( 'Views, orders, revenue and conversion per language, from Vacanam Language Switcher.', 'laghavi-performance-insight-for-woocommerce' ) . '</p></div></div>';
		self::section( 'languages', 'vacanam' );
	}

	/**
	 * Logins tab (Sarala).
	 */
	public static function page_logins() {
		echo '<div class="lgpi-head"><div><h2>' . esc_html__( 'Logins', 'laghavi-performance-insight-for-woocommerce' ) . '</h2><p>' . esc_html__( 'Sign-ins and sign-ups by provider, failed logins and lockouts, from Sarala Login Page.', 'laghavi-performance-insight-for-woocommerce' ) . '</p></div></div>';
		self::section( 'logins', 'sarala' );
		LGPI_Recommend::render( 'logins' );
	}

	/**
	 * One section: actions, totals, per-currency breakdown, chart and tables.
	 *
	 * @param string $section Section.
	 * @param string $plugin  Plugin key (for the "waiting for data" note).
	 * @param bool   $quiet   Skip silently when there is no data (extra sections).
	 */
	private static function section( $section, $plugin, $quiet = false ) {
		list( $since, $until, $label ) = LGPI_Admin::range();
		$def                           = self::sections()[ $section ];
		$d                             = LGPI_Family::data( $section, $since, $until );
		if ( ! $d ) {
			if ( ! $quiet ) {
				$name = LGPI_Family::plugins()[ $plugin ][0] ?? $def['title'];
				echo '<div class="lgpi-card"><p><strong>' . esc_html( sprintf( /* translators: %s: plugin name */ __( '%s is active.', 'laghavi-performance-insight-for-woocommerce' ), $name ) ) . '</strong> ' . esc_html__( 'Its numbers will appear here as soon as the plugin shares its insights data (a Rinnovo, Vacanam or Sarala update). Nothing else is needed on your side.', 'laghavi-performance-insight-for-woocommerce' ) . '</p></div>';
			}
			return;
		}
		if ( $quiet ) {
			echo '<h3 class="lgpi-sechead">' . esc_html( $def['title'] ) . '</h3>';
		}
		$actions = LGPI_Family::actions( $section, $d );
		foreach ( $actions as $a ) {
			echo '<div class="lgpi-note lgpi-act ' . esc_attr( $a['level'] ) . '"><span class="dashicons ' . esc_attr( 'bad' === $a['level'] ? 'dashicons-warning' : 'dashicons-flag' ) . '"></span> ' . esc_html( $a['title'] ) . ( $a['url'] ? ' <a href="' . esc_url( $a['url'] ) . '">' . esc_html__( 'Open →', 'laghavi-performance-insight-for-woocommerce' ) . '</a>' : '' ) . '</div>';
		}
		$known = array();
		foreach ( $def['groups'] as $group => $metrics ) {
			$shown = array_intersect_key( $metrics, $d['totals'] );
			$known = array_merge( $known, array_keys( $metrics ) );
			if ( ! $shown ) {
				continue;
			}
			echo '<h4 class="lgpi-group">' . esc_html( $group ) . '</h4><div class="lgpi-kpis">';
			foreach ( $shown as $k => $m ) {
				LGPI_Admin::kpi( $m[0], self::fmt( $d['totals'][ $k ], $m[1] ) );
			}
			echo '</div>';
		}
		$extra = array_diff_key( $d['totals'], array_flip( array_merge( $known, array( 'failed_open' ) ) ) );
		if ( $extra ) {
			echo '<div class="lgpi-kpis">';
			foreach ( $extra as $k => $v ) {
				LGPI_Admin::kpi( ucfirst( str_replace( '_', ' ', $k ) ), self::fmt( $v, is_numeric( $v ) ? 'n' : 't' ) );
			}
			echo '</div>';
		}
		if ( $d['by_currency'] ) {
			echo '<div class="lgpi-card"><h3>' . esc_html__( 'By currency', 'laghavi-performance-insight-for-woocommerce' ) . '</h3><div class="lgpi-tablewrap"><table class="lgpi-table"><thead><tr><th>' . esc_html__( 'Currency', 'laghavi-performance-insight-for-woocommerce' ) . '</th>';
			$cols = array();
			foreach ( $d['by_currency'] as $vals ) {
				$cols = array_unique( array_merge( $cols, array_keys( $vals ) ) );
			}
			foreach ( $cols as $c ) {
				echo '<th class="n">' . esc_html( self::col_label( $c ) ) . '</th>';
			}
			echo '</tr></thead><tbody>';
			foreach ( $d['by_currency'] as $code => $vals ) {
				echo '<tr><td><strong>' . esc_html( $code ) . '</strong></td>';
				foreach ( $cols as $c ) {
					$v = $vals[ $c ] ?? null;
					echo '<td class="n">' . esc_html( null === $v ? '–' : ( str_ends_with( $c, '_store' ) ? LGPI_Admin::money( (float) $v ) : number_format_i18n( (float) $v, 2 ) . ' ' . $code ) ) . '</td>';
				}
				echo '</tr>';
			}
			echo '</tbody></table></div><p class="lgpi-muted">' . esc_html__( 'Amounts in each currency as charged; columns ending in "in store currency" are converted.', 'laghavi-performance-insight-for-woocommerce' ) . '</p></div>';
		}
		foreach ( $def['chart'] as $key => $title ) {
			$daily = array();
			foreach ( $d['daily'] as $r ) {
				if ( isset( $r[ $key ] ) ) {
					$daily[ $r['date'] ] = (int) round( (float) $r[ $key ] );
				}
			}
			if ( $daily ) {
				echo '<div class="lgpi-card"><h3>' . esc_html( $title ) . ' <span class="lgpi-muted">' . esc_html( $label ) . '</span></h3>';
				LGPI_Admin::day_chart( $daily, $since, $until );
				echo '</div>';
			}
		}
		$tables = $def['tables'];
		foreach ( array_diff_key( $d['tables'], $tables ) as $k => $rows ) {
			$tables[ $k ] = array( ucfirst( str_replace( '_', ' ', $k ) ), array() );
		}
		foreach ( $tables as $k => $tdef ) {
			$rows = (array) ( $d['tables'][ $k ] ?? array() );
			if ( ! $rows ) {
				continue;
			}
			$cols = $tdef[1] ? $tdef[1] : array_combine( array_keys( $rows[0] ), array_map( array( __CLASS__, 'col_label' ), array_keys( $rows[0] ) ) );
			unset( $cols['url'] );
			$short = 'expected_items' === $k ? wp_list_pluck( LGPI_Family::box_shortages( $d ), 'stock', 'name' ) : array();
			echo '<div class="lgpi-card"><h3>' . esc_html( $tdef[0] ) . '</h3><div class="lgpi-tablewrap"><table class="lgpi-table lgpi-sortable"><thead><tr>';
			foreach ( $cols as $ck => $cl ) {
				$hf = strpos( $ck, ':' ) ? substr( $ck, strpos( $ck, ':' ) + 1 ) : ( '_' === $ck[0] ? 'n' : 't' );
				echo '<th class="' . esc_attr( 't' !== $hf && 'd' !== $hf ? 'n' : '' ) . '">' . esc_html( $cl ) . '</th>';
			}
			echo '</tr></thead><tbody>';
			foreach ( array_slice( $rows, 0, 100 ) as $r ) {
				echo '<tr>';
				$first = true;
				foreach ( $cols as $ck => $cl ) {
					list( $key, $f ) = array_pad( explode( ':', $ck, 2 ), 2, 't' );
					if ( '_pct' === $ck ) {
						$inc = (float) ( $r['included'] ?? 0 );
						$pct = $inc ? (float) ( $r['used'] ?? 0 ) / $inc * 100 : null;
						echo '<td class="n ' . esc_attr( null !== $pct && $pct >= 90 ? 'lgpi-bad' : '' ) . '">' . esc_html( null === $pct ? '–' : number_format_i18n( $pct, 0 ) . '%' ) . '</td>';
						continue;
					}
					if ( '_util' === $ck ) {
						$u = LGPI_Family::util( $r );
						echo '<td class="n ' . esc_attr( null !== $u && $u < 40 ? 'lgpi-bad' : '' ) . '">' . esc_html( null === $u ? '–' : number_format_i18n( $u, 0 ) . '%' ) . '</td>';
						continue;
					}
					if ( '_conv' === $ck ) {
						$v = (float) ( $r['views'] ?? 0 );
						echo '<td class="n">' . esc_html( $v ? number_format_i18n( (float) ( $r['orders'] ?? 0 ) / $v * 100, 2 ) . '%' : '–' ) . '</td>';
						continue;
					}
					if ( '_stock' === $ck ) {
						$p = ! empty( $r['product_id'] ) && function_exists( 'wc_get_product' ) ? wc_get_product( (int) $r['product_id'] ) : null;
						$s = $p && $p->managing_stock() ? (int) $p->get_stock_quantity() : null;
						echo '<td class="n ' . esc_attr( isset( $short[ (string) ( $r['name'] ?? '' ) ] ) ? 'lgpi-bad' : '' ) . '">' . esc_html( null === $s ? '–' : number_format_i18n( $s ) ) . '</td>';
						continue;
					}
					$v    = $r[ $key ] ?? '';
					$cell = esc_html( self::fmt( $v, $f ) );
					if ( $first && ! empty( $r['url'] ) ) {
						$cell = '<a href="' . esc_url( $r['url'] ) . '">' . $cell . '</a>';
					}
					echo '<td class="' . esc_attr( 't' !== $f && 'd' !== $f ? 'n' : '' ) . '"' . ( is_numeric( $v ) ? ' data-v="' . esc_attr( (string) $v ) . '"' : '' ) . '>' . wp_kses( $cell, array( 'a' => array( 'href' => true ) ) ) . '</td>';
					$first = false;
				}
				echo '</tr>';
			}
			echo '</tbody></table></div></div>';
		}
		if ( $quiet ) {
			return;
		}
		echo '<p class="lgpi-muted">' . esc_html( sprintf( /* translators: %s: minutes */ __( 'Updated every %s minutes.', 'laghavi-performance-insight-for-woocommerce' ), (string) ( LGPI_Family::CACHE / 60 ) ) ) . ' <a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=lgpi_family_refresh' ), 'lgpi_family_refresh' ) ) . '">' . esc_html__( 'Refresh now', 'laghavi-performance-insight-for-woocommerce' ) . '</a></p>';
	}

	/**
	 * Column header from a key.
	 *
	 * @param string $k Key.
	 * @return string
	 */
	public static function col_label( $k ) {
		$k     = preg_replace( '/_store$/', '', (string) $k, 1, $n );
		$upper = array(
			'mrr' => 'MRR',
			'arr' => 'ARR',
		);
		$l     = $upper[ $k ] ?? ucfirst( str_replace( '_', ' ', $k ) );
		return $n ? $l . ' ' . __( '(in store currency)', 'laghavi-performance-insight-for-woocommerce' ) : $l;
	}

	/**
	 * "Refresh now".
	 */
	public static function refresh() {
		check_admin_referer( 'lgpi_family_refresh' );
		if ( ! LGPI_Family::allowed() ) {
			wp_die( esc_html__( 'Not allowed.', 'laghavi-performance-insight-for-woocommerce' ) );
		}
		LGPI_Family::flush();
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=lgpi' ) );
		exit;
	}

	/**
	 * Strip of family actions on the main dashboard.
	 */
	public static function dashboard_strip() {
		if ( ! LGPI_Family::allowed() || ! LGPI_Family::sections() ) {
			return;
		}
		$acts = LGPI_Family::all_actions();
		if ( ! $acts ) {
			return;
		}
		$parts = array();
		foreach ( array_slice( $acts, 0, 4 ) as $a ) {
			$page    = 'languages' === $a['tab'] ? 'lgpi-languages' : ( 'logins' === $a['tab'] ? 'lgpi-logins' : 'lgpi-rinnovo' );
			$parts[] = '<a class="lgpi-pill ' . esc_attr( 'bad' === $a['level'] ? 'st-out' : 'st-low' ) . '" href="' . esc_url( LGPI_Admin::url( $page ) ) . '">' . esc_html( $a['title'] ) . '</a>';
		}
		echo '<div class="lgpi-note lgpi-stockstrip"><span class="dashicons dashicons-update"></span> <strong>' . esc_html__( 'To do', 'laghavi-performance-insight-for-woocommerce' ) . '</strong> ' . wp_kses(
			implode( ' ', $parts ),
			array(
				'a' => array(
					'class' => true,
					'href'  => true,
				),
			)
		) . '</div>';
	}
}
