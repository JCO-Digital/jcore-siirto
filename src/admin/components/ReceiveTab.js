import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	CheckboxControl,
	Notice,
	Spinner,
	TextareaControl,
	TextControl,
} from '@wordpress/components';
import { Fragment, useEffect, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import Notices from './Notices';
import LogTable from './LogTable';
import { formatBytes, formatDuration, rootLabels, rowsText } from '../utils';

const PATH = '/jcore-siirto/v1/receive';

/**
 * File groups that start unticked: most sites don't need them, and the
 * Composer packages are code that replaces this site's own.
 */
const OPT_IN_ROOTS = [ 'root', 'vendor' ];

/**
 * Statuses in which the browser keeps calling the server to make progress.
 */
const LOOPING = {
	scanning: 'step',
	running: 'step',
	cleaning: 'cleanup',
};

export default function ReceiveTab() {
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ paused, setPaused ] = useState( false );
	const [ loggedOut, setLoggedOut ] = useState( false );
	const mounted = useRef( true );

	const call = ( action, body = {} ) => {
		setBusy( true );
		setError( null );
		return apiFetch( {
			path: `${ PATH }/${ action }`,
			method: 'POST',
			data: body,
		} )
			.then( ( result ) => {
				if ( mounted.current ) {
					setData( result );
				}
				return result;
			} )
			.catch( ( e ) => {
				if ( mounted.current ) {
					setError( e.message );
				}
				throw e;
			} )
			.finally( () => mounted.current && setBusy( false ) );
	};

	useEffect( () => {
		mounted.current = true;
		apiFetch( { path: PATH } )
			.then( setData )
			.catch( ( e ) => setError( e.message ) );
		return () => {
			mounted.current = false;
		};
	}, [] );

	const status = data?.job?.status;
	const loopAction = LOOPING[ status ];

	// Keep the migration moving while this screen is open. Each call does a
	// few seconds of work on the server and returns the new state.
	useEffect( () => {
		if ( ! loopAction || paused || error ) {
			return;
		}
		const timer = setTimeout( () => {
			apiFetch( { path: `${ PATH }/${ loopAction }`, method: 'POST' } )
				.then( ( result ) => mounted.current && setData( result ) )
				.catch( ( e ) => mounted.current && setError( e.message ) );
		}, 250 );
		return () => clearTimeout( timer );
	}, [ data, loopAction, paused, error ] );

	if ( loggedOut ) {
		return <Finished data={ data } />;
	}

	if ( ! data ) {
		return error ? (
			<Notice status="error" isDismissible={ false }>
				{ error }
			</Notice>
		) : (
			<Spinner />
		);
	}

	const job = data.job;
	const common = { data, job, call, busy };

	let body;
	if ( ! job ) {
		body = <ConnectCard { ...common } />;
	} else if ( status === 'scanning' ) {
		body = <ScanningCard { ...common } />;
	} else if ( status === 'connected' ) {
		body = <OptionsCard { ...common } />;
	} else if ( status === 'running' || status === 'cleaning' ) {
		body = (
			<ProgressCard
				{ ...common }
				paused={ paused }
				setPaused={ setPaused }
			/>
		);
	} else if ( status === 'failed' && ! job.finalize_started ) {
		body = <FailedCard { ...common } />;
	} else if (
		status === 'ready' ||
		status === 'failed' ||
		status === 'finalizing'
	) {
		body = (
			<ConfirmCard
				{ ...common }
				onDone={ ( result ) => {
					if (
						result?.job?.status === 'done' &&
						result.job.options?.database
					) {
						setLoggedOut( true );
					}
				} }
			/>
		);
	} else {
		body = <AfterCard { ...common } />;
	}

	return (
		<>
			<Notices notices={ data.checks.notices } />
			{ error && (
				<Notice status="error" onRemove={ () => setError( null ) }>
					{ error }
				</Notice>
			) }
			{ body }
			<Card>
				<CardHeader>
					<h2>{ __( 'Activity', 'jcore-siirto' ) }</h2>
				</CardHeader>
				<CardBody>
					<LogTable entries={ data.log } />
				</CardBody>
			</Card>
		</>
	);
}

function ConnectCard( { call, busy } ) {
	const [ key, setKey ] = useState( '' );

	return (
		<Card>
			<CardHeader>
				<h2>{ __( 'Connect to the source site', 'jcore-siirto' ) }</h2>
			</CardHeader>
			<CardBody>
				<p>
					{ __(
						'On the site you are moving from, install JCORE Siirto, open Tools → Site migration → Send this site, and create a key. Paste it here.',
						'jcore-siirto'
					) }
				</p>
				<p>
					{ __(
						'Connecting and copying do not change this site. Nothing is replaced until you confirm at the end.',
						'jcore-siirto'
					) }
				</p>
				<TextareaControl
					__nextHasNoMarginBottom
					className="jcore-siirto-key"
					label={ __( 'Migration key', 'jcore-siirto' ) }
					value={ key }
					rows={ 4 }
					autoComplete="off"
					spellCheck={ false }
					onChange={ setKey }
				/>
				<div className="jcore-siirto-actions">
					<Button
						variant="primary"
						isBusy={ busy }
						disabled={ busy || ! key.trim() }
						onClick={ () =>
							call( 'connect', { key: key.trim() } )
								.then( () => setKey( '' ) )
								.catch( () => {} )
						}
					>
						{ __( 'Connect', 'jcore-siirto' ) }
					</Button>
				</div>
			</CardBody>
		</Card>
	);
}

function SourceFacts( { source } ) {
	const tables = Object.values( source.tables || {} );
	const dbBytes = tables.reduce( ( sum, table ) => sum + table.bytes, 0 );

	return (
		<dl className="jcore-siirto-facts">
			<dt>{ __( 'Site', 'jcore-siirto' ) }</dt>
			<dd>
				{ source.name } — { source.home }
			</dd>
			<dt>{ __( 'WordPress', 'jcore-siirto' ) }</dt>
			<dd>{ source.wp }</dd>
			<dt>{ __( 'PHP', 'jcore-siirto' ) }</dt>
			<dd>{ source.php }</dd>
			<dt>{ __( 'Database server', 'jcore-siirto' ) }</dt>
			<dd>{ source.db }</dd>
			{ tables.length > 0 && (
				<>
					<dt>{ __( 'Database', 'jcore-siirto' ) }</dt>
					<dd>
						{ sprintf(
							/* translators: 1: number of tables, 2: size */
							__( '%1$d tables, about %2$s', 'jcore-siirto' ),
							tables.length,
							formatBytes( dbBytes )
						) }
					</dd>
				</>
			) }
		</dl>
	);
}

function OptionsCard( { data, job, call, busy } ) {
	const source = job.source;
	const labels = rootLabels();
	const canFiles = data.checks.files;
	const available = canFiles ? source.roots : [];
	const entries = scanEntries( job.scan );
	const [ selected, setSelected ] = useState( () =>
		Object.fromEntries(
			available.map( ( root ) => [
				root,
				OPT_IN_ROOTS.includes( root )
					? []
					: ( entries[ root ] || [] ).map( ( entry ) => entry.name ),
			] )
		)
	);
	const roots = available.filter(
		( root ) => ( selected[ root ] || [] ).length > 0
	);
	const skip = Object.fromEntries(
		roots.map( ( root ) => [
			root,
			( entries[ root ] || [] )
				.map( ( entry ) => entry.name )
				.filter( ( name ) => ! selected[ root ].includes( name ) ),
		] )
	);
	const chosen = roots.flatMap( ( root ) =>
		( entries[ root ] || [] ).filter( ( entry ) =>
			selected[ root ].includes( entry.name )
		)
	);
	const chosenFiles = chosen.reduce( ( sum, entry ) => sum + entry.files, 0 );
	const chosenBytes = chosen.reduce( ( sum, entry ) => sum + entry.bytes, 0 );
	const dbBytes = Object.values( source.tables || {} ).reduce(
		( sum, table ) => sum + table.bytes,
		0
	);
	const [ database, setDatabase ] = useState(
		data.checks.database && source.scopes.includes( 'database' )
	);
	const [ skipExecutables, setSkipExecutables ] = useState( true );
	const [ skipTransients, setSkipTransients ] = useState( true );
	const [ exclude, setExclude ] = useState( '' );

	return (
		<Card>
			<CardHeader>
				<h2>{ __( 'Choose what to migrate', 'jcore-siirto' ) }</h2>
			</CardHeader>
			<CardBody>
				<SourceFacts source={ source } />

				{ source.wp !== data.wp_version && (
					<Notice status="info" isDismissible={ false }>
						{ __(
							'The two sites run different WordPress versions. That is fine, but WordPress may ask to update the database after the migration.',
							'jcore-siirto'
						) }
					</Notice>
				) }

				<h3>{ __( 'Files', 'jcore-siirto' ) }</h3>
				{ available.length === 0 && (
					<p>
						{ __(
							'Files cannot be migrated: either this site cannot write files, or the key does not allow it.',
							'jcore-siirto'
						) }
					</p>
				) }
				{ available.map( ( root ) => (
					<RootChecklist
						key={ root }
						label={ labels[ root ] }
						help={
							root === 'vendor'
								? __(
										'Needed when the theme loads packages such as Timber from the vendor folder in the site root. Packages in the source replace the same packages here.',
										'jcore-siirto'
								  )
								: undefined
						}
						entries={ entries[ root ] || [] }
						selected={ selected[ root ] || [] }
						onChange={ ( names ) =>
							setSelected( { ...selected, [ root ]: names } )
						}
					/>
				) ) }
				{ roots.includes( 'vendor' ) && (
					<Notice status="warning" isDismissible={ false }>
						{ sprintf(
							/* translators: 1: source PHP version, 2: this site's PHP version */
							__(
								'Composer packages are PHP code that will run on this site. Only copy them from a site you trust. They were installed for PHP %1$s; this site runs PHP %2$s, so check that they support it.',
								'jcore-siirto'
							),
							source.php,
							data.php_version
						) }
					</Notice>
				) }
				{ available.length > 0 && (
					<>
						<CheckboxControl
							__nextHasNoMarginBottom
							label={ __(
								'Skip PHP and other executable files in uploads and other wp-content files (recommended)',
								'jcore-siirto'
							) }
							help={ __(
								'Media folders should never contain code. Executable files there are a common sign of a hacked site.',
								'jcore-siirto'
							) }
							checked={ skipExecutables }
							onChange={ setSkipExecutables }
						/>
						<TextareaControl
							__nextHasNoMarginBottom
							label={ __(
								'Also skip (one pattern per line)',
								'jcore-siirto'
							) }
							help={ __(
								'Shell patterns matched against e.g. uploads/2019/photo.jpg or the file name: uploads/2015/*, *.zip',
								'jcore-siirto'
							) }
							value={ exclude }
							rows={ 3 }
							onChange={ setExclude }
						/>
					</>
				) }

				<h3>{ __( 'Database', 'jcore-siirto' ) }</h3>
				<CheckboxControl
					__nextHasNoMarginBottom
					label={ __(
						'Replace this site’s database with the source’s',
						'jcore-siirto'
					) }
					help={ __(
						'Content, settings and users all come from the source. Addresses and paths are rewritten for this site.',
						'jcore-siirto'
					) }
					disabled={
						! data.checks.database ||
						! source.scopes.includes( 'database' )
					}
					checked={ database }
					onChange={ setDatabase }
				/>
				{ database && (
					<CheckboxControl
						__nextHasNoMarginBottom
						label={ __(
							'Skip transients (temporary cached data)',
							'jcore-siirto'
						) }
						checked={ skipTransients }
						onChange={ setSkipTransients }
					/>
				) }

				<p className="jcore-siirto-selection">
					<strong>{ __( 'Selected:', 'jcore-siirto' ) }</strong>{ ' ' }
					{ sprintf(
						/* translators: 1: number of files, 2: size of the files */
						_n(
							'%1$s file, %2$s',
							'%1$s files, %2$s',
							chosenFiles,
							'jcore-siirto'
						),
						chosenFiles.toLocaleString(),
						formatBytes( chosenBytes )
					) }
					{ database &&
						' + ' +
							sprintf(
								/* translators: %s: database size */
								__( 'database, about %s', 'jcore-siirto' ),
								formatBytes( dbBytes )
							) }
				</p>
				{ data.checks.free_bytes !== null &&
					chosenBytes + ( database ? dbBytes : 0 ) >
						data.checks.free_bytes * 0.9 && (
						<Notice status="warning" isDismissible={ false }>
							{ sprintf(
								/* translators: %s: free disk space */
								__(
									'This server has only %s of free disk space, which may not be enough. Copied data is kept next to the current site until you clean up.',
									'jcore-siirto'
								),
								formatBytes( data.checks.free_bytes )
							) }
						</Notice>
					) }

				<div className="jcore-siirto-actions">
					<Button
						variant="primary"
						isBusy={ busy }
						disabled={
							busy || ( roots.length === 0 && ! database )
						}
						onClick={ () =>
							call( 'start', {
								roots,
								skip,
								database,
								skip_executables: skipExecutables,
								skip_transients: skipTransients,
								exclude,
							} ).catch( () => {} )
						}
					>
						{ __( 'Start copying', 'jcore-siirto' ) }
					</Button>
					<Button
						variant="tertiary"
						disabled={ busy }
						onClick={ () => call( 'cleanup' ).catch( () => {} ) }
					>
						{ __( 'Cancel', 'jcore-siirto' ) }
					</Button>
				</div>
			</CardBody>
		</Card>
	);
}

/**
 * Turns the scan result into sorted entry lists per root.
 *
 * @param {Object|null} scan Root => { name: [ files, bytes, isDir ] }.
 * @return {Object} Root => [ { name, files, bytes, isDir } ], largest first.
 */
function scanEntries( scan ) {
	return Object.fromEntries(
		Object.entries( scan || {} ).map( ( [ root, items ] ) => [
			root,
			Object.entries( items || {} )
				.map( ( [ name, [ files, bytes, isDir ] ] ) => ( {
					name,
					files,
					bytes,
					isDir,
				} ) )
				.sort( ( a, b ) => b.bytes - a.bytes ),
		] )
	);
}

function RootChecklist( { label, help, entries, selected, onChange } ) {
	const names = entries.map( ( entry ) => entry.name );
	const files = entries.reduce( ( sum, entry ) => sum + entry.files, 0 );
	const bytes = entries.reduce( ( sum, entry ) => sum + entry.bytes, 0 );
	const all = names.length > 0 && selected.length === names.length;
	const some = selected.length > 0 && ! all;

	const toggle = ( name, on ) =>
		onChange(
			on
				? [ ...selected, name ]
				: selected.filter( ( item ) => item !== name )
		);

	return (
		<div className="jcore-siirto-root">
			<CheckboxControl
				__nextHasNoMarginBottom
				label={ sprintf(
					/* translators: 1: file group, 2: size, 3: number of files */
					_n(
						'%1$s — %2$s, %3$s file',
						'%1$s — %2$s, %3$s files',
						files,
						'jcore-siirto'
					),
					label,
					formatBytes( bytes ),
					files.toLocaleString()
				) }
				help={ help }
				checked={ all }
				indeterminate={ some }
				disabled={ names.length === 0 }
				onChange={ ( on ) => onChange( on ? names : [] ) }
			/>
			{ names.length > 1 && (
				<details className="jcore-siirto-entries">
					<summary>
						{ sprintf(
							/* translators: 1: selected entries, 2: all entries */
							__( '%1$d of %2$d selected', 'jcore-siirto' ),
							selected.length,
							names.length
						) }
					</summary>
					<ul>
						{ entries.map( ( entry ) => (
							<li key={ entry.name }>
								<CheckboxControl
									__nextHasNoMarginBottom
									label={ sprintf(
										/* translators: 1: folder or file name, 2: size, 3: number of files */
										_n(
											'%1$s — %2$s, %3$s file',
											'%1$s — %2$s, %3$s files',
											entry.files,
											'jcore-siirto'
										),
										entry.name + ( entry.isDir ? '/' : '' ),
										formatBytes( entry.bytes ),
										entry.files.toLocaleString()
									) }
									checked={ selected.includes( entry.name ) }
									onChange={ ( on ) =>
										toggle( entry.name, on )
									}
								/>
							</li>
						) ) }
					</ul>
				</details>
			) }
		</div>
	);
}

function ScanningCard( { job, call, busy } ) {
	const labels = rootLabels();
	const entries = scanEntries( job.scan );
	const waiting = job.scanning || [];

	return (
		<Card>
			<CardHeader>
				<h2>{ __( 'Scanning the source site…', 'jcore-siirto' ) }</h2>
			</CardHeader>
			<CardBody>
				<p>
					{ __(
						'Counting files and sizes so you can choose what to copy and see how long it will take.',
						'jcore-siirto'
					) }
				</p>
				<dl className="jcore-siirto-facts">
					{ ( job.source?.roots || [] ).map( ( root ) => {
						const items = entries[ root ] || [];
						const files = items.reduce(
							( sum, entry ) => sum + entry.files,
							0
						);
						const bytes = items.reduce(
							( sum, entry ) => sum + entry.bytes,
							0
						);
						const done = ! waiting.includes( root );
						return (
							<Fragment key={ root }>
								<dt>{ labels[ root ] }</dt>
								<dd>
									{ ! done && waiting[ 0 ] !== root
										? __( 'waiting', 'jcore-siirto' )
										: sprintf(
												/* translators: 1: number of files, 2: size */
												__(
													'%1$s files, %2$s',
													'jcore-siirto'
												),
												files.toLocaleString(),
												formatBytes( bytes )
										  ) + ( done ? '' : '…' ) }
								</dd>
							</Fragment>
						);
					} ) }
				</dl>
				{ job.current && (
					<p>
						<code>{ job.current }</code>
					</p>
				) }
				<Spinner />
				<div className="jcore-siirto-actions">
					<Button
						variant="tertiary"
						disabled={ busy }
						onClick={ () => call( 'cleanup' ).catch( () => {} ) }
					>
						{ __( 'Cancel', 'jcore-siirto' ) }
					</Button>
				</div>
			</CardBody>
		</Card>
	);
}

function ProgressBar( { fraction } ) {
	const percent = Math.round( Math.min( 1, fraction || 0 ) * 1000 ) / 10;

	return (
		<div
			className="jcore-siirto-bar"
			role="progressbar"
			aria-valuemin={ 0 }
			aria-valuemax={ 100 }
			aria-valuenow={ percent }
		>
			<div style={ { width: `${ percent }%` } } />
			<span>{ percent.toFixed( 1 ) } %</span>
		</div>
	);
}

function Stats( { job } ) {
	const stats = job.stats || {};
	const progress = job.progress || {};

	return (
		<dl className="jcore-siirto-facts">
			{ job.options?.roots?.length > 0 && (
				<>
					<dt>{ __( 'Files', 'jcore-siirto' ) }</dt>
					<dd>
						{ sprintf(
							/* translators: 1: files done, 2: files in total, 3: bytes done, 4: bytes in total */
							__( '%1$s of %2$s (%3$s of %4$s)', 'jcore-siirto' ),
							( progress.files_done || 0 ).toLocaleString(),
							( progress.files_total || 0 ).toLocaleString(),
							formatBytes( progress.bytes_done || 0 ),
							formatBytes( progress.bytes_total || 0 )
						) }
					</dd>
				</>
			) }
			{ job.options?.database && (
				<>
					<dt>{ __( 'Database', 'jcore-siirto' ) }</dt>
					<dd>
						{ sprintf(
							/* translators: 1: tables done, 2: total tables, 3: rows copied */
							__( '%1$d of %2$d tables, %3$s', 'jcore-siirto' ),
							progress.tables_done || 0,
							progress.tables_total || 0,
							rowsText( stats.rows || 0 )
						) }
					</dd>
				</>
			) }
			{ stats.skipped > 0 && (
				<>
					<dt>{ __( 'Skipped', 'jcore-siirto' ) }</dt>
					<dd>{ stats.skipped.toLocaleString() }</dd>
				</>
			) }
		</dl>
	);
}

function ProgressCard( { job, call, busy, paused, setPaused } ) {
	const cleaning = job.status === 'cleaning';
	const progress = job.progress || {};

	let timing = __( 'Estimating time left…', 'jcore-siirto' );
	if ( progress.eta !== null && progress.eta !== undefined ) {
		timing = sprintf(
			/* translators: 1: time left, 2: transfer speed, 3: time so far */
			__( 'About %1$s left · %2$s/s · %3$s so far', 'jcore-siirto' ),
			formatDuration( progress.eta ),
			formatBytes( progress.bytes_per_s ),
			formatDuration( progress.seconds )
		);
	}

	return (
		<Card>
			<CardHeader>
				<h2>
					{ cleaning
						? __( 'Cleaning up…', 'jcore-siirto' )
						: __( 'Copying…', 'jcore-siirto' ) }
				</h2>
			</CardHeader>
			<CardBody>
				{ ! cleaning && (
					<>
						<p>
							{ __(
								'Keep this page open. If you close it, the copy pauses and continues when you come back.',
								'jcore-siirto'
							) }
						</p>
						<ProgressBar fraction={ progress.fraction } />
						<p className="jcore-siirto-timing">
							{ paused ? __( 'Paused', 'jcore-siirto' ) : timing }
						</p>
						<Stats job={ job } />
						{ job.current && (
							<p>
								<code>{ job.current }</code>
							</p>
						) }
						{ job.error && (
							<Notice status="warning" isDismissible={ false }>
								{ sprintf(
									/* translators: %s: error message */
									__(
										'Retrying after an error: %s',
										'jcore-siirto'
									),
									job.error
								) }
							</Notice>
						) }
					</>
				) }
				{ ! paused && <Spinner /> }
				<div className="jcore-siirto-actions">
					{ ! cleaning && (
						<Button
							variant="secondary"
							onClick={ () => setPaused( ! paused ) }
						>
							{ paused
								? __( 'Continue', 'jcore-siirto' )
								: __( 'Pause', 'jcore-siirto' ) }
						</Button>
					) }
					{ ! cleaning && (
						<Button
							variant="tertiary"
							isDestructive
							disabled={ busy }
							onClick={ () => {
								setPaused( true );
								call( 'cleanup' )
									.catch( () => {} )
									.finally( () => setPaused( false ) );
							} }
						>
							{ __(
								'Cancel and delete copied data',
								'jcore-siirto'
							) }
						</Button>
					) }
				</div>
			</CardBody>
		</Card>
	);
}

function FailedCard( { job, call, busy } ) {
	return (
		<Card>
			<CardHeader>
				<h2>{ __( 'Copying stopped', 'jcore-siirto' ) }</h2>
			</CardHeader>
			<CardBody>
				<Notice status="error" isDismissible={ false }>
					{ job.error }
				</Notice>
				<Stats job={ job } />
				<p>
					{ __(
						'This site has not been changed. You can continue where the copy stopped, or cancel.',
						'jcore-siirto'
					) }
				</p>
				<div className="jcore-siirto-actions">
					<Button
						variant="primary"
						disabled={ busy }
						onClick={ () => call( 'resume' ).catch( () => {} ) }
					>
						{ __( 'Try again', 'jcore-siirto' ) }
					</Button>
					<Button
						variant="tertiary"
						isDestructive
						disabled={ busy }
						onClick={ () => call( 'cleanup' ).catch( () => {} ) }
					>
						{ __(
							'Cancel and delete copied data',
							'jcore-siirto'
						) }
					</Button>
				</div>
			</CardBody>
		</Card>
	);
}

function Skipped( { job } ) {
	if ( ! job.skipped?.length ) {
		return null;
	}

	return (
		<details>
			<summary>
				{ sprintf(
					/* translators: %d: number of skipped items */
					__( '%d items were skipped', 'jcore-siirto' ),
					job.stats?.skipped || job.skipped.length
				) }
			</summary>
			<div className="jcore-siirto-skipped">
				<table className="widefat striped">
					<tbody>
						{ job.skipped.map( ( [ what, why ], index ) => (
							<tr key={ index }>
								<td>
									<code>{ what }</code>
								</td>
								<td>{ why }</td>
							</tr>
						) ) }
					</tbody>
				</table>
			</div>
		</details>
	);
}

function ConfirmCard( { data, job, call, busy, onDone } ) {
	const [ confirm, setConfirm ] = useState( '' );
	const [ password, setPassword ] = useState( '' );
	const host = data.site.host;
	const retrying = job.status !== 'ready';

	return (
		<Card className="jcore-siirto-danger">
			<CardHeader>
				<h2>
					{ retrying
						? __(
								'Replacing this site did not finish',
								'jcore-siirto'
						  )
						: __( 'Ready to replace this site', 'jcore-siirto' ) }
				</h2>
			</CardHeader>
			<CardBody>
				{ retrying && job.error && (
					<Notice status="error" isDismissible={ false }>
						{ job.error }
					</Notice>
				) }
				<Stats job={ job } />
				<Skipped job={ job } />
				<p>
					{ sprintf(
						/* translators: 1: source site URL, 2: this site's URL */
						__(
							'Everything selected has been copied from %1$s. Completing the migration replaces the matching files and the database of %2$s.',
							'jcore-siirto'
						),
						job.source_url,
						data.site.home
					) }
				</p>
				{ job.options?.database && (
					<Notice status="warning" isDismissible={ false }>
						{ __(
							'The database includes user accounts. You will be logged out, and must log in with an account from the source site.',
							'jcore-siirto'
						) }
					</Notice>
				) }
				<p>
					{ __(
						'Replaced files and tables are kept until you clean up, so the migration can be rolled back.',
						'jcore-siirto'
					) }
				</p>
				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ sprintf(
						/* translators: %s: this site's host name */
						__( 'Type %s to confirm', 'jcore-siirto' ),
						host
					) }
					value={ confirm }
					autoComplete="off"
					onChange={ setConfirm }
				/>
				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					type="password"
					autoComplete="current-password"
					label={ __( 'Your password', 'jcore-siirto' ) }
					value={ password }
					onChange={ setPassword }
				/>
				<div className="jcore-siirto-actions">
					<Button
						variant="primary"
						isDestructive
						isBusy={ busy }
						disabled={
							busy ||
							! password ||
							confirm.trim().toLowerCase() !== host.toLowerCase()
						}
						onClick={ () =>
							call( 'finalize', { confirm, password } )
								.then( onDone )
								.catch( () => {} )
						}
					>
						{ retrying
							? __( 'Try again', 'jcore-siirto' )
							: __( 'Replace this site', 'jcore-siirto' ) }
					</Button>
					{ retrying ? (
						<RollbackButton call={ call } busy={ busy } />
					) : (
						<Button
							variant="tertiary"
							disabled={ busy }
							onClick={ () =>
								call( 'cleanup' ).catch( () => {} )
							}
						>
							{ __(
								'Cancel and delete copied data',
								'jcore-siirto'
							) }
						</Button>
					) }
				</div>
			</CardBody>
		</Card>
	);
}

function RollbackButton( { call, busy } ) {
	const [ open, setOpen ] = useState( false );
	const [ password, setPassword ] = useState( '' );

	if ( ! open ) {
		return (
			<Button
				variant="secondary"
				disabled={ busy }
				onClick={ () => setOpen( true ) }
			>
				{ __( 'Roll back', 'jcore-siirto' ) }
			</Button>
		);
	}

	return (
		<>
			<TextControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				type="password"
				autoComplete="current-password"
				label={ __( 'Your password', 'jcore-siirto' ) }
				value={ password }
				onChange={ setPassword }
			/>
			<Button
				variant="secondary"
				isBusy={ busy }
				disabled={ busy || ! password }
				onClick={ () =>
					call( 'rollback', { password } ).catch( () => {} )
				}
			>
				{ __( 'Roll back now', 'jcore-siirto' ) }
			</Button>
		</>
	);
}

function AfterCard( { job, call, busy } ) {
	const done = job.status === 'done';

	return (
		<Card>
			<CardHeader>
				<h2>
					{ done
						? __( 'Migration complete', 'jcore-siirto' )
						: __( 'Migration rolled back', 'jcore-siirto' ) }
				</h2>
			</CardHeader>
			<CardBody>
				<Stats job={ job } />
				<Skipped job={ job } />
				<p>
					{ done
						? __(
								'Check the site. If something is wrong, roll back. When you are happy, clean up to delete the previous files and tables; after that, the migration can no longer be rolled back.',
								'jcore-siirto'
						  )
						: __(
								'This site is as it was before. Clean up to delete the copied data.',
								'jcore-siirto'
						  ) }
				</p>
				<div className="jcore-siirto-actions">
					<Button
						variant="primary"
						disabled={ busy }
						onClick={ () => call( 'cleanup' ).catch( () => {} ) }
					>
						{ __( 'Clean up', 'jcore-siirto' ) }
					</Button>
					{ done && <RollbackButton call={ call } busy={ busy } /> }
				</div>
			</CardBody>
		</Card>
	);
}

function Finished( { data } ) {
	return (
		<Card>
			<CardHeader>
				<h2>{ __( 'Migration complete', 'jcore-siirto' ) }</h2>
			</CardHeader>
			<CardBody>
				<p>
					{ __(
						'This site now runs the migrated copy. If the database was migrated, your session has ended: log in with an account from the source site, then return here to check the result and clean up.',
						'jcore-siirto'
					) }
				</p>
				<div className="jcore-siirto-actions">
					<Button variant="primary" href={ data?.login_url }>
						{ __( 'Log in', 'jcore-siirto' ) }
					</Button>
				</div>
			</CardBody>
		</Card>
	);
}
