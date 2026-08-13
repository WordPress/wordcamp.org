# Groups site design guidance

This document is the design baseline for public-facing group and event interfaces implemented in:

- `public_html/wp-content/themes/groups-site/`
- `public_html/wp-content/mu-plugins/wporg-groups-frontend/`

It is intended for designers, contributors, reviewers, and coding agents. Specific approved design direction for an issue or pull request may supersede this baseline; when that creates a durable change, update this document.

## Sources of truth

- [`groups-site/theme.json`](../../public_html/wp-content/themes/groups-site/theme.json) defines the available typography, color, spacing, and layout tokens.
- Component and theme styles should consume those tokens through WordPress CSS custom properties.
- Use the specifications below to choose the appropriate semantic token. Do not introduce raw hex colors or arbitrary spacing when an existing token expresses the same value.
- Token names such as `heading-3` and `heading-5` describe a visual scale. They do not determine the semantic HTML heading level.

## Typography baseline

| Role | Specification | Preferred implementation |
|---|---|---|
| Body/UI | Inter, 16px/1.7, weight 400 | `var(--wp--preset--font-family--inter)`, `var(--wp--preset--font-size--normal)`, `var(--wp--custom--body--typography--line-height)` |
| Secondary text | Inter, 14px/1.55, weight 400 | Inter and `var(--wp--preset--font-size--small)` |
| Metadata and dates | Inter, 12–14px/600, uppercase, `0.04em` letter spacing | `extra-small` or `small` font-size token, according to context |
| Group title | EB Garamond, `clamp(40px, 5vw, 70px)`, weight 400, line-height 1.05–1.1 | `var(--wp--preset--font-family--eb-garamond)` and `var(--wp--preset--font-size--heading-1)` |
| Main-section `h2` | EB Garamond, `clamp(28px, 2.4vw, 36px)`, weight 400, line-height 1.3 | Semantic `h2` with the `heading-3` visual token |
| Card `h3` | EB Garamond, 26px/1.2, weight 400 | Semantic `h3` with `var(--wp--preset--font-size--heading-5)` |
| Utility/sidebar heading | Inter, 16px/1.4, weight 600 | Inter and `var(--wp--preset--font-size--normal)` |

Keep the document outline semantic. Apply a visual token or class when a heading needs a different visual size; do not change an `h2` to an `h3` merely to obtain smaller text.

## Layout and spacing

| Element | Specification | Preferred implementation |
|---|---|---|
| Page width | 1160px maximum | `contentSize` and `wideSize` in `theme.json` |
| Horizontal padding | `clamp(20px, 4vw, 80px)` | `var(--wp--preset--spacing--edge-space)` |
| Section spacing | 50–80px | `var(--wp--preset--spacing--60)` |
| Component gaps | 10px, 20px, or 30px | `var(--wp--preset--spacing--10)`, `--20`, or `--30` |

Avoid arbitrary gap and margin values when one of these tokens fits the composition.

## Component baseline

| Component | Specification |
|---|---|
| Primary button | Inter 16px/600, 14px 32px padding, 2px radius |
| Card | 1px border using `light-grey-1`, 4px radius, 24px internal padding |
| Input | 1px border, 2px radius, approximately 8px 12px padding |
| Focus indicator | 2px `blueberry-1` outline with a 2px offset |
| Modal | Approximately 520px maximum width, 8px radius, 24px 32px 32px content padding |

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

Frontend modals must not inherit the legacy WordPress admin blue (`#007cba`). Scope the WordPress component accent variables to the event and message modals:

```css
.wporg-groups-event-modal,
.wporg-groups-message-modal {
    --wp-components-color-accent:
        var(--wp--preset--color--blueberry-1);
    --wp-components-color-accent-darker-10:
        var(--wp--preset--color--deep-blueberry);
    --wp-components-color-accent-darker-20:
        var(--wp--preset--color--dark-blueberry);
}
```

## Missing imagery

Handle missing imagery deliberately. When cards in the same collection contain images, preserve a consistent 16:9 image region and use the plain `blueberry-4` (`#eff2ff`) surface as the placeholder. Do not invent an illustration.

This is a provisional treatment. Ask Brand whether the placeholder should eventually use official default artwork, and update this guide if that decision changes.

## Review checklist

### Heading hierarchy

- Every main-column section heading—including “My upcoming events,” “Upcoming events,” “About this group,” “News,” and “Sponsors”—uses the main-section `h2` treatment: EB Garamond with the `heading-3` visual token.
- Reserve Inter 16px/600 for utility and sidebar headings.
- Historical examples of the inconsistency are the pinned [My Events CSS](https://github.com/WordPress/wordcamp.org/blob/31e76e7effa1a8021cfc7571d3a12bfd50365707/public_html/wp-content/mu-plugins/wporg-groups-frontend/src/blocks/my-events/style.css#L5-L10) and [News CSS](https://github.com/WordPress/wordcamp.org/blob/31e76e7effa1a8021cfc7571d3a12bfd50365707/public_html/wp-content/mu-plugins/wporg-groups-frontend/src/blocks/group-news/style.css#L1-L6).

### Content and fixtures

- Use “Organizer” consistently, with a `z`.
- Demonstrate the state where an organizer has also RSVP’d to an event.
- For the login experience, use: “Log in to your WordPress.org account to register for events and join the global WordPress community.” Do not add an extra headline unless a later approved design asks for one.
- These content requirements come from the [linked design feedback](https://github.com/WordPress/wordcamp.org/issues/1858#issuecomment-5239466525).

### Responsive behavior and accessibility

- Keep visual, DOM, keyboard, and screen-reader order aligned. Do not use CSS `order` to place a sidebar after the main column visually while leaving it first in the DOM.
- Allow long dates and translated labels to wrap without clipping or overlap.
- Test at 320px, 375px, and 430px viewport widths.
- Test at 200% browser zoom.
- Complete a keyboard pass that verifies focus visibility and logical focus order.
- The original ordering concern is recorded in the [pull-request review](https://github.com/WordPress/wordcamp.org/pull/1860#discussion_r3764072055).

### About content

Make an explicit decision about newcomer information. Either retain a compact “About this group” section or provide a prominent, real navigation route to that content. Do not silently strand useful newcomer content. If the product decision remains unresolved, flag it during review rather than choosing implicitly in CSS or templates.

## Minimum visual QA matrix

Before requesting review for a groups-site UI change, cover the relevant combinations:

| Area | Cases |
|---|---|
| Viewport | Desktop plus 320px, 375px, and 430px |
| Zoom | 100% and 200% |
| Input | Pointer and keyboard |
| Controls | Resting, hover, focus-visible, disabled, loading, success, and error |
| Content | Long dates/labels, missing imagery, and organizer-plus-attendee state |
| Theme integration | Primary/outline button contrast and frontend modal accent colors |
