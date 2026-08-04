/**
 * WPorg Groups Frontend — RSVP questions editor.
 *
 * The "Registration questions" section of the create/edit event modal. Lets an
 * organizer ask a handful of extra questions at RSVP time (company name,
 * dietary requirements, t-shirt size…) instead of moving registration off to
 * an external form.
 *
 * Kept deliberately small: every question is a single-line text field, and the
 * list is capped at `MAX_QUESTIONS`. New questions are saved with an empty
 * `id` — the server mints one, and it comes back on the next load.
 *
 * @package WordCamp\Groups\Frontend
 */
import { createElement as h } from '@wordpress/element';
import { Button, CheckboxControl, TextControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Must match `WordCamp\Groups\Frontend\RSVP_Questions\MAX_QUESTIONS`.
 */
const MAX_QUESTIONS = 5;

export default function RsvpQuestionsEditor( { questions, onChange } ) {
	const list = questions || [];

	const updateAt = ( index, changes ) => {
		onChange(
			list.map( ( question, i ) =>
				i === index ? { ...question, ...changes } : question
			)
		);
	};

	const removeAt = ( index ) => {
		onChange( list.filter( ( question, i ) => i !== index ) );
	};

	const addQuestion = () => {
		onChange( [ ...list, { id: '', label: '', required: false } ] );
	};

	return h(
		'div',
		{ className: 'wporg-groups-event-modal__field wporg-groups-rsvp-questions' },
		h(
			'label',
			{ className: 'components-base-control__label' },
			__( 'Registration questions', 'wporg-groups-frontend' )
		),
		h(
			'p',
			{ className: 'wporg-groups-rsvp-questions__help' },
			__(
				'Optional. Attendees answer these when they RSVP. Only organizers can see the answers.',
				'wporg-groups-frontend'
			)
		),
		list.map( ( question, index ) =>
			h(
				'div',
				{
					className: 'wporg-groups-rsvp-questions__row',
					key: question.id || `new-${ index }`,
				},
				h( TextControl, {
					label: sprintf(
						/* translators: %d: question number. */
						__( 'Question %d', 'wporg-groups-frontend' ),
						index + 1
					),
					value: question.label,
					onChange: ( value ) => updateAt( index, { label: value } ),
					placeholder: __(
						'e.g. Dietary requirements',
						'wporg-groups-frontend'
					),
					__nextHasNoMarginBottom: true,
				} ),
				h(
					'div',
					{ className: 'wporg-groups-rsvp-questions__row-actions' },
					h( CheckboxControl, {
						label: __( 'Required', 'wporg-groups-frontend' ),
						checked: !! question.required,
						onChange: ( value ) =>
							updateAt( index, { required: value } ),
						__nextHasNoMarginBottom: true,
					} ),
					h(
						Button,
						{
							variant: 'tertiary',
							isDestructive: true,
							onClick: () => removeAt( index ),
						},
						__( 'Remove', 'wporg-groups-frontend' )
					)
				)
			)
		),
		list.length < MAX_QUESTIONS
			? h(
				Button,
				{
					variant: 'secondary',
					onClick: addQuestion,
					className: 'wporg-groups-rsvp-questions__add',
				},
				__( '+ Add a question', 'wporg-groups-frontend' )
			)
			: h(
				'p',
				{ className: 'wporg-groups-rsvp-questions__help' },
				sprintf(
					/* translators: %d: maximum number of questions. */
					__(
						'You can ask up to %d questions.',
						'wporg-groups-frontend'
					),
					MAX_QUESTIONS
				)
			)
	);
}
