( function () {
	'use strict';

	function lockPublishButtons() {
		var buttons = document.querySelectorAll( '.acf-publish' );

		if ( ! buttons.length ) {
			return;
		}

		buttons.forEach( function ( button ) {
			button.disabled = true;
			button.setAttribute( 'aria-disabled', 'true' );
			button.setAttribute( 'title', fieldLockSyncGuardForAcf.message );
			button.classList.add( 'disabled' );
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', lockPublishButtons );
	} else {
		lockPublishButtons();
	}
}() );
