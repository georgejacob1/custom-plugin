( function () {
	'use strict';

	const $ = ( id ) => document.getElementById( id );

	function post( action, data = {} ) {
		const body = new URLSearchParams( { action, nonce: BS3O.nonce, ...data } );
		return fetch( BS3O.ajaxUrl, { method: 'POST', credentials: 'same-origin', body } )
			.then( ( r ) => r.json() );
	}

	// Test connection.
	const testBtn = $( 'bs3o-test' );
	if ( testBtn ) {
		testBtn.addEventListener( 'click', () => {
			const out = $( 'bs3o-test-result' );
			testBtn.disabled = true;
			out.className = '';
			out.textContent = 'Testing…';
			post( 'bs3o_test' )
				.then( ( res ) => {
					out.className = res.success ? 'bs3o-ok' : 'bs3o-err';
					out.textContent = res.data;
				} )
				.catch( () => {
					out.className = 'bs3o-err';
					out.textContent = 'Request failed.';
				} )
				.finally( () => ( testBtn.disabled = false ) );
		} );
	}

	// Bulk offload.
	const start = $( 'bs3o-start' );
	if ( ! start ) {
		return;
	}
	const retry = $( 'bs3o-retry' );
	const stop = $( 'bs3o-stop' );
	const status = $( 'bs3o-status' );
	const errors = $( 'bs3o-errors' );
	const bar = document.querySelector( '.bs3o-progress-bar' );
	let running = false;

	function render( counts ) {
		const fmt = ( n ) => Number( n ).toLocaleString();
		$( 'bs3o-total' ).textContent = fmt( counts.total );
		$( 'bs3o-offloaded' ).textContent = fmt( counts.offloaded );
		$( 'bs3o-remaining' ).textContent = fmt( counts.remaining );
		$( 'bs3o-failed' ).textContent = fmt( counts.failed );
		bar.style.width = ( counts.total ? Math.round( ( counts.offloaded / counts.total ) * 100 ) : 0 ) + '%';
	}

	function setRunning( on ) {
		running = on;
		start.disabled = on;
		retry.disabled = on;
		stop.disabled = ! on;
	}

	function addError( item ) {
		const li = document.createElement( 'li' );
		li.textContent = `#${ item.id } ${ item.title }: ${ item.message }`;
		errors.appendChild( li );
	}

	function step( first ) {
		if ( ! running ) {
			status.textContent = 'Stopped. Press Start to resume.';
			setRunning( false );
			return;
		}
		post( 'bs3o_bulk_batch', first ? { retry: first } : {} )
			.then( ( res ) => {
				if ( ! res.success ) {
					throw new Error( res.data || 'Batch failed.' );
				}
				render( res.data.counts );
				res.data.errors.forEach( addError );
				if ( res.data.finished ) {
					status.textContent = res.data.counts.failed
						? `Done, ${ res.data.counts.failed } item(s) failed — see below, then "Retry failed".`
						: 'Done — the whole Media Library is in S3.';
					setRunning( false );
					retry.disabled = ! res.data.counts.failed;
					return;
				}
				status.textContent = 'Offloading…';
				step( '' );
			} )
			.catch( ( err ) => {
				status.textContent = 'Error: ' + err.message + ' — press Start to resume.';
				setRunning( false );
			} );
	}

	function run( retryFailed ) {
		errors.textContent = '';
		setRunning( true );
		status.textContent = 'Offloading…';
		step( retryFailed ? '1' : '' );
	}

	start.addEventListener( 'click', () => run( false ) );
	retry.addEventListener( 'click', () => run( true ) );
	stop.addEventListener( 'click', () => {
		running = false;
		stop.disabled = true;
		status.textContent = 'Stopping after the current batch…';
	} );
	window.addEventListener( 'beforeunload', ( e ) => {
		if ( running ) {
			e.preventDefault();
			e.returnValue = '';
		}
	} );
} )();
