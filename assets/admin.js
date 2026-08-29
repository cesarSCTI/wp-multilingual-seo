( function () {
	'use strict';

	function setCellStatus( cell, status ) {
		if ( ! cell ) {
			return;
		}
		var label = cell.querySelector( '.mls-status-label' );
		if ( label ) {
			label.textContent =
				'pending' === status
					? mlsAdmin.i18nPending
					: 'manual' === status
					? mlsAdmin.i18nManual
					: mlsAdmin.i18nAuto;
		}
	}

	function translateOne( postId, lang, force ) {
		var body = new URLSearchParams( {
			action: 'mls_translate_one',
			post_id: postId,
			lang: lang,
			force: force ? 1 : 0,
			nonce: mlsAdmin.nonce,
		} );
		return fetch( mlsAdmin.ajaxUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body,
			credentials: 'same-origin',
		} ).then( function ( res ) {
			return res.text().then( function ( text ) {
				var data;
				try { data = JSON.parse( text ); } catch ( err ) {
					throw new Error( 'Respuesta inválida del servidor (' + res.status + ')' );
				}
				if ( ! res.ok ) { throw new Error( data && data.data && data.data.message ? data.data.message : 'HTTP ' + res.status ); }
				return data;
			} );
		} );
	}

	// Selector de imágenes: abre la librería de medios de WordPress para
	// reemplazar la imagen de un bloque en la traducción actual.
	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '.mls-choose-image' );
		if ( ! btn || typeof wp === 'undefined' || ! wp.media ) {
			return;
		}
		e.preventDefault();

		var row = btn.closest( '.mls-image-row' );
		var srcInput = row.querySelector( '.mls-image-src-input' );
		var preview = row.querySelector( '.mls-image-preview' );

		var frame = wp.media( {
			title: mlsAdmin.i18nChooseImage,
			button: { text: mlsAdmin.i18nUseImage },
			multiple: false,
		} );

		frame.on( 'select', function () {
			var attachment = frame.state().get( 'selection' ).first().toJSON();
			var url = attachment.url;

			srcInput.value = url;

			if ( preview && 'IMG' === preview.tagName ) {
				preview.src = url;
			} else {
				if ( preview ) {
					preview.remove();
				}
				var img = document.createElement( 'img' );
				img.src = url;
				img.className = 'mls-image-preview';
				row.insertBefore( img, row.firstChild );
			}
		} );

		frame.open();
	} );

	// Traducción individual (desde el meta box del editor o la tabla de Traducciones).
	document.addEventListener( 'click', function ( e ) {
		var link = e.target.closest( '.mls-translate-now' );
		if ( ! link ) {
			return;
		}
		e.preventDefault();

		var postId = link.getAttribute( 'data-post-id' );
		var lang = link.getAttribute( 'data-lang' );
		var original = link.textContent;
		link.textContent = mlsAdmin.i18nTranslating;

		translateOne( postId, lang, false ).then( function ( data ) {
			var cell = link.closest( '[data-post-id]' );
			if ( data.success ) {
				setCellStatus( cell, 'auto' );
				link.textContent = mlsAdmin.i18nDone;
				setTimeout( function () {
					window.location.reload();
				}, 700 );
			} else {
				link.textContent = mlsAdmin.i18nError;
				setTimeout( function () {
					link.textContent = original;
				}, 2500 );
			}
		} ).catch( function () {
			link.textContent = mlsAdmin.i18nError;
			setTimeout( function () { link.textContent = original; }, 2500 );
		} );
	} );

	// Sincronización masiva: recorre todos los pendientes (o todos, si se fuerza)
	// llamando a la API una vez por cada uno, con progreso visible en pantalla.
	function runBulk( force ) {
		var progressEl = document.getElementById( 'mls-sync-progress' );
		var barWrap = document.getElementById( 'mls-sync-bar-wrap' );
		var bar = document.getElementById( 'mls-sync-bar' );
		var logEl = document.getElementById( 'mls-sync-log' );
		if ( ! progressEl ) {
			return;
		}
		logEl.innerHTML = '';
		progressEl.classList.remove( 'is-hidden' );
		barWrap.classList.remove( 'is-hidden' );
		bar.style.width = '0%';
		progressEl.textContent = mlsAdmin.i18nLoadingList;

		var jobsBody = new URLSearchParams( {
			action: 'mls_get_pending_jobs',
			force: force ? 1 : 0,
			nonce: mlsAdmin.nonce,
		} );

		fetch( mlsAdmin.ajaxUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: jobsBody,
		} )
			.then( function ( res ) {
				return res.json();
			} )
			.then( function ( jobsData ) {
				if ( ! jobsData.success ) {
					progressEl.textContent = mlsAdmin.i18nListError;
					return;
				}
				var jobs = jobsData.data.jobs;
				if ( 0 === jobs.length ) {
					progressEl.textContent = mlsAdmin.i18nNothingPending;
					return;
				}
				processQueue( jobs, force, progressEl, bar, logEl );
			} );
	}

	function processQueue( jobs, force, progressEl, bar, logEl ) {
		var index = 0;
		var done = 0;
		var errors = 0;

		function next() {
			if ( index >= jobs.length ) {
				progressEl.textContent =
					done + ' ' + mlsAdmin.i18nProcessed + ', ' + errors + ' ' + mlsAdmin.i18nWithErrors;
				return;
			}
			var job = jobs[ index ];
			index++;

			progressEl.textContent =
				done + 1 + ' / ' + jobs.length + ' — ' + job.title + ' (' + job.lang.toUpperCase() + ')';

			translateOne( job.post_id, job.lang, force ).then( function ( data ) {
				done++;
				if ( data.success ) {
					var cell = document.querySelector(
						'.mls-status-cell[data-post-id="' + job.post_id + '"][data-lang="' + job.lang + '"]'
					);
					setCellStatus( cell, 'auto' );
				} else {
					errors++;
					var row = document.createElement( 'div' );
					row.textContent =
						job.title +
						' (' +
						job.lang +
						'): ' +
						( data.data && data.data.message ? data.data.message : mlsAdmin.i18nUnknownError );
					logEl.appendChild( row );
				}
				bar.style.width = Math.round( ( done / jobs.length ) * 100 ) + '%';
				next();
			} );
		}

		next();
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var syncBtn = document.getElementById( 'mls-sync-now' );
		var forceBtn = document.getElementById( 'mls-force-sync-now' );

		if ( syncBtn ) {
			syncBtn.addEventListener( 'click', function () {
				runBulk( false );
			} );
		}
		if ( forceBtn ) {
			forceBtn.addEventListener( 'click', function () {
				if ( window.confirm( mlsAdmin.i18nConfirmForce ) ) {
					runBulk( true );
				}
			} );
		}

		initAutosizeTextareas();
	} );

	// Textareas del editor de traducciones: crecen solas según el
	// contenido, en vez de quedarse en una cajita fija de 2 filas con
	// texto largo desbordado.
	function autosize( el ) {
		el.style.height = 'auto';
		el.style.height = el.scrollHeight + 'px';
	}

	function initAutosizeTextareas() {
		var areas = document.querySelectorAll( '.mls-autosize' );
		areas.forEach( function ( el ) {
			autosize( el );
			el.addEventListener( 'input', function () {
				autosize( el );
			} );
		} );
	}

	// "Detalles técnicos": muestra/oculta la ruta técnica de una unidad
	// de traducción (ej. la ruta dentro del JSON de Elementor), oculta
	// por defecto para no abrumar a un usuario no técnico.
	document.addEventListener( 'click', function ( e ) {
		var toggle = e.target.closest( '.mls-tu__details-toggle' );
		if ( ! toggle ) {
			return;
		}
		var details = toggle.parentElement.querySelector( '.mls-tu__details' );
		if ( details ) {
			details.hidden = ! details.hidden;
		}
	} );
} )();
