/**
 * Free A/B Testing: printed inline at the top of <head> on a page with a
 * running test (minified as tracker.min.js; run bin/build.sh after editing).
 *
 * 1. Picks the visitor's variant (evenly at random, remembered in the
 *    "fabt" cookie) and adds `fabt-<test>-is-<slot>` to <html> before the
 *    page paints. CSS (printed by the server, and repeated here in case an
 *    optimizer strips inline CSS) hides every other version.
 * 2. Reports one view per visitor per test, then one conversion when the
 *    goal happens: a click on the chosen element, a form being sent, or a
 *    tel:/sms: link being tapped. Purchases are counted by the server.
 *
 * Cookie: <visitor id>_<test>.<run>.<slot><flags>_... where flags are
 * s (view sent) and c (conversion sent). At most 20 tests are kept.
 *
 * Settings (__FABT_CONFIG__, filled in by PHP):
 *   u  endpoint (admin-ajax.php)       i  test ID       r  run number
 *   s  active slots (0 = original)     g  goal: c click, f form, t tel/sms, w purchase
 *   q  CSS selector for c / f          k  t (tel:) and/or s (sms:)
 *   n  cookie lifetime in days         p  1 = respect Global Privacy Control / Do Not Track
 *   f  forced slot (admin preview) or -1
 *   x  1 = don't count (admin preview or picker)
 */
( function ( c, d, n ) {
	var h = d.documentElement;
	var p = 'fabt-' + c.i;
	var slot;
	var flags = '';
	var vid;
	var others = [];

	function show( s ) {
		h.className += ' ' + p + '-is-' + s;
	}

	function save() {
		var list = others.slice( -19 );

		list.push( c.i + '.' + c.r + '.' + slot + flags );
		d.cookie = 'fabt=' + vid + '_' + list.join( '_' ) + ';path=/;max-age=' + c.n * 86400 + ';samesite=lax' + ( 'https:' === location.protocol ? ';secure' : '' );
	}

	function send( kind ) {
		var body;

		flags += 'v' === kind ? 's' : 'c';
		save();

		body = new URLSearchParams( { action: 'fabt_hit', a: kind, t: c.i, r: c.r, s: slot, v: vid } );

		if ( ! ( n.sendBeacon && n.sendBeacon( c.u, body ) ) ) {
			fetch( c.u, { method: 'POST', body: body, keepalive: true, credentials: 'same-origin' } );
		}
	}

	function convert() {
		if ( flags.indexOf( 'c' ) < 0 ) {
			send( 'c' );
		}
	}

	function newId() {
		var chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
		var bytes = new Uint8Array( 22 );
		var out = '';
		var i;

		crypto.getRandomValues( bytes );

		for ( i = 0; i < 22; i++ ) {
			out += chars[ bytes[ i ] % 62 ];
		}

		return out;
	}

	try {
		// The CSS again, built from the slots.
		var css = 'html';
		var rest = '';
		var style = d.createElement( 'style' );

		c.s.forEach( function ( s ) {
			if ( s ) {
				css += ':not(.' + p + '-is-' + s + ')';
				rest += 'html.' + p + '-is-' + s + ' .' + p + ':not(.' + p + '-' + s + '){display:none!important}';
			}
		} );

		style.textContent = css + ' .' + p + ':not(.' + p + '-0){display:none!important}' + rest;
		d.head.appendChild( style );

		if ( c.f > -1 ) {
			show( c.f );
			return;
		}

		if ( c.p && ( n.globalPrivacyControl || '1' === n.doNotTrack ) ) {
			return;
		}

		var found = d.cookie.match( /(?:^|;\s*)fabt=([\w.]{1,600})/ );
		var parts = found ? found[ 1 ].split( '_' ) : [];

		vid = parts.shift();

		if ( ! /^[A-Za-z0-9]{22}$/.test( vid || '' ) ) {
			vid = newId();
			parts = [];
		}

		parts.forEach( function ( entry ) {
			var e = /^(\d+)\.(\d+)\.(\d+)([sc]*)$/.exec( entry );

			if ( ! e ) {
				return;
			}

			if ( +e[ 1 ] !== c.i ) {
				others.push( entry );
			} else if ( +e[ 2 ] === c.r && c.s.indexOf( +e[ 3 ] ) > -1 ) {
				slot = +e[ 3 ];
				flags = e[ 4 ];
			}
		} );

		if ( undefined === slot ) {
			slot = c.s[ Math.floor( Math.random() * c.s.length ) ];
		}

		show( slot );
		save();

		if ( c.x ) {
			return;
		}

		if ( flags.indexOf( 's' ) < 0 ) {
			send( 'v' );
		}

		if ( 'f' === c.g ) {
			d.addEventListener( 'submit', function ( event ) {
				try {
					var form = event.target;

					if ( form.matches( c.q || 'form' ) && ( c.q || ! form.matches( '[role=search],.search-form,.searchform,.woocommerce-product-search' ) ) ) {
						convert();
					}
				} catch ( e ) {}
			}, true );
		}

		if ( 'c' === c.g || 't' === c.g ) {
			d.addEventListener( 'click', function ( event ) {
				try {
					var el = event.target.closest( 'c' === c.g ? c.q : 'a[href]' );
					var href;

					if ( ! el ) {
						return;
					}

					if ( 't' === c.g ) {
						href = ( el.getAttribute( 'href' ) || '' ).trim().toLowerCase();

						if ( ! ( ( c.k.indexOf( 't' ) > -1 && /^(tel|callto):/.test( href ) ) || ( c.k.indexOf( 's' ) > -1 && /^sms(to)?:/.test( href ) ) ) ) {
							return;
						}
					}

					convert();
				} catch ( e ) {}
			}, true );
		}
	} catch ( e ) {}
} )( __FABT_CONFIG__, document, navigator );
