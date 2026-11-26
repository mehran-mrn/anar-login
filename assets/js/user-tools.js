( function () {
	'use strict';
	const root = document.querySelector( '[data-user-tools]' );
	if ( ! root || ! window.AnarUserTools ) { return; }
	const config = window.AnarUserTools;
	const text = config.strings;
	const scan = root.querySelector( '[data-user-scan]' );
	const migrate = root.querySelector( '[data-user-migrate]' );
	const status = root.querySelector( '[data-user-status]' );
	const headings = Array.from( root.querySelectorAll( 'thead th' ), function ( node ) { return node.textContent; } );
	let result = null;
	let busy = false;
	const lock = function ( value ) {
		busy = value;
		scan.disabled = value;
		if ( migrate ) { migrate.disabled = value || ! result || ! result.done || ! result.migration || result.counts.ready === 0; }
		root.querySelector( '[data-user-prev]' ).disabled = value || ! result || result.page <= 1;
		root.querySelector( '[data-user-next]' ).disabled = value || ! result || result.page >= result.pages;
	};
	const request = async function ( operation, extra ) {
		const body = new URLSearchParams( Object.assign( { action: 'anar_user_tools', nonce: config.nonce, operation: operation, token: result ? result.token : '' }, extra || {} ) );
		const response = await fetch( config.url, { method: 'POST', credentials: 'same-origin', body: body } );
		const json = await response.json();
		if ( ! response.ok || ! json.success ) { throw new Error( json.data && json.data.message ? json.data.message : text.failed ); }
		result = json.data;
		return result;
	};
	const render = function () {
		root.querySelector( '[data-user-report]' ).hidden = ! result.done;
		root.querySelectorAll( '[data-user-count]' ).forEach( function ( node ) { node.textContent = result.counts[ node.dataset.userCount ]; } );
		const table = root.querySelector( '[data-user-rows]' );
		table.replaceChildren();
		result.rows.forEach( function ( row ) {
			const tr = document.createElement( 'tr' );
			[ row.name + ' (#' + row.id + ')', row.phone || '—', row.status ].forEach( function ( value, index ) { const td = document.createElement( 'td' ); td.textContent = value; td.dataset.label = headings[index]; tr.appendChild( td ); } );
			const action = document.createElement( 'td' );
			action.dataset.label = headings[3];
			if ( row.url ) { const link = document.createElement( 'a' ); link.href = row.url; link.textContent = text.edit; link.target = '_blank'; link.rel = 'noopener noreferrer'; action.appendChild( link ); }
			tr.appendChild( action ); table.appendChild( tr );
		} );
		if ( result.rows.length === 0 ) { const tr = document.createElement( 'tr' ); const td = document.createElement( 'td' ); td.colSpan = 4; td.textContent = text.empty; tr.appendChild( td ); table.appendChild( tr ); }
		root.querySelector( '[data-user-page]' ).textContent = text.progress + ' ' + result.page + ' / ' + result.pages;
		if ( migrate && ! result.migration ) { root.querySelector( '[data-digits-migration]' ).hidden = true; }
		if ( result.tools === false ) {
			document.querySelectorAll( '.anar-admin__nav a[href], #toplevel_page_anar-login .wp-submenu a[href]' ).forEach( function ( link ) {
				if ( new URL( link.href, window.location.href ).searchParams.get( 'page' ) === 'anar-login-user-tools' ) {
					if ( link.closest( '.wp-submenu' ) ) { link.closest( 'li' ).remove(); }
					else { link.remove(); }
				}
			} );
		}
	};
	const run = async function ( work ) {
		if ( busy ) { return; }
		lock( true );
		status.classList.remove( 'is-error' );
		try { await work(); render(); }
		catch ( error ) { status.textContent = error.message || text.failed; status.classList.add( 'is-error' ); result = null; }
		finally { lock( false ); }
	};
	scan.addEventListener( 'click', function () { run( async function () {
		status.textContent = text.working;
		await request( 'start' );
		while ( ! result.done ) { status.textContent = text.scanning + ' ' + result.scanned; await request( 'scan' ); }
		status.textContent = text.finished;
	} ); } );
	if ( migrate ) { migrate.addEventListener( 'click', function () {
		if ( busy || ! result || ! window.confirm( text.confirm ) ) { return; }
		run( async function () {
			status.textContent = text.importing;
			do { await request( 'apply', { confirmed: '1', offset: String( result.offset ) } ); } while ( ! result.applied );
			status.textContent = text.imported;
		} );
	} ); }
	[ [ '[data-user-prev]', -1 ], [ '[data-user-next]', 1 ] ].forEach( function ( item ) {
		root.querySelector( item[0] ).addEventListener( 'click', function () { run( async function () { await request( 'report', { page: String( result.page + item[1] ) } ); } ); } );
	} );
	lock( false );
}() );
