/* Let's — signup / contact popup. Opens from any link to #signup or .lets-open-signup. */
( function () {
	'use strict';

	var popup = document.getElementById( 'lets-signup' );

	if ( ! popup ) {
		return;
	}

	var cfg      = window.letsPopup || {};
	var form     = popup.querySelector( '.lets-popup__form' );
	var success  = popup.querySelector( '.lets-popup__success' );
	var errorBox = popup.querySelector( '.lets-popup__error' );
	var submit   = popup.querySelector( '.lets-popup__submit' );
	var lastFocus = null;

	function isTrigger( el ) {
		if ( ! el ) {
			return false;
		}
		if ( el.classList && el.classList.contains( 'lets-open-signup' ) ) {
			return true;
		}
		var href = el.getAttribute && el.getAttribute( 'href' );
		return !! href && /#signup$/.test( href );
	}

	function open() {
		lastFocus = document.activeElement;
		popup.hidden = false;
		document.body.classList.add( 'lets-popup-open' );
		form.querySelector( '[name="ts"]' ).value = Math.floor( Date.now() / 1000 );
		form.querySelector( '[name="source"]' ).value = location.href;

		requestAnimationFrame( function () {
			popup.classList.add( 'is-open' );
			var first = form.querySelector( 'input[name="name"]' );
			if ( first && ! form.hidden ) {
				first.focus();
			}
		} );
	}

	function close() {
		popup.classList.remove( 'is-open' );
		document.body.classList.remove( 'lets-popup-open' );

		setTimeout( function () {
			popup.hidden = true;
			if ( lastFocus && lastFocus.focus ) {
				lastFocus.focus();
			}
		}, 300 );
	}

	function showError( message, field ) {
		errorBox.textContent = message;
		errorBox.hidden = false;
		if ( field ) {
			field.closest( '.lets-popup__field' ).classList.add( 'is-invalid' );
			field.focus();
		}
	}

	function clearErrors() {
		errorBox.hidden = true;
		form.querySelectorAll( '.is-invalid' ).forEach( function ( el ) { el.classList.remove( 'is-invalid' ); } );
	}

	function validate( data ) {
		var errors = cfg.errors || {};

		if ( ! data.name.trim() ) {
			return [ errors.name, form.querySelector( '[name="name"]' ) ];
		}
		if ( ! /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test( data.email.trim() ) ) {
			return [ errors.email, form.querySelector( '[name="email"]' ) ];
		}
		if ( data.phone.replace( /\D/g, '' ).length < 7 ) {
			return [ errors.phone, form.querySelector( '[name="phone"]' ) ];
		}
		return null;
	}

	document.addEventListener( 'click', function ( e ) {
		var el = e.target.closest ? e.target.closest( 'a, button' ) : null;

		if ( isTrigger( el ) ) {
			e.preventDefault();
			open();
		} else if ( e.target.closest && e.target.closest( '[data-popup-close]' ) ) {
			close();
		}
	} );

	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' === e.key && ! popup.hidden ) {
			close();
		}
	} );

	// A page opened at …#signup shows the popup straight away.
	if ( '#signup' === location.hash ) {
		open();
	}

	form.addEventListener( 'submit', function ( e ) {
		e.preventDefault();
		clearErrors();

		var data = {};
		new FormData( form ).forEach( function ( value, key ) { data[ key ] = value; } );

		var invalid = validate( data );
		if ( invalid ) {
			showError( invalid[0], invalid[1] );
			return;
		}

		var label = submit.textContent;
		submit.disabled = true;
		submit.textContent = cfg.sending || label;

		fetch( cfg.endpoint, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( data )
		} ).then( function ( res ) {
			return res.json().then( function ( body ) { return { ok: res.ok, body: body }; } );
		} ).then( function ( r ) {
			if ( ! r.ok || ! r.body || ! r.body.ok ) {
				throw new Error( r.body && r.body.message ? r.body.message : '' );
			}
			form.hidden = true;
			success.hidden = false;
			document.dispatchEvent( new CustomEvent( 'lets:lead', { detail: { platform: data.platform, source: data.source } } ) );
		} ).catch( function ( err ) {
			showError( err.message || ( cfg.errors && cfg.errors.generic ) || 'Error' );
		} ).finally( function () {
			submit.disabled = false;
			submit.textContent = label;
		} );
	} );
}() );
