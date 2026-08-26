( function () {
	'use strict';

	function lockPublishButtons() {
		var buttons = document.querySelectorAll( '.acf-publish' );

		if ( ! buttons.length ) {
			return;
		}

		buttons.forEach( function ( button ) {
			var panel;
			var heading;
			var message;
			var list;
			var action;

			button.disabled = true;
			button.setAttribute( 'aria-disabled', 'true' );
			button.setAttribute( 'title', fieldLockSyncGuardForAcf.message );
			button.classList.add( 'disabled' );

			if ( document.querySelector( '.fieldlock-sync-guard-message' ) ) {
				return;
			}

			panel = document.createElement( 'div' );
			panel.className = 'notice notice-warning inline fieldlock-sync-guard-message';

			heading = document.createElement( 'p' );
			heading.appendChild( document.createElement( 'strong' ) );
			heading.firstChild.textContent = fieldLockSyncGuardForAcf.title;
			panel.appendChild( heading );

			message = document.createElement( 'p' );
			message.textContent = fieldLockSyncGuardForAcf.message;
			panel.appendChild( message );

			if ( fieldLockSyncGuardForAcf.pendingGroups.length ) {
				list = document.createElement( 'ul' );

				fieldLockSyncGuardForAcf.pendingGroups.forEach( function ( group ) {
					var item = document.createElement( 'li' );
					var name = document.createElement( 'strong' );

					name.textContent = group.title;
					item.appendChild( name );
					item.appendChild( document.createTextNode( ' - ' + group.reason ) );
					list.appendChild( item );
				} );

				panel.appendChild( list );
			}

			action = document.createElement( 'a' );
			action.className = 'button button-secondary';
			action.href = fieldLockSyncGuardForAcf.syncUrl;
			action.textContent = fieldLockSyncGuardForAcf.actionLabel;
			panel.appendChild( action );

			button.parentNode.insertBefore( panel, button );
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', lockPublishButtons );
	} else {
		lockPublishButtons();
	}
}() );
