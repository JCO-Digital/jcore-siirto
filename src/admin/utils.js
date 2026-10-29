import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Formats a byte count for people.
 *
 * @param {number} bytes Byte count.
 * @return {string} Formatted size.
 */
export function formatBytes( bytes ) {
	const units = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
	let value = Number( bytes ) || 0;
	let unit = 0;
	while ( value >= 1024 && unit < units.length - 1 ) {
		value /= 1024;
		unit++;
	}
	return `${ value.toFixed( unit === 0 ? 0 : 1 ) } ${ units[ unit ] }`;
}

/**
 * Formats a UTC MySQL datetime in the browser's locale.
 *
 * @param {string|null} value `YYYY-MM-DD HH:MM:SS` in UTC.
 * @return {string} Local date and time, or a dash.
 */
export function formatDate( value ) {
	if ( ! value ) {
		return '—';
	}
	return new Date( value.replace( ' ', 'T' ) + 'Z' ).toLocaleString();
}

/**
 * Labels for the file groups.
 *
 * @return {Object<string, string>} Root name to label.
 */
export function rootLabels() {
	return {
		themes: __( 'Themes', 'jcore-siirto' ),
		plugins: __( 'Plugins', 'jcore-siirto' ),
		'mu-plugins': __( 'Must-use plugins', 'jcore-siirto' ),
		uploads: __( 'Media library (uploads)', 'jcore-siirto' ),
		content: __(
			'Other wp-content files (languages, fonts…)',
			'jcore-siirto'
		),
		root: __(
			'Static files in the site root (robots.txt, verification files)',
			'jcore-siirto'
		),
	};
}

/**
 * Describes a number of rows.
 *
 * @param {number} count Row count.
 * @return {string} Text.
 */
export function rowsText( count ) {
	return sprintf(
		/* translators: %s: number of rows */
		_n( '%s row', '%s rows', count, 'jcore-siirto' ),
		Number( count ).toLocaleString()
	);
}
