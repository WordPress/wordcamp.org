<?php

namespace WordCamp\Multisite;
defined( 'WPINC' ) || die();


add_filter( 'global_terms_enabled', __NAMESPACE__ . '\disable_global_terms' );


/**
 * Disable Global Terms on new sites.
 *
 * Global Terms is an old, largely unused, and undocumented feature of WordPress. It was used on WordPress.com
 * for many years, but even they turned it off around 2015. We don't know why it was ever enabled for
 * WordCamp.org.
 *
 * When it's enabled, the "local" terms are still created like normal, but the `term_id_filter` will override
 * their IDs at runtime, so that all sites use the same ID for the same slug, across sites _and_ across taxonomies.
 * Because the local terms still exist, it can (theoretically) be safely disabled without any consequences, and
 * then the local terms will be used instead.
 *
 * When term splitting was introduced in WP 4.2 - 4.4, though, it was not compatible with Global Terms, and any
 * term that was split while Global Terms is enabled will have the wrong IDs set, which causes bugs, like not
 * being able to assign shared terms to a post, and not being able to edit the name of a shared term. So, we're
 * turning it off for new sites.
 *
 * It's left on for old sites out of caution, since there could be some unforeseeable consequences or hassles
 * with turning it off for them.
 *
 * This will not retroactively fix any terms that have been split with the wrong ID, those need to be fixed
 * manually.
 */
function disable_global_terms() {
	return wcorg_skip_feature( 'local_terms' );
}
