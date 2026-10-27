/**
 * Content Powered > Free A/B Testing page behaviour. Only loaded on that page.
 *
 * - Shows the settings for the chosen goal.
 * - Searches posts and pages for a new test (admin-ajax, nonce-checked).
 * - Opens the element picker on the tested page and takes the selector it
 *   sends back (only from this site's origin, and only from that tab).
 * - Asks before destructive buttons (data-confirm).
 *
 * fabtAdmin comes from PHP (see Free_A_B_Testing_Admin::enqueue_assets).
 */

/* global fabtAdmin */
( function () {
	'use strict';

	var cfg = window.fabtAdmin;
	var pickWindow = null;

	function goalDetails( form ) {
		var checked = form.querySelector( 'input[name="goal[type]"]:checked' );
		var type = checked ? checked.value : 'click';

		form.querySelectorAll( '.fabt-goal-detail' ).forEach( function ( el ) {
			el.hidden = el.getAttribute( 'data-goal' ) !== type;
		} );
	}

	function pageUrl( form ) {
		return form.getAttribute( 'data-page-url' ) || '';
	}

	function openPicker( form, field, button ) {
		var url = pageUrl( form );
		var note = button.parentNode.parentNode.querySelector( '.fabt-pick-note' );

		if ( ! note ) {
			note = document.createElement( 'p' );
			note.className = 'fabt-pick-note';
			button.parentNode.parentNode.appendChild( note );
		}

		note.textContent = '';

		if ( ! url ) {
			note.textContent = cfg.i18n.pickFirst;
			return;
		}

		url += ( url.indexOf( '?' ) > -1 ? '&' : '?' ) + 'fabt_pick=' + encodeURIComponent( field ) + '&_fabt=' + encodeURIComponent( cfg.pickNonce );
		pickWindow = window.open( url, 'fabt-picker' );

		if ( ! pickWindow ) {
			note.textContent = cfg.i18n.blocked;
		}
	}

	function onPicked( event ) {
		var data = event.data;
		var input;

		if ( event.origin !== cfg.homeOrigin || ! pickWindow || event.source !== pickWindow ) {
			return;
		}

		if ( ! data || 'fabt-pick' !== data.type || 'string' !== typeof data.selector || data.selector.length > 300 ) {
			return;
		}

		input = document.getElementById( 'form' === data.field ? 'fabt-selector-form' : 'fabt-selector-click' );

		if ( input ) {
			input.value = data.selector;
			input.focus();
		}
	}

	function el( tag, className, text ) {
		var node = document.createElement( tag );

		if ( className ) {
			node.className = className;
		}

		if ( text ) {
			node.textContent = text;
		}

		return node;
	}

	function pagePicker( form ) {
		var picker = form.querySelector( '.fabt-page-picker' );
		var query;
		var list;
		var timer;
		var last = null;

		if ( ! picker ) {
			return;
		}

		query = picker.querySelector( '#fabt-page-query' );
		list = picker.querySelector( '#fabt-page-results' );

		function note( text ) {
			list.innerHTML = '';
			list.appendChild( el( 'li', 'fabt-note', text ) );
		}

		function choose( item ) {
			picker.querySelector( '#fabt-post-id' ).value = item.id;
			picker.querySelector( '#fabt-page-title' ).textContent = item.title;
			picker.querySelector( '#fabt-page-meta' ).textContent = item.type + ' · ' + item.path;
			picker.querySelector( '.fabt-page-chosen' ).hidden = false;
			picker.querySelector( '.fabt-page-search' ).hidden = true;
			form.setAttribute( 'data-page-url', item.url );
		}

		function render( items ) {
			list.innerHTML = '';

			if ( ! items.length ) {
				note( cfg.i18n.noResults );
				return;
			}

			items.forEach( function ( item ) {
				var li = el( 'li' );
				var button = el( 'button' );
				var text = el( 'span', 'fabt-result-text' );

				button.type = 'button';
				button.setAttribute( 'role', 'option' );
				text.appendChild( el( 'strong', '', item.title || '#' + item.id ) );
				text.appendChild( el( 'span', '', item.path ) );
				button.appendChild( text );
				button.appendChild( el( 'span', 'fabt-type' + ( item.tested ? ' is-tested' : '' ), item.tested ? cfg.i18n.hasTest : item.type ) );
				button.addEventListener( 'click', function () {
					choose( item );
				} );
				li.appendChild( button );
				list.appendChild( li );
			} );
		}

		function search() {
			var q = query.value.trim();
			var params;

			if ( q === last ) {
				return;
			}

			last = q;
			note( cfg.i18n.searching );
			params = new URLSearchParams( { action: 'fabt_search', nonce: cfg.nonce, q: q } );

			fetch( cfg.ajaxUrl + '?' + params.toString(), { credentials: 'same-origin' } )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( json ) {
					if ( q === last ) {
						render( json && json.success ? json.data : [] );
					}
				} )
				.catch( function () {
					note( cfg.i18n.noResults );
				} );
		}

		query.addEventListener( 'input', function () {
			clearTimeout( timer );
			timer = setTimeout( search, 250 );
		} );

		query.addEventListener( 'focus', search );

		picker.querySelector( '#fabt-page-change' ).addEventListener( 'click', function () {
			picker.querySelector( '.fabt-page-chosen' ).hidden = true;
			picker.querySelector( '.fabt-page-search' ).hidden = false;
			query.focus();
		} );

		form.addEventListener( 'submit', function ( event ) {
			if ( ! picker.querySelector( '#fabt-post-id' ).value ) {
				event.preventDefault();
				query.focus();
				note( cfg.i18n.pickFirst );
			}
		} );

		if ( ! picker.querySelector( '#fabt-post-id' ).value ) {
			search();
		}
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		if ( ! cfg ) {
			return;
		}

		document.querySelectorAll( '.fabt-form' ).forEach( function ( form ) {
			if ( form.querySelector( '.fabt-goals' ) ) {
				goalDetails( form );
				form.querySelectorAll( 'input[name="goal[type]"]' ).forEach( function ( radio ) {
					radio.addEventListener( 'change', function () {
						goalDetails( form );
					} );
				} );
			}

			form.querySelectorAll( '.fabt-pick' ).forEach( function ( button ) {
				button.addEventListener( 'click', function () {
					openPicker( form, button.getAttribute( 'data-field' ), button );
				} );
			} );

			pagePicker( form );
		} );

		document.querySelectorAll( '.fabt-confirm' ).forEach( function ( button ) {
			button.addEventListener( 'click', function ( event ) {
				// eslint-disable-next-line no-alert
				if ( ! window.confirm( button.getAttribute( 'data-confirm' ) ) ) {
					event.preventDefault();
				}
			} );
		} );

		window.addEventListener( 'message', onPicked );
	} );
}() );
