# GatherPress Recurring Events

An experimental GatherPress extension that stores recurring events as one editable series post with lightweight projected occurrence rows.

## Calendar feeds

GatherPress's calendar feeds (site-wide, event archive, venue, and taxonomy) and a series' own download write a series as one recurring `VEVENT`, the shape GatherPress plans for recurrence in [GatherPress/gatherpress#1684](https://github.com/GatherPress/gatherpress/issues/1684):

- It keeps the series' `UID` (`gatherpress_{post ID}`) and URL, so a calendar holds one entry for the series however its dates change.
- `DTSTART` is the first date, in the series' own timezone or manual offset, with a `VTIMEZONE` defining it. A rule on a UTC start would drift by an hour across clock changes, and can fall on the day before or after. A series in UTC keeps its UTC start.
- `RRULE` is the series' rule, and each cancelled date is an `EXDATE`.
- A series stays in a feed while it has a date to come that isn't cancelled.
- GatherPress leaves password-protected events out of feeds, a protected series included.

Calendar links for an individual occurrence still use that occurrence's date and URL.

GatherPress has no filter on a feed's body, so a feed that holds a series is served by `Ical::serve_feed()`, ahead of GatherPress's own template.

## Known limitations

- The wp-admin events list shows one editable row per recurring series rather than one row per projected occurrence. Projected occurrences are not separate posts and do not have individual edit screens.
