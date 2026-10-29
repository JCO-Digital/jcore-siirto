import { createRoot } from '@wordpress/element';
import App from './App';
import './style.scss';

const root = document.getElementById( 'jcore-siirto-app' );
if ( root ) {
	createRoot( root ).render( <App /> );
}
