import { __ } from '@wordpress/i18n';
import { formatDate } from '../utils';

/**
 * The audit log.
 *
 * @param {Object} props
 * @param {Array}  props.entries Log rows.
 * @return {Element} Table.
 */
export default function LogTable( { entries } ) {
	if ( ! entries?.length ) {
		return <p>{ __( 'Nothing logged yet.', 'jcore-siirto' ) }</p>;
	}

	return (
		<table className="widefat striped">
			<thead>
				<tr>
					<th>{ __( 'Time', 'jcore-siirto' ) }</th>
					<th>{ __( 'Event', 'jcore-siirto' ) }</th>
					<th>{ __( 'Details', 'jcore-siirto' ) }</th>
					<th>{ __( 'Key', 'jcore-siirto' ) }</th>
					<th>{ __( 'IP address', 'jcore-siirto' ) }</th>
				</tr>
			</thead>
			<tbody>
				{ entries.map( ( entry ) => (
					<tr key={ entry.id }>
						<td>{ formatDate( entry.created_at ) }</td>
						<td>
							<code>{ entry.event }</code>
						</td>
						<td>{ entry.message }</td>
						<td>
							{ entry.key_id && (
								<code>{ entry.key_id.slice( 0, 8 ) }</code>
							) }
						</td>
						<td>{ entry.ip }</td>
					</tr>
				) ) }
			</tbody>
		</table>
	);
}
