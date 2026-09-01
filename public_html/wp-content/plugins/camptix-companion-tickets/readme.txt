=== CampTix - Activity Tickets ===
Contributors: wordcamp
Requires at least: 6.8
Tested up to: 6.8
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Lets confirmed event-ticket holders self-register for free, capacity-limited activity tickets (e.g. Contributor Day, Social Dinner), gated to ticket-holders and linked to their main ticket.

== Description ==

An add-on for CampTix. An organiser designates one or more tickets as **activity tickets** — free, capacity-limited extras (each by its own Quantity) such as Contributor Day or a Social Dinner. Then, for every activity ticket:

* **Gated** — only an attendee who is logged in AND already holds a published qualifying main ticket may register. (A combined "main + activity" purchase in one cart is not allowed; buy the main ticket first.)
* **Self-service, one per ticket per attendee** — each attendee registers themselves; the buyer's additional-attendee seats cannot be used, and a person can register for each activity ticket only once.
* **Linked** — each activity attendee record is linked to the attendee's main ticket record (via post meta).
* **Refund-aware** — refunding or cancelling the main ticket auto-cancels **all** of that attendee's linked activity seats and returns them to their pools. (Refund is terminal — re-publishing the main ticket does not auto-restore the seats.)

= Configuration =

CampTix → Setup → **Activity Tickets**:

* **Activity tickets** — which tickets are the free, capacity-limited activity tickets.
* **Qualifying tickets** — which main tickets make a holder eligible. Leave all unchecked to allow holders of any other (non-activity) published ticket. An activity ticket never qualifies as a main ticket.

= Requirements =

This add-on depends on the CampTix **require-login** add-on (it uses the `tix_username` identity that require-login provides). On WordCamp.org `require-login` is enabled by default. If it is inactive, an admin notice is shown and activity registration is disabled (fails closed).

= Integrations =

* **Admin Flags** — when the CampTix Admin Flags add-on is active, registering an activity seat automatically flags the attendee's main ticket (e.g. `activity-contributor-day`), so the admin attendee list and export can filter by it; the flag is removed if the seat is cancelled. Flag definitions are kept in sync on the Activity Tickets setup save.
* **Attendees page** — activity seats are hidden from the public `[camptix_attendees]` listing so each person lists once (their main ticket entry). A shortcode with an explicit `tickets="…"` attribute is not filtered, so a dedicated per-activity attendees page still works.

== Changelog ==

= 1.0.0 =
* First release in this repository. The entries below are the local development history that
  preceded it, kept because they describe what each layer of the add-on does; none of those
  versions were ever released.

= 0.4.0 =
* The ticket-selection screen now lists a logged-in attendee's existing tickets (main and activity) with a manage link for each, plus a throttled "Email me these links" action for attendees who lost their receipt email. The request is login-gated and always mails the requester's own account address (filterable subject/body).

= 0.3.0 =
* Automatically admin-flag the main attendee per held activity ticket (with the CampTix Admin Flags add-on); flags are removed again when the seat is cancelled.
* Hide activity seats from the public [camptix_attendees] listing so each person appears once (explicit tickets="…" attributes are respected).
* Ownership transfers (a ticket's confirmed identity changing hands via the edit-attendee flow) now release the affected activity seats back to their pools — a transferred main ticket releases the old owner's seats; a transferred activity seat is released itself. Filterable via camptix_companion_transfer_policy.

= 0.2.0 =
* Renamed the user-facing concept to "Activity ticket" (stored config/identifiers unchanged).
* Tickets-page now shows an attendee's already-held activity tickets as "already registered" instead of letting them appear to vanish.
* Added "Activity of" and "Activity tickets" columns to the attendee CSV/XML export, exposing the main↔activity link.
* Each attendee now receives a confirmation email when their activity registration is confirmed (filterable subject/body).

= 0.1.0 =
* Initial version: configurable activity tickets, ticket-holder eligibility gate, self-service one-per-ticket-per-attendee rule, attendee linking, refund/cancel cascade across all linked seats, qualifying-ticket whitelist UI, and a selection-screen eligibility notice.
