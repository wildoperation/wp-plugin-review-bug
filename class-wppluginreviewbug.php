<?php
/**
 * Version 1.1.6
 *
 * Update Namespace to avoid plugin conflicts.
 *
 * @package WPPluginReviewBug
 */

namespace WOWPRB;

/**
 * Handle review banner for a WordPress plugin.
 */
class WPPluginReviewBug {
	/**
	 * Plugin 'slug' (filename w/o path or extenstion)
	 *
	 * @var string $slug
	 */
	private $slug;

	/**
	 * Prefix used on options, vars, etc.
	 *
	 * @var string $prefix
	 */
	private $prefix;

	/**
	 * Show the review notice to users with this capability.
	 *
	 * @var string $capability
	 */
	private $capability;

	/**
	 * The number of days to snooze before showing again.
	 *
	 * @var int $snooze_days
	 */
	private $snooze_days;

	/**
	 * The URL to review the plugin.
	 *
	 * @var string $review_url
	 */
	private $review_url;

	/**
	 * The URL to a help page.
	 *
	 * @var string $need_help_url
	 */
	private $need_help_url;

	/**
	 * The class on the notice message.
	 *
	 * @var string
	 */
	private $notice_class;

	/**
	 * The messages displayed to the user.
	 *
	 * @var array $messages
	 */
	private $messages;

	/**
	 * Sets variables, creates hooks.
	 *
	 * @param string $plugin_file Pass the plugin file (__FILE__) to the class.
	 * @param string $plugin_slug The plugin slug on the WordPress repository. If empty, one will be generated from the filename.
	 * @param array  $messages An array of translated messages.
	 * @param array  $args An array of settings that overrides default arguments.
	 */
	public function __construct( $plugin_file, $plugin_slug, $messages, $args = array() ) {

		if ( is_admin() ) {

			$default_args = array(
				'capability'    => 'manage_options',
				'prefix'        => 'worb',
				'snooze_days'   => 7,
				'review_url'    => '',
				'notice_class'  => 'notice-info',
				'need_help_url' => '',
			);

			$args = wp_parse_args( $args, $default_args );

			/**
			 * Setup variables
			 */
			$this->slug          = sanitize_title( $plugin_slug );
			$this->prefix        = ( $args['prefix'] !== '' ) ? sanitize_title( $args['prefix'] ) : $default_args['prefix'];
			$this->capability    = ( $args['capability'] !== '' ) ? $args['capability'] : $default_args['capability'];
			$this->snooze_days   = ( intval( $args['snooze_days'] ) > 0 ) ? intval( $args['snooze_days'] ) : $default_args['snooze_days'];
			$this->review_url    = ( $args['review_url'] != '' ) ? $args['review_url'] : $this->default_review_url();
			$this->need_help_url = $args['need_help_url'];
			$this->notice_class  = $args['notice_class'];
			$this->messages      = $messages;

			/**
			 * Plugin activation hook
			 */
			register_activation_hook( $plugin_file, array( $this, 'set_check_timestamp' ) );
			register_deactivation_hook( $plugin_file, array( $this, 'deactivation_cleanup' ) );

			/**
			 * Hooks
			 */
			add_action( 'admin_init', array( $this, 'check' ), 20 );
		}
	}

	/**
	 * Delete any stored data on deactivation.
	 *
	 * @return void
	 */
	public function deactivation_cleanup() {
		delete_option( $this->get_option_name( 'check' ) );
		delete_option( $this->get_option_name( 'nobug' ) );
	}

	/**
	 * Create a relevant option name
	 *
	 * @param string $suffix Appended to prefix and plugin slug.
	 *
	 * @return string
	 */
	private function get_option_name( $suffix ) {
		$option_name = implode( '_', array( $this->prefix, $this->slug, $suffix ) );

		if ( strlen( $option_name ) > 150 ) {
			error_log( 'long option name. consider revising. ' . $option_name );
		}

		return $option_name;
	}

	/**
	 * Sets the check timestamp option if one does not exist. Or overrides if forced.
	 *
	 * @return void
	 */
	public function set_check_timestamp( $force = false ) {
		if ( $force ) {
			update_option( $this->get_option_name( 'check' ), time() );
		} elseif ( ! get_option( $this->get_option_name( 'check' ) ) ) {
			add_option( $this->get_option_name( 'check' ), time() );
		}
	}

	/**
	 * Compares current timestamp to the check timestamp. Adds admin_notices hook if necessary.
	 *
	 * @return void
	 */
	public function check() {

		if ( current_user_can( $this->capability ) ) {
			if ( ! get_option( $this->get_option_name( 'nobug' ) ) ) {
				$check_timestamp = get_option( $this->get_option_name( 'check' ) );

				if ( ! $check_timestamp ) {
					$this->set_check_timestamp();
				} else {

					$notice_date = strtotime( '+' . $this->snooze_days . ' days', $check_timestamp );

					if ( time() >= $notice_date ) {
						add_action( 'admin_notices', array( $this, 'display_admin_notice' ) );
						add_action( 'wp_ajax_' . $this->action_string(), array( $this, 'actions' ) );
						add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
						add_action( 'admin_print_footer_scripts', array( $this, 'notice_script' ) );
					}
				}
			}
		}
	}

	/**
	 * Sets the nobug option in the database
	 *
	 * @return void
	 */
	private function set_nobug_option() {
		update_option( $this->get_option_name( 'nobug' ), true );
	}

	/**
	 * Creates a URL to plugin reviews.
	 *
	 * @return string
	 */
	private function default_review_url() {
		return 'https://wordpress.org/support/plugin/' . $this->slug . '/reviews/';
	}

	/**
	 * Get the current URL.
	 *
	 * @return string
	 */
	public static function current_url() {
		$url         = ( isset( $_SERVER['HTTPS'] ) && sanitize_text_field( wp_unslash( $_SERVER['HTTPS'] ) ) == 'on' ? 'https' : 'http' ) . '://';
		$host        = ( isset( $_SERVER['HTTP_HOST'] ) ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		$request_uri = ( isset( $_SERVER['REQUEST_URI'] ) ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

		return sanitize_url( $url . $host . $request_uri );
	}

	/**
	 * Displays the admin review banner.
	 */
	public function display_admin_notice() {
		$showhelp = isset( $this->need_help_url ) && $this->need_help_url ? true : false;

		if ( $showhelp && ( strtolower( $this->need_help_url ) === strtolower( $this->current_url() ) ) ) {
			$showhelp = false;
		}
		?>
		<style type="text/css">
			#<?php echo esc_attr( $this->prefix ); ?>-<?php echo esc_attr( $this->slug ); ?> .actions a { margin-left: 10px; }
			#<?php echo esc_attr( $this->prefix ); ?>-<?php echo esc_attr( $this->slug ); ?> .actions a:first-child { margin-left: 0; }
		</style>
		<div class="notice <?php echo esc_attr( $this->notice_class ); ?> is-dismissible" id="<?php echo esc_attr( $this->prefix ); ?>-<?php echo esc_attr( $this->slug ); ?>">
			<p><?php echo esc_html( $this->messages['intro'] ); ?></p>
			<p class="actions">
				<a id="<?php echo esc_attr( $this->prefix ); ?>-rate-<?php echo esc_attr( $this->slug ); ?>" href="<?php echo esc_url( $this->review_url ); ?>" target="_blank" rel="noopener noreferrer" class="<?php echo esc_attr( $this->prefix ); ?>-rate <?php echo esc_attr( $this->prefix ); ?>-action button button-primary">
					<?php echo esc_html( $this->messages['rate_link_text'] ); ?>
				</a>
				<?php
				if ( $showhelp ) :
					if ( stripos( $this->need_help_url, site_url() ) !== false ) {
						$target = '';
					} else {
						$target = ' target="_blank" rel="noopener noreferrer"';
					}
					?>
				<a id="<?php echo esc_attr( $this->prefix ); ?>-help-<?php echo esc_attr( $this->slug ); ?>" href="<?php echo esc_url( $this->need_help_url ); ?>" class="<?php echo esc_attr( $this->prefix ); ?>-help button button-secondary"<?php echo $target; ?>>
					<?php echo esc_html( $this->messages['need_help_text'] ); ?>
				</a>
				<?php endif; ?>
				<a id="<?php echo esc_attr( $this->prefix ); ?>-later-<?php echo esc_attr( $this->slug ); ?>" href="#" class="<?php echo esc_attr( $this->prefix ); ?>-action <?php echo esc_attr( $this->prefix ); ?>-later"><?php echo esc_html( $this->messages['remind_link_text'] ); ?></a>
				<a id="<?php echo esc_attr( $this->prefix ); ?>-nobug-<?php echo esc_attr( $this->slug ); ?>" href="#" class="<?php echo esc_attr( $this->prefix ); ?>-action <?php echo esc_attr( $this->prefix ); ?>-nobug"><?php echo esc_html( $this->messages['nobug_link_text'] ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * This all needs to be done
	 */
	public function actions() {

		check_ajax_referer( $this->nonce_string(), 'security' );

		if ( ! current_user_can( $this->capability ) || ! isset( $_POST['action_performed'] ) ) {
			wp_die();
		}

		$action_performed = strtolower( sanitize_text_field( wp_unslash( $_POST['action_performed'] ) ) );

		if ( 'worb-later' === $action_performed ) {
			$this->set_check_timestamp( true );
		} elseif ( 'worb-nobug' === $action_performed ) {
			$this->set_nobug_option();
		}

		wp_die();
	}

	public function enqueue() {
		wp_enqueue_script( 'jquery' );
	}

	private function nonce_string() {
		return $this->prefix . '-n-' . $this->slug;
	}

	private function action_string() {
		return str_replace(
			'-',
			'_',
			$this->prefix . '_' . $this->slug
		);
	}

	public function notice_script() {

		$ajax_nonce = wp_create_nonce( $this->nonce_string() );

		?>

		<script type="text/javascript" id="<?php echo esc_attr( $this->prefix ); ?>-<?php echo esc_attr( $this->slug ); ?>-script">
			jQuery( document ).ready( function( $ ){

				var $actions = $('#<?php echo esc_html( $this->prefix ); ?>-<?php echo esc_html( $this->slug ); ?> .<?php echo esc_html( $this->prefix ); ?>-action');

				function close_wowprcv_notice($notice) {
					$notice.slideUp('fast', function() {
						$notice.remove();
					})
				}

				function wowprcv_post_data($elem, this_action) {
					var data = {
						action: '<?php echo esc_html( $this->action_string() ); ?>',
						security: '<?php echo esc_html( $ajax_nonce ); ?>',
						action_performed: this_action,
					};

					$.post( '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', data, function( response ) {
						close_wowprcv_notice($actions.closest('.notice'));
					});
				}

				$actions.click( function( e ){
					var $this = $(this);
					var action_performed = $this.hasClass('<?php echo esc_html( $this->prefix ); ?>-nobug') ? 'worb-nobug' : 'worb-later';

					if (!$this.hasClass('<?php echo esc_html( $this->prefix ); ?>-rate')) {
						e.preventDefault();
						wowprcv_post_data($this, action_performed);
					}
				} );

				$actions.closest('.notice').on('click', '.notice-dismiss', function(){
					wowprcv_post_data($(this), 'worb-later');
				});

			});
		</script>

		<?php
	}
}
