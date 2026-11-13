import { RawHTML } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { close } from '@wordpress/icons';
import { Card, IconButton, Link, Notice, Spinner, Stack, Text } from '@wordpress/ui';
import type { ReactNode } from 'react';
import { formatTime } from './dates';
import { getErrorMessage } from './hooks';
import type { Conversation } from './types';

/**
 * The panel's controls, which stay at its top: what its record offers on one side, and on the other the way to
 * the records before and after it and the way out.
 *
 * @param props          Props.
 * @param props.children What the record offers, like its Save button.
 * @param props.nav      The way to the records before and after it.
 * @param props.onClose  Closes the panel.
 * @return The header.
 */
export function PanelHeader( {
	children,
	nav,
	onClose,
}: {
	children?: ReactNode;
	nav?: ReactNode;
	onClose: () => void;
} ) {
	return (
		<Stack
			direction="row"
			align="center"
			justify="space-between"
			gap="xs"
			className="sponsor-vetting__panel-header"
		>
			<Stack direction="row" gap="sm" align="center">
				{ children }
			</Stack>
			<Stack direction="row" gap="xs">
				{ nav }
				<IconButton
					icon={ close }
					label={ __( 'Close', 'wordcamporg' ) }
					variant="minimal"
					tone="neutral"
					size="compact"
					onClick={ onClose }
				/>
			</Stack>
		</Stack>
	);
}

/**
 * What the panel shows instead of a record that hasn't loaded, or couldn't be.
 *
 * @param props         Props.
 * @param props.error   What loading it threw, if it failed.
 * @param props.title   What to say when it failed.
 * @param props.onClose Closes the panel.
 * @return The placeholder.
 */
export function PanelPlaceholder( {
	error,
	title,
	onClose,
}: {
	error?: unknown;
	title: string;
	onClose: () => void;
} ) {
	return (
		<>
			<PanelHeader onClose={ onClose } />
			<div className="sponsor-vetting__panel-body">
				{ error ? (
					<Notice.Root intent="error">
						<Notice.Title>{ title }</Notice.Title>
						<Notice.Description>{ getErrorMessage( error ) }</Notice.Description>
					</Notice.Root>
				) : (
					<Stack justify="center" className="sponsor-vetting__loading">
						<Spinner />
					</Stack>
				) }
			</div>
		</>
	);
}

/**
 * A titled group of the panel.
 *
 * @param props          Props.
 * @param props.title    The group's title.
 * @param props.children Its contents.
 * @return The group.
 */
export function Section( { title, children }: { title: string; children: ReactNode } ) {
	return (
		<Card.Root className="sponsor-vetting__section">
			<Card.Header>
				<Card.Title>
					<Text variant="heading-sm" render={ <h3 /> }>
						{ title }
					</Text>
				</Card.Title>
			</Card.Header>
			<Card.Content>
				<Stack direction="column" gap="md">
					{ children }
				</Stack>
			</Card.Content>
		</Card.Root>
	);
}

/**
 * A label with its value, side by side.
 *
 * @param props          Props.
 * @param props.label    The label.
 * @param props.children The value. Nothing is rendered without one.
 * @return The row.
 */
export function Row( { label, children }: { label: string; children: ReactNode } ) {
	if ( ! children ) {
		return null;
	}

	return (
		<Stack direction="row" justify="space-between" align="baseline" gap="md">
			<Text variant="body-sm" className="sponsor-vetting__label">
				{ label }
			</Text>
			<Text render={ <div /> } className="sponsor-vetting__value">
				{ children }
			</Text>
		</Stack>
	);
}

/**
 * A line of smaller, quieter text: a hint under a control, or what a list is about.
 *
 * @param props          Props.
 * @param props.children The text.
 * @return The line.
 */
export function Hint( { children }: { children: ReactNode } ) {
	return (
		<Text variant="body-sm" className="sponsor-vetting__label">
			{ children }
		</Text>
	);
}

/**
 * The emails sent about a record, each linked to its conversation in FreeScout.
 *
 * @param props               Props.
 * @param props.label         What the emails are.
 * @param props.conversations The emails.
 * @return The list, or nothing without emails.
 */
export function Emails( { label, conversations }: { label: string; conversations: Conversation[] } ) {
	if ( conversations.length === 0 ) {
		return null;
	}

	return (
		<Stack direction="column" gap="xs">
			<Hint>{ label }</Hint>
			{ conversations.map( ( { url, time } ) => (
				<Link key={ url } href={ url } openInNewTab>
					{ formatTime( time ) }
				</Link>
			) ) }
		</Stack>
	);
}

/**
 * The notes and status changes of a record, newest first.
 *
 * @param props         Props.
 * @param props.entries The entries: what each says, as HTML built by get_log_entry_html(), when, and for a
 *                      company's sponsorships the event it's about.
 * @return The entries.
 */
export function History( { entries }: { entries: { html: string; time: string; event?: string }[] } ) {
	return (
		<>
			{ entries.map( ( entry, index ) => (
				<Stack key={ index } direction="column" gap="xs" className="sponsor-vetting__entry">
					<RawHTML>{ entry.html }</RawHTML>
					<Hint>{ [ entry.event, formatTime( entry.time ) ].filter( Boolean ).join( ' · ' ) }</Hint>
				</Stack>
			) ) }
		</>
	);
}
