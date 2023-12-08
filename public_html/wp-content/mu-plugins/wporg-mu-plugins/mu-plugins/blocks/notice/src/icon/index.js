/**
 * Internal dependencies
 */
import { ReactComponent as Alert } from './library/alert.svg';
import { ReactComponent as Info } from './library/info.svg';
import { ReactComponent as Tip } from './library/tip.svg';
import { ReactComponent as Warning } from './library/warning.svg';

export default function ( { type } ) {
	switch ( type ) {
		case 'alert':
			return <Alert />;
		case 'info':
			return <Info />;
		case 'warning':
			return <Warning />;
		case 'tip':
		default:
			return <Tip />;
	}
}
