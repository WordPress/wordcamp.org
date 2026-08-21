<?php
/**
 * Title: Group back link
 * Slug: groups-site/group-back-link
 * Categories: groups-site
 * Inserter: no
 *
 * Renders an "← {Group name}" link to the group home page. Inner templates
 * carry no other group-level navigation, so this is both the way back and
 * the only place the group's name appears for visitors landing on a shared
 * URL.
 *
 * @package WordCamp\Groups\Site
 */

namespace WordCamp\Groups\Site\Patterns\GroupBackLink;

defined( 'ABSPATH' ) || exit;
?>
<!-- wp:paragraph {"fontSize":"small","style":{"spacing":{"margin":{"bottom":"var:preset|spacing|30"}}}} -->
<p class="has-small-font-size" style="margin-bottom:var(--wp--preset--spacing--30)"><a href="<?php echo esc_url( home_url( '/' ) ); ?>">&larr; <?php echo esc_html( get_bloginfo( 'name' ) ); ?></a></p>
<!-- /wp:paragraph -->
