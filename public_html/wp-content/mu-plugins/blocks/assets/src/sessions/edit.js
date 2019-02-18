
/**
 * Internal dependencies
 */
import SessionsBlockControls from "./block-controls";
import SessionsInspectorControls from "./inspector-controls";

class SessionsEdit extends Component {
	render() {
		const { mode } = this.props.attributes;

		return (
			<Fragment>
				<SessionsBlockControls { ...this.props } { ...this.state } />
				{ mode &&
				<Fragment>
					<SessionsInspectorControls { ...this.props } />
				</Fragment>
				}
			</Fragment>
		);
	}
}

export const edit = SessionsEdit;
