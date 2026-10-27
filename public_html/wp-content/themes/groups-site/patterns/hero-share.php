<?php
/**
 * Title: Hero share links
 * Slug: groups-site/hero-share
 * Categories: groups-site
 * Inserter: no
 *
 * The share row inside the front-page hero's polaroid card: a "Share" label
 * and one icon per network, each opening that network's share composer
 * pre-filled with this group's name and URL. Only networks with a real web
 * share endpoint are listed — Instagram, TikTok, and YouTube have none, so
 * they can only join this row once groups can store their own profile URLs.
 *
 * Mastodon has no canonical share host (every user lives on their own
 * instance); mastodonshare.com is a static redirector that asks which
 * instance to compose on.
 *
 * @package WordCamp\Groups\Site
 */

namespace WordCamp\Groups\Site\Patterns\HeroShare;

defined( 'ABSPATH' ) || exit;

$share_url  = home_url( '/' );
$share_text = get_bloginfo( 'name' );

// Every endpoint wants the same two facts under its own key names, so build
// the query strings once here and keep the list below to one line per network.
$wporg_query = static function ( array $args ): string {
	return http_build_query( $args, '', '&', PHP_QUERY_RFC3986 );
};

$text_and_url = $wporg_query(
	array(
		'text' => $share_text,
		'url'  => $share_url,
	)
);

$tumblr_query = $wporg_query(
	array(
		'canonicalUrl' => $share_url,
		'title'        => $share_text,
	)
);

// Networks that take no separate URL field want it inside the text.
$text_including_url = $wporg_query( array( 'text' => $share_text . ' ' . $share_url ) );

$share_links = array(
	array(
		'service' => 'x',
		'label'   => 'Share on X',
		'url'     => 'https://x.com/intent/post?' . $text_and_url,
	),
	array(
		'service' => 'bluesky',
		'label'   => 'Share on Bluesky',
		'url'     => 'https://bsky.app/intent/compose?' . $text_including_url,
	),
	array(
		'service' => 'mastodon',
		'label'   => 'Share on Mastodon',
		'url'     => 'https://mastodonshare.com/?' . $text_and_url,
	),
	array(
		'service' => 'threads',
		'label'   => 'Share on Threads',
		'url'     => 'https://www.threads.net/intent/post?' . $text_including_url,
	),
	array(
		'service' => 'facebook',
		'label'   => 'Share on Facebook',
		'url'     => 'https://www.facebook.com/sharer/sharer.php?' . $wporg_query( array( 'u' => $share_url ) ),
	),
	array(
		'service' => 'linkedin',
		'label'   => 'Share on LinkedIn',
		'url'     => 'https://www.linkedin.com/sharing/share-offsite/?' . $wporg_query( array( 'url' => $share_url ) ),
	),
	array(
		'service' => 'tumblr',
		'label'   => 'Share on Tumblr',
		'url'     => 'https://www.tumblr.com/widgets/share/tool?' . $tumblr_query,
	),
);

?>
<!-- wp:group {"className":"groups-site-hero-share","layout":{"type":"flex","flexWrap":"wrap","verticalAlignment":"center","justifyContent":"space-between"}} -->
<div class="wp-block-group groups-site-hero-share">
	<!-- wp:paragraph {"fontSize":"small","textColor":"charcoal-3"} -->
	<p class="has-charcoal-3-color has-text-color has-small-font-size">Share</p>
	<!-- /wp:paragraph -->

	<!-- wp:social-links {"iconColor":"charcoal-1","iconColorValue":"#1e1e1e","openInNewTab":true,"className":"is-style-logos-only"} -->
	<ul class="wp-block-social-links has-icon-color is-style-logos-only">
		<?php foreach ( $share_links as $share_link ) : ?>
		<!-- wp:social-link {"url":"<?php echo esc_url( $share_link['url'] ); ?>","service":"<?php echo esc_attr( $share_link['service'] ); ?>","label":"<?php echo esc_attr( $share_link['label'] ); ?>"} /-->
		<?php endforeach; ?>
	</ul>
	<!-- /wp:social-links -->
</div>
<!-- /wp:group -->
