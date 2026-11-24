# Groups site design guidance

This document is the design baseline for public-facing group and event interfaces implemented in:

- `public_html/wp-content/themes/groups-site/`
- `public_html/wp-content/mu-plugins/wporg-groups-frontend/`

It is intended for designers, contributors, reviewers, and coding agents. Specific approved design direction for an issue or pull request may supersede this baseline; when that creates a durable change, update this document.

## Sources of truth

- [`groups-site/theme.json`](../../public_html/wp-content/themes/groups-site/theme.json) defines the available typography, color, spacing, and layout tokens.
- `groups-site` is a child theme of `wporg-parent-2021` and overrides several of its tokens (body line height, content width, edge spacing, fluid heading sizes). Read values from the child `theme.json`, not from the parent or another WordPress.org site.
- Component and theme styles should consume those tokens through WordPress CSS custom properties.
- Use the specifications below to choose the appropriate semantic token. Do not introduce raw hex colors or arbitrary spacing when an existing token expresses the same value.
- Token names such as `heading-3` and `heading-5` describe a visual scale. They do not determine the semantic HTML heading level.

## Typography baseline

| Role | Specification | Preferred implementation |
|---|---|---|
| Body/UI | Inter, 16px/1.7, weight 400 | `var(--wp--preset--font-family--inter)`, `var(--wp--preset--font-size--normal)`, `var(--wp--custom--body--typography--line-height)` |
| Secondary text | Inter, 14px/1.55, weight 400 | Inter and `var(--wp--preset--font-size--small)` |
| Metadata and dates | Inter, 12–14px, weight 600, uppercase, `0.04em` letter spacing | `extra-small` or `small` font-size token, according to context |
| Group title | EB Garamond, 50px/1.2, weight 400 | Set on the hero's site title in `templates/front-page.html` |
| Event title | EB Garamond, `clamp(34px, 3.4vw, 50px)`, weight 400, line-height 1.1 | Semantic `h1` with the `heading-2` visual token |
| Inner-page title | EB Garamond, `clamp(28px, 2.4vw, 36px)`, weight 400, line-height 1.15 | Semantic `h1` inside `.groups-site-page-header` |
| Main-section `h2` | EB Garamond, `clamp(28px, 2.4vw, 36px)`, weight 400, line-height 1.3 | Semantic `h2` with the `heading-3` visual token |
| Card `h3` | EB Garamond, 26px/1.2, weight 400 | Semantic `h3` with `var(--wp--preset--font-size--heading-5)` |
| Utility/sidebar heading | Inter, 16px/1.4, weight 600 | Inter and `var(--wp--preset--font-size--normal)` |

Keep the document outline semantic. Apply a visual token or class when a heading needs a different visual size; do not change an `h2` to an `h3` merely to obtain smaller text.

## Layout and spacing

| Element | Specification | Preferred implementation |
|---|---|---|
| Page width | 1280px for page layouts (group home, event, archive, directory, members); 1160px for reading-width content | Templates set 1280px on their layout; `theme.json` holds the 1160px default |
| Horizontal padding | `clamp(20px, 6vw, 80px)` | `var(--wp--preset--spacing--edge-space)` |
| Section spacing | 50–80px | `var(--wp--preset--spacing--60)` |
| Component gaps | 10px, 20px, or 30px | `var(--wp--preset--spacing--10)`, `--20`, or `--30` |

Avoid arbitrary gap and margin values when one of these tokens fits the composition.

## Component baseline

| Component | Specification |
|---|---|
| Primary button | Inter 16px, weight 600, 14px 32px padding, 2px radius |
| Card | 1px border using `light-grey-1`, 4px radius, 24px internal padding |
| Input | 1px border, 2px radius, approximately 8px 12px padding |
| Focus indicator | 2px `blueberry-1` outline with a 2px offset |
| Modal | Approximately 520px maximum width, 8px radius, 24px 32px 32px content padding. The event modal is up to 704px wide and the full-height Group Settings modal up to 688px, so their 640px forms fit |
| Organizer tools | Organizer-only actions on a page: a small uppercase label above a wrapping row of 20px icon + Inter 14px `charcoal-3` text buttons, `blueberry-1` with underline on hover. Not full buttons, so they don't compete with visitor actions such as RSVP |
| Group home hero | Split layout on the plain page background: group details on the left; on the right, the 16:9 featured image in a white card with a share row, set on an 8px-radius color mat. The mat is a block background color (`pomegranate-3` by default), so it can be retinted without CSS |
| Event hero | The same split on a `light-grey-2` band with a `light-grey-1` bottom border: title, host row, and “Part of {group}” on the left; the bare 16:9 image on the right |
| Inner-page header | News posts, the events archive, static pages, venues, and search open with the compact `.groups-site-page-header` on the plain page background, with no tinted band |
| Breadcrumb | Lives in the sticky local-navigation bar, as on other WordPress.org sites. Do not add per-template back links |
| Search button | An icon-only search button takes the field's `light-grey-2` fill. Only a labelled “Search” button is primary blueberry |
| Footer callout | A ruled list of links, not a row of buttons |

Use an established component variant before creating a visually unrelated component. In particular, “My events” is the compact variant of the normal event card and should retain the same date/title typography and interaction treatment.

## Color tokens

The hex values below document the palette. Use the corresponding CSS custom properties in implementation.

| Role | Reference value | Token |
|---|---:|---|
| Text | `#1a1919` | `var(--wp--preset--color--charcoal-0)` |
| Secondary text | `#40464d` | `var(--wp--preset--color--charcoal-3)` |
| Muted metadata | `#656a71` | `var(--wp--preset--color--charcoal-4)` |
| Border | `#d9d9d9` | `var(--wp--preset--color--light-grey-1)` |
| Light surface | `#f6f6f6` | `var(--wp--preset--color--light-grey-2)` |
| Primary, link, and focus | `#3858e9` | `var(--wp--preset--color--blueberry-1)` |
| Primary hover | `#213fd4` | `var(--wp--preset--color--deep-blueberry)` |
| Hero/callout surface | `#eff2ff` | `var(--wp--preset--color--blueberry-4)` |
| Text on primary controls | `#ffffff` | `var(--wp--preset--color--white)` |

## Interaction states

Demonstrate and verify every applicable state for new or changed interactive components:

- Resting
- Hover
- Focus-visible
- Disabled
- Loading
- Success
- Error

Primary buttons must retain white text on both the resting and hover blues. Outline buttons must remain legible and must never become blue text on a blue background. Loading controls should expose their busy state programmatically without losing their accessible name. Success and error feedback must not rely on color alone.

## Frontend modal accents

Frontend modals must not inherit the legacy WordPress admin blue (`#007cba`). The `.wporg-groups-modal-accent` class in [`component-accents.css`](../../public_html/wp-content/mu-plugins/wporg-groups-frontend/src/components/component-accents.css) sets the WordPress component accent variables to the Blueberry tokens. Modals render at the end of `<body>`, outside the block, so add the class to every frontend modal next to its own class:

```js
className: 'wporg-groups-modal-accent wporg-groups-event-modal',
```

## Missing imagery

Handle missing imagery deliberately. When cards in the same collection contain images, preserve a consistent 16:9 image region and use the plain `blueberry-4` (`#eff2ff`) surface as the placeholder. Do not invent an illustration.

Heroes have no placeholder. When a group home, event, or news post has no featured image, hide the media column and let the text take the full width.

The card placeholder is a provisional treatment. The August 2026 design review did not supply default artwork; update this guide if Brand provides it.

## Review checklist

### Heading hierarchy

- Every main-column section heading—including “My upcoming events,” “Upcoming events,” “About this group,” “News,” and “Sponsors”—uses the main-section `h2` treatment: EB Garamond with the `heading-3` visual token.
- Reserve Inter 16px, weight 600 for utility and sidebar headings.
- Frontend blocks get this treatment from the shared `.wporg-section-heading` class in [`section-heading.css`](../../public_html/wp-content/mu-plugins/wporg-groups-frontend/src/components/section-heading.css). Use it instead of restyling the heading per block.

### Content and fixtures

- Use “Organizer” consistently, with a `z`.
- Demonstrate the state where an organizer has also RSVP’d to an event.
- For the login experience, use: “Log in to your WordPress.org account to register for events and join the global WordPress community.” Do not add an extra headline unless a later approved design asks for one.
- These content requirements come from the [linked design feedback](https://github.com/WordPress/wordcamp.org/issues/1858#issuecomment-5239466525).

### Responsive behavior and accessibility

- Keep visual, DOM, keyboard, and screen-reader order aligned. Do not use CSS `order` to place a sidebar after the main column visually while leaving it first in the DOM.
- The group home repeats “Join this group” in the hero only while the sidebar is stacked under the content (up to 960px). It is hidden on wider screens, where the sidebar’s button is already beside the hero.
- Allow long dates and translated labels to wrap without clipping or overlap.
- Test at 320px, 375px, and 430px viewport widths.
- Test at 200% browser zoom.
- Complete a keyboard pass that verifies focus visibility and logical focus order.
- The original ordering concern is recorded in the [pull-request review](https://github.com/WordPress/wordcamp.org/pull/1860#discussion_r3764072055).

### About content

The group home shows a teaser of the group's About page between “My upcoming events” and “Upcoming events”, with a “Read more” link to the full page. “Events I attended” comes after “Upcoming events”, so a member with nothing booked still sees what's next before their history. The `wporg/page-content` block renders it in excerpt mode, so the prose is edited in one place. Keep newcomer content reachable from the home page this way; do not move it behind navigation alone.

## Minimum visual QA matrix

Before requesting review for a groups-site UI change, cover the relevant combinations:

| Area | Cases |
|---|---|
| Viewport | Desktop plus 320px, 375px, and 430px |
| Zoom | 100% and 200% |
| Input | Pointer and keyboard |
| Controls | Resting, hover, focus-visible, disabled, loading, success, and error |
| Content | Long dates/labels, missing imagery on cards and in heroes, and organizer-plus-attendee state |
| Theme integration | Primary/outline button contrast and frontend modal accent colors |
