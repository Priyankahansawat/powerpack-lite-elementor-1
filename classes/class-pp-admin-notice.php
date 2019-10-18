<?php
/**
 * Original Author: danieliser
 * Original Author URL: https://danieliser.com
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Prefix_Modules_Reviews
 *
 * This class adds a review request system for your plugin or theme to the WP dashboard.
 */
class PP_Modules_Reviews {

	/**
	 * Tracking API Endpoint.
	 *
	 * @var string
	 */
	public static $api_url = '';

	/**
	 *
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'hooks' ) );
		add_action( 'wp_ajax_pp_review_action', array( __CLASS__, 'ajax_handler' ) );
	}

	/**
	 * Hook into relevant WP actions.
	 */
	public static function hooks() {
		if ( is_admin() && current_user_can( 'edit_posts' ) ) {
			self::installed_on();
			add_action( 'admin_notices', array( __CLASS__, 'admin_notices' ) );
			add_action( 'network_admin_notices', array( __CLASS__, 'admin_notices' ) );
			add_action( 'user_admin_notices', array( __CLASS__, 'admin_notices' ) );
		}
	}

	/**
	 * Get the install date for comparisons. Sets the date to now if none is found.
	 *
	 * @return false|string
	 */
	public static function installed_on() {
		$installed_on = get_option( 'pp_reviews_installed_on', false );

		if ( ! $installed_on ) {
			$installed_on = current_time( 'mysql' );
			update_option( 'pp_reviews_installed_on', $installed_on );
		}

		return $installed_on;
	}

	/**
	 *
	 */
	public static function ajax_handler() {
		$args = wp_parse_args( $_REQUEST, array(
			'group'  => self::get_trigger_group(),
			'code'   => self::get_trigger_code(),
			'pri'    => self::get_current_trigger( 'pri' ),
			'reason' => 'maybe_later',
		) );

		if ( ! wp_verify_nonce( $_REQUEST['nonce'], 'pp_review_action' ) ) {
			wp_send_json_error();
		}

		try {
			$user_id = get_current_user_id();

			$dismissed_triggers                   = self::dismissed_triggers();
			$dismissed_triggers[ $args['group'] ] = $args['pri'];
			update_user_meta( $user_id, '_pp_reviews_dismissed_triggers', $dismissed_triggers );
			update_user_meta( $user_id, '_pp_reviews_last_dismissed', current_time( 'mysql' ) );

			switch ( $args['reason'] ) {
				case 'maybe_later':
					update_user_meta( $user_id, '_pp_reviews_last_dismissed', current_time( 'mysql' ) );
					break;
				case 'am_now':
				case 'already_did':
					self::already_did( true );
					break;
			}

			wp_send_json_success();

		} catch ( Exception $e ) {
			wp_send_json_error( $e );
		}
	}

	/**
	 * @return int|string
	 */
	public static function get_trigger_group() {
		static $selected;

		if ( ! isset( $selected ) ) {

			$dismissed_triggers = self::dismissed_triggers();

			$triggers = self::triggers();

			foreach ( $triggers as $g => $group ) {
				foreach ( $group['triggers'] as $t => $trigger ) {
					if ( ! in_array( false, $trigger['conditions'] ) && ( empty( $dismissed_triggers[ $g ] ) || $dismissed_triggers[ $g ] < $trigger['pri'] ) ) {
						$selected = $g;
						break;
					}
				}

				if ( isset( $selected ) ) {
					break;
				}
			}
		}
		return $selected;
	}

	/**
	 * @return int|string
	 */
	public static function get_trigger_code() {
		static $selected;

		if ( ! isset( $selected ) ) {

			$dismissed_triggers = self::dismissed_triggers();

			foreach ( self::triggers() as $g => $group ) {
				foreach ( $group['triggers'] as $t => $trigger ) {
					if ( ! in_array( false, $trigger['conditions'] ) && ( empty( $dismissed_triggers[ $g ] ) || $dismissed_triggers[ $g ] < $trigger['pri'] ) ) {
						$selected = $t;
						break;
					}
				}

				if ( isset( $selected ) ) {
					break;
				}
			}
		}

		return $selected;
	}

	/**
	 * @param null $key
	 *
	 * @return bool|mixed|void
	 */
	public static function get_current_trigger( $key = null ) {
		$group = self::get_trigger_group();
		$code  = self::get_trigger_code();

		if ( ! $group || ! $code ) {
			return false;
		}

		$trigger = self::triggers( $group, $code );

		return empty( $key ) ? $trigger : ( isset( $trigger[ $key ] ) ? $trigger[ $key ] : false );
	}

	/**
	 * Returns an array of dismissed trigger groups.
	 *
	 * Array contains the group key and highest priority trigger that has been shown previously for each group.
	 *
	 * $return = array(
	 *   'group1' => 20
	 * );
	 *
	 * @return array|mixed
	 */
	public static function dismissed_triggers() {
		$user_id = get_current_user_id();

		$dismissed_triggers = get_user_meta( $user_id, '_pp_reviews_dismissed_triggers', true );

		if ( ! $dismissed_triggers ) {
			$dismissed_triggers = array();
		}

		return $dismissed_triggers;
	}

	/**
	 * Returns true if the user has opted to never see this again. Or sets the option.
	 *
	 * @param bool $set If set this will mark the user as having opted to never see this again.
	 *
	 * @return bool
	 */
	public static function already_did( $set = false ) {
		$user_id = get_current_user_id();

		if ( $set ) {
			update_user_meta( $user_id, '_pp_reviews_already_did', true );

			return true;
		}

		return (bool) get_user_meta( $user_id, '_pp_reviews_already_did', true );
	}

	/**
	 *  Triggers and Messages
	 */

	 public static function get_trigger ($trigger_id) {

		if(	"feedback" === $trigger_id	) {
		
			$message	=	[
					"message"	=>	"Loving PowerPack? We'll love to hear more about it from you. Please share your feelings with us by clicking the links below.",
					"link-1"	=>	["Feedback", "https://#"],
					"link-2"	=>	["Support",	"https://#"],
			];
		} else {
			$message = "PowerPackkko!";	
		} 

		return $message;
	 }

	/**
	 * Gets a list of triggers.
	 *
	 * @param null $group
	 * @param null $code
	 *
	 * @return bool|mixed|void
	 */
	public static function triggers( $group = null, $code = null ) {
		static $triggers;

		if ( ! isset( $triggers ) ) {

			$review_message		=	__( "Hey, we hope that you're loving PowerPack. It'll be really awesome if you can give us 5-star rating on WordPress.org", "powerpack" );
			$feeback_message 	=	__(	"Loving PowerPack? It'll be really motivating if you can leave us a feedback!", "powerpack"	);
			$upgrade_messsage	=	__(	"Upgrade now to PowerPack Pro to get 15% OFF and a lot more premium features like 60+ Premium Widgets, Display Extensions", "powerpack"	);

			$triggers = apply_filters( 'pp_reviews_triggers', array(
				'time_installed' => array(
					'triggers' => array(
						'five_minutes'     => array(
							'message'    => $review_message,
							'conditions' => array(
								strtotime( self::installed_on() . ' +5 minutes' ) < time(),
							),
							'link'       => 'https://wordpress.org/support/plugin/powerpack-lite-for-elementor/reviews/?rate=5#rate-response',
							'pri'        => 10,
						),						
						'one_week'    => array(
							'message'    => $feeback_message,
							'conditions' => array(
								strtotime( self::installed_on() . ' +1 week' ) < time(),
							),
							'link'       => 'https://wordpress.org/support/plugin/powerpack-lite-for-elementor/reviews/?rate=5#rate-response',
							'pri'        => 20,
						),
						'one_month'    => array(
							'message'    => $upgrade_messsage,
							'conditions' => array(
								strtotime( self::installed_on() . ' +1 month' ) < time(),
							),
							'link'       => 'https://wordpress.org/support/plugin/powerpack-lite-for-elementor/reviews/?rate=5#rate-response',
							'pri'        => 30,
						),
						'three_months' => array(
							'message'    => $upgrade_messsage,
							'conditions' => array(
								strtotime( self::installed_on() . ' +3 months' ) < time(),
							),
							'link'       => 'https://wordpress.org/support/plugin/powerpack-lite-for-elementor/reviews/?rate=5#rate-response',
							'pri'        => 40,
						),

					),
					'pri'      => 10,
				),
				'open_count'     => array(
					'triggers' => array(),
					'pri'      => 50,
				),
			) );

			$open_message = __( "Hey, we hope that you're loving PowerPack. It'll be really awesome if you can give us 5-star rating on WordPress.org", 'powerpack' );

			$priority = 10;
			foreach ( array( 5,10,50, 100, 500, 1000, 5000, 10000, 50000, 100000, 500000, 1000000, 5000000 ) as $num ) {
				$triggers['open_count']['triggers'][ $num . '_opens' ] = array(
					'message'    => sprintf( $open_message, number_format( $num ) ),
					'conditions' => array(
						get_option( 'pp_total_open_count', 0 ) > $num,
					),
					'link'       => 'https://wordpress.org/support/plugin/powerpack-lite-for-elementor/reviews/?rate=5#rate-response',
					'pri'        => $priority,
				);

				$priority += 10;
			}



			// Sort Groups
			uasort( $triggers, array( __CLASS__, 'rsort_by_priority' ) );

			// Sort each groups triggers.
			foreach ( $triggers as $k => $v ) {
				uasort( $triggers[ $k ]['triggers'], array( __CLASS__, 'rsort_by_priority' ) );
			}
		}

		if ( isset( $group ) ) {
			if ( ! isset( $triggers[ $group ] ) ) {
				return false;
			}

			return ! isset( $code ) ? $triggers[ $group ] : isset( $triggers[ $group ]['triggers'][ $code ] ) ? $triggers[ $group ]['triggers'][ $code ] : false;
		}

		return $triggers;
	}

	/**
	 * Render admin notices if available.
	 */
	public static function admin_notices() {
		if ( self::hide_notices() ) {
			return;
		}

		$group  = self::get_trigger_group();
		$code   = self::get_trigger_code();
		$pri    = self::get_current_trigger( 'pri' );
		$tigger = self::get_current_trigger();

		// Used to anonymously distinguish unique site+user combinations in terms of effectiveness of each trigger.
		$uuid = wp_hash( home_url() . '-' . get_current_user_id() );

		?>

		<script type="text/javascript">
			(function ($) {
				var trigger = {
					group: '<?php echo $group; ?>',
					code: '<?php echo $code; ?>',
					pri: '<?php echo $pri; ?>'
				};

				function dismiss(reason) {
					$.ajax({
						method: "POST",
						dataType: "json",
						url: ajaxurl,
						data: {
							action: 'pp_review_action',
							nonce: '<?php echo wp_create_nonce( 'pp_review_action' ); ?>',
							group: trigger.group,
							code: trigger.code,
							pri: trigger.pri,
							reason: reason
						}
					});

					<?php if ( ! empty( self::$api_url ) ) : ?>
					$.ajax({
						method: "POST",
						dataType: "json",
						url: '<?php echo self::$api_url; ?>',
						data: {
							trigger_group: trigger.group,
							trigger_code: trigger.code,
							reason: reason,
							uuid: '<?php echo $uuid; ?>'
						}
					});
					<?php endif; ?>
				}

				$(document)
					.on('click', '.pp-notice .pp-dismiss', function (event) {
						var $this = $(this),
							reason = $this.data('reason'),
							notice = $this.parents('.pp-notice');

						notice.fadeTo(100, 0, function () {
							notice.slideUp(100, function () {
								notice.remove();
							});
						});

						dismiss(reason);
					})
					.ready(function () {
						setTimeout(function () {
							$('.pp-notice button.notice-dismiss').click(function (event) {
								dismiss('maybe_later');
							});
						}, 1000);
					});
			}(jQuery));
		</script>

		<link type="text/css" rel="stylesheet" href="<?php echo POWERPACK_ELEMENTS_LITE_URL . 'assets/css/pp-admin-notice.css'; ?>"  />

		<div class="notice is-dismissible pp-notice">

			<div class="pp-admin-review-logo-wrapper">
				<a href="https://powerpackelements.com/" title="Visit PowerPack for Elementor's Website" target="_blank">
					<img class="logo" src="<?php echo POWERPACK_ELEMENTS_LITE_URL . 'assets/images/pp-elements-brandmark.svg'; ?>" />
				</a>
			</div>

			<div class="pp-admin-review-msg-wrapper">
				<p class="pp-admin-message">
					<?php echo $tigger['message']; ?>
				</p>
				<div>
					<ul class="pp-admin-review-options-list">
						
						<li>
							<a class="pp-dismiss pp-deserve" target="_blank" href="https://wordpress.org/support/plugin/powerpack-lite-for-elementor/reviews/?rate=5#rate-response" data-reason="am_now">
								<strong><?php _e( 'Sure!', 'powerpack' ); ?></strong>
							</a>
						</li>
						<li>
							<a href="#" class="pp-dismiss pp-maybe" data-reason="maybe_later">
								<?php _e( 'Maybe Later', 'powerpack' ); ?>
							</a>
						</li>
						<li>
							<a href="#" class="pp-dismiss pp-already" data-reason="already_did">
								<?php _e( 'I already did', 'powerpack' ); ?>
							</a>
						</li>
						<li>
							<a href="https://powerpackelements.com/contact/" class="pp-support">
								<?php _e( 'Need Help?', 'powerpack' ); ?>
							</a>
						</li>						
					</ul>
				</div>
			</div>
		</div>

		<?php
	}

	/**
	 * Checks if notices should be shown.
	 *
	 * @return bool
	 */
	public static function hide_notices() {
		$conditions = array(
			self::already_did(),
			self::last_dismissed() && strtotime( self::last_dismissed() . ' +2 weeks' ) > time(),
			empty( self::get_trigger_code() ),
		);

		return in_array( true, $conditions );
	}

	/**
	 * Gets the last dismissed date.
	 *
	 * @return false|string
	 */
	public static function last_dismissed() {
		$user_id = get_current_user_id();

		return get_user_meta( $user_id, '_pp_reviews_last_dismissed', true );
	}

	/**
	 * Sort array by priority value
	 *
	 * @param $a
	 * @param $b
	 *
	 * @return int
	 */
	public static function sort_by_priority( $a, $b ) {
		if ( ! isset( $a['pri'] ) || ! isset( $b['pri'] ) || $a['pri'] === $b['pri'] ) {
			return 0;
		}

		return ( $a['pri'] < $b['pri'] ) ? - 1 : 1;
	}

	/**
	 * Sort array in reverse by priority value
	 *
	 * @param $a
	 * @param $b
	 *
	 * @return int
	 */
	public static function rsort_by_priority( $a, $b ) {
		if ( ! isset( $a['pri'] ) || ! isset( $b['pri'] ) || $a['pri'] === $b['pri'] ) {
			return 0;
		}

		return ( $a['pri'] < $b['pri'] ) ? 1 : - 1;
	}

}

PP_Modules_Reviews::init();
