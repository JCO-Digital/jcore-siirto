import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	CheckboxControl,
	Notice,
	SelectControl,
	Spinner,
	TextareaControl,
	TextControl,
} from '@wordpress/components';
import { useCopyToClipboard } from '@wordpress/compose';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import Notices from './Notices';
import LogTable from './LogTable';
import { formatDate } from '../utils';

const PATH = '/jcore-siirto/v1/send';

const STATUS_LABELS = {
	active: __( 'Active', 'jcore-siirto' ),
	expired: __( 'Expired', 'jcore-siirto' ),
	revoked: __( 'Revoked', 'jcore-siirto' ),
	locked: __( 'Locked (too many bad requests)', 'jcore-siirto' ),
};

export default function SendTab() {
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ hours, setHours ] = useState( '4' );
	const [ files, setFiles ] = useState( true );
	const [ database, setDatabase ] = useState( true );
	const [ password, setPassword ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ newKey, setNewKey ] = useState( null );
	const [ copied, setCopied ] = useState( false );
	const copyRef = useCopyToClipboard( newKey || '', () => setCopied( true ) );

	const load = () =>
		apiFetch( { path: PATH } )
			.then( setData )
			.catch( ( e ) => setError( e.message ) );

	useEffect( () => {
		load();
	}, [] );

	const createKey = () => {
		setBusy( true );
		setError( null );
		apiFetch( {
			path: `${ PATH }/keys`,
			method: 'POST',
			data: {
				hours: Number( hours ),
				scopes: [
					...( files ? [ 'files' ] : [] ),
					...( database ? [ 'database' ] : [] ),
				],
				password,
			},
		} )
			.then( ( result ) => {
				setNewKey( result.key );
				setCopied( false );
				setPassword( '' );
				return load();
			} )
			.catch( ( e ) => setError( e.message ) )
			.finally( () => setBusy( false ) );
	};

	const revoke = ( id ) => {
		setError( null );
		apiFetch( { path: `${ PATH }/keys/${ id }`, method: 'DELETE' } )
			.then( setData )
			.catch( ( e ) => setError( e.message ) );
	};

	if ( ! data ) {
		return error ? (
			<Notice status="error" isDismissible={ false }>
				{ error }
			</Notice>
		) : (
			<Spinner />
		);
	}

	return (
		<>
			<Notices notices={ data.checks.notices } />
			{ error && (
				<Notice status="error" onRemove={ () => setError( null ) }>
					{ error }
				</Notice>
			) }

			<Card>
				<CardHeader>
					<h2>{ __( 'Create a migration key', 'jcore-siirto' ) }</h2>
				</CardHeader>
				<CardBody>
					<p>
						{ __(
							'A migration key lets another WordPress site with JCORE Siirto copy this site. Paste it into “Receive a site here” on the site you are moving to. Nothing on this site is changed.',
							'jcore-siirto'
						) }
					</p>
					<SelectControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Valid for', 'jcore-siirto' ) }
						value={ hours }
						options={ [
							{
								value: '1',
								label: __( '1 hour', 'jcore-siirto' ),
							},
							{
								value: '4',
								label: __( '4 hours', 'jcore-siirto' ),
							},
							{
								value: '24',
								label: __( '24 hours', 'jcore-siirto' ),
							},
							{
								value: '72',
								label: __( '3 days', 'jcore-siirto' ),
							},
						] }
						onChange={ setHours }
					/>
					<p>
						<strong>
							{ __( 'Allow copying', 'jcore-siirto' ) }
						</strong>
					</p>
					<CheckboxControl
						__nextHasNoMarginBottom
						label={ __(
							'Files: themes, plugins, media and other wp-content files',
							'jcore-siirto'
						) }
						checked={ files }
						onChange={ setFiles }
					/>
					<CheckboxControl
						__nextHasNoMarginBottom
						label={ __(
							'Database: content, settings and user accounts',
							'jcore-siirto'
						) }
						checked={ database }
						onChange={ setDatabase }
					/>
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						type="password"
						autoComplete="current-password"
						label={ __( 'Your password', 'jcore-siirto' ) }
						help={ __(
							'Required because the key gives access to the whole site.',
							'jcore-siirto'
						) }
						value={ password }
						onChange={ setPassword }
					/>
					<div className="jcore-siirto-actions">
						<Button
							variant="primary"
							isBusy={ busy }
							disabled={
								busy ||
								! password ||
								( ! files && ! database ) ||
								! data.checks.ok
							}
							onClick={ createKey }
						>
							{ __( 'Create key', 'jcore-siirto' ) }
						</Button>
					</div>
				</CardBody>
			</Card>

			{ newKey && (
				<Card className="jcore-siirto-danger">
					<CardHeader>
						<h2>{ __( 'Your migration key', 'jcore-siirto' ) }</h2>
					</CardHeader>
					<CardBody>
						<Notice status="warning" isDismissible={ false }>
							{ __(
								'This key is shown only once. Anyone who has it can download this entire site, including the database with user accounts, until it expires or is used. Do not send it by email or chat; paste it straight into the target site.',
								'jcore-siirto'
							) }
						</Notice>
						<TextareaControl
							__nextHasNoMarginBottom
							className="jcore-siirto-key"
							label={ __( 'Key', 'jcore-siirto' ) }
							hideLabelFromVision
							value={ newKey }
							readOnly
							rows={ 4 }
							onFocus={ ( event ) => event.target.select() }
						/>
						<div className="jcore-siirto-actions">
							<Button variant="secondary" ref={ copyRef }>
								{ copied
									? __( 'Copied', 'jcore-siirto' )
									: __( 'Copy key', 'jcore-siirto' ) }
							</Button>
							<Button
								variant="tertiary"
								onClick={ () => setNewKey( null ) }
							>
								{ __( 'Hide', 'jcore-siirto' ) }
							</Button>
						</div>
					</CardBody>
				</Card>
			) }

			<Card>
				<CardHeader>
					<h2>{ __( 'Keys', 'jcore-siirto' ) }</h2>
				</CardHeader>
				<CardBody>
					{ data.keys.length === 0 ? (
						<p>{ __( 'No keys yet.', 'jcore-siirto' ) }</p>
					) : (
						<table className="widefat striped">
							<thead>
								<tr>
									<th>{ __( 'Key', 'jcore-siirto' ) }</th>
									<th>{ __( 'Allows', 'jcore-siirto' ) }</th>
									<th>{ __( 'Status', 'jcore-siirto' ) }</th>
									<th>{ __( 'Expires', 'jcore-siirto' ) }</th>
									<th>
										{ __( 'Last used', 'jcore-siirto' ) }
									</th>
									<th></th>
								</tr>
							</thead>
							<tbody>
								{ data.keys.map( ( key ) => (
									<tr key={ key.id }>
										<td>
											<code>
												{ key.id.slice( 0, 8 ) }
											</code>
										</td>
										<td>{ key.scopes.join( ', ' ) }</td>
										<td>
											{ STATUS_LABELS[ key.status ] }
											{ key.status === 'active' &&
												key.bound &&
												' · ' +
													__(
														'in use',
														'jcore-siirto'
													) }
										</td>
										<td>
											{ formatDate( key.expires_at ) }
										</td>
										<td>
											{ formatDate( key.last_used_at ) }
											{ key.last_ip &&
												` (${ key.last_ip })` }
										</td>
										<td>
											{ key.status === 'active' && (
												<Button
													variant="link"
													isDestructive
													onClick={ () =>
														revoke( key.id )
													}
												>
													{ __(
														'Revoke',
														'jcore-siirto'
													) }
												</Button>
											) }
										</td>
									</tr>
								) ) }
							</tbody>
						</table>
					) }
				</CardBody>
			</Card>

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
