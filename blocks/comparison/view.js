/**
 * Front-end format switch. Every cell carries all three formats as data attributes, so
 * switching is a text swap: no maths in JS, no request to the server. The visitor's last
 * choice is remembered per browser.
 */
( function () {
	const STORAGE_KEY = 'odds-compare-format';

	const apply = ( root, format ) => {
		root.dataset.format = format;
		root.querySelectorAll( '[data-decimal]' ).forEach( ( cell ) => {
			const text = cell.querySelector( '.odds-compare__odds' );
			if ( text && cell.dataset[ format ] ) {
				text.textContent = cell.dataset[ format ];
			}
		} );
		root.querySelectorAll( '.odds-compare__format button' ).forEach(
			( button ) => {
				button.setAttribute(
					'aria-pressed',
					button.dataset.format === format ? 'true' : 'false'
				);
			}
		);
	};

	const remember = ( format ) => {
		try {
			window.localStorage.setItem( STORAGE_KEY, format );
		} catch {
			// Private mode or storage disabled: the switch still works for this page view.
		}
	};

	const recall = () => {
		try {
			return window.localStorage.getItem( STORAGE_KEY );
		} catch {
			return null;
		}
	};

	document
		.querySelectorAll( '.odds-compare[data-format]' )
		.forEach( ( root ) => {
			const saved = recall();
			if (
				saved &&
				root.querySelector(
					`.odds-compare__format button[data-format="${ saved }"]`
				)
			) {
				apply( root, saved );
			}
			root.addEventListener( 'click', ( event ) => {
				const button = event.target.closest(
					'.odds-compare__format button'
				);
				if ( ! button || ! root.contains( button ) ) {
					return;
				}
				apply( root, button.dataset.format );
				remember( button.dataset.format );
			} );
		} );
} )();
