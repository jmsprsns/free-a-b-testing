/**
 * Free A/B Testing element picker. Loaded only for admins, on a page opened
 * from a test's goal settings with a nonce (?fabt_pick=click|form).
 *
 * Hover to highlight, click to choose. Clicks don't reach the page while
 * picking, so links don't open and forms don't send. "Use this" sends the
 * CSS selector back to the test screen (the window that opened this one),
 * addressed to the admin's origin only, and closes the tab.
 */
/* global fabtPicker */
( function () {
	'use strict';

	var cfg = window.fabtPicker;
	var isForm = 'form' === cfg.field;
	var box;
	var bar;
	var label;
	var useButton;
	var chosen = '';

	// Classes that describe a passing state, not the element.
	var unstable = /^(is-|has-|wp-elements-|hover|active|focus|current|selected|open|visible|animated|fabt-)|[0-9a-f]{6,}|\d{3,}/i;

	function esc( value ) {
		return window.CSS && CSS.escape ? CSS.escape( value ) : value.replace( /[^a-zA-Z0-9_-]/g, '\\$&' );
	}

	function unique( selector ) {
		try {
			return 1 === document.querySelectorAll( selector ).length;
		} catch ( e ) {
			return false;
		}
	}

	function part( el ) {
		var tag = el.tagName.toLowerCase();
		var classes = Array.prototype.filter.call( el.classList, function ( c ) {
			return ! unstable.test( c );
		} ).slice( 0, 3 );

		return tag + classes.map( function ( c ) {
			return '.' + esc( c );
		} ).join( '' );
	}

	function nth( el ) {
		var i = 1;
		var sib = el;

		while ( ( sib = sib.previousElementSibling ) ) {
			if ( sib.tagName === el.tagName ) {
				i++;
			}
		}

		return ':nth-of-type(' + i + ')';
	}

	// Shortest selector (id, tag + classes, then a path up the tree) that
	// matches this element alone.
	function selectorFor( el ) {
		var path = [];
		var node = el;
		var step;
		var attr;

		if ( el.id && ! unstable.test( el.id ) && unique( '#' + esc( el.id ) ) ) {
			return '#' + esc( el.id );
		}

		if ( 'A' === el.tagName && ( attr = el.getAttribute( 'href' ) ) && attr.length < 120 && unique( 'a[href="' + attr.replace( /"/g, '\\"' ) + '"]' ) ) {
			return 'a[href="' + attr.replace( /"/g, '\\"' ) + '"]';
		}

		while ( node && 1 === node.nodeType && 'BODY' !== node.tagName ) {
			step = part( node );

			if ( node.id && ! unstable.test( node.id ) ) {
				path.unshift( '#' + esc( node.id ) );

				if ( unique( path.join( ' > ' ) ) ) {
					return path.join( ' > ' );
				}

				path.shift();
			}

			path.unshift( step );

			if ( unique( path.join( ' > ' ) ) ) {
				return path.join( ' > ' );
			}

			path[ 0 ] = step + nth( node );

			if ( unique( path.join( ' > ' ) ) ) {
				return path.join( ' > ' );
			}

			node = node.parentElement;
		}

		return path.join( ' > ' );
	}

	function target( el ) {
		if ( isForm ) {
			return el.closest( 'form' );
		}

		return el.closest( 'a, button, input[type=submit], input[type=button], [role=button], summary, label' ) || el;
	}

	function own( el ) {
		return bar.contains( el ) || box === el;
	}

	function place( el ) {
		var r = el.getBoundingClientRect();

		box.style.display = 'block';
		box.style.top = r.top - 3 + 'px';
		box.style.left = r.left - 3 + 'px';
		box.style.width = r.width + 6 + 'px';
		box.style.height = r.height + 6 + 'px';
	}

	function count( selector ) {
		try {
			return document.querySelectorAll( selector ).length;
		} catch ( e ) {
			return 0;
		}
	}

	function build() {
		var style = document.createElement( 'style' );
		var cancel = document.createElement( 'button' );

		style.textContent = '#fabt-pick-box{position:fixed;z-index:2147483646;pointer-events:none;display:none;border:3px solid #2271b1;border-radius:4px;background:rgba(34,113,177,.12);transition:all .06s}'
			+ '#fabt-pick-bar{position:fixed;z-index:2147483647;left:50%;bottom:16px;transform:translateX(-50%);display:flex;flex-wrap:wrap;align-items:center;gap:8px;width:min(720px,calc(100vw - 32px));box-sizing:border-box;padding:12px 14px;border-radius:10px;background:#1d2327;color:#fff;font:14px/1.4 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;box-shadow:0 8px 24px rgba(0,0,0,.3)}'
			+ '#fabt-pick-bar p{flex:1 1 100%;margin:0}#fabt-pick-bar code{flex:1;min-width:0;overflow:hidden;padding:6px 8px;border-radius:5px;background:#2c3338;color:#9ec2e6;font:12px/1.4 Menlo,Consolas,monospace;text-overflow:ellipsis;white-space:nowrap}'
			+ '#fabt-pick-bar small{flex:1 1 100%;color:#c3c4c7}#fabt-pick-bar button{padding:7px 14px;border:0;border-radius:6px;background:#3c434a;color:#fff;font-weight:600;font-size:13px;line-height:1;font-family:inherit;cursor:pointer}'
			+ '#fabt-pick-bar button.fabt-use{background:#2271b1}#fabt-pick-bar button:disabled{opacity:.5;cursor:default}';

		box = document.createElement( 'div' );
		box.id = 'fabt-pick-box';

		bar = document.createElement( 'div' );
		bar.id = 'fabt-pick-bar';
		bar.innerHTML = '<p></p><code>&nbsp;</code><small></small>';
		bar.querySelector( 'p' ).textContent = isForm ? cfg.i18n.form : cfg.i18n.click;

		label = bar.querySelector( 'code' );

		useButton = document.createElement( 'button' );
		useButton.type = 'button';
		useButton.className = 'fabt-use';
		useButton.textContent = cfg.i18n.use;
		useButton.disabled = true;

		cancel.type = 'button';
		cancel.textContent = cfg.i18n.cancel;

		bar.insertBefore( useButton, bar.querySelector( 'small' ) );
		bar.insertBefore( cancel, bar.querySelector( 'small' ) );

		document.head.appendChild( style );
		document.body.appendChild( box );
		document.body.appendChild( bar );

		useButton.addEventListener( 'click', function () {
			if ( ! chosen || ! window.opener ) {
				return;
			}

			window.opener.postMessage( { type: 'fabt-pick', field: cfg.field, selector: chosen }, cfg.origin );
			bar.querySelector( 'p' ).textContent = cfg.i18n.closed;
			window.close();
		} );

		cancel.addEventListener( 'click', function () {
			window.close();
		} );
	}

	function onMove( event ) {
		var el = event.target;

		if ( ! el || own( el ) ) {
			return;
		}

		el = target( el );

		if ( el ) {
			place( el );
		} else {
			box.style.display = 'none';
		}
	}

	function onClick( event ) {
		var el = event.target;
		var found;
		var n;

		if ( own( el ) ) {
			return;
		}

		event.preventDefault();
		event.stopPropagation();
		event.stopImmediatePropagation();

		found = target( el );

		if ( ! found ) {
			bar.querySelector( 'small' ).textContent = cfg.i18n.noForm;
			return;
		}

		chosen = selectorFor( found );
		n = count( chosen );
		label.textContent = chosen;
		bar.querySelector( 'small' ).textContent = 1 === n ? cfg.i18n.match : cfg.i18n.matches.replace( '%d', n );
		useButton.disabled = ! chosen;
		place( found );
	}

	function block( event ) {
		if ( ! own( event.target ) ) {
			event.preventDefault();
			event.stopPropagation();
		}
	}

	function start() {
		build();
		document.addEventListener( 'mouseover', onMove, true );
		document.addEventListener( 'click', onClick, true );
		document.addEventListener( 'submit', block, true );
		document.addEventListener( 'mousedown', block, true );
		document.addEventListener( 'pointerdown', block, true );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
}() );
