/**
 * Settings screen helpers: bookmaker select all/none and the live quota cost hint.
 */
( function () {
	document.addEventListener( 'DOMContentLoaded', function () {
		document.querySelectorAll( '.odds-compare-select' ).forEach( function ( link ) {
			link.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				var on = link.dataset.select === 'all';
				document.querySelectorAll( '.odds-compare-bookmaker' ).forEach( function ( box ) {
					box.checked = on;
				} );
			} );
		} );

		var hint = document.querySelector( '[data-odds-compare-cost]' );
		var markets = document.querySelectorAll( '.odds-compare-market' );
		if ( ! hint || ! markets.length ) {
			return;
		}
		var update = function () {
			var count = 0;
			markets.forEach( function ( box ) {
				if ( box.checked ) {
					count++;
				}
			} );
			hint.textContent = hint.dataset.oddsCompareCost.replace( '%d', String( count ) );
		};
		markets.forEach( function ( box ) {
			box.addEventListener( 'change', update );
		} );
	} );
} )();
