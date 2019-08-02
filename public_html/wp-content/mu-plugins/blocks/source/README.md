# Code Structure

## Component Naming

Component names should describe what the component does/renders. You should favor clarity, use specific terms for the component instead of vague terms ("content", "meta", etc are vague unless you're talking about just `post_content` or `post_meta`). `*Container` is okay if necessary.

- Good: `SpeakerList`, `ScheduleGrid`, `GridInspectorPanel`
- Not good: `BlockContent`, `BlockControls`

File & folder names will mirror components, so `SpeakerList` should either be in `speaker-list/index.js` or `speaker-list.js`. Each file should have a one default export of the main component, additional exported components can be added in other files. Components should be kept one per file- stateless components are allowed, just not to be exported.

## Folder Structure

### `/blocks/`

Each folder here corresponds to a block. Usually a block folder will contain the following files.

- `index.js` - The main JS file for the block, this should export `NAME, SETTINGS` so the block can be registered.
- `edit.js` - Optional, you can extract the edit functionality to a separate component.
- `inspector-controls.js` - Optional, you can extract the sidebar settings to a separate component (this is rendered into the Edit component).
- Additional components used in rendering the editor preview (`speaker-list.js`, `organizer-select.js`, etc).
- `controller.php` - Register the block in PHP, for the front end view. Also sets up the attribute schema, populates the available options for block settings.
- `view.php` - The template used to render each block item (individual speaker, session, etc). These are collected and output to the front end of the site by `controller.php`.
- `style.scss` - Each block should have one SCSS file for any styles specific to this block, this will load on the front end of the site _and_ in the editor.
- ⚠️ `editor.scss` - A block can also have a second SCSS file for styles specific to this block that should only load in the editor. This does not work yet ⏳

### `/components/`

This is a place for shared components, things that are reused across multiple blocks. Each shared component should be its own folder, following the naming conventions above. A component folder can also have a `style.scss` for any styles (the same note about an `editor.scss` applies here too).

### `/data/`

This folder contains functionality related to data handling.

### `/i18n/`

This folder contains functionality related to translation & internationalization.

## CSS Naming

Our classnames should all have a prefix, to prevent leaking styles into other areas of the editor. In practice, this is slightly different between blocks and shared components.

### Blocks

Blocks will use `.wordcamp-{block}__` as a prefix, for example:

- organizers: `.wordcamp-organizers__`
- sessions: `.wordcamp-sessions__`
- speakers: `.wordcamp-speakers__`
- sponsors: `.wordcamp-sponsors__`

If you want to share a class name between blocks, you probably want a shared component.

### Components

Components will use `.wordcamp-{folder}__` as a prefix, for example:

- `<EditAppender />`: `.wordcamp-edit-appender`
- Image has no top-level component…
- Image -> `<Avatar />`: `.wordcamp-image__avatar-link-`
- Image -> `<FeaturedImage />`: `.wordcamp-image__featured-image-`
- `<PostList />`: `wordcamp-post-list__`

