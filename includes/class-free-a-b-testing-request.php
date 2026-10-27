<?php
/**
 * Facts about the current request that the public endpoint relies on:
 * where it came from, the visitor's IP, and whether it's a bot. Shared with
 * the WooCommerce attribution, which reads the same cookie.
 *
 * @package Free_A_B_Testing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Request helpers.
 */
final class Free_A_B_Testing_Request {

	/**
	 * Cookie holding the visitor's random ID and their variant per test.
	 */
	const COOKIE = 'fabt';

	/**
	 * Option holding today's salt for connection hashes (autoloaded: read
	 * on every hit).
	 */
	const SALT_OPTION = 'free_a_b_testing_salt';

	/**
	 * User agents that are never counted.
	 */
	const BOTS = '/bot\b|bot\/|crawl|spider|slurp|mediapartners|headless|lighthouse|pagespeed|gtmetrix|pingdom|uptime|phantomjs|puppeteer|playwright|selenium|webdriver|python|curl\/|wget|httpclient|okhttp|axios|node-fetch|go-http|java\/|libwww|scrapy|facebookexternalhit|embedly|preview|validator|prerender|semrush|ahrefs|mj12|petalbot|bytespider|yandex|baiduspider|duckduckbot|applebot|amazonbot|gptbot|chatgpt-user|oai-searchbot|claudebot|claude-user|perplexitybot|ccbot|diffbot|dataforseo/i';

	/**
	 * A $_SERVER value as a trimmed string ('' if missing).
	 *
	 * @param string $key Key.
	 * @return string
	 */
	public static function server( $key ) {
		if ( ! isset( $_SERVER[ $key ] ) || ! is_string( $_SERVER[ $key ] ) ) {
			return '';
		}

		return trim( substr( wp_unslash( $_SERVER[ $key ] ), 0, 1000 ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only matched, hashed or validated by the callers.
	}

	/**
	 * The visitor's cookie, parsed. Anything malformed is ignored.
	 *
	 * @return array{visitor:string,tests:array<int,array{run:int,slot:int}>}|null
	 */
	public static function cookie() {
		if ( ! isset( $_COOKIE[ self::COOKIE ] ) || ! is_string( $_COOKIE[ self::COOKIE ] ) ) {
			return null;
		}

		// Matched exactly as sent: the pattern below is the only thing that lets it through.
		$raw = wp_unslash( $_COOKIE[ self::COOKIE ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated by the pattern below.

		if ( strlen( $raw ) > 600 || ! preg_match( '/^([A-Za-z0-9]{22})((?:_\d{1,10}\.\d{1,6}\.\d{1,3}[sc]{0,2}){0,20})$/D', $raw, $m ) ) {
			return null;
		}

		$tests = array();

		foreach ( array_filter( explode( '_', $m[2] ) ) as $entry ) {
			if ( preg_match( '/^(\d+)\.(\d+)\.(\d+)/', $entry, $e ) ) {
				$tests[ (int) $e[1] ] = array(
					'run'  => (int) $e[2],
					'slot' => (int) $e[3],
				);
			}
		}

		return array(
			'visitor' => $m[1],
			'tests'   => $tests,
		);
	}

	/**
	 * What's stored for a visitor ID: a keyed hash, so the database never
	 * holds the cookie value itself.
	 *
	 * @param string $visitor Visitor ID from the cookie.
	 * @return string 32 hex characters.
	 */
	public static function visitor_hash( $visitor ) {
		return substr( hash_hmac( 'sha256', 'v|' . $visitor, wp_salt( 'auth' ) ), 0, 32 );
	}

	/**
	 * A short hash of the visitor's connection, with a salt that changes
	 * daily, for rate limits.
	 *
	 * @return string 16 hex characters.
	 */
	public static function client_hash() {
		return substr( hash_hmac( 'sha256', 'c|' . self::client_ip(), self::daily_salt() ), 0, 16 );
	}

	/**
	 * Whether the request came from this site. Browsers send Sec-Fetch-Site
	 * and Origin with POSTs, so another website can't make its visitors'
	 * browsers post here. (Tools like curl can say anything; the rate limit
	 * is what holds those back.)
	 *
	 * @return bool
	 */
	public static function same_origin() {
		$fetch_site = strtolower( self::server( 'HTTP_SEC_FETCH_SITE' ) );

		if ( '' !== $fetch_site && 'same-origin' !== $fetch_site ) {
			return false;
		}

		$origin = self::server( 'HTTP_ORIGIN' );

		if ( '' === $origin ) {
			return true;
		}

		$parts = wp_parse_url( $origin );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return false;
		}

		$host    = strtolower( $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) );
		$allowed = array( strtolower( self::server( 'HTTP_HOST' ) ) );

		foreach ( array( home_url(), site_url() ) as $url ) {
			$own = wp_parse_url( $url );

			if ( is_array( $own ) && ! empty( $own['host'] ) ) {
				$allowed[] = strtolower( $own['host'] . ( isset( $own['port'] ) ? ':' . $own['port'] : '' ) );
			}
		}

		return in_array( $host, $allowed, true );
	}

	/**
	 * Whether the request is from a bot, a headless browser or a prefetch.
	 *
	 * @return bool
	 */
	public static function is_bot() {
		$agent = self::server( 'HTTP_USER_AGENT' );

		if ( strlen( $agent ) < 20 || preg_match( self::BOTS, $agent ) ) {
			return true;
		}

		$purpose = strtolower( self::server( 'HTTP_SEC_PURPOSE' ) . ' ' . self::server( 'HTTP_PURPOSE' ) . ' ' . self::server( 'HTTP_X_MOZ' ) );

		return false !== strpos( $purpose, 'prefetch' );
	}

	/**
	 * The visitor's IP. Forwarding headers are only believed when the
	 * direct connection is from a private address (a proxy on the same
	 * network), taking the rightmost public address, and Cloudflare's
	 * header only when the connection really comes from Cloudflare.
	 *
	 * @return string
	 */
	public static function client_ip() {
		$remote = self::valid_ip( self::server( 'REMOTE_ADDR' ) );
		$hop    = $remote;

		if ( '' !== $remote && ! self::is_public( $remote ) ) {
			$forwarded = array_reverse( explode( ',', self::server( 'HTTP_X_FORWARDED_FOR' ) ) );

			foreach ( $forwarded as $candidate ) {
				$candidate = self::valid_ip( $candidate );

				if ( '' !== $candidate && self::is_public( $candidate ) ) {
					$hop = $candidate;
					break;
				}
			}

			if ( $hop === $remote ) {
				$real = self::valid_ip( self::server( 'HTTP_X_REAL_IP' ) );
				$hop  = '' !== $real && self::is_public( $real ) ? $real : $hop;
			}
		}

		$cloudflare = self::valid_ip( self::server( 'HTTP_CF_CONNECTING_IP' ) );
		$ip         = '' !== $cloudflare && self::is_cloudflare( $hop ) ? $cloudflare : $hop;

		/**
		 * The visitor IP used for rate limits (only ever stored hashed).
		 * Change it for an unusual proxy setup.
		 *
		 * @param string $ip IP address.
		 */
		return (string) apply_filters( 'free_a_b_testing_client_ip', $ip );
	}

	/**
	 * Today's salt, made on first use each day.
	 *
	 * @return string
	 */
	private static function daily_salt() {
		$today = gmdate( 'Y-m-d' );
		$saved = get_option( self::SALT_OPTION );

		if ( is_array( $saved ) && isset( $saved['day'], $saved['salt'] ) && $today === $saved['day'] && is_string( $saved['salt'] ) ) {
			return $saved['salt'];
		}

		$salt = wp_generate_password( 40, true, true );
		update_option(
			self::SALT_OPTION,
			array(
				'day'  => $today,
				'salt' => $salt,
			),
			true
		);

		return $salt;
	}

	/**
	 * A valid IP, or ''.
	 *
	 * @param mixed $ip Candidate.
	 * @return string
	 */
	private static function valid_ip( $ip ) {
		$ip = is_string( $ip ) ? trim( $ip ) : '';

		return '' !== $ip && strlen( $ip ) <= 45 && filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/**
	 * Whether an IP is public (not private or reserved).
	 *
	 * @param string $ip IP.
	 * @return bool
	 */
	private static function is_public( $ip ) {
		return false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
	}

	/**
	 * Whether an IP is one of Cloudflare's.
	 *
	 * @param string $ip IP.
	 * @return bool
	 */
	private static function is_cloudflare( $ip ) {
		$ranges = array(
			'173.245.48.0/20',
			'103.21.244.0/22',
			'103.22.200.0/22',
			'103.31.4.0/22',
			'141.101.64.0/18',
			'108.162.192.0/18',
			'190.93.240.0/20',
			'188.114.96.0/20',
			'197.234.240.0/22',
			'198.41.128.0/17',
			'162.158.0.0/15',
			'104.16.0.0/13',
			'104.24.0.0/14',
			'172.64.0.0/13',
			'131.0.72.0/22',
			'2400:cb00::/32',
			'2606:4700::/32',
			'2803:f800::/32',
			'2405:b500::/32',
			'2405:8100::/32',
			'2a06:98c0::/29',
			'2c0f:f248::/32',
		);

		foreach ( $ranges as $range ) {
			if ( self::in_range( $ip, $range ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether an IP is in a CIDR range.
	 *
	 * @param string $ip    IP.
	 * @param string $range CIDR range.
	 * @return bool
	 */
	private static function in_range( $ip, $range ) {
		$parts  = explode( '/', $range, 2 );
		$ip_bin = @inet_pton( (string) $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Invalid input just means "no match".
		$sn_bin = @inet_pton( $parts[0] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( false === $ip_bin || false === $sn_bin || strlen( $ip_bin ) !== strlen( $sn_bin ) ) {
			return false;
		}

		$bits  = isset( $parts[1] ) ? (int) $parts[1] : strlen( $ip_bin ) * 8;
		$bytes = intdiv( $bits, 8 );
		$rest  = $bits % 8;

		if ( $bytes > 0 && 0 !== substr_compare( $ip_bin, $sn_bin, 0, $bytes ) ) {
			return false;
		}

		if ( $rest > 0 ) {
			$mask = ( 0xff << ( 8 - $rest ) ) & 0xff;

			if ( ( ord( $ip_bin[ $bytes ] ) & $mask ) !== ( ord( $sn_bin[ $bytes ] ) & $mask ) ) {
				return false;
			}
		}

		return true;
	}
}
