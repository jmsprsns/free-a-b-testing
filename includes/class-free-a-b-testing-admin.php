<?php
/**
 * Admin side: Content Powered > Free A/B Testing (tests, results, settings),
 * the "A/B test" links on the Posts and Pages screens, and the notices in
 * the editor.
 *
 * Every change goes through admin-post.php with a nonce and a
 * manage_options check. Messages after a change are kept per user for one
 * page load, never put in the URL, so a link can't show an admin made-up
 * text.
 *
 * @package Free_A_B_Testing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin screens.
 */
final class Free_A_B_Testing_Admin {

	/**
	 * Page slug, also used as the settings group.
	 */
	const PAGE = 'free-a-b-testing';

	/**
	 * Lucide icon shown beside the menu item and page title.
	 */
	const ICON = 'split';

	/**
	 * Header tabs: view => Lucide icon.
	 */
	const TABS = array(
		'tests'    => 'flask-conical',
		'new'      => 'plus',
		'settings' => 'sliders-horizontal',
	);

	/**
	 * Nonce action for the page's AJAX requests.
	 */
	const NONCE = 'free_a_b_testing_admin';

	/**
	 * Plugin instance.
	 *
	 * @var Free_A_B_Testing_Plugin
	 */
	private $plugin;

	/**
	 * Hook suffix of the page.
	 *
	 * @var string
	 */
	private $hook_suffix = '';

	/**
	 * Post ID => test, for the Posts and Pages row links (built once).
	 *
	 * @var array<int,array>|null
	 */
	private $tests_by_post = null;

	/**
	 * Constructor.
	 *
	 * @param Free_A_B_Testing_Plugin $plugin Plugin instance.
	 */
	public function __construct( Free_A_B_Testing_Plugin $plugin ) {
		$this->plugin = $plugin;

		Free_A_B_Testing_Menu::init();
		Free_A_B_Testing_Feedback::init();

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_head', array( $this, 'print_menu_icon_style' ) );
		add_action( 'admin_post_fabt_create', array( $this, 'handle_create' ) );
		add_action( 'admin_post_fabt_test', array( $this, 'handle_test' ) );
		add_action( 'wp_ajax_fabt_search', array( $this, 'ajax_search' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'editor_notice' ) );
		add_action( 'admin_notices', array( $this, 'classic_editor_notice' ) );
		add_action( 'load-edit.php', array( $this, 'redirect_variant_screens' ) );
		add_action( 'load-post-new.php', array( $this, 'redirect_variant_screens' ) );

		add_filter( 'post_row_actions', array( $this, 'row_action' ), 10, 2 );
		add_filter( 'page_row_actions', array( $this, 'row_action' ), 10, 2 );
		add_filter(
			'plugin_action_links_' . plugin_basename( FREE_A_B_TESTING_FILE ),
			array( $this, 'add_settings_link' )
		);
	}

	// URLs and labels -----------------------------------------------------------

	/**
	 * URL of the plugin's page.
	 *
	 * @param array $args Query args (view, test...).
	 * @return string
	 */
	public static function page_url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * URL of one test's screen.
	 *
	 * @param int $test_id Test ID.
	 * @return string
	 */
	public static function test_url( $test_id ) {
		return self::page_url(
			array(
				'view' => 'test',
				'test' => (int) $test_id,
			)
		);
	}

	/**
	 * Status names.
	 *
	 * @return array<string,string>
	 */
	public static function status_labels() {
		return array(
			'draft'   => __( 'Draft', 'free-a-b-testing' ),
			'running' => __( 'Running', 'free-a-b-testing' ),
			'paused'  => __( 'Paused', 'free-a-b-testing' ),
			'ended'   => __( 'Ended', 'free-a-b-testing' ),
		);
	}

	/**
	 * Goal names and icons.
	 *
	 * @return array<string,array{label:string,desc:string,icon:string}>
	 */
	public static function goal_types() {
		return array(
			'click' => array(
				'label' => __( 'Clicks on an element', 'free-a-b-testing' ),
				'desc'  => __( 'A button, a link or anything else on the page.', 'free-a-b-testing' ),
				'icon'  => 'mouse-pointer-click',
			),
			'form'  => array(
				'label' => __( 'Form submissions', 'free-a-b-testing' ),
				'desc'  => __( 'Contact forms, quote requests, signups.', 'free-a-b-testing' ),
				'icon'  => 'send',
			),
			'tel'   => array(
				'label' => __( 'Phone and text taps', 'free-a-b-testing' ),
				'desc'  => __( 'Taps on tel: and sms: links.', 'free-a-b-testing' ),
				'icon'  => 'phone-call',
			),
			'woo'   => array(
				'label' => __( 'WooCommerce purchases', 'free-a-b-testing' ),
				'desc'  => __( 'Paid orders, with the revenue per variant.', 'free-a-b-testing' ),
				'icon'  => 'shopping-cart',
			),
		);
	}

	/**
	 * Inline Lucide icon.
	 *
	 * @param string $name Icon.
	 * @param int    $size Size in px.
	 * @return string
	 */
	private static function icon( $name, $size = 18 ) {
		return Free_A_B_Testing_Icons::get( $name, $size );
	}

	// Menu, settings, assets ------------------------------------------------------

	/**
	 * Add a "Settings" link to the plugin's row on the Plugins screen.
	 *
	 * @param array $links Existing action links (Deactivate, etc.).
	 * @return array
	 */
	public function add_settings_link( $links ) {
		if ( ! is_array( $links ) ) {
			return $links;
		}

		array_unshift(
			$links,
			sprintf( '<a href="%s">%s</a>', esc_url( self::page_url() ), esc_html__( 'Tests', 'free-a-b-testing' ) ),
			sprintf( '<a href="%s">%s</a>', esc_url( self::page_url( array( 'view' => 'settings' ) ) ), esc_html__( 'Settings', 'free-a-b-testing' ) )
		);

		return $links;
	}

	/**
	 * Add the page under the shared Content Powered menu.
	 *
	 * @return void
	 */
	public function register_menu() {
		$icon       = Free_A_B_Testing_Icons::get( self::ICON, 17, 'free-a-b-testing-menu-icon' );
		$menu_title = $icon . '<span class="free-a-b-testing-menu-label">' . esc_html__( 'Free A/B Testing', 'free-a-b-testing' ) . '</span>';

		$this->hook_suffix = add_submenu_page(
			Free_A_B_Testing_Menu::SLUG,
			__( 'Free A/B Testing', 'free-a-b-testing' ),
			$menu_title,
			Free_A_B_Testing_Tests::CAP,
			self::PAGE,
			array( $this, 'render_page' )
		);

		if ( $this->hook_suffix ) {
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ), 20 );
			add_filter( 'admin_body_class', array( $this, 'body_class' ) );
		}
	}

	/**
	 * Mark the page's <body>, so its header can run edge to edge.
	 *
	 * @param string $classes Space-separated body classes.
	 * @return string
	 */
	public function body_class( $classes ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		return $screen && $screen->id === $this->hook_suffix ? $classes . ' fabt-page' : $classes;
	}

	/**
	 * Register the settings.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			self::PAGE,
			Free_A_B_Testing_Plugin::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => Free_A_B_Testing_Plugin::defaults(),
			)
		);
	}

	/**
	 * Clean the submitted settings before they are saved.
	 *
	 * @param mixed $input Raw form values.
	 * @return array<string,mixed>
	 */
	public function sanitize( $input ) {
		$in       = is_array( $input ) ? $input : array();
		$defaults = Free_A_B_Testing_Plugin::defaults();
		$number   = function ( $key, $min, $max ) use ( $in, $defaults ) {
			$value = isset( $in[ $key ] ) && is_numeric( $in[ $key ] ) ? (int) $in[ $key ] : (int) $defaults[ $key ];
			return max( $min, min( $max, $value ) );
		};

		return array(
			'exclude_editors' => empty( $in['exclude_editors'] ) ? 0 : 1,
			'respect_gpc'     => empty( $in['respect_gpc'] ) ? 0 : 1,
			'cookie_days'     => $number( 'cookie_days', 1, 365 ),
			'hourly_limit'    => $number( 'hourly_limit', 5, 1000 ),
		);
	}

	/**
	 * Tiny inline style so the menu icon sits neatly beside its label, even
	 * when a long plugin name wraps onto a second line.
	 *
	 * @return void
	 */
	public function print_menu_icon_style() {
		echo '<style id="free-a-b-testing-menu-icon-style">'
			. '#adminmenu a:has(>.free-a-b-testing-menu-icon){display:flex;align-items:flex-start;gap:7px}'
			. '#adminmenu .free-a-b-testing-menu-icon{flex:none;margin-top:1px;opacity:.7}'
			. '#adminmenu li.current .free-a-b-testing-menu-icon,#adminmenu a:hover .free-a-b-testing-menu-icon,#adminmenu a:focus .free-a-b-testing-menu-icon{opacity:1}'
			. '</style>';
	}

	/**
	 * Load the page's styles and scripts.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( $hook !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_style( 'free-a-b-testing-admin', FREE_A_B_TESTING_URL . 'assets/admin.css', array(), FREE_A_B_TESTING_VERSION );
		wp_enqueue_script( 'free-a-b-testing-admin', FREE_A_B_TESTING_URL . 'assets/admin.js', array(), FREE_A_B_TESTING_VERSION, true );

		$home = wp_parse_url( home_url() );

		wp_localize_script(
			'free-a-b-testing-admin',
			'fabtAdmin',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( self::NONCE ),
				'pickNonce'  => wp_create_nonce( 'fabt_pick' ),
				'homeOrigin' => $home['scheme'] . '://' . $home['host'] . ( isset( $home['port'] ) ? ':' . $home['port'] : '' ),
				'i18n'       => array(
					'searching' => __( 'Searching…', 'free-a-b-testing' ),
					'noResults' => __( 'Nothing found. Only published posts and pages can be tested.', 'free-a-b-testing' ),
					'hasTest'   => __( 'Has a test', 'free-a-b-testing' ),
					'pickFirst' => __( 'Choose the page to test first.', 'free-a-b-testing' ),
					'blocked'   => __( 'Your browser blocked the new tab. Allow pop-ups for this site and try again.', 'free-a-b-testing' ),
				),
			)
		);
	}

	// Page ----------------------------------------------------------------------

	/**
	 * Render the page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( Free_A_B_Testing_Tests::CAP ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only view switches.
		$view = isset( $_GET['view'] ) && is_string( $_GET['view'] ) ? sanitize_key( $_GET['view'] ) : 'tests';
		$test = 'test' === $view && isset( $_GET['test'] ) ? Free_A_B_Testing_Tests::get( absint( $_GET['test'] ) ) : null;
		// phpcs:enable

		if ( 'test' === $view && ! $test ) {
			$view = 'tests';
			self::flash( 'error', __( 'That test no longer exists.', 'free-a-b-testing' ) );
		}

		$view = in_array( $view, array( 'tests', 'new', 'settings', 'test' ), true ) ? $view : 'tests';

		$this->render_header( 'test' === $view ? 'tests' : $view );
		?>
		<div class="wrap fabt-wrap">
			<hr class="wp-header-end">
			<?php
			if ( 'settings' === $view ) {
				// Pages outside the Settings menu have to show "Settings saved." themselves.
				settings_errors();
			}

			self::print_flash();

			if ( 'new' === $view ) {
				$this->render_new_view();
			} elseif ( 'settings' === $view ) {
				$this->render_settings_view();
			} elseif ( 'test' === $view ) {
				$this->render_test_view( $test );
			} else {
				$this->render_tests_view();
			}
			?>
		</div>
		<?php
	}

	/**
	 * Header with the title and tabs.
	 *
	 * @param string $active Active tab.
	 * @return void
	 */
	private function render_header( $active ) {
		$labels = array(
			'tests'    => __( 'Tests', 'free-a-b-testing' ),
			'new'      => __( 'New test', 'free-a-b-testing' ),
			'settings' => __( 'Settings', 'free-a-b-testing' ),
		);
		?>
		<div class="fabt-header">
			<div class="fabt-header-title">
				<span class="fabt-header-icon"><?php echo self::icon( self::ICON, 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
				<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
				<span class="fabt-version"><?php echo esc_html( 'v' . FREE_A_B_TESTING_VERSION ); ?></span>
			</div>
			<nav class="fabt-tabs" aria-label="<?php esc_attr_e( 'A/B testing sections', 'free-a-b-testing' ); ?>">
				<?php foreach ( self::TABS as $id => $icon ) : ?>
					<a href="<?php echo esc_url( self::page_url( 'tests' === $id ? array() : array( 'view' => $id ) ) ); ?>" class="fabt-tab<?php echo $active === $id ? ' is-active' : ''; ?>"<?php echo $active === $id ? ' aria-current="page"' : ''; ?>>
						<?php echo self::icon( $icon, 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
						<?php echo esc_html( $labels[ $id ] ); ?>
					</a>
				<?php endforeach; ?>
			</nav>
		</div>
		<?php
	}

	/**
	 * A postbox.
	 *
	 * @param string   $id     Box ID.
	 * @param string   $icon   Lucide icon.
	 * @param string   $title  Title.
	 * @param callable $render Body.
	 * @param string   $extra  Extra HTML for the header (already escaped).
	 * @return void
	 */
	private static function box( $id, $icon, $title, $render, $extra = '' ) {
		?>
		<div class="postbox fabt-box" id="fabt-box-<?php echo esc_attr( $id ); ?>">
			<div class="postbox-header">
				<h2 class="hndle">
					<?php echo self::icon( $icon ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<?php echo esc_html( $title ); ?>
				</h2>
				<?php echo $extra; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the caller. ?>
			</div>
			<div class="inside">
				<?php call_user_func( $render ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Two-column layout: main column and sidebar.
	 *
	 * @param callable $main    Main column.
	 * @param callable $sidebar Sidebar.
	 * @return void
	 */
	private static function columns( $main, $sidebar ) {
		?>
		<div id="poststuff">
			<div id="post-body" class="metabox-holder columns-2">
				<div id="post-body-content"><?php call_user_func( $main ); ?></div>
				<div id="postbox-container-1" class="postbox-container">
					<div class="fabt-sidebar"><?php call_user_func( $sidebar ); ?></div>
				</div>
			</div>
			<br class="clear">
		</div>
		<?php
	}

	// Tests list ------------------------------------------------------------------

	/**
	 * Every test, with its headline numbers.
	 *
	 * @return void
	 */
	private function render_tests_view() {
		$tests   = Free_A_B_Testing_Tests::all();
		$labels  = self::status_labels();
		$goals   = self::goal_types();
		$running = 0;
		$winners = 0;
		$visits  = 0;
		$rows    = array();

		foreach ( $tests as $test ) {
			$results = Free_A_B_Testing_Stats::results( $test );
			$rows[]  = array( $test, $results );

			$running += 'running' === $test['status'] ? 1 : 0;
			$winners += $results['winner'] > 0 ? 1 : 0;
			$visits  += $results['visitors'];
		}

		self::columns(
			function () use ( $tests, $rows, $labels, $goals, $running, $winners, $visits ) {
				if ( ! $tests ) {
					self::box( 'start', 'flask-conical', __( 'Run your first A/B test', 'free-a-b-testing' ), array( __CLASS__, 'render_empty' ) );
					return;
				}
				?>
				<div class="fabt-stats">
					<?php
					self::stat( 'circle-play', number_format_i18n( $running ), _n( 'Test running', 'Tests running', $running, 'free-a-b-testing' ) );
					self::stat( 'users', number_format_i18n( $visits ), __( 'Visitors tested', 'free-a-b-testing' ) );
					self::stat( 'trophy', number_format_i18n( $winners ), _n( 'Winner found', 'Winners found', $winners, 'free-a-b-testing' ), $winners ? 'is-good' : '' );
					?>
				</div>
				<?php
				self::box(
					'tests',
					'list-checks',
					__( 'Tests', 'free-a-b-testing' ),
					function () use ( $rows, $labels, $goals ) {
						?>
						<table class="widefat striped fabt-table fabt-tests">
							<thead>
								<tr>
									<th scope="col"><?php esc_html_e( 'Test', 'free-a-b-testing' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Status', 'free-a-b-testing' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Goal', 'free-a-b-testing' ); ?></th>
									<th scope="col" class="num"><?php esc_html_e( 'Visitors', 'free-a-b-testing' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Result', 'free-a-b-testing' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $rows as $pair ) : ?>
									<?php
									list( $test, $results ) = $pair;
									$page                   = get_post( $test['post_id'] );
									?>
									<tr>
										<td>
											<a class="fabt-row-title" href="<?php echo esc_url( self::test_url( $test['id'] ) ); ?>"><?php echo esc_html( $test['name'] ); ?></a>
											<span class="fabt-muted">
												<?php
												echo $page
													? esc_html( wp_strip_all_tags( get_the_title( $page ) ) )
													: esc_html__( 'Page deleted', 'free-a-b-testing' );
												echo ' · ';
												/* translators: %d: number of variants including the original. */
												echo esc_html( sprintf( _n( '%d version', '%d versions', count( $test['variants'] ), 'free-a-b-testing' ), count( $test['variants'] ) ) );
												?>
											</span>
										</td>
										<td><span class="fabt-status is-<?php echo esc_attr( $test['status'] ); ?>"><?php echo esc_html( $labels[ $test['status'] ] ); ?></span></td>
										<td>
											<span class="fabt-goal-cell">
												<?php echo self::icon( $goals[ $test['goal']['type'] ]['icon'], 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
												<?php echo esc_html( $goals[ $test['goal']['type'] ]['label'] ); ?>
											</span>
										</td>
										<td class="num"><?php echo esc_html( number_format_i18n( $results['visitors'] ) ); ?></td>
										<td><?php self::result_summary( $test, $results ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
						<?php
					},
					'<a class="button button-primary fabt-head-button" href="' . esc_url( self::page_url( array( 'view' => 'new' ) ) ) . '">' . self::icon( 'plus', 16 ) . esc_html__( 'New test', 'free-a-b-testing' ) . '</a>'
				);
			},
			array( __CLASS__, 'render_how_box' )
		);
	}

	/**
	 * Short result line for the tests list.
	 *
	 * @param array $test    Test.
	 * @param array $results Results.
	 * @return void
	 */
	private static function result_summary( array $test, array $results ) {
		if ( $test['applied'] > 0 ) {
			/* translators: %s: variant letter. */
			echo '<span class="fabt-result is-good">' . self::icon( 'badge-check', 16 ) . esc_html( sprintf( __( 'Variant %s applied', 'free-a-b-testing' ), Free_A_B_Testing_Tests::letter( $test['applied'] ) ) ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG.
			return;
		}

		if ( $results['winner'] >= 0 ) {
			$text = 0 === $results['winner']
				? __( 'The original wins', 'free-a-b-testing' )
				/* translators: %s: variant letter. */
				: sprintf( __( 'Variant %s wins', 'free-a-b-testing' ), Free_A_B_Testing_Tests::letter( $results['winner'] ) );

			echo '<span class="fabt-result is-good">' . self::icon( 'trophy', 16 ) . esc_html( $text ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG.
			return;
		}

		if ( ! $results['visitors'] ) {
			echo '<span class="fabt-muted">' . esc_html__( 'No visitors yet', 'free-a-b-testing' ) . '</span>';
			return;
		}

		$best = null;

		foreach ( $results['rows'] as $row ) {
			if ( $row['slot'] && null !== $row['confidence'] && ( null === $best || $row['confidence'] > $best['confidence'] ) ) {
				$best = $row;
			}
		}

		if ( $best ) {
			printf(
				'<span class="fabt-result">%s</span>',
				esc_html(
					sprintf(
						/* translators: 1: variant letter, 2: confidence percentage. */
						__( 'No winner yet · %1$s at %2$s confidence', 'free-a-b-testing' ),
						Free_A_B_Testing_Tests::letter( $best['slot'] ),
						self::percent( $best['confidence'], 0 )
					)
				)
			);
		}
	}

	/**
	 * Empty state.
	 *
	 * @return void
	 */
	public static function render_empty() {
		$steps = array(
			array( 'file-text', __( 'Pick a page', 'free-a-b-testing' ), __( 'Any published post, page or product.', 'free-a-b-testing' ) ),
			array( 'copy', __( 'Make a variant', 'free-a-b-testing' ), __( 'Change the headline, a button, a block or the whole page in the normal editor.', 'free-a-b-testing' ) ),
			array( 'target', __( 'Choose a goal', 'free-a-b-testing' ), __( 'A click, a form, a call or text, or a WooCommerce purchase.', 'free-a-b-testing' ) ),
			array( 'trophy', __( 'Apply the winner', 'free-a-b-testing' ), __( 'Once a variant reaches 95% confidence, one click puts it live.', 'free-a-b-testing' ) ),
		);
		?>
		<p class="fabt-intro"><?php esc_html_e( 'Show visitors two or more versions of a page, count which one gets more conversions, and keep the winner.', 'free-a-b-testing' ); ?></p>
		<ol class="fabt-steps">
			<?php foreach ( $steps as $i => $step ) : ?>
				<li>
					<span class="fabt-step-icon"><?php echo self::icon( $step[0], 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
					<span class="fabt-step-text">
						<strong><?php echo esc_html( ( $i + 1 ) . '. ' . $step[1] ); ?></strong>
						<span><?php echo esc_html( $step[2] ); ?></span>
					</span>
				</li>
			<?php endforeach; ?>
		</ol>
		<p><a class="button button-primary button-hero fabt-hero-button" href="<?php echo esc_url( self::page_url( array( 'view' => 'new' ) ) ); ?>"><?php echo self::icon( 'plus', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Create your first test', 'free-a-b-testing' ); ?></a></p>
		<?php
	}

	/**
	 * A number card.
	 *
	 * @param string $icon  Lucide icon.
	 * @param string $num   Number (formatted).
	 * @param string $label Label.
	 * @param string $class Extra class.
	 * @return void
	 */
	private static function stat( $icon, $num, $label, $class = '' ) {
		?>
		<div class="fabt-stat <?php echo esc_attr( $class ); ?>">
			<span class="fabt-stat-icon"><?php echo self::icon( $icon, 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
			<span class="fabt-stat-num"><?php echo esc_html( $num ); ?></span>
			<span class="fabt-stat-label"><?php echo esc_html( $label ); ?></span>
		</div>
		<?php
	}

	/**
	 * Sidebar: how it works.
	 *
	 * @return void
	 */
	public static function render_how_box() {
		self::box(
			'how',
			'info',
			__( 'How it works', 'free-a-b-testing' ),
			function () {
				$points = array(
					array( 'split', __( 'Visitors are split evenly between the versions.', 'free-a-b-testing' ) ),
					array( 'cookie', __( 'Each visitor always sees the same version.', 'free-a-b-testing' ) ),
					array( 'zap', __( 'No flicker: the version is chosen before the page paints.', 'free-a-b-testing' ) ),
					array( 'layers', __( 'Works with page caching and CDNs.', 'free-a-b-testing' ) ),
					array( 'gauge', __( 'About 1 KB of inline code, on tested pages only.', 'free-a-b-testing' ) ),
					array( 'scale', __( 'A winner is only declared at 95% confidence.', 'free-a-b-testing' ) ),
				);
				?>
				<ul class="fabt-checklist">
					<?php foreach ( $points as $point ) : ?>
						<li><?php echo self::icon( $point[0], 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php echo esc_html( $point[1] ); ?></span></li>
					<?php endforeach; ?>
				</ul>
				<p class="fabt-muted"><?php esc_html_e( 'Your own visits as an admin or editor aren\'t counted (see Settings), so check a running test in a private window.', 'free-a-b-testing' ); ?></p>
				<?php
			}
		);
	}

	// New test ----------------------------------------------------------------------

	/**
	 * Create a test: page, name and goal.
	 *
	 * @return void
	 */
	private function render_new_view() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Prefills the form only.
		$post = isset( $_GET['post_id'] ) ? get_post( absint( $_GET['post_id'] ) ) : null;
		$post = Free_A_B_Testing_Tests::is_testable( $post ) ? $post : null;
		$url  = $post ? (string) get_permalink( $post ) : '';
		?>
		<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" class="fabt-form" data-page-url="<?php echo esc_url( $url ); ?>">
			<input type="hidden" name="action" value="fabt_create">
			<?php wp_nonce_field( 'fabt_create' ); ?>
			<?php
			self::columns(
				function () use ( $post ) {
					self::box(
						'page',
						'file-text',
						__( 'Page to test', 'free-a-b-testing' ),
						function () use ( $post ) {
							?>
							<p class="fabt-intro"><?php esc_html_e( 'Any published post, page or product. You\'ll get a copy of it as Variant B to change.', 'free-a-b-testing' ); ?></p>
							<div class="fabt-page-picker">
								<input type="hidden" name="post_id" id="fabt-post-id" value="<?php echo esc_attr( $post ? $post->ID : '' ); ?>" required>
								<div class="fabt-page-chosen"<?php echo $post ? '' : ' hidden'; ?>>
									<span class="fabt-page-chosen-icon"><?php echo self::icon( 'file-text', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
									<span class="fabt-page-chosen-text">
										<strong id="fabt-page-title"><?php echo $post ? esc_html( wp_strip_all_tags( get_the_title( $post ) ) ) : ''; ?></strong>
										<span id="fabt-page-meta" class="fabt-muted"><?php echo $post ? esc_html( wp_make_link_relative( (string) get_permalink( $post ) ) ) : ''; ?></span>
									</span>
									<button type="button" class="button" id="fabt-page-change"><?php esc_html_e( 'Change', 'free-a-b-testing' ); ?></button>
								</div>
								<div class="fabt-page-search"<?php echo $post ? ' hidden' : ''; ?>>
									<label class="screen-reader-text" for="fabt-page-query"><?php esc_html_e( 'Search posts and pages', 'free-a-b-testing' ); ?></label>
									<span class="fabt-search-field">
										<?php echo self::icon( 'search', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
										<input type="search" id="fabt-page-query" class="regular-text" placeholder="<?php esc_attr_e( 'Search posts and pages…', 'free-a-b-testing' ); ?>" autocomplete="off">
									</span>
									<ul class="fabt-page-results" id="fabt-page-results" role="listbox" aria-label="<?php esc_attr_e( 'Pages', 'free-a-b-testing' ); ?>"></ul>
								</div>
							</div>
							<?php
						}
					);

					self::box(
						'name',
						'pencil',
						__( 'Test name', 'free-a-b-testing' ),
						function () {
							?>
							<p><input type="text" name="name" class="large-text" maxlength="120" placeholder="<?php esc_attr_e( 'For example: Homepage headline', 'free-a-b-testing' ); ?>"></p>
							<p class="description"><?php esc_html_e( 'Only you see this. Leave it empty to name the test after the page.', 'free-a-b-testing' ); ?></p>
							<?php
						}
					);

					self::box(
						'goal',
						'target',
						__( 'Goal', 'free-a-b-testing' ),
						function () {
							self::render_goal_fields( Free_A_B_Testing_Tests::clean_goal( array() ), true );
						}
					);
				},
				function () {
					self::box(
						'create',
						'flask-conical',
						__( 'Create test', 'free-a-b-testing' ),
						function () {
							?>
							<p><?php esc_html_e( 'The test starts as a draft with one variant, a copy of the page. Change it, preview it, then start the test.', 'free-a-b-testing' ); ?></p>
							<?php submit_button( __( 'Create test', 'free-a-b-testing' ), 'primary large', 'submit', false ); ?>
							<?php
						}
					);
					self::render_how_box();
				}
			);
		?>
		</form>
		<?php
	}

	/**
	 * Goal choices and their settings.
	 *
	 * @param array $goal     Goal.
	 * @param bool  $editable Whether it can be changed.
	 * @return void
	 */
	private static function render_goal_fields( array $goal, $editable ) {
		$woo      = class_exists( 'WooCommerce' );
		$disabled = $editable ? '' : ' disabled';
		$tels     = array(
			'both' => __( 'Calls and texts', 'free-a-b-testing' ),
			'tel'  => __( 'Calls only (tel: links)', 'free-a-b-testing' ),
			'sms'  => __( 'Texts only (sms: links)', 'free-a-b-testing' ),
		);
		?>
		<p class="fabt-intro"><?php esc_html_e( 'What counts as a conversion. Each visitor converts once, however many times they do it.', 'free-a-b-testing' ); ?></p>
		<div class="fabt-options fabt-goals">
			<?php foreach ( self::goal_types() as $type => $info ) : ?>
				<label class="fabt-option">
					<input type="radio" name="goal[type]" value="<?php echo esc_attr( $type ); ?>" <?php checked( $goal['type'], $type ); ?><?php echo esc_attr( $disabled ); ?>>
					<span class="fabt-option-icon"><?php echo self::icon( $info['icon'], 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
					<span class="fabt-option-text">
						<strong><?php echo esc_html( $info['label'] ); ?></strong>
						<span><?php echo esc_html( $info['desc'] ); ?></span>
					</span>
				</label>
			<?php endforeach; ?>
		</div>

		<div class="fabt-goal-detail" data-goal="click">
			<label for="fabt-selector-click"><strong><?php esc_html_e( 'Element to count clicks on', 'free-a-b-testing' ); ?></strong></label>
			<span class="fabt-inline">
				<input type="text" id="fabt-selector-click" name="goal[selector_click]" class="regular-text code" value="<?php echo esc_attr( 'click' === $goal['type'] ? $goal['selector'] : '' ); ?>" placeholder="#signup-button" maxlength="300" spellcheck="false"<?php echo esc_attr( $disabled ); ?>>
				<?php if ( $editable ) : ?>
					<button type="button" class="button fabt-pick" data-field="click"><?php echo self::icon( 'crosshair', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Pick on page', 'free-a-b-testing' ); ?></button>
				<?php endif; ?>
			</span>
			<p class="description"><?php esc_html_e( 'Pick it on the page, or type a CSS selector. Clicks anywhere inside the element count.', 'free-a-b-testing' ); ?></p>
		</div>

		<div class="fabt-goal-detail" data-goal="form">
			<label for="fabt-selector-form"><strong><?php esc_html_e( 'Form to count', 'free-a-b-testing' ); ?></strong></label>
			<span class="fabt-inline">
				<input type="text" id="fabt-selector-form" name="goal[selector_form]" class="regular-text code" value="<?php echo esc_attr( 'form' === $goal['type'] ? $goal['selector'] : '' ); ?>" placeholder="<?php esc_attr_e( 'Any form', 'free-a-b-testing' ); ?>" maxlength="300" spellcheck="false"<?php echo esc_attr( $disabled ); ?>>
				<?php if ( $editable ) : ?>
					<button type="button" class="button fabt-pick" data-field="form"><?php echo self::icon( 'crosshair', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Pick on page', 'free-a-b-testing' ); ?></button>
				<?php endif; ?>
			</span>
			<p class="description"><?php esc_html_e( 'Leave it empty to count any form on the page except search forms. Counted when the form is sent, so a form that loads a thank-you message in place counts too.', 'free-a-b-testing' ); ?></p>
		</div>

		<div class="fabt-goal-detail" data-goal="tel">
			<strong><?php esc_html_e( 'Links to count', 'free-a-b-testing' ); ?></strong>
			<div class="fabt-radios">
				<?php foreach ( $tels as $value => $label ) : ?>
					<label><input type="radio" name="goal[tel]" value="<?php echo esc_attr( $value ); ?>" <?php checked( $goal['tel'], $value ); ?><?php echo esc_attr( $disabled ); ?>> <?php echo esc_html( $label ); ?></label>
				<?php endforeach; ?>
			</div>
			<p class="description"><?php esc_html_e( 'Any tel: or sms: link on the page, including ones in your header and footer.', 'free-a-b-testing' ); ?></p>
		</div>

		<div class="fabt-goal-detail" data-goal="woo">
			<?php if ( $woo ) : ?>
				<p class="fabt-callout"><?php echo self::icon( 'shopping-cart', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php esc_html_e( 'A visitor converts when they place an order that gets paid (Processing or Completed), on any page, after seeing the tested page. Works with the classic and block checkout. Revenue is the order total.', 'free-a-b-testing' ); ?></span></p>
			<?php else : ?>
				<p class="fabt-callout is-warning"><?php echo self::icon( 'triangle-alert', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php esc_html_e( 'WooCommerce isn\'t active, so there are no purchases to count.', 'free-a-b-testing' ); ?></span></p>
			<?php endif; ?>
		</div>
		<?php
	}

	// Test screen ---------------------------------------------------------------------

	/**
	 * One test: results, variants, goal and status.
	 *
	 * @param array $test Test.
	 * @return void
	 */
	private function render_test_view( array $test ) {
		$page     = get_post( $test['post_id'] );
		$results  = Free_A_B_Testing_Stats::results( $test );
		$editable = 'ended' !== $test['status'];
		?>
		<div class="fabt-test-head">
			<a class="fabt-back" href="<?php echo esc_url( self::page_url() ); ?>"><?php echo self::icon( 'arrow-left', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'All tests', 'free-a-b-testing' ); ?></a>
			<h2 class="fabt-test-title"><?php echo esc_html( $test['name'] ); ?></h2>
			<?php if ( $page ) : ?>
				<a class="fabt-test-page" href="<?php echo esc_url( (string) get_permalink( $page ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( wp_strip_all_tags( get_the_title( $page ) ) ); ?><?php echo self::icon( 'external-link', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></a>
			<?php endif; ?>
		</div>

		<?php if ( ! $page ) : ?>
			<div class="notice notice-error inline"><p><?php esc_html_e( 'The page this test was for has been deleted, so it can\'t run.', 'free-a-b-testing' ); ?></p></div>
		<?php endif; ?>

		<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" class="fabt-form" id="fabt-test-form" data-page-url="<?php echo esc_url( $page ? (string) get_permalink( $page ) : '' ); ?>">
			<input type="hidden" name="action" value="fabt_test">
			<input type="hidden" name="test" value="<?php echo esc_attr( $test['id'] ); ?>">
			<?php wp_nonce_field( 'fabt_test_' . $test['id'] ); ?>
			<?php
			self::columns(
				function () use ( $test, $results, $editable ) {
					if ( 'draft' !== $test['status'] || $results['visitors'] ) {
						self::box(
							'results',
							'chart-column',
							__( 'Results', 'free-a-b-testing' ),
							function () use ( $test, $results ) {
								self::render_results( $test, $results );
							}
						);
					}

					self::box(
						'variants',
						'layers',
						__( 'Versions', 'free-a-b-testing' ),
						function () use ( $test, $editable ) {
							self::render_variants( $test, $editable );
						}
					);

					self::box(
						'goal',
						'target',
						__( 'Goal', 'free-a-b-testing' ),
						function () use ( $test, $editable ) {
							self::render_goal_fields( $test['goal'], $editable );
						}
					);
				},
				function () use ( $test, $results ) {
					self::render_status_box( $test, $results );
					self::render_how_box();
				}
			);
			?>
		</form>
		<?php
	}

	/**
	 * Results: winner, totals and the per-version table.
	 *
	 * @param array $test    Test.
	 * @param array $results Results.
	 * @return void
	 */
	private static function render_results( array $test, array $results ) {
		$woo      = 'woo' === $test['goal']['type'];
		$winner   = $results['winner'];
		$open     = $test['applied'] < 0;
		$started  = $test['started'];
		$end      = $test['ended'] ? $test['ended'] : time();
		$max_rate = 0.0;

		foreach ( $results['rows'] as $row ) {
			$max_rate = max( $max_rate, $row['rate'] );
		}

		self::render_verdict( $test, $results );
		?>
		<div class="fabt-stats">
			<?php
			self::stat( 'users', number_format_i18n( $results['visitors'] ), __( 'Visitors', 'free-a-b-testing' ) );
			self::stat( 'target', number_format_i18n( $results['conversions'] ), __( 'Conversions', 'free-a-b-testing' ) );
			self::stat( 'percent', $results['visitors'] ? self::percent( $results['conversions'] / $results['visitors'] ) : '–', __( 'Conversion rate', 'free-a-b-testing' ) );
			self::stat( 'clock', $started ? human_time_diff( $started, $end ) : '–', 'ended' === $test['status'] ? __( 'Ran for', 'free-a-b-testing' ) : __( 'Running for', 'free-a-b-testing' ) );
			?>
		</div>

		<?php self::render_chart( $test ); ?>

		<div class="fabt-table-wrap">
			<table class="widefat fabt-table fabt-results">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Version', 'free-a-b-testing' ); ?></th>
						<th scope="col" class="num"><?php esc_html_e( 'Visitors', 'free-a-b-testing' ); ?></th>
						<th scope="col" class="num"><?php esc_html_e( 'Conversions', 'free-a-b-testing' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Conversion rate', 'free-a-b-testing' ); ?></th>
						<th scope="col" class="num"><?php esc_html_e( 'vs. original', 'free-a-b-testing' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Confidence', 'free-a-b-testing' ); ?></th>
						<?php if ( $woo ) : ?>
							<th scope="col" class="num"><?php esc_html_e( 'Revenue', 'free-a-b-testing' ); ?></th>
						<?php endif; ?>
						<?php if ( $open ) : ?>
							<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'free-a-b-testing' ); ?></span></th>
						<?php endif; ?>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $results['rows'] as $slot => $row ) : ?>
						<tr class="<?php echo $slot === $winner ? 'is-winner' : ''; ?>">
							<td>
								<span class="fabt-version-name">
									<span class="fabt-letter"><?php echo esc_html( Free_A_B_Testing_Tests::letter( $slot ) ); ?></span>
									<?php echo esc_html( $test['variants'][ $slot ]['name'] ); ?>
									<?php if ( $slot === $winner ) : ?>
										<span class="fabt-tag is-good"><?php echo self::icon( 'trophy', 13 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Winner', 'free-a-b-testing' ); ?></span>
									<?php elseif ( $slot === $test['applied'] ) : ?>
										<span class="fabt-tag is-good"><?php esc_html_e( 'Applied', 'free-a-b-testing' ); ?></span>
									<?php endif; ?>
								</span>
							</td>
							<td class="num"><?php echo esc_html( number_format_i18n( $row['visitors'] ) ); ?></td>
							<td class="num"><?php echo esc_html( number_format_i18n( $row['conversions'] ) ); ?></td>
							<td>
								<span class="fabt-rate">
									<span class="fabt-rate-num"><?php echo esc_html( $row['visitors'] ? self::percent( $row['rate'] ) : '–' ); ?></span>
									<span class="fabt-rate-bar" aria-hidden="true"><span style="width:<?php echo esc_attr( $max_rate > 0 ? round( 100 * $row['rate'] / $max_rate, 1 ) : 0 ); ?>%"></span></span>
								</span>
							</td>
							<td class="num">
								<?php if ( 0 === $slot ) : ?>
									<span class="fabt-muted"><?php esc_html_e( 'Baseline', 'free-a-b-testing' ); ?></span>
								<?php elseif ( null === $row['lift'] ) : ?>
									<span class="fabt-muted">–</span>
								<?php else : ?>
									<span class="fabt-lift <?php echo $row['lift'] >= 0 ? 'is-up' : 'is-down'; ?>">
										<?php echo self::icon( $row['lift'] >= 0 ? 'trending-up' : 'trending-down', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
										<?php echo esc_html( ( $row['lift'] >= 0 ? '+' : '−' ) . self::percent( abs( $row['lift'] ) ) ); ?>
									</span>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( 0 === $slot ) : ?>
									<span class="fabt-muted">–</span>
								<?php elseif ( null === $row['confidence'] ) : ?>
									<span class="fabt-muted"><?php esc_html_e( 'Waiting for data', 'free-a-b-testing' ); ?></span>
								<?php else : ?>
									<?php $confidence = min( 0.999, $row['confidence'] ); ?>
									<span class="fabt-confidence<?php echo $row['valid'] && $confidence >= Free_A_B_Testing_Stats::CONFIDENCE ? ' is-reached' : ''; ?>">
										<span class="fabt-confidence-num"><?php echo esc_html( self::percent( $confidence ) ); ?></span>
										<span class="fabt-meter" aria-hidden="true"><span style="width:<?php echo esc_attr( round( 100 * $confidence, 1 ) ); ?>%"></span><i></i></span>
									</span>
									<?php if ( ! $row['valid'] ) : ?>
										<span class="fabt-muted fabt-small"><?php esc_html_e( 'Needs more data', 'free-a-b-testing' ); ?></span>
									<?php endif; ?>
								<?php endif; ?>
							</td>
							<?php if ( $woo ) : ?>
								<td class="num">
									<?php echo esc_html( self::money( $row['revenue'] ) ); ?>
									<span class="fabt-muted fabt-small">
										<?php
										/* translators: %s: number of orders. */
										echo esc_html( sprintf( _n( '%s order', '%s orders', $row['orders'], 'free-a-b-testing' ), number_format_i18n( $row['orders'] ) ) );
										?>
									</span>
								</td>
							<?php endif; ?>
							<?php if ( $open ) : ?>
								<td class="fabt-actions-cell">
									<?php if ( $slot > 0 && $slot !== $winner ) : ?>
										<button type="submit" class="button-link fabt-confirm" name="fabt_do" value="apply:<?php echo esc_attr( $slot ); ?>" data-confirm="<?php echo esc_attr( sprintf( /* translators: %s: variant letter. */ __( 'Variant %s hasn\'t won with 95%% confidence. Copy its headline and content onto the page and end the test anyway?', 'free-a-b-testing' ), Free_A_B_Testing_Tests::letter( $slot ) ) ); ?>"><?php esc_html_e( 'Use this', 'free-a-b-testing' ); ?></button>
									<?php endif; ?>
								</td>
							<?php endif; ?>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<p class="fabt-muted fabt-small fabt-footnote">
			<?php
			if ( count( $results['rows'] ) > 2 ) {
				esc_html_e( 'Confidence compares each variant with the original (two-sided z-test), adjusted for testing several variants at once. A winner needs 95% and at least 30 visitors per version.', 'free-a-b-testing' );
			} else {
				esc_html_e( 'Confidence compares the variant with the original (two-sided z-test). A winner needs 95% and at least 30 visitors per version.', 'free-a-b-testing' );
			}
			?>
		</p>
		<?php
	}

	/**
	 * Line colours per slot: the original is a dashed neutral baseline, the
	 * variants take the categorical hues in a fixed order (checked for
	 * colour-blind separation), so a colour always means the same variant.
	 */
	const SERIES_COLORS = array( '#787c82', '#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4' );

	/**
	 * Chart: each version's conversion rate so far, day by day. Drawn as
	 * inline SVG on the server (no chart library); hovering a point shows
	 * its day and rate.
	 *
	 * @param array $test Test.
	 * @return void
	 */
	private static function render_chart( array $test ) {
		$data = Free_A_B_Testing_Stats::daily( $test['id'] );
		$days = $data['days'];

		if ( count( $days ) < 2 ) {
			return;
		}

		$w     = 720;
		$h     = 220;
		$left  = 44;
		$right = 92;
		$top   = 12;
		$bot   = 26;
		$max   = 0.0;

		foreach ( $data['series'] as $slot => $points ) {
			if ( isset( $test['variants'][ $slot ] ) ) {
				$max = max( $max, (float) max( array_map( 'floatval', $points ) ) );
			}
		}

		if ( $max <= 0 ) {
			return;
		}

		// A round top for the axis: 1%, 2%, 5%, 10%...
		$steps = array( 0.01, 0.02, 0.05, 0.1, 0.2, 0.25, 0.5, 1 );
		$ceil  = 1;

		foreach ( $steps as $step ) {
			if ( $max <= $step ) {
				$ceil = $step;
				break;
			}
		}

		$n      = count( $days );
		$plot_w = $w - $left - $right;
		$plot_h = $h - $top - $bot;
		$x      = function ( $i ) use ( $left, $plot_w, $n ) {
			return round( $left + $plot_w * $i / max( 1, $n - 1 ), 1 );
		};
		$y      = function ( $rate ) use ( $top, $plot_h, $ceil ) {
			return round( $top + $plot_h * ( 1 - $rate / $ceil ), 1 );
		};
		$format = get_option( 'date_format' );
		$labels = array();
		?>
		<figure class="fabt-chart">
			<figcaption><?php esc_html_e( 'Conversion rate so far, by day', 'free-a-b-testing' ); ?></figcaption>
			<svg viewBox="0 0 <?php echo (int) $w; ?> <?php echo (int) $h; ?>" role="img" aria-label="<?php esc_attr_e( 'Each version\'s conversion rate over the test. The numbers are in the table below.', 'free-a-b-testing' ); ?>">
				<?php foreach ( array( 0, 0.5, 1 ) as $f ) : ?>
					<line class="fabt-grid" x1="<?php echo (int) $left; ?>" x2="<?php echo (int) ( $w - $right ); ?>" y1="<?php echo esc_attr( $y( $ceil * $f ) ); ?>" y2="<?php echo esc_attr( $y( $ceil * $f ) ); ?>"></line>
					<text class="fabt-axis" x="<?php echo (int) ( $left - 8 ); ?>" y="<?php echo esc_attr( $y( $ceil * $f ) + 4 ); ?>" text-anchor="end"><?php echo esc_html( self::percent( $ceil * $f, fmod( round( 1000 * $ceil * $f ), 10 ) ? 1 : 0 ) ); ?></text>
				<?php endforeach; ?>
				<?php foreach ( array_unique( array( 0, (int) floor( ( $n - 1 ) / 2 ), $n - 1 ) ) as $i ) : ?>
					<text class="fabt-axis" x="<?php echo esc_attr( $x( $i ) ); ?>" y="<?php echo (int) ( $h - 6 ); ?>" text-anchor="<?php echo 0 === $i ? 'start' : ( $n - 1 === $i ? 'end' : 'middle' ); ?>"><?php echo esc_html( wp_date( 'M j', strtotime( $days[ $i ] . ' 12:00:00 UTC' ) ) ); ?></text>
				<?php endforeach; ?>
				<?php foreach ( $data['series'] as $slot => $points ) : ?>
					<?php
					if ( ! isset( $test['variants'][ $slot ] ) ) {
						continue;
					}

					$color = self::SERIES_COLORS[ $slot % count( self::SERIES_COLORS ) ];
					$path  = '';
					$end   = null;

					foreach ( $points as $i => $rate ) {
						if ( null !== $rate ) {
							$path .= ( '' === $path ? 'M' : 'L' ) . $x( $i ) . ' ' . $y( $rate );
							$end   = array( $x( $i ), $y( $rate ), $rate );
						}
					}

					if ( ! $end ) {
						continue;
					}

					$labels[] = array( $slot, $end[1], $end[2], $color );
					?>
					<path class="fabt-line" d="<?php echo esc_attr( $path ); ?>" stroke="<?php echo esc_attr( $color ); ?>"<?php echo 0 === $slot ? ' stroke-dasharray="5 4"' : ''; ?>></path>
					<?php foreach ( $points as $i => $rate ) : ?>
						<?php if ( null !== $rate ) : ?>
							<circle class="fabt-hit" cx="<?php echo esc_attr( $x( $i ) ); ?>" cy="<?php echo esc_attr( $y( $rate ) ); ?>" r="7" style="--c:<?php echo esc_attr( $color ); ?>"><title><?php echo esc_html( Free_A_B_Testing_Tests::letter( $slot ) . ' · ' . wp_date( $format, strtotime( $days[ $i ] . ' 12:00:00 UTC' ) ) . ': ' . self::percent( $rate ) ); ?></title></circle>
						<?php endif; ?>
					<?php endforeach; ?>
				<?php endforeach; ?>
				<?php
				// Direct labels at the line ends, nudged apart so they never overlap.
				usort(
					$labels,
					function ( $a, $b ) {
						return $a[1] <=> $b[1];
					}
				);

				$prev = -100;

				foreach ( $labels as $label ) :
					$ly   = max( $label[1] + 4, $prev + 14 );
					$prev = $ly;
					?>
					<text class="fabt-end" x="<?php echo (int) ( $w - $right + 8 ); ?>" y="<?php echo esc_attr( $ly ); ?>"><tspan fill="<?php echo esc_attr( $label[3] ); ?>">●</tspan> <?php echo esc_html( Free_A_B_Testing_Tests::letter( $label[0] ) . ' ' . self::percent( $label[2] ) ); ?></text>
				<?php endforeach; ?>
			</svg>
			<ul class="fabt-legend">
				<?php foreach ( array_keys( $data['series'] ) as $slot ) : ?>
					<?php if ( isset( $test['variants'][ $slot ] ) ) : ?>
						<li><span class="fabt-swatch<?php echo 0 === $slot ? ' is-dashed' : ''; ?>" style="--c:<?php echo esc_attr( self::SERIES_COLORS[ $slot % count( self::SERIES_COLORS ) ] ); ?>"></span><?php echo esc_html( Free_A_B_Testing_Tests::letter( $slot ) . ': ' . $test['variants'][ $slot ]['name'] ); ?></li>
					<?php endif; ?>
				<?php endforeach; ?>
			</ul>
		</figure>
		<?php
	}

	/**
	 * The callout at the top of the results.
	 *
	 * @param array $test    Test.
	 * @param array $results Results.
	 * @return void
	 */
	private static function render_verdict( array $test, array $results ) {
		$winner = $results['winner'];
		$rows   = $results['rows'];

		if ( $test['applied'] >= 0 ) {
			$text = $test['applied'] > 0
				/* translators: 1: "Variant B", 2: date. */
				? sprintf( __( '%1$s was applied to the page on %2$s. The page\'s earlier version is in its revisions.', 'free-a-b-testing' ), Free_A_B_Testing_Tests::label( $test, $test['applied'] ), wp_date( get_option( 'date_format' ), $test['ended'] ) )
				/* translators: %s: date. */
				: sprintf( __( 'The test ended on %s and the original was kept.', 'free-a-b-testing' ), wp_date( get_option( 'date_format' ), $test['ended'] ) );

			echo '<div class="fabt-verdict is-done">' . self::icon( 'badge-check', 22 ) . '<div><strong>' . esc_html__( 'Test finished', 'free-a-b-testing' ) . '</strong><p>' . esc_html( $text ) . '</p></div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG.
			return;
		}

		if ( $winner > 0 ) {
			$row = $rows[ $winner ];
			?>
			<div class="fabt-verdict is-winner">
				<?php echo self::icon( 'trophy', 22 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
				<div>
					<strong>
						<?php
						/* translators: %s: "Variant B". */
						echo esc_html( sprintf( __( '%s is the winner', 'free-a-b-testing' ), Free_A_B_Testing_Tests::label( $test, $winner ) ) );
						?>
					</strong>
					<p>
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: conversion rate, 2: lift, 3: confidence. */
								__( 'It converts at %1$s, %2$s better than the original, with %3$s confidence. Apply it to make it the page everyone sees.', 'free-a-b-testing' ),
								self::percent( $row['rate'] ),
								null === $row['lift'] ? '–' : self::percent( $row['lift'] ),
								self::percent( min( 0.999, $row['confidence'] ) )
							)
						);
						?>
					</p>
				</div>
				<button type="submit" class="button button-primary fabt-confirm" name="fabt_do" value="apply:<?php echo esc_attr( $winner ); ?>" data-confirm="<?php esc_attr_e( 'Copy the winner\'s headline and content onto the page and end the test? The page\'s current version stays in its revisions.', 'free-a-b-testing' ); ?>">
					<?php echo self::icon( 'wand-sparkles', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<?php esc_html_e( 'Apply winner', 'free-a-b-testing' ); ?>
				</button>
			</div>
			<?php
			return;
		}

		if ( 0 === $winner ) {
			?>
			<div class="fabt-verdict is-winner">
				<?php echo self::icon( 'trophy', 22 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
				<div>
					<strong><?php esc_html_e( 'The original is the winner', 'free-a-b-testing' ); ?></strong>
					<p><?php esc_html_e( 'Every variant converts worse than the page as it is, with 95% confidence. End the test to keep the original.', 'free-a-b-testing' ); ?></p>
				</div>
				<button type="submit" class="button button-primary fabt-confirm" name="fabt_do" value="apply:0" data-confirm="<?php esc_attr_e( 'End the test and keep the original page?', 'free-a-b-testing' ); ?>">
					<?php esc_html_e( 'Keep original', 'free-a-b-testing' ); ?>
				</button>
			</div>
			<?php
			return;
		}

		if ( ! $results['visitors'] ) {
			$text = 'running' === $test['status']
				? __( 'Results appear here as soon as people visit the page. Your own visits aren\'t counted, so open the page in a private window to check.', 'free-a-b-testing' )
				: __( 'No visitors were counted.', 'free-a-b-testing' );

			echo '<div class="fabt-verdict">' . self::icon( 'hourglass', 22 ) . '<div><strong>' . esc_html__( 'No visitors yet', 'free-a-b-testing' ) . '</strong><p>' . esc_html( $text ) . '</p></div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG.
			return;
		}

		$leader = $results['leader'];
		$text   = __( 'A winner is declared once a variant reaches 95% confidence.', 'free-a-b-testing' );

		if ( $leader >= 0 ) {
			$text .= ' ' . sprintf(
				/* translators: %s: version letter and name. */
				__( '%s is ahead so far.', 'free-a-b-testing' ),
				0 === $leader ? __( 'The original', 'free-a-b-testing' ) : Free_A_B_Testing_Tests::label( $test, $leader )
			);
		}

		if ( 'running' === $test['status'] && $test['started'] > time() - WEEK_IN_SECONDS ) {
			$text .= ' ' . __( 'Let it run at least a week, so weekdays and weekends both count.', 'free-a-b-testing' );
		}

		echo '<div class="fabt-verdict">' . self::icon( 'scale', 22 ) . '<div><strong>' . esc_html__( 'No winner yet', 'free-a-b-testing' ) . '</strong><p>' . esc_html( $text ) . '</p></div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG.
	}

	/**
	 * The versions: the original and each variant, with what changed.
	 *
	 * @param array $test     Test.
	 * @param bool  $editable Whether names and headlines can be changed.
	 * @return void
	 */
	private static function render_variants( array $test, $editable ) {
		$original = Free_A_B_Testing_Tests::slot_post( $test, 0 );
		$can_add  = in_array( $test['status'], array( 'draft', 'paused' ), true );
		?>
		<p class="fabt-intro">
			<?php
			echo 'running' === $test['status']
				? esc_html__( 'Changes go live as soon as they\'re saved. Big changes mid-test mix old and new results, so reset the results after one.', 'free-a-b-testing' )
				: esc_html__( 'Each variant is a copy of the page. Change its headline here, or edit its content (buttons, blocks or the whole page) in the editor.', 'free-a-b-testing' );
			?>
		</p>
		<div class="fabt-variants">
			<?php foreach ( $test['variants'] as $slot => $variant ) : ?>
				<?php
				$post = Free_A_B_Testing_Tests::slot_post( $test, $slot );
				$diff = $editable && $slot && $post && $original ? self::differences( $test, $original, $post ) : null;
				?>
				<div class="fabt-variant<?php echo 0 === $slot ? ' is-original' : ''; ?>">
					<span class="fabt-letter is-big"><?php echo esc_html( Free_A_B_Testing_Tests::letter( $slot ) ); ?></span>
					<div class="fabt-variant-body">
						<?php if ( 0 === $slot ) : ?>
							<div class="fabt-variant-name"><strong><?php esc_html_e( 'Original', 'free-a-b-testing' ); ?></strong> <span class="fabt-muted"><?php esc_html_e( 'The page as it is now', 'free-a-b-testing' ); ?></span></div>
							<div class="fabt-field">
								<span class="fabt-field-label"><?php esc_html_e( 'Headline', 'free-a-b-testing' ); ?></span>
								<span class="fabt-headline"><?php echo $original ? esc_html( $original->post_title ) : '–'; ?></span>
							</div>
						<?php elseif ( ! $post ) : ?>
							<div class="fabt-variant-name"><strong><?php echo esc_html( $variant['name'] ); ?></strong></div>
							<p class="fabt-callout is-warning"><?php echo self::icon( 'triangle-alert', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php esc_html_e( 'This variant\'s content was deleted, so it isn\'t shown to anyone. Remove it.', 'free-a-b-testing' ); ?></span></p>
						<?php else : ?>
							<div class="fabt-field">
								<label class="fabt-field-label" for="fabt-name-<?php echo esc_attr( $slot ); ?>"><?php esc_html_e( 'Name', 'free-a-b-testing' ); ?></label>
								<input type="text" id="fabt-name-<?php echo esc_attr( $slot ); ?>" name="variants[<?php echo esc_attr( $slot ); ?>][name]" value="<?php echo esc_attr( $variant['name'] ); ?>" maxlength="60" class="regular-text"<?php disabled( ! $editable ); ?>>
							</div>
							<div class="fabt-field">
								<label class="fabt-field-label" for="fabt-headline-<?php echo esc_attr( $slot ); ?>"><?php esc_html_e( 'Headline', 'free-a-b-testing' ); ?></label>
								<input type="text" id="fabt-headline-<?php echo esc_attr( $slot ); ?>" name="variants[<?php echo esc_attr( $slot ); ?>][headline]" value="<?php echo esc_attr( $post->post_title ); ?>" class="large-text"<?php disabled( ! $editable ); ?>>
							</div>
							<?php self::render_difference( $diff ); ?>
						<?php endif; ?>
					</div>
					<div class="fabt-variant-actions">
						<?php if ( $post ) : ?>
							<a class="button" href="<?php echo esc_url( Free_A_B_Testing_Frontend::preview_url( $test, $slot ) ); ?>" target="_blank" rel="noopener"><?php echo self::icon( 'eye', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Preview', 'free-a-b-testing' ); ?></a>
							<?php if ( $editable && get_edit_post_link( $post->ID, 'raw' ) ) : ?>
								<a class="button" href="<?php echo esc_url( get_edit_post_link( $post->ID, 'raw' ) ); ?>"><?php echo self::icon( 'pencil', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php echo 0 === $slot ? esc_html__( 'Edit page', 'free-a-b-testing' ) : esc_html__( 'Edit content', 'free-a-b-testing' ); ?></a>
							<?php endif; ?>
						<?php endif; ?>
						<?php if ( $slot && ( $can_add || ! $post ) ) : ?>
							<button type="submit" class="button-link fabt-remove fabt-confirm" name="fabt_do" value="remove:<?php echo esc_attr( $slot ); ?>" data-confirm="<?php esc_attr_e( 'Remove this variant and its results?', 'free-a-b-testing' ); ?>"><?php echo self::icon( 'trash-2', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span class="screen-reader-text"><?php esc_html_e( 'Remove variant', 'free-a-b-testing' ); ?></span></button>
						<?php endif; ?>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
		<?php if ( $can_add && count( $test['variants'] ) - 1 < Free_A_B_Testing_Tests::MAX_VARIANTS ) : ?>
			<p class="fabt-add-variant">
				<button type="submit" class="button" name="fabt_do" value="add_variant"><?php echo self::icon( 'plus', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Add variant', 'free-a-b-testing' ); ?></button>
				<span class="fabt-muted"><?php esc_html_e( 'A new copy of the page. Traffic is split evenly between all versions.', 'free-a-b-testing' ); ?></span>
			</p>
		<?php elseif ( 'running' === $test['status'] ) : ?>
			<p class="fabt-muted fabt-small"><?php esc_html_e( 'Pause the test to add or remove variants.', 'free-a-b-testing' ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * What a variant changes compared with the original.
	 *
	 * @param array   $test     Test.
	 * @param WP_Post $original Original page.
	 * @param WP_Post $variant  Variant post.
	 * @return array{title:bool,parts:int}
	 */
	private static function differences( array $test, WP_Post $original, WP_Post $variant ) {
		$merged = Free_A_B_Testing_Merge::build(
			$test['id'],
			array(
				0 => $original->post_content,
				1 => $variant->post_content,
			)
		);

		return array(
			'title' => $original->post_title !== $variant->post_title,
			'parts' => $merged['changes'],
		);
	}

	/**
	 * Line saying what a variant changes.
	 *
	 * @param array|null $diff See {@see differences()}.
	 * @return void
	 */
	private static function render_difference( $diff ) {
		if ( ! $diff ) {
			return;
		}

		if ( ! $diff['title'] && ! $diff['parts'] ) {
			echo '<p class="fabt-diff is-same">' . self::icon( 'triangle-alert', 15 ) . '<span>' . esc_html__( 'Same as the original so far. Change its headline, or edit its content.', 'free-a-b-testing' ) . '</span></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG.
			return;
		}

		/* translators: %d: number of changed parts. */
		$parts = sprintf( _n( '%d part of the content', '%d parts of the content', $diff['parts'], 'free-a-b-testing' ), $diff['parts'] );

		if ( $diff['title'] && $diff['parts'] ) {
			/* translators: %s: "2 parts of the content". */
			$text = sprintf( __( 'Changes the headline and %s.', 'free-a-b-testing' ), $parts );
		} elseif ( $diff['title'] ) {
			$text = __( 'Changes the headline.', 'free-a-b-testing' );
		} else {
			/* translators: %s: "2 parts of the content". */
			$text = sprintf( __( 'Changes %s.', 'free-a-b-testing' ), $parts );
		}

		echo '<p class="fabt-diff">' . self::icon( 'git-compare', 15 ) . '<span>' . esc_html( $text ) . '</span></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG.
	}

	/**
	 * Sidebar: status and the buttons that change it.
	 *
	 * @param array $test    Test.
	 * @param array $results Results.
	 * @return void
	 */
	private static function render_status_box( array $test, array $results ) {
		self::box(
			'status',
			'flag',
			__( 'Status', 'free-a-b-testing' ),
			function () use ( $test, $results ) {
				$labels = self::status_labels();
				$format = get_option( 'date_format' );
				?>
				<p class="fabt-status-line">
					<span class="fabt-status is-<?php echo esc_attr( $test['status'] ); ?>"><?php echo esc_html( $labels[ $test['status'] ] ); ?></span>
					<?php if ( $test['started'] ) : ?>
						<span class="fabt-muted">
							<?php
							/* translators: %s: date. */
							echo esc_html( sprintf( __( 'Started %s', 'free-a-b-testing' ), wp_date( $format, $test['started'] ) ) );
							?>
						</span>
					<?php endif; ?>
				</p>

				<?php if ( 'ended' !== $test['status'] ) : ?>
					<p class="fabt-field">
						<label class="fabt-field-label" for="fabt-test-name"><?php esc_html_e( 'Test name', 'free-a-b-testing' ); ?></label>
						<input type="text" id="fabt-test-name" name="name" value="<?php echo esc_attr( $test['name'] ); ?>" class="widefat" maxlength="120">
					</p>
				<?php endif; ?>

				<?php if ( 'draft' === $test['status'] ) : ?>
					<p><?php esc_html_e( 'Nobody sees the variants yet. Preview them, then start the test.', 'free-a-b-testing' ); ?></p>
					<button type="submit" class="button button-primary button-large fabt-wide" name="fabt_do" value="start"><?php echo self::icon( 'play', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Start test', 'free-a-b-testing' ); ?></button>
				<?php elseif ( 'running' === $test['status'] ) : ?>
					<p>
						<?php
						/* translators: %d: number of versions. */
						echo esc_html( sprintf( _n( 'Visitors are split evenly between %d version.', 'Visitors are split evenly between %d versions.', count( Free_A_B_Testing_Tests::active_slots( $test ) ), 'free-a-b-testing' ), count( Free_A_B_Testing_Tests::active_slots( $test ) ) ) );
						?>
					</p>
					<div class="fabt-button-row">
						<button type="submit" class="button" name="fabt_do" value="pause"><?php echo self::icon( 'pause', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Pause', 'free-a-b-testing' ); ?></button>
						<button type="submit" class="button fabt-confirm" name="fabt_do" value="end" data-confirm="<?php esc_attr_e( 'End the test? Everyone will see the original page, and the test can\'t be restarted.', 'free-a-b-testing' ); ?>"><?php echo self::icon( 'square', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'End test', 'free-a-b-testing' ); ?></button>
					</div>
				<?php elseif ( 'paused' === $test['status'] ) : ?>
					<p><?php esc_html_e( 'Paused: everyone sees the original and nothing is counted.', 'free-a-b-testing' ); ?></p>
					<div class="fabt-button-row">
						<button type="submit" class="button button-primary" name="fabt_do" value="resume"><?php echo self::icon( 'play', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Resume', 'free-a-b-testing' ); ?></button>
						<button type="submit" class="button fabt-confirm" name="fabt_do" value="end" data-confirm="<?php esc_attr_e( 'End the test? Everyone will see the original page, and the test can\'t be restarted.', 'free-a-b-testing' ); ?>"><?php echo self::icon( 'square', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'End test', 'free-a-b-testing' ); ?></button>
					</div>
				<?php else : ?>
					<p>
						<?php
						/* translators: %s: date. */
						echo esc_html( sprintf( __( 'Ended %s. Everyone sees the page as it is now.', 'free-a-b-testing' ), wp_date( $format, $test['ended'] ) ) );
						?>
					</p>
				<?php endif; ?>

				<?php if ( 'ended' !== $test['status'] ) : ?>
					<hr>
					<button type="submit" class="button fabt-wide" name="fabt_do" value="save"><?php echo self::icon( 'circle-check', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Save changes', 'free-a-b-testing' ); ?></button>
				<?php endif; ?>

				<div class="fabt-danger">
					<?php if ( 'ended' !== $test['status'] && $results['visitors'] ) : ?>
						<button type="submit" class="button-link fabt-confirm" name="fabt_do" value="reset" data-confirm="<?php esc_attr_e( 'Delete this test\'s results and start counting again from now?', 'free-a-b-testing' ); ?>"><?php echo self::icon( 'rotate-ccw', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Reset results', 'free-a-b-testing' ); ?></button>
					<?php endif; ?>
					<button type="submit" class="button-link is-destructive fabt-confirm" name="fabt_do" value="delete" data-confirm="<?php esc_attr_e( 'Delete this test, its variants and its results? The page itself isn\'t changed.', 'free-a-b-testing' ); ?>"><?php echo self::icon( 'trash-2', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Delete test', 'free-a-b-testing' ); ?></button>
				</div>
				<?php
			}
		);
	}

	// Settings --------------------------------------------------------------------

	/**
	 * Settings.
	 *
	 * @return void
	 */
	private function render_settings_view() {
		$settings = $this->plugin->get_settings();
		$name     = Free_A_B_Testing_Plugin::OPTION;
		?>
		<form action="options.php" method="post" class="fabt-form">
			<?php settings_fields( self::PAGE ); ?>
			<?php
			self::columns(
				function () use ( $settings, $name ) {
					self::box(
						'tracking',
						'users',
						__( 'Who gets counted', 'free-a-b-testing' ),
						function () use ( $settings, $name ) {
							?>
							<table class="form-table" role="presentation">
								<tr>
									<th scope="row"><?php esc_html_e( 'Your team', 'free-a-b-testing' ); ?></th>
									<td>
										<label class="fabt-check"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[exclude_editors]" value="1" <?php checked( 1, (int) $settings['exclude_editors'] ); ?>> <span><?php esc_html_e( 'Don\'t count your logged-in team (anyone who can write posts)', 'free-a-b-testing' ); ?></span></label>
										<p class="description"><?php esc_html_e( 'They still see a variant, so they can check the test, but their visits and clicks don\'t move the numbers.', 'free-a-b-testing' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row"><?php esc_html_e( 'Privacy signals', 'free-a-b-testing' ); ?></th>
									<td>
										<label class="fabt-check"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[respect_gpc]" value="1" <?php checked( 1, (int) $settings['respect_gpc'] ); ?>> <span><?php esc_html_e( 'Honor Global Privacy Control and Do Not Track', 'free-a-b-testing' ); ?></span></label>
										<p class="description"><?php esc_html_e( 'Visitors whose browser sends either signal see the original page, get no cookie and aren\'t counted.', 'free-a-b-testing' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="fabt-cookie-days"><?php esc_html_e( 'Remember variants for', 'free-a-b-testing' ); ?></label></th>
									<td>
										<input type="number" id="fabt-cookie-days" class="small-text" name="<?php echo esc_attr( $name ); ?>[cookie_days]" value="<?php echo esc_attr( $settings['cookie_days'] ); ?>" min="1" max="365" step="1"> <?php esc_html_e( 'days', 'free-a-b-testing' ); ?>
										<p class="description"><?php esc_html_e( 'How long a returning visitor keeps seeing the same version (the "fabt" cookie).', 'free-a-b-testing' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="fabt-hourly-limit"><?php esc_html_e( 'Rate limit', 'free-a-b-testing' ); ?></label></th>
									<td>
										<input type="number" id="fabt-hourly-limit" class="small-text" name="<?php echo esc_attr( $name ); ?>[hourly_limit]" value="<?php echo esc_attr( $settings['hourly_limit'] ); ?>" min="5" max="1000" step="1"> <?php esc_html_e( 'new visitors and conversions per connection per hour', 'free-a-b-testing' ); ?>
										<p class="description"><?php esc_html_e( 'Stops one computer from flooding the results with fake visits. Raise it if many real visitors share one connection (an office, a school).', 'free-a-b-testing' ); ?></p>
									</td>
								</tr>
							</table>
							<?php
						}
					);

					self::box(
						'caching',
						'layers',
						__( 'Page caching', 'free-a-b-testing' ),
						function () {
							?>
							<p class="fabt-intro"><?php esc_html_e( 'Tests work with any page cache or CDN: every visitor gets the same cached page with all versions in it, and a few lines of inline code show each visitor theirs before the page paints. Nothing to set up.', 'free-a-b-testing' ); ?></p>
							<ul class="fabt-checklist">
								<li><?php echo self::icon( 'circle-check', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php esc_html_e( 'When a test starts, pauses, ends or changes, its page is cleared from WP Rocket, W3 Total Cache, WP Super Cache, LiteSpeed Cache, WP Fastest Cache, SiteGround Optimizer and Cache Enabler.', 'free-a-b-testing' ); ?></span></li>
								<li><?php echo self::icon( 'circle-check', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php esc_html_e( 'The inline code is excluded from "delay JavaScript", "defer" and "remove unused CSS" in WP Rocket, LiteSpeed, Perfmatters, Autoptimize, Jetpack Boost and Cloudflare Rocket Loader.', 'free-a-b-testing' ); ?></span></li>
								<li><?php echo self::icon( 'info', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php esc_html_e( 'Using a CDN or host cache that isn\'t listed? Clear the page there after starting or ending a test.', 'free-a-b-testing' ); ?></span></li>
							</ul>
							<?php
						}
					);
				},
				function () {
					self::box(
						'save',
						'circle-check',
						__( 'Save', 'free-a-b-testing' ),
						function () {
							?>
							<p><?php esc_html_e( 'These settings apply to every test.', 'free-a-b-testing' ); ?></p>
							<?php submit_button( __( 'Save settings', 'free-a-b-testing' ), 'primary large fabt-wide', 'submit', false ); ?>
							<?php
						}
					);
					self::render_updates_box();
				}
			);
		?>
		</form>
		<?php
	}

	/**
	 * Show where updates come from, so it's easy to confirm the repo is wired up.
	 *
	 * @return void
	 */
	private static function render_updates_box() {
		self::box(
			'updates',
			'refresh-cw',
			__( 'Updates', 'free-a-b-testing' ),
			function () {
				$repo_label = preg_replace( '#^https?://[^/]+/#', '', untrailingslashit( FREE_A_B_TESTING_REPO ) );
				$check_url  = Free_A_B_Testing_Updater::check_now_url();
				?>
				<p>
					<?php
					/* translators: %s: installed plugin version. */
					printf( esc_html__( 'Installed version: %s', 'free-a-b-testing' ), '<strong>' . esc_html( FREE_A_B_TESTING_VERSION ) . '</strong>' );
					?>
				</p>

				<?php if ( ! Free_A_B_Testing_Updater::has_repo() ) : ?>
					<p>
						<?php
						printf(
							/* translators: 1: header name, 2: main plugin file. */
							esc_html__( 'No repo set. Add it to the %1$s header in %2$s to turn on automatic updates.', 'free-a-b-testing' ),
							'<code>Update URI</code>',
							'<code>' . esc_html( plugin_basename( FREE_A_B_TESTING_FILE ) ) . '</code>'
						);
						?>
					</p>
				<?php elseif ( Free_A_B_Testing_Updater::is_dev_copy() ) : ?>
					<p>
						<?php
						printf(
							/* translators: 1: repo, 2: constant name. */
							esc_html__( 'Releases come from %1$s, but update checks are off on this development copy so an update can\'t overwrite your work. Define %2$s as false in wp-config.php to test updates here.', 'free-a-b-testing' ),
							'<a href="' . esc_url( FREE_A_B_TESTING_REPO ) . '" target="_blank" rel="noopener">' . esc_html( $repo_label ) . '</a>',
							'<code>FREE_A_B_TESTING_DEV_MODE</code>'
						);
						?>
					</p>
				<?php else : ?>
					<p>
						<?php
						printf(
							/* translators: %s: repo. */
							esc_html__( 'Updates come from the latest release of %s.', 'free-a-b-testing' ),
							'<a href="' . esc_url( FREE_A_B_TESTING_REPO ) . '" target="_blank" rel="noopener">' . esc_html( $repo_label ) . '</a>'
						);
						?>
					</p>
					<?php if ( $check_url ) : ?>
						<p><a class="button" href="<?php echo esc_url( $check_url ); ?>"><?php echo self::icon( 'refresh-cw', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Check for updates', 'free-a-b-testing' ); ?></a></p>
					<?php endif; ?>
				<?php endif; ?>
				<?php
			}
		);
	}

	// Handlers ------------------------------------------------------------------------

	/**
	 * Create a test.
	 *
	 * @return void
	 */
	public function handle_create() {
		check_admin_referer( 'fabt_create' );

		if ( ! current_user_can( Free_A_B_Testing_Tests::CAP ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'free-a-b-testing' ), '', array( 'response' => 403 ) );
		}

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$name    = isset( $_POST['name'] ) && is_string( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$test_id = Free_A_B_Testing_Tests::create( $post_id, mb_substr( $name, 0, 120 ), self::posted_goal() );

		if ( is_wp_error( $test_id ) ) {
			self::flash( 'error', $test_id->get_error_message() );
			self::redirect(
				self::page_url(
					array_filter(
						array(
							'view'    => 'new',
							'post_id' => $post_id,
						)
					)
				)
			);
		}

		self::flash( 'success', __( 'Test created. Variant B is a copy of the page: change its headline below or edit its content, preview it, then start the test.', 'free-a-b-testing' ) );
		self::redirect( self::test_url( $test_id ) );
	}

	/**
	 * Save a test, then do what the button asked (start, pause, apply...).
	 *
	 * @return void
	 */
	public function handle_test() {
		$test_id = isset( $_POST['test'] ) ? absint( $_POST['test'] ) : 0;

		check_admin_referer( 'fabt_test_' . $test_id );

		if ( ! current_user_can( Free_A_B_Testing_Tests::CAP ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'free-a-b-testing' ), '', array( 'response' => 403 ) );
		}

		$test = Free_A_B_Testing_Tests::get( $test_id );

		if ( ! $test ) {
			self::flash( 'error', __( 'That test no longer exists.', 'free-a-b-testing' ) );
			self::redirect( self::page_url() );
		}

		$do     = isset( $_POST['fabt_do'] ) && is_string( $_POST['fabt_do'] ) ? sanitize_text_field( wp_unslash( $_POST['fabt_do'] ) ) : 'save';
		$parts  = explode( ':', $do, 2 );
		$action = sanitize_key( $parts[0] );
		$slot   = isset( $parts[1] ) ? absint( $parts[1] ) : 0;

		if ( 'delete' === $action ) {
			Free_A_B_Testing_Tests::delete( $test_id );
			self::flash( 'success', __( 'Test deleted. The page wasn\'t changed.', 'free-a-b-testing' ) );
			self::redirect( self::page_url() );
		}

		if ( 'ended' !== $test['status'] ) {
			$this->save_posted( $test );
			$test = Free_A_B_Testing_Tests::get( $test_id );
		}

		$result  = true;
		$message = __( 'Changes saved.', 'free-a-b-testing' );

		switch ( $action ) {
			case 'start':
			case 'resume':
				$result = self::can_start( $test );

				if ( true === $result ) {
					$result = Free_A_B_Testing_Tests::set_status( $test_id, 'running' );
				}

				$message = 'start' === $action
					? __( 'Test started. Visitors are now split evenly between the versions. Open the page in a private window to see it as a visitor.', 'free-a-b-testing' )
					: __( 'Test resumed.', 'free-a-b-testing' );
				break;

			case 'pause':
				$result  = Free_A_B_Testing_Tests::set_status( $test_id, 'paused' );
				$message = __( 'Test paused. Everyone sees the original page until you resume it.', 'free-a-b-testing' );
				break;

			case 'end':
				$result  = Free_A_B_Testing_Tests::set_status( $test_id, 'ended' );
				$message = __( 'Test ended. Everyone sees the original page.', 'free-a-b-testing' );
				break;

			case 'add_variant':
				if ( ! in_array( $test['status'], array( 'draft', 'paused' ), true ) ) {
					$result = new WP_Error( 'fabt_running', __( 'Pause the test to add variants.', 'free-a-b-testing' ) );
					break;
				}

				$added  = Free_A_B_Testing_Tests::add_variant( $test_id );
				$result = is_wp_error( $added ) ? $added : true;
				/* translators: %s: variant letter. */
				$message = is_wp_error( $added ) ? '' : sprintf( __( 'Variant %s added: a fresh copy of the page.', 'free-a-b-testing' ), Free_A_B_Testing_Tests::letter( $added ) );
				break;

			case 'remove':
				$post = Free_A_B_Testing_Tests::slot_post( $test, $slot );

				if ( $post && ! in_array( $test['status'], array( 'draft', 'paused' ), true ) ) {
					$result = new WP_Error( 'fabt_running', __( 'Pause the test to remove variants.', 'free-a-b-testing' ) );
					break;
				}

				$result  = Free_A_B_Testing_Tests::delete_variant( $test_id, $slot ) ? true : new WP_Error( 'fabt_variant', __( 'That variant no longer exists.', 'free-a-b-testing' ) );
				$message = __( 'Variant removed.', 'free-a-b-testing' );

				// A running test whose last variant was deleted elsewhere can't keep running.
				$test = Free_A_B_Testing_Tests::get( $test_id );

				if ( $test && 'running' === $test['status'] && count( Free_A_B_Testing_Tests::active_slots( $test ) ) < 2 ) {
					Free_A_B_Testing_Tests::set_status( $test_id, 'paused' );
				}
				break;

			case 'reset':
				Free_A_B_Testing_Tests::reset( $test_id );
				$message = __( 'Results reset. Every visitor is counted again from now.', 'free-a-b-testing' );
				break;

			case 'apply':
				$result  = Free_A_B_Testing_Tests::apply( $test_id, $slot );
				$message = $slot
					/* translators: %s: variant letter. */
					? sprintf( __( 'Variant %s is now the page: its headline and content were copied over, and the test ended. The page\'s earlier version is in its revisions.', 'free-a-b-testing' ), Free_A_B_Testing_Tests::letter( $slot ) )
					: __( 'Test ended. The original page was kept.', 'free-a-b-testing' );
				break;
		}

		if ( is_wp_error( $result ) ) {
			self::flash( 'error', $result->get_error_message() );
		} else {
			self::flash( 'success', $message );
		}

		self::redirect( self::test_url( $test_id ) );
	}

	/**
	 * Save the posted name, goal, variant names and headlines.
	 *
	 * @param array $test Test.
	 * @return void
	 */
	private function save_posted( array $test ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked in handle_test().
		$name = isset( $_POST['name'] ) && is_string( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';

		Free_A_B_Testing_Tests::update( $test['id'], $name, self::posted_goal() );

		$variants = isset( $_POST['variants'] ) && is_array( $_POST['variants'] ) ? wp_unslash( $_POST['variants'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each value is cleaned below.
		// phpcs:enable

		foreach ( $variants as $slot => $fields ) {
			$slot = (int) $slot;

			if ( $slot < 1 || ! isset( $test['variants'][ $slot ] ) || ! is_array( $fields ) ) {
				continue;
			}

			$headline = isset( $fields['headline'] ) && is_string( $fields['headline'] ) ? trim( $fields['headline'] ) : null;

			Free_A_B_Testing_Tests::update_variant(
				$test,
				$slot,
				isset( $fields['name'] ) && is_string( $fields['name'] ) ? $fields['name'] : '',
				'' === $headline ? null : $headline
			);
		}
	}

	/**
	 * The posted goal.
	 *
	 * @return array
	 */
	private static function posted_goal() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked by the callers.
		$in = isset( $_POST['goal'] ) && is_array( $_POST['goal'] ) ? wp_unslash( $_POST['goal'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Cleaned by clean_goal().
		// phpcs:enable

		if ( ! $in ) {
			return array();
		}

		$type = isset( $in['type'] ) && is_string( $in['type'] ) ? $in['type'] : 'click';
		$key  = 'form' === $type ? 'selector_form' : 'selector_click';

		return Free_A_B_Testing_Tests::clean_goal(
			array(
				'type'     => $type,
				'selector' => isset( $in[ $key ] ) && is_string( $in[ $key ] ) ? $in[ $key ] : '',
				'tel'      => isset( $in['tel'] ) && is_string( $in['tel'] ) ? $in['tel'] : 'both',
			)
		);
	}

	/**
	 * Whether a test is ready to run.
	 *
	 * @param array $test Test.
	 * @return true|WP_Error
	 */
	private static function can_start( array $test ) {
		if ( 'click' === $test['goal']['type'] && '' === $test['goal']['selector'] ) {
			return new WP_Error( 'fabt_goal', __( 'Choose the element to count clicks on (Goal), then start the test.', 'free-a-b-testing' ) );
		}

		if ( 'woo' === $test['goal']['type'] && ! class_exists( 'WooCommerce' ) ) {
			return new WP_Error( 'fabt_goal', __( 'WooCommerce isn\'t active, so a purchase goal can\'t count anything. Choose another goal.', 'free-a-b-testing' ) );
		}

		$original = Free_A_B_Testing_Tests::slot_post( $test, 0 );

		if ( ! $original ) {
			return new WP_Error( 'fabt_page', __( 'The page being tested no longer exists.', 'free-a-b-testing' ) );
		}

		foreach ( Free_A_B_Testing_Tests::active_slots( $test ) as $slot ) {
			$post = $slot ? Free_A_B_Testing_Tests::slot_post( $test, $slot ) : null;

			if ( $post ) {
				$diff = self::differences( $test, $original, $post );

				if ( $diff['title'] || $diff['parts'] ) {
					return true;
				}
			}
		}

		return new WP_Error( 'fabt_same', __( 'Every variant is still the same as the original. Change a headline or edit a variant\'s content first.', 'free-a-b-testing' ) );
	}

	/**
	 * Page search for the new-test form.
	 *
	 * @return void
	 */
	public function ajax_search() {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( Free_A_B_Testing_Tests::CAP ) ) {
			wp_send_json_error( null, 403 );
		}

		$query = isset( $_GET['q'] ) && is_string( $_GET['q'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_GET['q'] ) ), 0, 100 ) : '';
		$types = array_values( array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment' ) ) );
		$args  = array(
			'post_type'        => $types,
			'post_status'      => 'publish',
			'has_password'     => false,
			'posts_per_page'   => 20,
			'no_found_rows'    => true,
			'suppress_filters' => false,
		);

		if ( '' !== $query ) {
			$args['s'] = $query;
		} else {
			$args['orderby'] = 'modified';
		}

		$tested = array();

		foreach ( Free_A_B_Testing_Tests::all() as $test ) {
			if ( 'ended' !== $test['status'] ) {
				$tested[ $test['post_id'] ] = true;
			}
		}

		$out = array();

		foreach ( get_posts( $args ) as $post ) {
			if ( ! Free_A_B_Testing_Tests::is_testable( $post ) ) {
				continue;
			}

			$type  = get_post_type_object( $post->post_type );
			$out[] = array(
				'id'     => $post->ID,
				'title'  => html_entity_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES, 'UTF-8' ),
				'type'   => $type ? $type->labels->singular_name : $post->post_type,
				'path'   => wp_make_link_relative( (string) get_permalink( $post ) ),
				'url'    => (string) get_permalink( $post ),
				'tested' => isset( $tested[ $post->ID ] ),
			);
		}

		wp_send_json_success( $out );
	}

	// Elsewhere in the admin -------------------------------------------------------------

	/**
	 * "A/B test" link on the Posts and Pages screens.
	 *
	 * @param array   $actions Row actions.
	 * @param WP_Post $post    Post.
	 * @return array
	 */
	public function row_action( $actions, $post ) {
		if ( ! is_array( $actions ) || ! current_user_can( Free_A_B_Testing_Tests::CAP ) || ! Free_A_B_Testing_Tests::is_testable( $post ) ) {
			return $actions;
		}

		if ( null === $this->tests_by_post ) {
			$this->tests_by_post = array();

			foreach ( array_reverse( Free_A_B_Testing_Tests::all() ) as $test ) {
				if ( 'ended' !== $test['status'] ) {
					$this->tests_by_post[ $test['post_id'] ] = $test;
				}
			}
		}

		if ( isset( $this->tests_by_post[ $post->ID ] ) ) {
			$test   = $this->tests_by_post[ $post->ID ];
			$labels = self::status_labels();

			$actions['fabt'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( self::test_url( $test['id'] ) ),
				/* translators: %s: test status. */
				esc_html( sprintf( __( 'A/B test (%s)', 'free-a-b-testing' ), strtolower( $labels[ $test['status'] ] ) ) )
			);
		} else {
			$actions['fabt'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url(
					self::page_url(
						array(
							'view'    => 'new',
							'post_id' => $post->ID,
						)
					)
				),
				esc_html__( 'A/B test', 'free-a-b-testing' )
			);
		}

		return $actions;
	}

	/**
	 * What the editor should say: on a variant, which test it's in; on a
	 * page with a running test, that edits change the original only.
	 *
	 * @return array{text:string,url:string,label:string}|null
	 */
	private static function editor_message() {
		$post = get_post();

		if ( ! $post || ! current_user_can( Free_A_B_Testing_Tests::CAP ) ) {
			return null;
		}

		if ( Free_A_B_Testing_Tests::VARIANT_TYPE === $post->post_type ) {
			$test = Free_A_B_Testing_Tests::get( $post->post_parent );
			$slot = (int) get_post_meta( $post->ID, '_fabt_slot', true );

			if ( ! $test || ! isset( $test['variants'][ $slot ] ) ) {
				return null;
			}

			return array(
				'text'  => sprintf(
					/* translators: 1: "Variant B", 2: test name. */
					__( 'You\'re editing %1$s of the A/B test "%2$s". Visitors in this variant see this title and content instead of the original page\'s.', 'free-a-b-testing' ),
					Free_A_B_Testing_Tests::label( $test, $slot ),
					$test['name']
				),
				'url'   => self::test_url( $test['id'] ),
				'label' => __( 'Back to the test', 'free-a-b-testing' ),
			);
		}

		$test = Free_A_B_Testing_Tests::live_for( $post->ID );

		if ( ! $test ) {
			return null;
		}

		return array(
			/* translators: %s: test name. */
			'text'  => sprintf( __( 'This page is in the running A/B test "%s". Edits here change the original (version A) only.', 'free-a-b-testing' ), $test['name'] ),
			'url'   => self::test_url( $test['id'] ),
			'label' => __( 'View the test', 'free-a-b-testing' ),
		);
	}

	/**
	 * Notice in the block editor.
	 *
	 * @return void
	 */
	public function editor_notice() {
		$message = self::editor_message();

		if ( ! $message ) {
			return;
		}

		wp_register_script( 'free-a-b-testing-editor', false, array( 'wp-data', 'wp-notices', 'wp-dom-ready' ), FREE_A_B_TESTING_VERSION, true );
		wp_enqueue_script( 'free-a-b-testing-editor' );
		wp_add_inline_script(
			'free-a-b-testing-editor',
			'wp.domReady(function(){var m=' . wp_json_encode( $message, JSON_HEX_TAG | JSON_HEX_AMP ) . ';wp.data.dispatch("core/notices").createNotice("info",m.text,{id:"fabt-editor",isDismissible:true,actions:[{label:m.label,url:m.url}]});});'
		);
	}

	/**
	 * Same notice in the classic editor.
	 *
	 * @return void
	 */
	public function classic_editor_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || 'post' !== $screen->base || ( method_exists( $screen, 'is_block_editor' ) && $screen->is_block_editor() ) ) {
			return;
		}

		$message = self::editor_message();

		if ( $message ) {
			printf(
				'<div class="notice notice-info"><p>%s <a href="%s">%s</a></p></div>',
				esc_html( $message['text'] ),
				esc_url( $message['url'] ),
				esc_html( $message['label'] )
			);
		}
	}

	/**
	 * Variants are listed and created from the test screen only.
	 *
	 * @return void
	 */
	public function redirect_variant_screens() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Redirect only.
		if ( isset( $_GET['post_type'] ) && Free_A_B_Testing_Tests::VARIANT_TYPE === $_GET['post_type'] ) {
			self::redirect( self::page_url() );
		}
	}

	// Helpers ---------------------------------------------------------------------------

	/**
	 * Keep a message for the current user's next page load.
	 *
	 * @param string $type    success or error.
	 * @param string $message Plain text.
	 * @return void
	 */
	private static function flash( $type, $message ) {
		set_transient(
			'fabt_notice_' . get_current_user_id(),
			array(
				'type'    => 'error' === $type ? 'error' : 'success',
				'message' => (string) $message,
			),
			MINUTE_IN_SECONDS * 5
		);
	}

	/**
	 * Show (and forget) the kept message.
	 *
	 * @return void
	 */
	private static function print_flash() {
		$key    = 'fabt_notice_' . get_current_user_id();
		$notice = get_transient( $key );

		if ( ! is_array( $notice ) || empty( $notice['message'] ) ) {
			return;
		}

		delete_transient( $key );

		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			'error' === $notice['type'] ? 'error' : 'success',
			esc_html( $notice['message'] )
		);
	}

	/**
	 * Redirect within the admin and stop.
	 *
	 * @param string $url URL.
	 * @return void
	 */
	private static function redirect( $url ) {
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * A share as a percentage.
	 *
	 * @param float $value    0 to 1.
	 * @param int   $decimals Decimals.
	 * @return string
	 */
	private static function percent( $value, $decimals = 1 ) {
		return number_format_i18n( 100 * (float) $value, $decimals ) . '%';
	}

	/**
	 * An amount in the store's currency.
	 *
	 * @param float $amount Amount.
	 * @return string
	 */
	private static function money( $amount ) {
		if ( function_exists( 'wc_price' ) ) {
			return html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ), ENT_QUOTES, 'UTF-8' );
		}

		return number_format_i18n( $amount, 2 );
	}
}
