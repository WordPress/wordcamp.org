# GatherPress Recurring Events

An experimental GatherPress extension that stores recurring events as one editable series post with lightweight projected occurrence rows.

## Known limitations

- Aggregate GatherPress calendar feeds, including site-wide, event archive, venue, and taxonomy feeds, remain series-based in the MVP. They include the series' master event but do not expand its projected occurrences. Calendar links for an individual occurrence still use that occurrence's date and URL.
- The wp-admin events list shows one editable row per recurring series rather than one row per projected occurrence. Projected occurrences are not separate posts and do not have individual edit screens.
