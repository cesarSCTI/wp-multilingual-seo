/**
 * Selector de idioma en modo dropdown.
 *
 * Sin dependencias. Soporta varias instancias en la misma página. Se
 * autoinicializa al cargar el DOM y expone `window.mlsLangSwitchInit()` para
 * que el editor de Elementor lo vuelva a enganchar tras un render AJAX.
 */
( function () {
	'use strict';

	var OPEN_CLASS = 'is-open';

	function closeAll( except ) {
		document.querySelectorAll( '.mls-lang-switch--dropdown' ).forEach( function ( nav ) {
			if ( nav === except ) {
				return;
			}
			setOpen( nav, false );
		} );
	}

	function setOpen( nav, open ) {
		var toggle = nav.querySelector( '.mls-lang-switch__toggle' );
		var panel = nav.querySelector( '.mls-lang-switch__panel' );
		if ( ! toggle || ! panel ) {
			return;
		}
		nav.classList.toggle( OPEN_CLASS, open );
		toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		if ( open ) {
			panel.removeAttribute( 'hidden' );
		} else {
			panel.setAttribute( 'hidden', '' );
		}
	}

	function bind( nav ) {
		if ( nav.dataset.mlsBound ) {
			return;
		}
		nav.dataset.mlsBound = '1';

		var toggle = nav.querySelector( '.mls-lang-switch__toggle' );
		if ( ! toggle ) {
			return;
		}

		toggle.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			var willOpen = ! nav.classList.contains( OPEN_CLASS );
			closeAll( nav );
			setOpen( nav, willOpen );
		} );
	}

	function init() {
		document.querySelectorAll( '.mls-lang-switch--dropdown' ).forEach( bind );
	}

	document.addEventListener( 'click', function ( e ) {
		var target = e.target;
		if ( ! ( target instanceof Element ) || ! target.closest( '.mls-lang-switch--dropdown' ) ) {
			closeAll( null );
		}
	} );

	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' === e.key ) {
			closeAll( null );
		}
	} );

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}

	window.mlsLangSwitchInit = init;
} )();
