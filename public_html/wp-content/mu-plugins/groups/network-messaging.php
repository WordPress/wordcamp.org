<?php
/**
 * Network-admin messaging for WordPress Group sites.
 *
 * Gives Program Managers (network admins) a single Network Admin screen for
 * emailing group people, covering two audiences that were previously
 * impossible to reach without hand-assembling address lists:
 *
 * - Every group organiser across the whole Groups network (#1775).
 * - Every member of a hand-picked set of groups (#1776).
 *
 * Both are the same underlying tool: an audience filter (organisers only vs.
 * all members) crossed with a group filter (all groups vs. selected ones).
 *
 * Delivery runs on cron in small batches rather than in the submit request,
 * so a send to thousands of recipients can't time out the browser. The queue
 * lives in a network option; recipients are resolved lazily, site by site,
 * as the queue drains — a network-wide `get_users()` would be a single
 * enormous query, and the row set would have to be held in the option too.
 *
 * Loaded on the groups network only (sits in the `groups/` mu-plugins folder).
 *
 * @package WordCamp\Groups
 */

namespace WordCamp\Groups\Messaging;

use WordCamp\Logger;

defined( 'WPINC' ) || die();

/**
 * Capability required to compose and send. `manage_network` is what makes
 * someone a Program Manager on this network; there's no narrower cap that
 * fits, and this tool can reach every group at once.
 */
const CAPABILITY = 'manage_network';

/** Network Admin page slug. */
const MENU_SLUG = 'wporg-groups-messaging';

/** `admin_post_` action the compose form submits to. */
const FORM_ACTION = 'wporg_groups_send_message';

/** Cron hook that drains the queue. */
const CRON_HOOK = 'wporg_groups_message_batch';

/** Network option holding queued jobs (a FIFO list). */
const JOBS_OPTION = 'wporg_groups_message_jobs';

/** Network option holding a summary of the last completed job. */
const SUMMARY_OPTION = 'wporg_groups_message_last_send';

/** Object-cache key serialising read-modify-write cycles on `JOBS_OPTION`. */
const LOCK_KEY = 'jobs_lock';

/** Object-cache group for `LOCK_KEY`. Registered global, see below. */
const LOCK_GROUP = 'wporg-groups-messaging';

/**
 * Seconds before an abandoned lock expires on its own, so a process that dies
 * mid-update can't wedge the queue permanently.
 */
const LOCK_TIMEOUT = 30;

/** Lock acquisition attempts, `LOCK_RETRY_DELAY` apart. */
const LOCK_ATTEMPTS = 20;

/** Microseconds between lock acquisition attempts. */
const LOCK_RETRY_DELAY = 50000;

/**
 * Emails sent per cron run. Small enough that a single run stays well inside
 * PHP's time limit even when the mail transport is slow.
 */
const BATCH_SIZE = 50;

/** Audience: editors and administrators only ("Organisers"). */
const AUDIENCE_ORGANIZERS = 'organizers';

/** Audience: everyone on the site, whatever their role. */
const AUDIENCE_MEMBERS = 'members';

/**
 * Roles that make someone a group organiser. Mirrors the "Organiser" tier in
 * `Members_Controller::ROLE_LABELS` — authors are "Event Organisers" and are
 * deliberately not included, since they only manage their own events.
 */
const ORGANIZER_ROLES = array( 'administrator', 'editor' );

add_action( 'network_admin_menu', __NAMESPACE__ . '\add_page' );
add_action( 'admin_post_' . FORM_ACTION, __NAMESPACE__ . '\handle_form_post' );
add_action( CRON_HOOK, __NAMESPACE__ . '\process_batch' );

/*
 * `JOBS_OPTION` is a network option, but `process_batch()` runs under whatever
 * blog happens to be current. Non-global cache groups are keyed per blog, so
 * without this two runs on different blogs would take two different locks and
 * not exclude each other at all.
 *
 * The object cache is started in `wp-settings.php` well before mu-plugins
 * load, so this is safe at file scope.
 */
wp_cache_add_global_groups( array( LOCK_GROUP ) );

/**
 * Whether the current user may use this tool.
 */
function current_user_can_send_messages(): bool {
	return current_user_can( CAPABILITY );
}

/**
 * Register the Network Admin screen.
 */
function add_page(): void {
	add_submenu_page(
		'settings.php',
		'Message Groups',
		'Message Groups',
		CAPABILITY,
		MENU_SLUG,
		__NAMESPACE__ . '\render_page'
	);
}

/**
 * Get the sites on the Groups network that represent an actual group.
 *
 * The network's root site is the `/group/` landing page rather than a group,
 * so it has no members to message. Archived, deleted and spam sites are
 * skipped for the same reason: nobody should be emailed on their behalf.
 *
 * @return int[] Site IDs.
 */
function get_group_site_ids(): array {
	$sites = get_sites(
		array(
			'network_id'   => GROUPS_NETWORK_ID,
			'site__not_in' => array( GROUPS_ROOT_BLOG_ID ),
			'archived'     => 0,
			'deleted'      => 0,
			'spam'         => 0,
			'number'       => 0,
			'orderby'      => 'path',
			'fields'       => 'ids',
		)
	);

	return array_map( 'intval', $sites );
}

/**
 * Get the group sites as `id => name` pairs, for the group selector.
 *
 * @return array<int, string>
 */
function get_group_choices(): array {
	$choices = array();

	foreach ( get_group_site_ids() as $site_id ) {
		$name = get_blog_option( $site_id, 'blogname', '' );

		$choices[ $site_id ] = $name ? $name : untrailingslashit( get_site( $site_id )->path );
	}

	return $choices;
}

/**
 * Get one page of recipients from a single group.
 *
 * @param int    $site_id  Group site ID.
 * @param string $audience One of the `AUDIENCE_*` constants.
 * @param int    $number   Maximum users to return.
 * @param int    $offset   Users to skip.
 * @return array<int, array{email: string, name: string}>
 */
function get_site_recipients( int $site_id, string $audience, int $number, int $offset = 0 ): array {
	$args = array(
		'blog_id' => $site_id,
		'number'  => $number,
		'offset'  => $offset,
		'orderby' => 'ID',
		'order'   => 'ASC',
		'fields'  => array( 'ID', 'user_email', 'display_name' ),
	);

	if ( AUDIENCE_ORGANIZERS === $audience ) {
		$args['role__in'] = ORGANIZER_ROLES;
	}

	$recipients = array();

	foreach ( get_users( $args ) as $user ) {
		if ( ! is_email( $user->user_email ) ) {
			continue;
		}

		$recipients[] = array(
			'email' => $user->user_email,
			'name'  => $user->display_name,
		);
	}

	return $recipients;
}

/**
 * Queue a message for background delivery.
 *
 * @param string $subject  Message subject.
 * @param string $body     Message body (plain text).
 * @param string $audience One of the `AUDIENCE_*` constants.
 * @param int[]  $site_ids Group sites to message.
 * @return string The queued job's ID, or an empty string if the queue was
 *                locked by a concurrent update and the job wasn't stored.
 */
function queue_message( string $subject, string $body, string $audience, array $site_ids ): string {
	$job = array(
		'id'            => wp_generate_uuid4(),
		'subject'       => $subject,
		'body'          => $body,
		'audience'      => $audience,
		'sites'         => array_values( $site_ids ),
		'pending_sites' => array_values( $site_ids ),
		'site_offset'   => 0,
		'queue'         => array(),
		// Lowercased emails already mailed, used to dedupe people who belong
		// to more than one of the selected groups.
		'sent'          => array(),
		'sent_count'    => 0,
		'failed_count'  => 0,
		'author'        => get_current_user_id(),
		'created'       => time(),
	);

	$queued = with_jobs_lock(
		function () use ( $job ) {
			$jobs   = get_jobs();
			$jobs[] = $job;

			update_site_option( JOBS_OPTION, $jobs );

			return true;
		}
	);

	if ( ! $queued ) {
		return '';
	}

	schedule_next_batch();

	return $job['id'];
}

/**
 * Run `$callback` with exclusive access to `JOBS_OPTION`.
 *
 * Both the compose form and the cron batch read the whole job list, change
 * one part of it, and write the whole thing back. Interleave two of those and
 * one silently overwrites the other — cron reads `[A]`, an admin queues `B`
 * and writes `[A, B]`, cron finishes `A` and writes back `[]`, and `B` is
 * gone. Core's `doing_cron` lock only stops cron racing cron, not cron racing
 * a form submission.
 *
 * `wp_cache_add()` is atomic on a shared backend (memcached in production),
 * which makes it a usable cross-process mutex. Without a persistent object
 * cache the lock is per-process and this degrades to the unsynchronised
 * behaviour it replaces — no worse than not having it.
 *
 * @param callable $callback Runs while the lock is held.
 * @param bool     $steal    Run the callback anyway if the lock can't be
 *                           acquired. For updates where dropping the write
 *                           loses more than a rare clobber would.
 * @return mixed The callback's return value, or null if the lock wasn't
 *               acquired and `$steal` is false.
 */
function with_jobs_lock( callable $callback, bool $steal = false ) {
	$acquired = false;

	for ( $attempt = 0; $attempt < LOCK_ATTEMPTS; $attempt++ ) {
		if ( wp_cache_add( LOCK_KEY, 1, LOCK_GROUP, LOCK_TIMEOUT ) ) {
			$acquired = true;
			break;
		}

		usleep( LOCK_RETRY_DELAY );
	}

	if ( ! $acquired && ! $steal ) {
		return null;
	}

	try {
		return $callback();
	} finally {
		wp_cache_delete( LOCK_KEY, LOCK_GROUP );
	}
}

/**
 * Get the queued jobs.
 *
 * @return array[]
 */
function get_jobs(): array {
	$jobs = get_site_option( JOBS_OPTION, array() );

	return is_array( $jobs ) ? $jobs : array();
}

/**
 * Schedule the next batch, unless one is already due.
 */
function schedule_next_batch(): void {
	if ( wp_next_scheduled( CRON_HOOK ) ) {
		return;
	}

	$scheduled = wp_schedule_single_event( time(), CRON_HOOK );

	if ( false === $scheduled ) {
		trigger_error(
			'Failed to schedule the next group message batch -- `wp_schedule_single_event()` returned false. Queued messages will not be delivered.',
			E_USER_WARNING
		);
	}
}

/**
 * Do up to `BATCH_SIZE` units of work on the oldest queued job.
 *
 * A unit is one recipient taken off the queue or one per-site recipient
 * lookup — not one email sent. Bounding on emails instead would leave a run
 * unbounded whenever the work doesn't produce mail: someone who organises a
 * dozen groups is skipped as a duplicate, and a group with no members yields
 * nothing, so a run could walk any number of sites and queries before hitting
 * a send-based limit. Counting the work itself is what keeps a single cron
 * run inside the time limit.
 *
 * Reschedules itself until the queue is empty. Core's `doing_cron` lock keeps
 * two runs from overlapping; the `sent` map means that even if a run were
 * duplicated, an address already mailed wouldn't be mailed again.
 *
 * The job is claimed off `JOBS_OPTION` under `with_jobs_lock()` and written
 * back the same way, but the sending in between runs unlocked — holding the
 * lock across `BATCH_SIZE` `wp_mail()` calls would block the compose form for
 * as long as the mail transport takes.
 */
function process_batch(): void {
	$job = with_jobs_lock(
		function () {
			$jobs = get_jobs();

			if ( empty( $jobs ) ) {
				return null;
			}

			$claimed = array_shift( $jobs );

			// Claiming removes the job from the option for the duration of
			// the batch, so a concurrent queue_message() appends to a list
			// that no longer contains it and can't resurrect stale progress.
			update_site_option( JOBS_OPTION, $jobs );

			return $claimed;
		}
	);

	if ( empty( $job ) ) {
		return;
	}

	$processed = 0;
	$crashed   = false;

	/*
	 * The job was already removed from `JOBS_OPTION` by the claim above, and
	 * isn't written back until the commit below -- so a `Throwable` escaping
	 * this loop (a rogue `pre_wp_mail`/`wp_mail` filter, a transport that
	 * exhausts the request instead of returning cleanly) must not skip that
	 * commit, or the job -- and every recipient still in it -- disappears
	 * with nothing sent, re-queued, or recorded. `send_message()` already
	 * turns an ordinary transport failure into `false`, so this is only
	 * reached by something that couldn't be handled that way.
	 */
	try {
		while ( $processed < BATCH_SIZE ) {
			if ( empty( $job['queue'] ) ) {
				if ( empty( $job['pending_sites'] ) ) {
					break;
				}

				++$processed;

				$site_id = (int) $job['pending_sites'][0];
				$batch   = get_site_recipients( $site_id, $job['audience'], BATCH_SIZE, $job['site_offset'] );

				if ( empty( $batch ) ) {
					array_shift( $job['pending_sites'] );
					$job['site_offset'] = 0;
				} else {
					$job['site_offset'] += count( $batch );
					$job['queue']        = $batch;
				}

				continue;
			}

			$recipient = array_shift( $job['queue'] );
			$key       = strtolower( $recipient['email'] );

			++$processed;

			if ( isset( $job['sent'][ $key ] ) ) {
				continue;
			}

			/*
			 * Only mark the address done once the mail is actually away. Marking
			 * first meant a transient transport failure retired the recipient for
			 * good — no send, no retry, no trace. Left unmarked, the same person
			 * gets another chance if they turn up again via another group, and
			 * the failure is at least on the record either way.
			 */
			if ( send_message( $recipient, $job ) ) {
				$job['sent'][ $key ] = true;

				++$job['sent_count'];
			} else {
				$job['failed_count'] = ( $job['failed_count'] ?? 0 ) + 1;

				Logger\log(
					'groups_message_send_failed',
					array(
						'job'       => $job['id'],
						'recipient' => $recipient['email'],
					)
				);
			}
		}
	} catch ( \Throwable $error ) {
		$crashed = true;

		/*
		 * Not just `compact( 'error' )`: `redact_keys()` only special-cases
		 * `Exception`, not `Throwable` generally, so a plain `Error` (e.g. a
		 * `TypeError`) would fall through to an `(array)` cast instead --
		 * mangled keys for its private/protected properties that `wp_json_encode()`
		 * silently drops, per that function's own docblock.
		 */
		Logger\log(
			'groups_message_batch_crashed',
			array(
				'job'     => $job['id'],
				'error'   => array(
					'class'   => get_class( $error ),
					'message' => $error->getMessage(),
					'file'    => $error->getFile(),
					'line'    => $error->getLine(),
				),
			)
		);
	}

	$finished = ! $crashed && empty( $job['queue'] ) && empty( $job['pending_sites'] );

	$jobs = with_jobs_lock(
		function () use ( $job, $finished ) {
			$jobs = get_jobs();

			if ( $finished ) {
				record_summary( $job );
			} else {
				array_unshift( $jobs, $job );

				update_site_option( JOBS_OPTION, $jobs );
			}

			return $jobs;
		},
		// The batch's progress only exists in memory at this point, so
		// abandoning the write would re-send everything this run just sent.
		true
	);

	if ( ! empty( $jobs ) ) {
		schedule_next_batch();
	}
}

/**
 * Email a single recipient.
 *
 * @param array{email: string, name: string} $recipient Recipient.
 * @param array                              $job       Job being processed.
 * @return bool Whether the mail was handed off successfully.
 */
function send_message( array $recipient, array $job ): bool {
	$to = $recipient['name']
		? sprintf( '%s <%s>', $recipient['name'], $recipient['email'] )
		: $recipient['email'];

	/*
	 * Passed a string, `wp_mail()` splits it on commas to support multiple
	 * recipients — so a display name containing one ("evil@example.test, Jane")
	 * would smuggle an extra address into every message of a broadcast. Users
	 * pick their own display name, so that's attacker-controlled. An array
	 * skips the split entirely and this stays one address.
	 */
	return wp_mail(
		array( $to ),
		$job['subject'],
		$job['body'],
		array( 'Content-Type: text/plain; charset=UTF-8' )
	);
}

/**
 * Store a summary of a finished job, so the screen can report what happened.
 *
 * @param array $job Completed job.
 */
function record_summary( array $job ): void {
	update_site_option(
		SUMMARY_OPTION,
		array(
			'subject'      => $job['subject'],
			'audience'     => $job['audience'],
			'site_count'   => count( $job['sites'] ),
			'sent_count'   => (int) $job['sent_count'],
			'failed_count' => (int) ( $job['failed_count'] ?? 0 ),
			'author'       => (int) $job['author'],
			'finished'     => time(),
		)
	);
}

/**
 * Handle the compose form submission.
 */
function handle_form_post(): void {
	if ( ! current_user_can_send_messages() ) {
		wp_die( 'You do not have permission to send messages to groups.', 403 );
	}

	check_admin_referer( FORM_ACTION );

	$subject  = sanitize_text_field( wp_unslash( $_POST['subject'] ?? '' ) );
	$body     = sanitize_textarea_field( wp_unslash( $_POST['body'] ?? '' ) );
	$audience = sanitize_key( wp_unslash( $_POST['audience'] ?? '' ) );
	$scope    = sanitize_key( wp_unslash( $_POST['scope'] ?? '' ) );
	$selected = array_map( 'absint', (array) ( $_POST['groups'] ?? array() ) );

	if ( ! in_array( $audience, array( AUDIENCE_ORGANIZERS, AUDIENCE_MEMBERS ), true ) ) {
		redirect_with_notice( 'invalid-audience' );
	}

	if ( '' === $subject || '' === $body ) {
		redirect_with_notice( 'empty-message' );
	}

	$group_ids = get_group_site_ids();

	if ( 'selected' === $scope ) {
		$group_ids = array_values( array_intersect( $group_ids, $selected ) );

		if ( empty( $group_ids ) ) {
			redirect_with_notice( 'no-groups' );
		}
	}

	if ( empty( $group_ids ) ) {
		redirect_with_notice( 'no-groups' );
	}

	if ( ! queue_message( $subject, $body, $audience, $group_ids ) ) {
		redirect_with_notice( 'queue-busy' );
	}

	redirect_with_notice( 'queued', count( $group_ids ) );
}

/**
 * Redirect back to the compose screen with a notice, and exit.
 *
 * @param string $notice Notice slug.
 * @param int    $groups Number of groups the message was queued for.
 */
function redirect_with_notice( string $notice, int $groups = 0 ): void {
	$url = add_query_arg(
		array(
			'page'   => MENU_SLUG,
			'notice' => $notice,
			'groups' => $groups,
		),
		network_admin_url( 'settings.php' )
	);

	wp_safe_redirect( $url );
	exit;
}

/**
 * Render the notice for the current request, if any.
 */
function render_notice(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display of the result of an already-verified POST.
	$notice = isset( $_GET['notice'] ) ? sanitize_key( wp_unslash( $_GET['notice'] ) ) : '';

	if ( ! $notice ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Ditto.
	$groups = isset( $_GET['groups'] ) ? absint( $_GET['groups'] ) : 0;

	$messages = array(
		'queued'           => array(
			'success',
			sprintf(
				/* translators: %s: number of groups. */
				_n(
					'Message queued for %s group. Delivery runs in the background.',
					'Message queued for %s groups. Delivery runs in the background.',
					$groups,
					'wporg-groups-frontend'
				),
				number_format_i18n( $groups )
			),
		),
		'empty-message'    => array( 'error', 'Please provide both a subject and a message.' ),
		'no-groups'        => array( 'error', 'Please select at least one group.' ),
		'invalid-audience' => array( 'error', 'Please choose who should receive the message.' ),
		'queue-busy'       => array( 'error', 'The message queue was busy and your message was not queued. Please try again.' ),
	);

	if ( ! isset( $messages[ $notice ] ) ) {
		return;
	}

	list( $type, $text ) = $messages[ $notice ];

	printf(
		'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
		esc_attr( $type ),
		esc_html( $text )
	);
}

/**
 * Render the last-send summary, so an admin can confirm a message went out.
 */
function render_summary(): void {
	$summary = get_site_option( SUMMARY_OPTION );

	if ( ! is_array( $summary ) || empty( $summary['finished'] ) ) {
		return;
	}

	$author = get_userdata( (int) $summary['author'] );
	$failed = (int) ( $summary['failed_count'] ?? 0 );

	printf(
		'<p class="description">%s</p>',
		esc_html(
			sprintf(
				'Last send: "%1$s" reached %2$s recipient(s) across %3$s group(s), sent by %4$s on %5$s.%6$s',
				$summary['subject'],
				number_format_i18n( (int) $summary['sent_count'] ),
				number_format_i18n( (int) $summary['site_count'] ),
				$author ? $author->display_name : 'an unknown user',
				wp_date( 'Y-m-d H:i', (int) $summary['finished'] ),
				$failed
					? sprintf(
						/* translators: %s: number of failed recipients. */
						' %s message(s) could not be sent — see the error log.',
						number_format_i18n( $failed )
					)
					: ''
			)
		)
	);
}

/**
 * Render the compose screen.
 */
function render_page(): void {
	if ( ! current_user_can_send_messages() ) {
		wp_die( 'You do not have permission to access this page.', 403 );
	}

	$groups = get_group_choices();

	?>
	<div class="wrap">
		<h1>Message Groups</h1>

		<?php render_notice(); ?>

		<p>
			Send an email to group organisers or members across the Groups network.
			Messages are delivered in the background, in batches.
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( FORM_ACTION ); ?>" />
			<?php wp_nonce_field( FORM_ACTION ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">Recipients</th>
					<td>
						<fieldset>
							<legend class="screen-reader-text">Who should receive this message?</legend>
							<label>
								<input type="radio" name="audience" value="<?php echo esc_attr( AUDIENCE_ORGANIZERS ); ?>" checked="checked" />
								Organisers only (editors and administrators)
							</label>
							<br />
							<label>
								<input type="radio" name="audience" value="<?php echo esc_attr( AUDIENCE_MEMBERS ); ?>" />
								All members, whatever their role
							</label>
						</fieldset>
					</td>
				</tr>

				<tr>
					<th scope="row">Groups</th>
					<td>
						<fieldset>
							<legend class="screen-reader-text">Which groups should receive this message?</legend>
							<label>
								<input type="radio" name="scope" value="all" checked="checked" />
								All groups on the network (<?php echo esc_html( number_format_i18n( count( $groups ) ) ); ?>)
							</label>
							<br />
							<label>
								<input type="radio" name="scope" value="selected" />
								Only the groups selected below
							</label>
						</fieldset>

						<p>
							<label for="wporg-groups-message-groups" class="screen-reader-text">Groups</label>
							<select name="groups[]" id="wporg-groups-message-groups" multiple="multiple" size="10" style="min-width: 20rem;">
								<?php foreach ( $groups as $site_id => $name ) : ?>
									<option value="<?php echo esc_attr( $site_id ); ?>"><?php echo esc_html( $name ); ?></option>
								<?php endforeach; ?>
							</select>
						</p>
						<p class="description">Hold <kbd>Cmd</kbd>/<kbd>Ctrl</kbd> to select more than one group.</p>
					</td>
				</tr>

				<tr>
					<th scope="row"><label for="wporg-groups-message-subject">Subject</label></th>
					<td>
						<input type="text" name="subject" id="wporg-groups-message-subject" class="regular-text" required="required" />
					</td>
				</tr>

				<tr>
					<th scope="row"><label for="wporg-groups-message-body">Message</label></th>
					<td>
						<textarea name="body" id="wporg-groups-message-body" rows="10" class="large-text" required="required"></textarea>
						<p class="description">Plain text only.</p>
					</td>
				</tr>
			</table>

			<?php submit_button( 'Send message' ); ?>
		</form>

		<?php render_summary(); ?>
	</div>
	<?php
}
