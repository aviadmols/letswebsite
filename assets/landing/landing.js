/* Let's landing pages — reveal sections as they scroll into view. */
( function () {
	'use strict';

	var items = document.querySelectorAll( '.lp-reveal' );

	if ( ! items.length ) {
		return;
	}

	if ( ! ( 'IntersectionObserver' in window ) || window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches ) {
		items.forEach( function ( el ) { el.classList.add( 'lp-is-in' ); } );
		return;
	}

	var io = new IntersectionObserver( function ( entries ) {
		entries.forEach( function ( entry ) {
			if ( entry.isIntersecting ) {
				entry.target.classList.add( 'lp-is-in' );
				io.unobserve( entry.target );
			}
		} );
	}, { rootMargin: '0px 0px -10% 0px', threshold: 0.12 } );

	items.forEach( function ( el ) { io.observe( el ); } );
}() );
