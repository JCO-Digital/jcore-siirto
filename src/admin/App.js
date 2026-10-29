import { TabPanel } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import SendTab from './components/SendTab';
import ReceiveTab from './components/ReceiveTab';

const TAB_NAMES = [ 'send', 'receive' ];

function getTabFromUrl() {
	const tab = new URLSearchParams( window.location.search ).get( 'tab' );
	return TAB_NAMES.includes( tab ) ? tab : 'send';
}

function setTabInUrl( tabName ) {
	const url = new URL( window.location.href );
	url.searchParams.set( 'tab', tabName );
	window.history.replaceState( null, '', url.toString() );
}

export default function App() {
	const tabs = [
		{ name: 'send', title: __( 'Send this site', 'jcore-siirto' ) },
		{
			name: 'receive',
			title: __( 'Receive a site here', 'jcore-siirto' ),
		},
	];

	return (
		<div className="wrap jcore-siirto-admin">
			<h1>{ __( 'Site migration', 'jcore-siirto' ) }</h1>
			<hr className="wp-header-end" />
			<TabPanel
				tabs={ tabs }
				initialTabName={ getTabFromUrl() }
				onSelect={ setTabInUrl }
			>
				{ ( tab ) =>
					tab.name === 'send' ? <SendTab /> : <ReceiveTab />
				}
			</TabPanel>
		</div>
	);
}
