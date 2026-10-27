<?php
/**
 * Builds the page every visitor gets: the original's content with each part
 * a variant changes replaced by all its versions, ready for CSS to pick one.
 *
 * Why every version is in the page: page caches hand the same HTML to every
 * visitor, so the server can't choose. Instead each version's root element
 * gets classes (`fabt-x fabt-12 fabt-12-1`), and one CSS rule per variant,
 * switched on by a class the inline head script adds to <html> before the
 * first paint, hides all but the visitor's version. No flicker, no extra
 * request, and visitors without JavaScript see the original.
 *
 * Only what differs is repeated. The original and the variants are compared
 * block by block (recursing into groups, columns and other containers when
 * only their insides changed); unchanged blocks are sent once. Classic
 * (non-block) content is switched as a whole.
 *
 * @package Free_A_B_Testing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Content and headline merging.
 */
final class Free_A_B_Testing_Merge {

	/**
	 * Server-side block that stands in for each changed part.
	 */
	const BLOCK = 'free-a-b-testing/switch';

	/**
	 * Tags that can't carry the classes (wrapped instead).
	 */
	const NO_CLASS_TAGS = array( 'script', 'style', 'link', 'meta', 'template', 'noscript', 'br', 'wbr', 'source', 'track' );

	/**
	 * Versions of each changed part built during this request: key =>
	 * array( 'test' => ID, 'groups' => list of array( 'slots' => int[], 'blocks' => array[] ) or
	 * array( 'slots' => int[], 'content' => string ) ).
	 *
	 * @var array<string,array>
	 */
	private static $switches = array();

	/**
	 * Per-request key prefix.
	 *
	 * @var string
	 */
	private static $prefix = '';

	/**
	 * Register the switch block. Server-only: it never appears in the
	 * inserter, and in saved content it renders nothing (its versions only
	 * exist while this plugin builds a page).
	 *
	 * @return void
	 */
	public static function register_block() {
		if ( WP_Block_Type_Registry::get_instance()->is_registered( self::BLOCK ) ) {
			return;
		}

		register_block_type(
			self::BLOCK,
			array(
				'render_callback' => array( __CLASS__, 'render' ),
				'attributes'      => array(
					'k' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
				'supports'        => array(
					'inserter' => false,
					'html'     => false,
				),
			)
		);
	}

	/**
	 * Merge the contents of every slot.
	 *
	 * @param int               $test_id  Test ID.
	 * @param array<int,string> $contents Slot => raw post content. Slot 0 is the original.
	 * @return array{content:string,changes:int} Content to render, and how many parts differ.
	 */
	public static function build( $test_id, array $contents ) {
		ksort( $contents );

		$changes = 0;
		$blocks  = true;

		foreach ( $contents as $content ) {
			$blocks = $blocks && has_blocks( $content );
		}

		if ( count( array_unique( $contents ) ) === 1 ) {
			return array(
				'content' => (string) reset( $contents ),
				'changes' => 0,
			);
		}

		if ( ! $blocks ) {
			$alternatives = array();

			foreach ( $contents as $slot => $content ) {
				$alternatives[ $slot ] = array( 'content' => $content );
			}

			$switch = self::add_switch( $test_id, $alternatives, $changes );

			return array(
				'content' => serialize_block( $switch ),
				'changes' => $changes,
			);
		}

		$lists = array();

		foreach ( $contents as $slot => $content ) {
			$lists[ $slot ] = self::without_blank( parse_blocks( $content ) );
		}

		$merged = self::merge_top( $test_id, $lists, $changes );

		return array(
			'content' => $changes ? serialize_blocks( $merged ) : (string) $contents[0],
			'changes' => $changes,
		);
	}

	/**
	 * The page title with every slot's headline in it, or the original
	 * alone when no variant changes it.
	 *
	 * @param int               $test_id Test ID.
	 * @param string            $title   The original title, already filtered.
	 * @param array<int,string> $titles  Slot => raw title. Slot 0 is the original.
	 * @return string
	 */
	public static function title( $test_id, $title, array $titles ) {
		ksort( $titles );

		$groups = array();

		foreach ( $titles as $slot => $raw ) {
			$key = md5( (string) $raw );

			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'slots' => array(),
					'html'  => 0 === $slot ? $title : wptexturize( convert_chars( trim( (string) $raw ) ) ),
				);
			}

			$groups[ $key ]['slots'][] = (int) $slot;
		}

		if ( count( $groups ) < 2 || self::plain_context() ) {
			return $title;
		}

		$html = '';

		foreach ( $groups as $group ) {
			$html .= '<span class="' . esc_attr( self::classes( $test_id, $group['slots'] ) ) . '">' . $group['html'] . '</span>';
		}

		return $html;
	}

	/**
	 * Render a switch: each version, marked with the slots it belongs to.
	 *
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	public static function render( $attributes ) {
		$key = isset( $attributes['k'] ) && is_string( $attributes['k'] ) ? $attributes['k'] : '';

		if ( '' === $key || ! isset( self::$switches[ $key ] ) ) {
			return '';
		}

		$switch = self::$switches[ $key ];
		$plain  = self::plain_context();
		$html   = '';

		foreach ( $switch['groups'] as $group ) {
			if ( $plain && ! in_array( 0, $group['slots'], true ) ) {
				continue;
			}

			$out = '';

			if ( isset( $group['content'] ) ) {
				$out = has_blocks( $group['content'] ) ? do_blocks( $group['content'] ) : wpautop( $group['content'] );
			} else {
				foreach ( $group['blocks'] as $block ) {
					$out .= render_block( $block );
				}
			}

			$html .= $plain ? $out : self::mark( $out, $switch['test'], $group['slots'] );
		}

		return $html;
	}

	/**
	 * Whether the page is being read for something other than display (an
	 * excerpt, a meta description, a feed): those get the original only.
	 *
	 * @return bool
	 */
	public static function plain_context() {
		return doing_filter( 'get_the_excerpt' )
			|| doing_filter( 'wp_trim_excerpt' )
			|| doing_action( 'wp_head' )
			|| is_feed();
	}

	/**
	 * Classes for one version.
	 *
	 * @param int   $test_id Test ID.
	 * @param int[] $slots   Slots it's shown for.
	 * @return string
	 */
	public static function classes( $test_id, array $slots ) {
		$classes = 'fabt-x fabt-' . (int) $test_id;

		foreach ( $slots as $slot ) {
			$classes .= ' fabt-' . (int) $test_id . '-' . (int) $slot;
		}

		return $classes;
	}

	// Merging ---------------------------------------------------------------

	/**
	 * Merge the top-level block lists. Blocks every slot shares at the start
	 * and end are kept once; the part in between is merged block by block
	 * when every slot has the same number of blocks there, or switched as a
	 * whole when a variant added or removed blocks.
	 *
	 * @param int              $test_id Test ID.
	 * @param array<int,array> $lists   Slot => parsed blocks.
	 * @param int              $changes Count of changed parts (incremented).
	 * @return array Parsed blocks.
	 */
	private static function merge_top( $test_id, array $lists, &$changes ) {
		$control  = $lists[0];
		$shortest = min( array_map( 'count', $lists ) );
		$prefix   = 0;
		$suffix   = 0;

		while ( $prefix < $shortest && self::all_same( self::column( $lists, $prefix ) ) ) {
			++$prefix;
		}

		while ( $suffix < $shortest - $prefix && self::all_same( self::column( $lists, -1 - $suffix ) ) ) {
			++$suffix;
		}

		$middles = array();
		$lengths = array();

		foreach ( $lists as $slot => $list ) {
			$middles[ $slot ] = array_slice( $list, $prefix, count( $list ) - $prefix - $suffix );
			$lengths[]        = count( $middles[ $slot ] );
		}

		$out = array_slice( $control, 0, $prefix );

		if ( max( $lengths ) > 0 ) {
			if ( count( array_unique( $lengths ) ) === 1 ) {
				$out = array_merge( $out, self::merge_list( $test_id, $middles, $changes ) );
			} else {
				$out[] = self::add_switch( $test_id, self::as_alternatives( $middles ), $changes );
			}
		}

		return array_merge( $out, $suffix ? array_slice( $control, -$suffix ) : array() );
	}

	/**
	 * Merge lists of the same length, position by position.
	 *
	 * @param int              $test_id Test ID.
	 * @param array<int,array> $lists   Slot => parsed blocks.
	 * @param int              $changes Count of changed parts.
	 * @return array
	 */
	private static function merge_list( $test_id, array $lists, &$changes ) {
		$out = array();

		for ( $i = 0, $n = count( $lists[0] ); $i < $n; $i++ ) {
			$out[] = self::merge_block( $test_id, self::column( $lists, $i ), $changes );
		}

		return $out;
	}

	/**
	 * Merge one block across slots: unchanged, merged inside (same block
	 * with only its inner blocks changed), or switched.
	 *
	 * @param int              $test_id Test ID.
	 * @param array<int,array> $column  Slot => parsed block.
	 * @param int              $changes Count of changed parts.
	 * @return array Parsed block.
	 */
	private static function merge_block( $test_id, array $column, &$changes ) {
		$first = $column[0];

		if ( self::all_same( $column ) ) {
			return $first;
		}

		if ( self::same_shell( $column ) ) {
			$children = array();

			foreach ( $column as $slot => $block ) {
				$children[ $slot ] = $block['innerBlocks'];
			}

			$first['innerBlocks'] = self::merge_list( $test_id, $children, $changes );

			return $first;
		}

		$alternatives = array();

		foreach ( $column as $slot => $block ) {
			$alternatives[ $slot ] = array( $block );
		}

		return self::add_switch( $test_id, self::as_alternatives( $alternatives ), $changes );
	}

	/**
	 * Store a changed part's versions, grouping slots that share a version,
	 * and return the block that renders them.
	 *
	 * @param int              $test_id      Test ID.
	 * @param array<int,array> $alternatives Slot => array( 'blocks' => array ) or array( 'content' => string ).
	 * @param int              $changes      Count of changed parts.
	 * @return array Parsed switch block.
	 */
	private static function add_switch( $test_id, array $alternatives, &$changes ) {
		if ( '' === self::$prefix ) {
			self::$prefix = 's' . wp_rand( 100000, 999999 ) . '-';
		}

		$groups = array();

		foreach ( $alternatives as $slot => $alternative ) {
			$hash = md5( isset( $alternative['content'] ) ? 'c' . $alternative['content'] : 'b' . serialize_blocks( $alternative['blocks'] ) );

			if ( ! isset( $groups[ $hash ] ) ) {
				$groups[ $hash ] = $alternative + array( 'slots' => array() );
			}

			$groups[ $hash ]['slots'][] = (int) $slot;
		}

		++$changes;

		$key = self::$prefix . count( self::$switches );

		self::$switches[ $key ] = array(
			'test'   => (int) $test_id,
			'groups' => array_values( $groups ),
		);

		return array(
			'blockName'    => self::BLOCK,
			'attrs'        => array( 'k' => $key ),
			'innerBlocks'  => array(),
			'innerHTML'    => '',
			'innerContent' => array(),
		);
	}

	/**
	 * Wrap slot => blocks lists for {@see add_switch()}.
	 *
	 * @param array<int,array> $lists Slot => parsed blocks.
	 * @return array<int,array>
	 */
	private static function as_alternatives( array $lists ) {
		$out = array();

		foreach ( $lists as $slot => $blocks ) {
			$out[ $slot ] = array( 'blocks' => $blocks );
		}

		return $out;
	}

	/**
	 * The block at one position in every slot's list (negative = from the end).
	 *
	 * @param array<int,array> $lists Slot => parsed blocks.
	 * @param int              $index Position.
	 * @return array<int,array>
	 */
	private static function column( array $lists, $index ) {
		$column = array();

		foreach ( $lists as $slot => $list ) {
			$column[ $slot ] = $index < 0 ? $list[ count( $list ) + $index ] : $list[ $index ];
		}

		return $column;
	}

	/**
	 * Whether every slot has exactly the same block.
	 *
	 * @param array<int,array> $column Slot => parsed block.
	 * @return bool
	 */
	private static function all_same( array $column ) {
		$first = serialize_block( reset( $column ) );

		foreach ( $column as $block ) {
			if ( serialize_block( $block ) !== $first ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether every slot has the same container block (name, settings and
	 * markup around the inner blocks) with the same number of inner blocks,
	 * so only the inner blocks need merging.
	 *
	 * @param array<int,array> $column Slot => parsed block.
	 * @return bool
	 */
	private static function same_shell( array $column ) {
		$first = reset( $column );
		$shell = self::shell( $first );

		if ( empty( $first['innerBlocks'] ) ) {
			return false;
		}

		foreach ( $column as $block ) {
			if ( self::shell( $block ) !== $shell ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * A block without its inner blocks, as a comparable string.
	 *
	 * @param array $block Parsed block.
	 * @return string
	 */
	private static function shell( array $block ) {
		// Only compared, never unserialized.
		return serialize( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
			array(
				isset( $block['blockName'] ) ? $block['blockName'] : null,
				isset( $block['attrs'] ) ? $block['attrs'] : array(),
				isset( $block['innerContent'] ) ? $block['innerContent'] : array(),
				isset( $block['innerBlocks'] ) ? count( $block['innerBlocks'] ) : 0,
			)
		);
	}

	/**
	 * Drop the whitespace-only "blocks" the parser makes between top-level
	 * blocks, so spacing differences don't count as changes.
	 *
	 * @param array $blocks Parsed blocks.
	 * @return array
	 */
	private static function without_blank( array $blocks ) {
		$out = array();

		foreach ( $blocks as $block ) {
			if ( empty( $block['blockName'] ) && '' === trim( isset( $block['innerHTML'] ) ? $block['innerHTML'] : '' ) ) {
				continue;
			}

			$out[] = $block;
		}

		return $out;
	}

	// Marking ---------------------------------------------------------------

	/**
	 * Add the version's classes to its root element, or wrap it in a
	 * layout-neutral <div style="display:contents"> when it has no single
	 * root element (text, several elements, a script first).
	 *
	 * @param string $html    Rendered version.
	 * @param int    $test_id Test ID.
	 * @param int[]  $slots   Slots it's shown for.
	 * @return string
	 */
	public static function mark( $html, $test_id, array $slots ) {
		if ( '' === trim( $html ) ) {
			return '';
		}

		$classes = self::classes( $test_id, $slots );
		$root    = self::single_root( $html );

		if ( $root ) {
			$marked = self::add_classes( $html, $classes );

			if ( null !== $marked ) {
				return $marked;
			}
		}

		return '<div class="' . esc_attr( $classes ) . '" style="display:contents">' . $html . '</div>';
	}

	/**
	 * Whether the HTML is one element (with whatever inside it). Unsure
	 * means no: a wrapper is always safe, a class on the wrong element isn't.
	 *
	 * @param string $html HTML.
	 * @return bool
	 */
	public static function single_root( $html ) {
		$html = trim( $html );

		if ( ! preg_match( '/^<([a-zA-Z][a-zA-Z0-9-]*)[\s>\/]/', $html, $m ) ) {
			return false;
		}

		$tag = strtolower( $m[1] );

		if ( in_array( $tag, self::NO_CLASS_TAGS, true ) ) {
			return false;
		}

		$void = array( 'area', 'base', 'col', 'embed', 'hr', 'img', 'input', 'param' );

		if ( in_array( $tag, $void, true ) ) {
			return 1 === preg_match( '/^<[^<>]*>$/', $html );
		}

		// Track only this tag's nesting; it must close at the very end.
		if ( ! preg_match_all( '#<(/?)' . preg_quote( $tag, '#' ) . '(?=[\s>/])#i', $html, $all, PREG_OFFSET_CAPTURE | PREG_SET_ORDER ) ) {
			return false;
		}

		$depth = 0;

		foreach ( $all as $match ) {
			$depth += '/' === $match[1][0] ? -1 : 1;

			if ( 0 === $depth ) {
				$end = strpos( $html, '>', $match[0][1] );

				return false !== $end && strlen( $html ) - 1 === $end;
			}
		}

		return false;
	}

	/**
	 * Add classes to the first tag.
	 *
	 * @param string $html    HTML starting with an element.
	 * @param string $classes Classes to add.
	 * @return string|null Null if the tag couldn't be read.
	 */
	private static function add_classes( $html, $classes ) {
		if ( class_exists( 'WP_HTML_Tag_Processor' ) ) {
			$processor = new WP_HTML_Tag_Processor( $html );

			if ( ! $processor->next_tag() ) {
				return null;
			}

			foreach ( explode( ' ', $classes ) as $class ) {
				$processor->add_class( $class );
			}

			return $processor->get_updated_html();
		}

		// WordPress before 6.2: edit the first tag by hand.
		if ( ! preg_match( '/^(\s*<[a-zA-Z][a-zA-Z0-9-]*)((?:\s+[^\s"\'>\/=]+(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s"\'>]+))?)*)(\s*\/?>)/', $html, $m ) ) {
			return null;
		}

		$attributes = $m[2];
		$count      = 0;
		$attributes = preg_replace_callback(
			'/(\sclass\s*=\s*)(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))/i',
			function ( $c ) use ( $classes ) {
				$existing = isset( $c[4] ) ? $c[4] : ( isset( $c[3] ) && '' !== $c[3] ? $c[3] : $c[2] );

				return $c[1] . '"' . esc_attr( trim( $existing . ' ' . $classes ) ) . '"';
			},
			$attributes,
			1,
			$count
		);

		if ( ! $count ) {
			$attributes .= ' class="' . esc_attr( $classes ) . '"';
		}

		return $m[1] . $attributes . $m[3] . substr( $html, strlen( $m[0] ) );
	}

	/**
	 * Forget this request's switches (tests only).
	 *
	 * @return void
	 */
	public static function reset() {
		self::$switches = array();
	}
}
