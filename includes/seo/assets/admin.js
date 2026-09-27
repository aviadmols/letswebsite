/* Let's SEO — admin: tabs, counters, live previews, media pickers, FAQ rows. */
( function ( $ ) {
	'use strict';

	var cfg = window.letsSeo || {};

	/* Tabs ---------------------------------------------------------------- */

	$( document ).on( 'click', '.lets-seo__tabs button', function () {
		var $box = $( this ).closest( '.lets-seo' );
		var tab  = $( this ).data( 'tab' );

		$box.find( '.lets-seo__tabs button' ).removeClass( 'is-active' );
		$( this ).addClass( 'is-active' );
		$box.find( '.lets-seo__panel' ).removeClass( 'is-active' ).filter( '[data-panel="' + tab + '"]' ).addClass( 'is-active' );
	} );

	/* Character counters -------------------------------------------------- */

	function updateCounter( $field ) {
		var max   = parseInt( $field.data( 'count' ), 10 );
		var min   = parseInt( $field.data( 'min' ), 10 ) || 0;
		var value = $field.val() || '';
		var len   = value.length;
		var $c    = $field.next( '.lets-seo__count' );

		if ( ! $c.length ) {
			$c = $( '<span class="lets-seo__count"><span class="lets-seo__count-bar"><i></i></span><span class="lets-seo__count-text"></span></span>' );
			$field.after( $c );
		}

		var state = ! len ? 'is-bad' : ( len > max ? 'is-bad' : ( len < min ? 'is-ok' : 'is-good' ) );

		$c.removeClass( 'is-good is-ok is-bad' ).addClass( state );
		$c.find( 'i' ).css( 'width', Math.min( 100, ( len / max ) * 100 ) + '%' );
		$c.find( '.lets-seo__count-text' ).text( len ? len + ' / ' + max : 'ריק — ייעשה שימוש בברירת המחדל' );
	}

	/* Live previews ------------------------------------------------------- */

	function postTitle( $box ) {
		// Block editor, then the classic title field, then what was saved.
		try {
			if ( window.wp && wp.data && wp.data.select( 'core/editor' ) ) {
				var t = wp.data.select( 'core/editor' ).getEditedPostAttribute( 'title' );
				if ( t ) {
					return t;
				}
			}
		} catch ( e ) {}

		var classic = $( '#title' ).val();
		return classic || $box.data( 'title' ) || '';
	}

	function fill( template, title ) {
		var out = String( template )
			.replace( /\{title\}/g, title )
			.replace( /\{site\}/g, cfg.site || '' )
			.replace( /\{sep\}/g, cfg.sep || '' )
			.replace( /\{tagline\}/g, cfg.tagline || '' )
			.replace( /\{category\}/g, '' )
			.replace( /\{page\}/g, '' );

		return out.replace( /\s{2,}/g, ' ' ).trim();
	}

	function truncate( text, max ) {
		return text.length > max ? text.slice( 0, max - 1 ).trim() + '…' : text;
	}

	function updatePreviews( $box ) {
		var title    = postTitle( $box );
		var custom   = $box.find( '#lets_seo_title' ).val();
		var seoTitle = fill( custom || cfg.single || '{title}', title );
		var desc     = $box.find( '#lets_seo_description' ).val() || $box.find( '#lets_seo_description' ).attr( 'placeholder' ) || '';
		var ogTitle  = $box.find( '#lets_seo_og_title' ).val() || title;
		var ogDesc   = $box.find( '#lets_seo_og_description' ).val() || desc;

		$box.find( '[data-preview="title"]' ).text( truncate( seoTitle, 60 ) );
		$box.find( '[data-preview="description"]' ).text( truncate( desc, 160 ) );
		$box.find( '[data-preview="og_title"]' ).text( ogTitle );
		$box.find( '[data-preview="og_description"]' ).text( ogDesc );
	}

	/* Media picker -------------------------------------------------------- */

	$( document ).on( 'click', '[data-media-pick]', function ( e ) {
		e.preventDefault();

		var $wrap = $( this ).closest( '[data-media]' );
		var frame = wp.media( {
			title: 'בחירת תמונה',
			library: { type: 'image' },
			multiple: false
		} );

		frame.on( 'select', function () {
			var img = frame.state().get( 'selection' ).first().toJSON();
			var url = img.sizes && img.sizes.medium ? img.sizes.medium.url : img.url;

			$wrap.find( 'input' ).val( img.id );
			$wrap.find( 'img' ).attr( 'src', url ).prop( 'hidden', false );
			$wrap.find( '[data-media-clear]' ).prop( 'hidden', false );
		} );

		frame.open();
	} );

	$( document ).on( 'click', '[data-media-clear]', function ( e ) {
		e.preventDefault();

		var $wrap = $( this ).closest( '[data-media]' );

		$wrap.find( 'input' ).val( 0 );
		$wrap.find( 'img' ).attr( 'src', '' ).prop( 'hidden', true );
		$( this ).prop( 'hidden', true );
	} );

	/* FAQ rows ------------------------------------------------------------ */

	$( document ).on( 'click', '[data-faq-add]', function () {
		var $field = $( this ).closest( '.lets-seo__field' );
		var $list  = $field.find( '[data-faq]' );
		var html   = $field.find( 'template[data-faq-template]' ).html();
		var index  = Date.now();

		$list.append( html.replace( /__i__/g, index ) );
		$list.find( '.lets-seo__faq-row' ).last().find( 'input' ).trigger( 'focus' );
	} );

	$( document ).on( 'click', '[data-faq-remove]', function () {
		$( this ).closest( '.lets-seo__faq-row' ).remove();
	} );

	/* Boot ---------------------------------------------------------------- */

	$( function () {
		$( '.lets-seo [data-count]' ).each( function () {
			updateCounter( $( this ) );
		} );

		$( '.lets-seo' ).each( function () {
			updatePreviews( $( this ) );
		} );

		$( document ).on( 'input', '.lets-seo [data-count]', function () {
			updateCounter( $( this ) );
		} );

		$( document ).on( 'input', '.lets-seo input, .lets-seo textarea, #title', function () {
			$( '.lets-seo' ).each( function () {
				updatePreviews( $( this ) );
			} );
		} );

		// Block editor: the title lives in the store, not in a field.
		if ( window.wp && wp.data && wp.data.subscribe ) {
			var last = null;
			wp.data.subscribe( function () {
				var editor = wp.data.select( 'core/editor' );
				var title  = editor ? editor.getEditedPostAttribute( 'title' ) : null;

				if ( title !== last ) {
					last = title;
					$( '.lets-seo' ).each( function () {
						updatePreviews( $( this ) );
					} );
				}
			} );
		}
	} );
}( jQuery ) );
