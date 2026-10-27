<?php
/**
 * Front end: builds the tested page with every version in it, and prints
 * the inline CSS and script that show each visitor theirs.
 *
 * Nothing at all is added to pages without a running test. A tested page
 * gets one small <style> and one inline <script> at the top of <head>: the
 * script picks (or remembers) the visitor's variant and sets a class on
 * <html> before anything is painted, so there's no flicker and no extra
 * request, and it works the same from a page cache. Script optimizers are
 * told to leave both alone, because a delayed script would mean a flash of
 * the original.
 *
 * Admins can preview any variant (?fabt_preview=, with a nonce) and pick
 * goal elements on the live page (?fabt_pick=, with a nonce). Both are
 * marked uncacheable and never count.
 *
 * @package Free_A_B_Testing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Front-end output.
 */
final class Free_A_B_Testing_Frontend {

	/**
	 * Query args page builders add to their editing previews.
	 */
	const BUILDER_ARGS = array( 'elementor-preview', 'fl_builder', 'et_fb', 'ct_builder', 'bricks', 'brizy-edit-iframe', 'vcv-editable', 'tve', 'uxb_iframe', 'breakdance_iframe' );

	/**
	 * Goal type => code used by the script.
	 */
	const GOAL_CODES = array(
		'click' => 'c',
		'form'  => 'f',
		'tel'   => 't',
		'woo'   => 'w',
	);

	/**
	 * Plugin instance.
	 *
	 * @var Free_A_B_Testing_Plugin
	 */
	private $plugin;

	/**
	 * This request's test context, false for none, null until worked out.
	 *
	 * @var array|false|null
	 */
	private $context = null;

	/**
	 * Merged content for the tested page, once built.
	 *
	 * @var string|null
	 */
	private $merged = null;

	/**
	 * Constructor.
	 *
	 * @param Free_A_B_Testing_Plugin $plugin Plugin instance.
	 */
	public function __construct( Free_A_B_Testing_Plugin $plugin ) {
		$this->plugin = $plugin;

		add_action( 'init', array( 'Free_A_B_Testing_Merge', 'register_block' ) );
		add_action( 'template_redirect', array( $this, 'no_cache_for_admin_views' ) );
		add_filter( 'content_pagination', array( $this, 'content' ), 10, 2 );
		add_filter( 'the_title', array( $this, 'title' ), 99, 2 );
		add_action( 'wp_head', array( $this, 'print_head' ), 1 );
		add_action( 'wp_footer', array( $this, 'print_preview_bar' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_picker' ) );
		add_action( 'admin_bar_menu', array( $this, 'admin_bar' ), 90 );

		add_filter( 'rocket_delay_js_exclusions', array( $this, 'exclude_scripts' ) );
		add_filter( 'rocket_excluded_inline_js_content', array( $this, 'exclude_scripts' ) );
		add_filter( 'rocket_defer_inline_exclusions', array( $this, 'exclude_scripts' ) );
		add_filter( 'rocket_rucss_inline_content_exclusions', array( $this, 'exclude_scripts' ) );
		add_filter( 'rocket_rucss_inline_atts_exclusions', array( $this, 'exclude_style_attributes' ) );
		add_filter( 'perfmatters_delay_js_exclusions', array( $this, 'exclude_scripts' ) );
		add_filter( 'autoptimize_filter_js_exclude', array( $this, 'exclude_csv' ) );
		add_filter( 'autoptimize_filter_css_exclude', array( $this, 'exclude_csv' ) );
	}

	// Context ---------------------------------------------------------------

	/**
	 * The test on the current page, if it has one: array( test, slots,
	 * mode, slot ). Mode is live, preview (an admin viewing one variant) or
	 * pick (an admin choosing a goal element; no test shown).
	 *
	 * @return array|null
	 */
	public function context() {
		if ( null !== $this->context ) {
			return $this->context ? $this->context : null;
		}

		if ( ! did_action( 'wp' ) ) {
			return null;
		}

		$this->context = false;

		if ( is_admin()
			|| wp_doing_ajax()
			|| ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			|| ! is_singular()
			|| is_feed()
			|| is_embed()
			|| is_customize_preview()
			|| ( function_exists( 'amp_is_request' ) && amp_is_request() ) ) {
			return null;
		}

		$post_id = (int) get_queried_object_id();

		if ( ! $post_id ) {
			return null;
		}

		$admin = $this->admin_view( $post_id );

		if ( $admin ) {
			$this->context = $admin;

			return $this->context;
		}

		// Builders' editing screens get the plain page.
		if ( current_user_can( 'edit_posts' ) ) {
			foreach ( self::BUILDER_ARGS as $arg ) {
				if ( isset( $_GET[ $arg ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only check.
					return null;
				}
			}
		}

		$test = Free_A_B_Testing_Tests::live_for( $post_id );

		if ( ! $test ) {
			return null;
		}

		$slots = Free_A_B_Testing_Tests::active_slots( $test );

		if ( count( $slots ) < 2 || 0 !== $slots[0] ) {
			return null;
		}

		$this->context = array(
			'test'  => $test,
			'slots' => $slots,
			'mode'  => 'live',
			'slot'  => -1,
		);

		return $this->context;
	}

	/**
	 * A preview or element-picking view, for admins with a valid link.
	 *
	 * @param int $post_id Current page.
	 * @return array|null
	 */
	private function admin_view( $post_id ) {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- The nonce is checked right here.
		$nonce = isset( $_GET['_fabt'] ) && is_string( $_GET['_fabt'] ) ? sanitize_key( wp_unslash( $_GET['_fabt'] ) ) : '';

		if ( '' === $nonce || ! current_user_can( Free_A_B_Testing_Tests::CAP ) ) {
			return null;
		}

		if ( isset( $_GET['fabt_pick'] ) && wp_verify_nonce( $nonce, 'fabt_pick' ) ) {
			return array(
				'test'  => null,
				'slots' => array(),
				'mode'  => 'pick',
				'slot'  => -1,
				'field' => 'form' === $_GET['fabt_pick'] ? 'form' : 'click',
			);
		}

		if ( ! isset( $_GET['fabt_preview'] ) || ! is_string( $_GET['fabt_preview'] ) || ! preg_match( '/^(\d{1,10})\.(\d{1,3})$/D', sanitize_text_field( wp_unslash( $_GET['fabt_preview'] ) ), $m ) ) {
			return null;
		}
		// phpcs:enable

		$test = Free_A_B_Testing_Tests::get( (int) $m[1] );

		if ( ! $test || $test['post_id'] !== $post_id || ! wp_verify_nonce( $nonce, 'fabt_preview_' . $test['id'] ) ) {
			return null;
		}

		$slots = Free_A_B_Testing_Tests::active_slots( $test );
		$slot  = (int) $m[2];

		if ( ! in_array( $slot, $slots, true ) || 0 !== $slots[0] ) {
			return null;
		}

		return array(
			'test'  => $test,
			'slots' => $slots,
			'mode'  => 'preview',
			'slot'  => $slot,
		);
	}

	/**
	 * Link that previews one variant of a test.
	 *
	 * @param array $test Test.
	 * @param int   $slot Slot.
	 * @return string
	 */
	public static function preview_url( array $test, $slot ) {
		$url = get_permalink( $test['post_id'] );

		return $url ? add_query_arg(
			array(
				'fabt_preview' => $test['id'] . '.' . (int) $slot,
				'_fabt'        => wp_create_nonce( 'fabt_preview_' . $test['id'] ),
			),
			$url
		) : '';
	}

	/**
	 * Link that opens the element picker on a page.
	 *
	 * @param int    $post_id Page.
	 * @param string $field   click or form.
	 * @return string
	 */
	public static function pick_url( $post_id, $field ) {
		$url = get_permalink( $post_id );

		return $url ? add_query_arg(
			array(
				'fabt_pick' => 'form' === $field ? 'form' : 'click',
				'_fabt'     => wp_create_nonce( 'fabt_pick' ),
			),
			$url
		) : '';
	}

	/**
	 * Keep preview and picker views out of page caches.
	 *
	 * @return void
	 */
	public function no_cache_for_admin_views() {
		$context = $this->context();

		if ( $context && 'live' !== $context['mode'] ) {
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true );
			}

			nocache_headers();
		}
	}

	// Content -----------------------------------------------------------------

	/**
	 * Swap the tested page's content for the merged version. Hooked to
	 * content_pagination, which is where WordPress reads a post's content
	 * for display (in classic and block themes alike), and only for the
	 * page being viewed. Paginated pages (<!--nextpage-->) keep their
	 * content, so only their headline can be tested.
	 *
	 * @param string[] $pages Content split into pages.
	 * @param WP_Post  $post  Post.
	 * @return string[]
	 */
	public function content( $pages, $post ) {
		$context = $this->context();

		if ( ! $context || ! $context['test'] || ! $post instanceof WP_Post || (int) $post->ID !== $context['test']['post_id'] || ! is_array( $pages ) || 1 !== count( $pages ) ) {
			return $pages;
		}

		if ( null === $this->merged ) {
			$contents = array();

			foreach ( $context['slots'] as $slot ) {
				$slot_post = Free_A_B_Testing_Tests::slot_post( $context['test'], $slot );

				if ( $slot_post ) {
					$contents[ $slot ] = 0 === $slot ? (string) $pages[0] : $slot_post->post_content;
				}
			}

			$merged       = Free_A_B_Testing_Merge::build( $context['test']['id'], $contents );
			$this->merged = $merged['content'];
		}

		return array( $this->merged );
	}

	/**
	 * Add the variants' headlines to the page's title where the theme prints
	 * it as the page heading: the_title() in classic themes, the Post Title
	 * block in block themes (and Astra's title function). Other uses of the
	 * title (the <title> tag, menus, attributes, feeds) keep the original.
	 *
	 * @param string $title   Title.
	 * @param int    $post_id Post ID.
	 * @return string
	 */
	public function title( $title, $post_id = 0 ) {
		$context = $this->context();

		if ( ! $context || ! $context['test'] || (int) $post_id !== $context['test']['post_id'] || ! self::called_from_heading() ) {
			return $title;
		}

		$titles = array();

		foreach ( $context['slots'] as $slot ) {
			$slot_post = Free_A_B_Testing_Tests::slot_post( $context['test'], $slot );

			if ( $slot_post ) {
				$titles[ $slot ] = $slot_post->post_title;
			}
		}

		return Free_A_B_Testing_Merge::title( $context['test']['id'], $title, $titles );
	}

	/**
	 * Whether the title is being fetched to print the page heading.
	 *
	 * @return bool
	 */
	private static function called_from_heading() {
		/**
		 * Functions that print a page heading from the_title. Add a theme's
		 * own title function here if its headline doesn't change.
		 *
		 * @param string[] $callers Function names.
		 */
		$callers = (array) apply_filters( 'free_a_b_testing_title_callers', array( 'the_title', 'render_block_core_post_title', 'astra_the_title' ) );

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Only on the tested page, to tell the heading from other uses of the title.
		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 12 ) as $frame ) {
			if ( empty( $frame['class'] ) && isset( $frame['function'] ) && in_array( $frame['function'], $callers, true ) ) {
				return true;
			}
		}

		return false;
	}

	// Head --------------------------------------------------------------------

	/**
	 * CSS that hides every version but the visitor's. With no class on
	 * <html> (no JavaScript, or before it runs), the original shows.
	 *
	 * @param int   $test_id Test ID.
	 * @param int[] $slots   Active slots, the original first.
	 * @return string
	 */
	public static function css( $test_id, array $slots ) {
		$p    = 'fabt-' . (int) $test_id;
		$not  = '';
		$rest = '';

		foreach ( $slots as $slot ) {
			if ( $slot ) {
				$not  .= ':not(.' . $p . '-is-' . (int) $slot . ')';
				$rest .= 'html.' . $p . '-is-' . (int) $slot . ' .' . $p . ':not(.' . $p . '-' . (int) $slot . '){display:none!important}';
			}
		}

		return 'html' . $not . ' .' . $p . ':not(.' . $p . '-0){display:none!important}' . $rest;
	}

	/**
	 * The script's settings for this page.
	 *
	 * @param array $context Context.
	 * @return array<string,mixed>
	 */
	public function config( array $context ) {
		$test  = $context['test'];
		$goal  = $test['goal'];
		$ajax  = admin_url( 'admin-ajax.php' );
		$kinds = array(
			'both' => 'ts',
			'tel'  => 't',
			'sms'  => 's',
		);

		// Relative when the admin is on the same host, so a cached copy works
		// on http and https alike.
		if ( wp_parse_url( $ajax, PHP_URL_HOST ) === wp_parse_url( home_url(), PHP_URL_HOST ) ) {
			$ajax = wp_make_link_relative( $ajax );
		}

		$config = array(
			'u' => $ajax,
			'i' => $test['id'],
			'r' => $test['run'],
			's' => array_values( $context['slots'] ),
			'g' => self::GOAL_CODES[ $goal['type'] ],
			'q' => in_array( $goal['type'], array( 'click', 'form' ), true ) ? $goal['selector'] : '',
			'k' => $kinds[ $goal['tel'] ],
			'n' => max( 1, min( 365, (int) $this->plugin->get_setting( 'cookie_days' ) ) ),
			'p' => $this->plugin->get_setting( 'respect_gpc' ) ? 1 : 0,
			'f' => (int) $context['slot'],
			'x' => 'live' === $context['mode'] ? 0 : 1,
		);

		/**
		 * Filters the inline script's settings (see assets/tracker.js).
		 *
		 * @param array $config Settings.
		 * @param array $test   Test.
		 */
		return (array) apply_filters( 'free_a_b_testing_script_config', $config, $test );
	}

	/**
	 * Print the CSS and the inline script.
	 *
	 * @return void
	 */
	public function print_head() {
		$context = $this->context();

		if ( ! $context || ! $context['test'] ) {
			return;
		}

		$code = self::asset( 'tracker.min.js' );

		// JSON_HEX_TAG writes < and > as Unicode escapes, so nothing in the
		// settings (a goal selector, say) can close the <script> early.
		$json = wp_json_encode( $this->config( $context ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		if ( '' === $code || ! $json ) {
			return;
		}

		// Only digits, class names and braces: nothing to escape.
		echo '<style id="fabt-css" data-no-optimize="1" data-no-minify="1">' . self::css( $context['test']['id'], $context['slots'] ) . "</style>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from integers only.

		wp_print_inline_script_tag(
			str_replace( '__FABT_CONFIG__', $json, $code ),
			array(
				'id'                 => 'free-a-b-testing-js',
				// Opt out of Cloudflare Rocket Loader, LiteSpeed, WP Rocket,
				// Jetpack Boost and "delay until interaction" plugins.
				'data-cfasync'       => 'false',
				'data-no-optimize'   => '1',
				'data-no-defer'      => '1',
				'data-no-delay'      => '1',
				'data-no-minify'     => '1',
				'data-jetpack-boost' => 'ignore',
				'nowprocket'         => true,
			)
		);
	}

	/**
	 * Contents of a bundled asset.
	 *
	 * @param string $file File in assets/.
	 * @return string Empty if it can't be read.
	 */
	public static function asset( $file ) {
		static $cache = array();

		if ( ! isset( $cache[ $file ] ) ) {
			$path           = FREE_A_B_TESTING_DIR . 'assets/' . basename( $file );
			$cache[ $file ] = is_readable( $path ) ? trim( (string) file_get_contents( $path ) ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}

		return $cache[ $file ];
	}

	// Admin views -------------------------------------------------------------

	/**
	 * The bar along the bottom of a preview, to switch variants or leave.
	 *
	 * @return void
	 */
	public function print_preview_bar() {
		$context = $this->context();

		if ( ! $context || 'preview' !== $context['mode'] ) {
			return;
		}

		$test = $context['test'];
		?>
		<div id="fabt-preview-bar" data-no-delay="1" role="region" aria-label="<?php esc_attr_e( 'A/B test preview', 'free-a-b-testing' ); ?>">
			<style>
				#fabt-preview-bar{position:fixed;z-index:2147483646;left:50%;bottom:16px;transform:translateX(-50%);display:flex;flex-wrap:wrap;align-items:center;gap:6px;max-width:calc(100vw - 32px);padding:8px 10px;border-radius:10px;background:#1d2327;color:#fff;font:500 13px/1.3 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;box-shadow:0 8px 24px rgba(0,0,0,.25)}
				#fabt-preview-bar span{padding:0 6px;opacity:.8}
				#fabt-preview-bar a{padding:5px 10px;border-radius:6px;color:#fff;text-decoration:none;background:rgba(255,255,255,.1)}
				#fabt-preview-bar a:hover,#fabt-preview-bar a:focus{background:rgba(255,255,255,.2)}
				#fabt-preview-bar a[aria-current]{background:#2271b1}
			</style>
			<span><?php esc_html_e( 'Previewing', 'free-a-b-testing' ); ?></span>
			<?php foreach ( $context['slots'] as $slot ) : ?>
				<a href="<?php echo esc_url( self::preview_url( $test, $slot ) ); ?>"<?php echo $slot === $context['slot'] ? ' aria-current="page"' : ''; ?>>
					<?php echo esc_html( Free_A_B_Testing_Tests::letter( $slot ) . ': ' . $test['variants'][ $slot ]['name'] ); ?>
				</a>
			<?php endforeach; ?>
			<a href="<?php echo esc_url( Free_A_B_Testing_Admin::test_url( $test['id'] ) ); ?>"><?php esc_html_e( 'Back to test', 'free-a-b-testing' ); ?></a>
		</div>
		<?php
	}

	/**
	 * Load the element picker.
	 *
	 * @return void
	 */
	public function enqueue_picker() {
		$context = $this->context();

		if ( ! $context || 'pick' !== $context['mode'] ) {
			return;
		}

		$admin = wp_parse_url( admin_url() );

		wp_enqueue_script( 'free-a-b-testing-picker', FREE_A_B_TESTING_URL . 'assets/picker.js', array(), FREE_A_B_TESTING_VERSION, true );
		wp_localize_script(
			'free-a-b-testing-picker',
			'fabtPicker',
			array(
				'field'  => $context['field'],
				'origin' => $admin['scheme'] . '://' . $admin['host'] . ( isset( $admin['port'] ) ? ':' . $admin['port'] : '' ),
				'i18n'   => array(
					'click'   => __( 'Click the element visitors should click. Links won\'t open while you pick.', 'free-a-b-testing' ),
					'form'    => __( 'Click anywhere inside the form to count when it\'s sent.', 'free-a-b-testing' ),
					'use'     => __( 'Use this', 'free-a-b-testing' ),
					'cancel'  => __( 'Cancel', 'free-a-b-testing' ),
					/* translators: %d: number of elements. */
					'matches' => __( 'Matches %d elements on this page', 'free-a-b-testing' ),
					'match'   => __( 'Matches 1 element on this page', 'free-a-b-testing' ),
					'noForm'  => __( 'That isn\'t inside a form. Click a field or button in the form.', 'free-a-b-testing' ),
					'closed'  => __( 'Selector sent. You can close this tab.', 'free-a-b-testing' ),
				),
			)
		);
	}

	/**
	 * Toolbar menu on a tested page, for admins: status, results and previews.
	 *
	 * @param WP_Admin_Bar $bar Toolbar.
	 * @return void
	 */
	public function admin_bar( $bar ) {
		if ( is_admin() || ! is_singular() || ! current_user_can( Free_A_B_Testing_Tests::CAP ) ) {
			return;
		}

		$tests = array_filter(
			Free_A_B_Testing_Tests::all( (int) get_queried_object_id() ),
			function ( $test ) {
				return 'ended' !== $test['status'];
			}
		);

		if ( ! $tests ) {
			return;
		}

		$test   = reset( $tests );
		$labels = Free_A_B_Testing_Admin::status_labels();

		$bar->add_node(
			array(
				'id'    => 'free-a-b-testing',
				/* translators: %s: test status (Running, Paused...). */
				'title' => esc_html( sprintf( __( 'A/B test: %s', 'free-a-b-testing' ), $labels[ $test['status'] ] ) ),
				'href'  => Free_A_B_Testing_Admin::test_url( $test['id'] ),
			)
		);

		$bar->add_node(
			array(
				'parent' => 'free-a-b-testing',
				'id'     => 'free-a-b-testing-results',
				'title'  => esc_html__( 'View results', 'free-a-b-testing' ),
				'href'   => Free_A_B_Testing_Admin::test_url( $test['id'] ),
			)
		);

		foreach ( Free_A_B_Testing_Tests::active_slots( $test ) as $slot ) {
			$bar->add_node(
				array(
					'parent' => 'free-a-b-testing',
					'id'     => 'free-a-b-testing-preview-' . $slot,
					/* translators: 1: variant letter, 2: variant name. */
					'title'  => esc_html( sprintf( __( 'Preview %1$s: %2$s', 'free-a-b-testing' ), Free_A_B_Testing_Tests::letter( $slot ), $test['variants'][ $slot ]['name'] ) ),
					'href'   => self::preview_url( $test, $slot ),
				)
			);
		}
	}

	// Optimizer exclusions ------------------------------------------------------

	/**
	 * Keep script optimizers from delaying, deferring or combining the script.
	 *
	 * @param mixed $patterns Excluded patterns.
	 * @return array
	 */
	public function exclude_scripts( $patterns ) {
		$patterns   = is_array( $patterns ) ? $patterns : array();
		$patterns[] = 'free-a-b-testing';
		$patterns[] = 'fabt-';

		return $patterns;
	}

	/**
	 * Keep WP Rocket's Remove Unused CSS from stripping the variant CSS
	 * (its selectors only match once the script has run).
	 *
	 * @param mixed $attributes Excluded attributes.
	 * @return array
	 */
	public function exclude_style_attributes( $attributes ) {
		$attributes   = is_array( $attributes ) ? $attributes : array();
		$attributes[] = 'id="fabt-css"';

		return $attributes;
	}

	/**
	 * Autoptimize takes its exclusions as a comma-separated string.
	 *
	 * @param mixed $list Excluded patterns.
	 * @return string
	 */
	public function exclude_csv( $list ) {
		$list = is_string( $list ) ? $list : '';

		return ( '' !== trim( $list ) ? rtrim( $list, ', ' ) . ', ' : '' ) . 'free-a-b-testing, fabt-';
	}
}
