<?php
/**
 * Plugin Name: Open Horizons Scholarships
 * Description: Applications for the Open Horizons travel scholarship, and the screens Comet reviews them on.
 * Version:     0.1
 * Author:      WordCamp Central
 * Author URI:  https://wordcamp.org
 *
 * Applications hold personal data, some of it special-category, so the post type is non-public: no front-end
 * URLs, no REST endpoint, and only users with `wordcamp_review_scholarships` see it in wp-admin. Activated on
 * central.wordcamp.org only.
 *
 * @package WordCamp\Open_Horizons
 */

declare( strict_types = 1 );

namespace WordCamp\Open_Horizons;

defined( 'WPINC' ) || die();

/**
 * The application post type.
 */
const POST_TYPE = 'oh_application';

/**
 * The capability that grants access to applications, through the `scholarship_reviewer` subrole.
 */
const CAPABILITY = 'wordcamp_review_scholarships';

require_once __DIR__ . '/includes/post-type.php';
require_once __DIR__ . '/includes/events.php';
require_once __DIR__ . '/includes/fields.php';
require_once __DIR__ . '/includes/form.php';
require_once __DIR__ . '/includes/privacy.php';

if ( is_admin() ) {
	require_once __DIR__ . '/includes/admin.php';
}
