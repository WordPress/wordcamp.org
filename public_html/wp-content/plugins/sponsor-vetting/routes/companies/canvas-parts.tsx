/**
 * What the open record's view is built from: its header, parts, sections, rows, emails and history.
 */

import { RawHTML } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { close } from '@wordpress/icons';
import { Card, IconButton, Link, Notice, Spinner, Stack, Text } from '@wordpress/ui';
import type { ReactNode } from 'react';
import { formatTime } from './dates';
import { getErrorMessage } from './hooks';
import type { Conversation } from './types';

/**
 * The button that closes the open record, which gives the list its full width back.
 *
 * @param props         Props.
 * @param props.onClose Closes the record.
 * @return The button.
 */
function CloseButton( { onClose }: { onClose: () => void } ) {
	return (
		<IconButton
			icon={ close }
			label={ __( 'Close', 'wordcamporg' ) }
			variant="minimal"
			tone="neutral"
			size="compact"
			onClick={ onClose }
		/>
	);
}

/**
 * The top of the open record, which stays in place while the rest scrolls: what it is on one side, and on the
 * other what can be done with it and the way out.
 *
 * @param props          Props.
 * @param props.title    The record's name.
 * @param props.badge    Its status.
 * @param props.children What else identifies it, under its name.
 * @param props.actions  What the record offers, like its Save button.
 * @param props.onClose  Closes the record.
 * @return The header.
 */
export function CanvasHeader( {
	title,
	badge,
	children,
	actions,
	onClose,
}: {
	title: string;
	badge?: ReactNode;
	children?: ReactNode;
	actions?: ReactNode;
	onClose: () => void;
} ) {
	return (
		<Stack
			direction="row"
			align="start"
			justify="space-between"
			gap="lg"
			className="sponsor-vetting__canvas-header"
		>
			<Stack direction="column" gap="xs">
				<Stack direction="row" align="center" gap="sm" wrap="wrap">
					<Text variant="heading-lg" render={ <h2 /> }>
						{ title }
					</Text>
					{ badge }
				</Stack>
				{ children }
			</Stack>
			<Stack direction="row" align="center" gap="sm">
				{ actions }
				<CloseButton onClose={ onClose } />
			</Stack>
		</Stack>
	);
}

/**
 * What the canvas shows instead of a record that hasn't loaded, or couldn't be.
 *
 * @param props         Props.
 * @param props.error   What loading it threw, if it failed.
 * @param props.title   What to say when it failed.
 * @param props.onClose Closes the record.
 * @return The placeholder.
 */
export function CanvasPlaceholder( {
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
			<Stack direction="row" justify="end" className="sponsor-vetting__canvas-header">
				<CloseButton onClose={ onClose } />
			</Stack>
			<div className="sponsor-vetting__canvas-body">
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
 * One of the two parts of an open company: its events, or the company itself.
 *
 * @param props          Props.
 * @param props.title    The part's title.
 * @param props.hint     What to know about it, under its title.
 * @param props.children Its contents.
 * @return The part.
 */
export function Part( { title, hint, children }: { title: string; hint?: string; children: ReactNode } ) {
	return (
		<Stack direction="column" gap="md" render={ <section /> }>
			<Stack direction="column" gap="xs">
				<Text variant="heading-md" render={ <h3 /> }>
					{ title }
				</Text>
				{ hint && <Hint>{ hint }</Hint> }
			</Stack>
			{ children }
		</Stack>
	);
}

/**
 * A titled group of an open record.
 *
 * @param props          Props.
 * @param props.title    The group's title.
 * @param props.level    The level of its heading: 4 inside a part, which has the heading above it.
 * @param props.children Its contents.
 * @return The group.
 */
export function Section( { title, level = 3, children }: { title: string; level?: 3 | 4; children: ReactNode } ) {
	const Heading = `h${ level }` as const;

	return (
		<Card.Root className="sponsor-vetting__section">
			<Card.Header>
				<Card.Title>
					<Text variant="heading-sm" render={ <Heading /> }>
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
