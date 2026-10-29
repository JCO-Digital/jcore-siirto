import { Notice } from '@wordpress/components';

/**
 * Renders the environment notices the server reported.
 *
 * @param {Object} props
 * @param {Array}  props.notices `{ level, message }` items.
 * @return {Element} Notices.
 */
export default function Notices( { notices } ) {
	return ( notices || [] ).map( ( notice, index ) => (
		<Notice
			key={ index }
			status={ notice.level === 'error' ? 'error' : notice.level }
			isDismissible={ false }
		>
			{ notice.message }
		</Notice>
	) );
}
