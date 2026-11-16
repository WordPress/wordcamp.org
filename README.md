# Sponsor Vetting preview for #2372

A one-off [WordPress Playground](https://wordpress.org/playground/) preview of the vetting screen in
[#2372](https://github.com/WordPress/wordcamp.org/pull/2372). It is not part of the pull request and nothing on this
branch ships. Delete the branch once the pull request is merged or closed.

**[Open the preview](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/WordPress/wordcamp.org/preview/2372/blueprint.json)**

It runs entirely in your browser tab: nothing is sent anywhere, and closing the tab throws it away.

## What you get

A small made-up network: Central as the main site, four camps, and ten companies at different points of vetting,
with their sponsorships, findings, notes and history. You're logged in as a vetter.

## What's different from WordCamp.org

- **Emails aren't sent.** Compliance requests and approval emails land in *Sponsor Vetting → Preview outbox* instead
  of FreeScout, and the reply checks never find a reply.
- **Camp sites are bare.** They have a plain Sponsors list, not the real sponsor form, invoices or Multi-Event
  Sponsors, so the organizer side is only roughly there.
- **The database is SQLite**, not MariaDB.

## What's here

| File | What it is |
|---|---|
| `sponsor-vetting.zip` | The plugin, built from the pull request at `340810b3`. |
| `mu-plugins/` | Stand-ins for what the plugin needs from the rest of WordCamp.org. |
| `seed.php` | Creates the camps and records, through the plugin's own intake and workflow. |
| `blueprint.json` | Puts the three together in Playground. |

To refresh it after new commits: run `npm run build` in `public_html/wp-content/plugins/sponsor-vetting`, zip
`bootstrap.php`, `includes/`, `js/` and `build/` inside a `sponsor-vetting/` folder, and replace the ZIP here.
