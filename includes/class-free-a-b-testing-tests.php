<?php
/**
 * Tests and variants: storage, lifecycle and cache purging.
 *
 * A test is a private `fabt_test` post that points at the post or page
 * being tested. Each variant is a private `fabt_variant` post holding a full
 * copy of that page's title and content, edited in the normal WordPress
 * editor. The original page is always slot 0 ("A"); variants take slots
 * 1, 2, 3... and keep their slot for life, so results never get mixed up
 * when a variant is removed.
 *
 * Both post types are locked to admins (manage_options), including through
 * the REST API, which the block editor needs: WordPress would otherwise let
 * anyone read published posts of any type shown in the REST API.
 *
 * @package Free_A_B_Testing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Test storage and lifecycle.
 */
final class Free_A_B_Testing_Tests {

	/**
	 * Test post type.
	 */
	const TEST_TYPE = 'fabt_test';

	/**
	 * Variant post type.
	 */
	const VARIANT_TYPE = 'fabt_variant';

	/**
	 * Option mapping each page with a running test to that test:
	 * post ID => test ID. Autoloaded, read on every page view.
	 */
	const LIVE_OPTION = 'free_a_b_testing_live';

	/**
	 * Capability needed to manage tests and edit variants.
	 */
	const CAP = 'manage_options';

	/**
	 * Most variants a test can have, besides the original.
	 */
	const MAX_VARIANTS = 5;

	/**
	 * Test statuses.
	 */
	const STATUSES = array( 'draft', 'running', 'paused', 'ended' );

	/**
	 * Goal types.
	 */
	const GOALS = array( 'click', 'form', 'tel', 'woo' );

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'init', array( __CLASS__, 'register_types' ) );
		add_action( 'save_post_' . self::VARIANT_TYPE, array( __CLASS__, 'variant_saved' ), 10, 2 );
		add_action( 'wp_trash_post', array( __CLASS__, 'page_removed' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'page_removed' ) );
	}

	/**
	 * Register the two private post types.
	 *
	 * @return void
	 */
	public static function register_types() {
		// Every capability, primitive or mapped, comes down to manage_options.
		$caps = array(
			'edit_post'              => 'edit_' . self::VARIANT_TYPE,
			'read_post'              => 'read_' . self::VARIANT_TYPE,
			'delete_post'            => 'delete_' . self::VARIANT_TYPE,
			'edit_posts'             => self::CAP,
			'edit_others_posts'      => self::CAP,
			'edit_private_posts'     => self::CAP,
			'edit_published_posts'   => self::CAP,
			'publish_posts'          => self::CAP,
			'read_private_posts'     => self::CAP,
			'read'                   => self::CAP,
			'delete_posts'           => self::CAP,
			'delete_private_posts'   => self::CAP,
			'delete_published_posts' => self::CAP,
			'delete_others_posts'    => self::CAP,
			'create_posts'           => self::CAP,
		);

		register_post_type(
			self::TEST_TYPE,
			array(
				'labels'           => array(
					'name'          => __( 'A/B tests', 'free-a-b-testing' ),
					'singular_name' => __( 'A/B test', 'free-a-b-testing' ),
				),
				'public'           => false,
				'show_ui'          => false,
				'show_in_rest'     => false,
				'rewrite'          => false,
				'query_var'        => false,
				'can_export'       => false,
				'supports'         => array( 'title' ),
				'capabilities'     => $caps,
				'map_meta_cap'     => true,
				'delete_with_user' => false,
			)
		);

		require_once FREE_A_B_TESTING_DIR . 'includes/class-free-a-b-testing-variants-controller.php';

		register_post_type(
			self::VARIANT_TYPE,
			array(
				'labels'                => array(
					'name'          => __( 'A/B test variants', 'free-a-b-testing' ),
					'singular_name' => __( 'A/B test variant', 'free-a-b-testing' ),
					'edit_item'     => __( 'Edit variant', 'free-a-b-testing' ),
				),
				'public'                => false,
				'publicly_queryable'    => false,
				'exclude_from_search'   => true,
				'show_ui'               => true,
				'show_in_menu'          => false,
				'show_in_nav_menus'     => false,
				'show_in_admin_bar'     => false,
				// The block editor saves through the REST API. The controller
				// below only answers people who can edit the variant.
				'show_in_rest'          => true,
				'rest_base'             => 'fabt-variants',
				'rest_controller_class' => 'Free_A_B_Testing_Variants_Controller',
				'rewrite'               => false,
				'query_var'             => false,
				'has_archive'           => false,
				'can_export'            => false,
				'hierarchical'          => false,
				'supports'              => array( 'title', 'editor', 'revisions' ),
				'capabilities'          => $caps,
				'map_meta_cap'          => true,
				'delete_with_user'      => false,
			)
		);

		add_filter( 'map_meta_cap', array( __CLASS__, 'map_meta_cap' ), 10, 4 );
	}

	/**
	 * Editing, reading or deleting one test or variant takes manage_options,
	 * whoever wrote it and whatever its status.
	 *
	 * @param string[] $caps    Required primitive capabilities.
	 * @param string   $cap     Capability being checked.
	 * @param int      $user_id User ID.
	 * @param array    $args    Extra arguments (the post ID first).
	 * @return string[]
	 */
	public static function map_meta_cap( $caps, $cap, $user_id, $args ) {
		$own = array(
			'edit_' . self::VARIANT_TYPE,
			'read_' . self::VARIANT_TYPE,
			'delete_' . self::VARIANT_TYPE,
		);

		if ( in_array( $cap, $own, true ) ) {
			return array( self::CAP );
		}

		if ( in_array( $cap, array( 'edit_post', 'read_post', 'delete_post' ), true ) && ! empty( $args[0] ) ) {
			$type = get_post_type( (int) $args[0] );

			if ( self::VARIANT_TYPE === $type || self::TEST_TYPE === $type ) {
				return array( self::CAP );
			}
		}

		return $caps;
	}

	// Reading ---------------------------------------------------------------

	/**
	 * Pages with a running test: post ID => test ID.
	 *
	 * @return array<int,int>
	 */
	public static function live() {
		$live = get_option( self::LIVE_OPTION, array() );
		$out  = array();

		if ( is_array( $live ) ) {
			foreach ( $live as $post_id => $test_id ) {
				$out[ (int) $post_id ] = (int) $test_id;
			}
		}

		return $out;
	}

	/**
	 * The running test on a page, if any.
	 *
	 * @param int $post_id Post ID.
	 * @return array|null Test, see {@see get()}.
	 */
	public static function live_for( $post_id ) {
		$live = self::live();

		if ( empty( $live[ $post_id ] ) ) {
			return null;
		}

		$test = self::get( $live[ $post_id ] );

		return $test && 'running' === $test['status'] && (int) $post_id === $test['post_id'] ? $test : null;
	}

	/**
	 * One test, with its variants.
	 *
	 * @param int $test_id Test ID.
	 * @return array|null {
	 *     @type int    $id       Test ID.
	 *     @type string $name     Name.
	 *     @type int    $post_id  Page being tested.
	 *     @type string $status   draft, running, paused or ended.
	 *     @type array  $goal     type, selector, tel.
	 *     @type array  $variants Slot => array( post, name ). Slot 0 is the original page.
	 *     @type int    $run      Bumped when results are reset, so returning visitors are counted again.
	 *     @type int    $started  When it last started (Unix time), or 0.
	 *     @type int    $ended    When it ended, or 0.
	 *     @type int    $applied  Slot that was applied to the page, or -1.
	 * }
	 */
	public static function get( $test_id ) {
		$post = get_post( (int) $test_id );

		if ( ! $post || self::TEST_TYPE !== $post->post_type || 'trash' === $post->post_status ) {
			return null;
		}

		$status   = (string) get_post_meta( $post->ID, '_fabt_status', true );
		$variants = get_post_meta( $post->ID, '_fabt_variants', true );
		$applied  = get_post_meta( $post->ID, '_fabt_applied', true );

		return array(
			'id'       => (int) $post->ID,
			'name'     => $post->post_title,
			'post_id'  => (int) get_post_meta( $post->ID, '_fabt_post', true ),
			'status'   => in_array( $status, self::STATUSES, true ) ? $status : 'draft',
			'goal'     => self::clean_goal( get_post_meta( $post->ID, '_fabt_goal', true ) ),
			'variants' => self::clean_variants( $variants, (int) get_post_meta( $post->ID, '_fabt_post', true ) ),
			'run'      => max( 1, (int) get_post_meta( $post->ID, '_fabt_run', true ) ),
			'started'  => (int) get_post_meta( $post->ID, '_fabt_started', true ),
			'ended'    => (int) get_post_meta( $post->ID, '_fabt_ended', true ),
			'applied'  => '' === $applied ? -1 : (int) $applied,
			'created'  => (int) get_post_time( 'U', true, $post ),
		);
	}

	/**
	 * Every test, newest first.
	 *
	 * @param int $post_id Only tests of this page (0 = all).
	 * @return array[]
	 */
	public static function all( $post_id = 0 ) {
		$args = array(
			'post_type'              => self::TEST_TYPE,
			'post_status'            => 'any',
			'posts_per_page'         => 500,
			'orderby'                => 'date',
			'order'                  => 'DESC',
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
		);

		if ( $post_id ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Admin only, few rows.
			$args['meta_query'] = array(
				array(
					'key'   => '_fabt_post',
					'value' => (int) $post_id,
					'type'  => 'NUMERIC',
				),
			);
		}

		$tests = array();

		foreach ( get_posts( $args ) as $id ) {
			$test = self::get( $id );

			if ( $test ) {
				$tests[] = $test;
			}
		}

		return $tests;
	}

	/**
	 * Letter for a slot: A for the original, then B, C...
	 *
	 * @param int $slot Slot.
	 * @return string
	 */
	public static function letter( $slot ) {
		$slot = (int) $slot;

		return $slot < 26 ? chr( 65 + $slot ) : (string) ( $slot + 1 );
	}

	/**
	 * How a slot is named in sentences: "the original", "Variant B", or
	 * "Variant B (Long headline)" once it has its own name.
	 *
	 * @param array $test Test.
	 * @param int   $slot Slot.
	 * @return string
	 */
	public static function label( array $test, $slot ) {
		if ( 0 === (int) $slot ) {
			return __( 'the original', 'free-a-b-testing' );
		}

		/* translators: %s: variant letter (B, C...). */
		$plain = sprintf( __( 'Variant %s', 'free-a-b-testing' ), self::letter( $slot ) );
		$name  = isset( $test['variants'][ $slot ]['name'] ) ? $test['variants'][ $slot ]['name'] : '';

		return '' === $name || $name === $plain ? $plain : $plain . ' (' . $name . ')';
	}

	/**
	 * Title and content of one slot: the page itself for slot 0, else its
	 * variant post.
	 *
	 * @param array $test Test.
	 * @param int   $slot Slot.
	 * @return WP_Post|null
	 */
	public static function slot_post( array $test, $slot ) {
		if ( ! isset( $test['variants'][ $slot ] ) ) {
			return null;
		}

		$post = get_post( $test['variants'][ $slot ]['post'] );

		if ( ! $post ) {
			return null;
		}

		if ( $slot > 0 && ( self::VARIANT_TYPE !== $post->post_type || (int) $post->post_parent !== $test['id'] || 'trash' === $post->post_status ) ) {
			return null;
		}

		return $post;
	}

	/**
	 * Slots that can be shown: the original plus every variant whose post
	 * still exists.
	 *
	 * @param array $test Test.
	 * @return int[]
	 */
	public static function active_slots( array $test ) {
		$slots = array();

		foreach ( array_keys( $test['variants'] ) as $slot ) {
			if ( self::slot_post( $test, $slot ) ) {
				$slots[] = (int) $slot;
			}
		}

		return $slots;
	}

	// Cleaning --------------------------------------------------------------

	/**
	 * A goal, with anything unknown dropped.
	 *
	 * @param mixed $goal Saved or submitted goal.
	 * @return array{type:string,selector:string,tel:string}
	 */
	public static function clean_goal( $goal ) {
		$goal = is_array( $goal ) ? $goal : array();
		$type = isset( $goal['type'] ) && in_array( $goal['type'], self::GOALS, true ) ? $goal['type'] : 'click';
		$tel  = isset( $goal['tel'] ) && in_array( $goal['tel'], array( 'both', 'tel', 'sms' ), true ) ? $goal['tel'] : 'both';

		return array(
			'type'     => $type,
			'selector' => isset( $goal['selector'] ) && is_string( $goal['selector'] ) ? self::clean_selector( $goal['selector'] ) : '',
			'tel'      => $tel,
		);
	}

	/**
	 * A CSS selector from the settings or the element picker. The browser
	 * is the real judge (an invalid one is caught and ignored there); this
	 * keeps it to one printable line of a sane length.
	 *
	 * @param string $selector Selector.
	 * @return string
	 */
	public static function clean_selector( $selector ) {
		$selector = trim( (string) preg_replace( '/[\x00-\x1F\x7F<]+/', ' ', (string) $selector ) );

		return strlen( $selector ) > 300 ? '' : $selector;
	}

	/**
	 * Saved variants list, with the original always in slot 0.
	 *
	 * @param mixed $variants Saved list.
	 * @param int   $post_id  Page being tested.
	 * @return array<int,array{post:int,name:string}>
	 */
	private static function clean_variants( $variants, $post_id ) {
		$out = array(
			0 => array(
				'post' => (int) $post_id,
				'name' => __( 'Original', 'free-a-b-testing' ),
			),
		);

		if ( is_array( $variants ) ) {
			foreach ( $variants as $slot => $variant ) {
				$slot = (int) $slot;

				if ( $slot > 0 && is_array( $variant ) && ! empty( $variant['post'] ) ) {
					$out[ $slot ] = array(
						'post' => (int) $variant['post'],
						'name' => isset( $variant['name'] ) && is_string( $variant['name'] ) && '' !== $variant['name']
							/* translators: %s: variant letter (B, C...). */
							? $variant['name'] : sprintf( __( 'Variant %s', 'free-a-b-testing' ), self::letter( $slot ) ),
					);
				}
			}
		}

		ksort( $out );

		return $out;
	}

	// Writing ---------------------------------------------------------------

	/**
	 * Create a test of a page, with one variant to start from.
	 *
	 * @param int    $post_id Page to test.
	 * @param string $name    Test name.
	 * @param array  $goal    Goal.
	 * @return int|WP_Error Test ID.
	 */
	public static function create( $post_id, $name, array $goal ) {
		$page = get_post( $post_id );

		if ( ! self::is_testable( $page ) ) {
			return new WP_Error( 'fabt_page', __( 'Pick a published post or page to test.', 'free-a-b-testing' ) );
		}

		$name = '' !== trim( $name ) ? $name : sprintf(
			/* translators: %s: page title. */
			__( 'Test of %s', 'free-a-b-testing' ),
			wp_strip_all_tags( get_the_title( $page ) )
		);

		$test_id = wp_insert_post(
			wp_slash(
				array(
					'post_type'   => self::TEST_TYPE,
					'post_status' => 'publish',
					'post_title'  => sanitize_text_field( $name ),
				)
			),
			true
		);

		if ( is_wp_error( $test_id ) ) {
			return $test_id;
		}

		update_post_meta( $test_id, '_fabt_post', (int) $page->ID );
		update_post_meta( $test_id, '_fabt_status', 'draft' );
		update_post_meta( $test_id, '_fabt_goal', self::clean_goal( $goal ) );
		update_post_meta( $test_id, '_fabt_variants', array() );
		update_post_meta( $test_id, '_fabt_next_slot', 1 );
		update_post_meta( $test_id, '_fabt_run', 1 );

		self::add_variant( $test_id );

		return (int) $test_id;
	}

	/**
	 * Whether a post can be tested: published, of a public type, not a
	 * password-protected page and not one of ours.
	 *
	 * @param WP_Post|null $post Post.
	 * @return bool
	 */
	public static function is_testable( $post ) {
		return $post instanceof WP_Post
			&& 'publish' === $post->post_status
			&& '' === $post->post_password
			&& 'attachment' !== $post->post_type
			&& is_post_type_viewable( $post->post_type )
			&& ! in_array( $post->post_type, array( self::TEST_TYPE, self::VARIANT_TYPE ), true );
	}

	/**
	 * Add a variant: a copy of the page's current title and content.
	 *
	 * @param int $test_id Test ID.
	 * @return int|WP_Error New slot.
	 */
	public static function add_variant( $test_id ) {
		$test = self::get( $test_id );
		$page = $test ? get_post( $test['post_id'] ) : null;

		if ( ! $test || ! $page ) {
			return new WP_Error( 'fabt_test', __( 'That test no longer exists.', 'free-a-b-testing' ) );
		}

		if ( count( $test['variants'] ) - 1 >= self::MAX_VARIANTS ) {
			/* translators: %d: number of variants. */
			return new WP_Error( 'fabt_max', sprintf( __( 'A test can have up to %d variants.', 'free-a-b-testing' ), self::MAX_VARIANTS ) );
		}

		$slot = max( 1, (int) get_post_meta( $test_id, '_fabt_next_slot', true ) );

		// The copy is the page's existing content, so save it exactly as it
		// is. Kses would otherwise strip parts of it for admins without
		// unfiltered_html (multisite), and every stripped block would then
		// show up as a "change".
		$variant_id = self::without_kses(
			function () use ( $page, $test_id, $slot ) {
				return wp_insert_post(
					wp_slash(
						array(
							'post_type'    => self::VARIANT_TYPE,
							'post_status'  => 'publish',
							'post_parent'  => (int) $test_id,
							'post_title'   => $page->post_title,
							'post_content' => $page->post_content,
							'post_author'  => get_current_user_id(),
							'meta_input'   => array( '_fabt_slot' => $slot ),
						)
					),
					true
				);
			}
		);

		if ( is_wp_error( $variant_id ) ) {
			return $variant_id;
		}

		$variants = get_post_meta( $test_id, '_fabt_variants', true );
		$variants = is_array( $variants ) ? $variants : array();

		$variants[ $slot ] = array(
			'post' => (int) $variant_id,
			/* translators: %s: variant letter (B, C...). */
			'name' => sprintf( __( 'Variant %s', 'free-a-b-testing' ), self::letter( $slot ) ),
		);

		update_post_meta( $test_id, '_fabt_variants', $variants );
		update_post_meta( $test_id, '_fabt_next_slot', $slot + 1 );

		return $slot;
	}

	/**
	 * Remove a variant and its results.
	 *
	 * @param int $test_id Test ID.
	 * @param int $slot    Slot (not 0).
	 * @return bool
	 */
	public static function delete_variant( $test_id, $slot ) {
		$test = self::get( $test_id );

		if ( ! $test || $slot < 1 || ! isset( $test['variants'][ $slot ] ) ) {
			return false;
		}

		$variants = get_post_meta( $test_id, '_fabt_variants', true );
		$variants = is_array( $variants ) ? $variants : array();
		$post     = self::slot_post( $test, $slot );

		unset( $variants[ $slot ] );
		update_post_meta( $test_id, '_fabt_variants', $variants );

		if ( $post ) {
			wp_delete_post( $post->ID, true );
		}

		Free_A_B_Testing_Stats::delete( $test_id, $slot );

		return true;
	}

	/**
	 * Rename a test and change its goal.
	 *
	 * @param int    $test_id Test ID.
	 * @param string $name    Name.
	 * @param array  $goal    Goal.
	 * @return void
	 */
	public static function update( $test_id, $name, array $goal ) {
		if ( '' !== trim( $name ) ) {
			wp_update_post(
				wp_slash(
					array(
						'ID'         => (int) $test_id,
						'post_title' => sanitize_text_field( $name ),
					)
				)
			);
		}

		update_post_meta( $test_id, '_fabt_goal', self::clean_goal( $goal ) );
	}

	/**
	 * Rename a variant and set its headline (the variant post's title).
	 *
	 * @param array  $test     Test.
	 * @param int    $slot     Slot (not 0).
	 * @param string $name     Name shown in the results.
	 * @param string $headline Headline, or null to leave it.
	 * @return void
	 */
	public static function update_variant( array $test, $slot, $name, $headline ) {
		$post = $slot > 0 ? self::slot_post( $test, $slot ) : null;

		if ( ! $post ) {
			return;
		}

		$variants = get_post_meta( $test['id'], '_fabt_variants', true );

		if ( is_array( $variants ) && isset( $variants[ $slot ] ) ) {
			$name = trim( sanitize_text_field( $name ) );

			$variants[ $slot ]['name'] = '' !== $name ? mb_substr( $name, 0, 60 ) : $variants[ $slot ]['name'];
			update_post_meta( $test['id'], '_fabt_variants', $variants );
		}

		if ( null !== $headline && $headline !== $post->post_title ) {
			// Normal title filters apply, as when the title is edited in the editor.
			wp_update_post(
				wp_slash(
					array(
						'ID'         => $post->ID,
						'post_title' => $headline,
					)
				)
			);
		}
	}

	/**
	 * Start, pause, resume or end a test.
	 *
	 * @param int    $test_id Test ID.
	 * @param string $status  New status.
	 * @return true|WP_Error
	 */
	public static function set_status( $test_id, $status ) {
		$test = self::get( $test_id );

		if ( ! $test || ! in_array( $status, self::STATUSES, true ) ) {
			return new WP_Error( 'fabt_test', __( 'That test no longer exists.', 'free-a-b-testing' ) );
		}

		$live = self::live();

		if ( 'running' === $status ) {
			if ( 'ended' === $test['status'] ) {
				return new WP_Error( 'fabt_ended', __( 'This test has ended. Create a new test to run it again.', 'free-a-b-testing' ) );
			}

			if ( ! self::is_testable( get_post( $test['post_id'] ) ) ) {
				return new WP_Error( 'fabt_page', __( 'The page being tested isn\'t published (or has a password), so the test can\'t run.', 'free-a-b-testing' ) );
			}

			if ( count( self::active_slots( $test ) ) < 2 ) {
				return new WP_Error( 'fabt_variants', __( 'Add a variant before starting the test.', 'free-a-b-testing' ) );
			}

			if ( ! empty( $live[ $test['post_id'] ] ) && $live[ $test['post_id'] ] !== $test['id'] && self::get( $live[ $test['post_id'] ] ) ) {
				return new WP_Error( 'fabt_busy', __( 'Another test is already running on this page. Pause or end it first.', 'free-a-b-testing' ) );
			}

			if ( 'draft' === $test['status'] ) {
				update_post_meta( $test_id, '_fabt_started', time() );
			}

			$live[ $test['post_id'] ] = $test['id'];
		} else {
			if ( isset( $live[ $test['post_id'] ] ) && $live[ $test['post_id'] ] === $test['id'] ) {
				unset( $live[ $test['post_id'] ] );
			}

			if ( 'ended' === $status ) {
				update_post_meta( $test_id, '_fabt_ended', time() );
			}
		}

		update_post_meta( $test_id, '_fabt_status', $status );
		update_option( self::LIVE_OPTION, $live, true );

		self::purge_page( $test['post_id'] );

		return true;
	}

	/**
	 * Clear a test's results and count everyone again from now.
	 *
	 * @param int $test_id Test ID.
	 * @return void
	 */
	public static function reset( $test_id ) {
		$test = self::get( $test_id );

		if ( ! $test ) {
			return;
		}

		Free_A_B_Testing_Stats::delete( $test_id );

		update_post_meta( $test_id, '_fabt_run', $test['run'] + 1 );

		if ( 'draft' !== $test['status'] ) {
			update_post_meta( $test_id, '_fabt_started', time() );
		}

		self::purge_page( $test['post_id'] );
	}

	/**
	 * Copy a variant's headline and content onto the page and end the test.
	 * The page's previous version stays in its revisions.
	 *
	 * @param int $test_id Test ID.
	 * @param int $slot    Slot to apply (0 just ends the test, keeping the page).
	 * @return true|WP_Error
	 */
	public static function apply( $test_id, $slot ) {
		$test = self::get( $test_id );

		if ( ! $test ) {
			return new WP_Error( 'fabt_test', __( 'That test no longer exists.', 'free-a-b-testing' ) );
		}

		if ( ! current_user_can( 'edit_post', $test['post_id'] ) ) {
			return new WP_Error( 'fabt_cap', __( 'You can\'t edit the page being tested.', 'free-a-b-testing' ) );
		}

		if ( $slot > 0 ) {
			$variant = self::slot_post( $test, $slot );

			if ( ! $variant ) {
				return new WP_Error( 'fabt_variant', __( 'That variant no longer exists.', 'free-a-b-testing' ) );
			}

			// Content already on the site (and kses-filtered when it was saved,
			// for anyone who needed it): copy it across untouched.
			$result = self::without_kses(
				function () use ( $test, $variant ) {
					return wp_update_post(
						wp_slash(
							array(
								'ID'           => $test['post_id'],
								'post_title'   => $variant->post_title,
								'post_content' => $variant->post_content,
							)
						),
						true
					);
				}
			);

			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		if ( 'ended' !== $test['status'] ) {
			$status = self::set_status( $test_id, 'ended' );

			if ( is_wp_error( $status ) ) {
				return $status;
			}
		}

		update_post_meta( $test_id, '_fabt_applied', (int) $slot );
		self::purge_page( $test['post_id'] );

		return true;
	}

	/**
	 * Delete a test with its variants and results.
	 *
	 * @param int $test_id Test ID.
	 * @return void
	 */
	public static function delete( $test_id ) {
		$test = self::get( $test_id );

		if ( ! $test ) {
			return;
		}

		if ( 'running' === $test['status'] ) {
			self::set_status( $test_id, 'paused' );
		}

		foreach ( $test['variants'] as $slot => $variant ) {
			$post = $slot > 0 ? self::slot_post( $test, $slot ) : null;

			if ( $post ) {
				wp_delete_post( $post->ID, true );
			}
		}

		Free_A_B_Testing_Stats::delete( $test_id );
		wp_delete_post( $test_id, true );
	}

	/**
	 * Run a save with WordPress's kses content filters switched off, then
	 * put them back exactly as they were.
	 *
	 * @param callable $save Save to run.
	 * @return mixed What $save returned.
	 */
	private static function without_kses( $save ) {
		$filtered = false !== has_filter( 'content_save_pre', 'wp_filter_post_kses' );

		if ( $filtered ) {
			kses_remove_filters();
		}

		$result = call_user_func( $save );

		if ( $filtered ) {
			kses_init_filters();
		}

		return $result;
	}

	// Hooks -----------------------------------------------------------------

	/**
	 * A variant was edited: refresh the cached page if its test is running.
	 *
	 * @param int     $post_id Variant post ID.
	 * @param WP_Post $post    Variant post.
	 * @return void
	 */
	public static function variant_saved( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || ! $post->post_parent ) {
			return;
		}

		$test = self::get( $post->post_parent );

		if ( $test && 'running' === $test['status'] ) {
			self::purge_page( $test['post_id'] );
		}
	}

	/**
	 * A tested page was trashed or deleted: pause its test, so nothing
	 * keeps counting against a page that's gone.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function page_removed( $post_id ) {
		$live = self::live();

		if ( ! empty( $live[ (int) $post_id ] ) ) {
			self::set_status( $live[ (int) $post_id ], 'paused' );
		}
	}

	/**
	 * Clear a page from WordPress's object cache and from the page caches
	 * of popular caching plugins, so visitors get the change right away.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function purge_page( $post_id ) {
		$post_id = (int) $post_id;

		if ( ! $post_id ) {
			return;
		}

		clean_post_cache( $post_id );

		if ( function_exists( 'rocket_clean_post' ) ) {
			rocket_clean_post( $post_id );
		}

		if ( function_exists( 'w3tc_flush_post' ) ) {
			w3tc_flush_post( $post_id );
		}

		if ( function_exists( 'wp_cache_post_change' ) ) {
			wp_cache_post_change( $post_id );
		}

		if ( function_exists( 'wpfc_clear_post_cache_by_id' ) ) {
			wpfc_clear_post_cache_by_id( $post_id );
		}

		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			$url = get_permalink( $post_id );

			if ( $url ) {
				sg_cachepress_purge_cache( $url );
			}
		}

		// LiteSpeed Cache and Cache Enabler listen for these.
		do_action( 'litespeed_purge_post', $post_id );
		do_action( 'cache_enabler_clear_page_cache_by_post', $post_id );

		/**
		 * A page's test changed (started, paused, ended or edited). Hook in
		 * to purge another cache or CDN.
		 *
		 * @param int $post_id Post ID.
		 */
		do_action( 'free_a_b_testing_purge_page', $post_id );
	}
}
