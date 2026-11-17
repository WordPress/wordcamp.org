<?php
/**
 * Title: Event - add to calendar
 * Slug: groups-site/event-add-to-calendar
 * Categories: groups-site
 * Inserter: no
 *
 * The "Add to calendar" dropdown in the single event info card. Its label
 * and link text live in block attributes and saved markup, so the block is
 * rendered from a pattern to make them translatable.
 *
 * @package WordCamp\Groups\Site
 */

namespace WordCamp\Groups\Site\Patterns\EventAddToCalendar;

defined( 'ABSPATH' ) || exit;

$dropdown_label = __( 'Add to calendar', 'wordcamporg' );

$dropdown_attributes = array(
	'dropdownId'           => 'dropdown-groups-site-add-to-calendar',
	'label'                => $dropdown_label,
	'labelColor'           => '#3858e9',
	'labelTextColor'       => '#3858e9',
	'dropdownBorderColor'  => '#d9d9d9',
	'dropdownBorderRadius' => 4,
	'dropdownWidth'        => '180',
	'itemTextColor'        => '#1e1e1e',
	'itemBgColor'          => '#ffffff',
	'itemHoverTextColor'   => '#1e1e1e',
	'itemHoverBgColor'     => '#f6f6f6',
	'itemDividerColor'     => '#d9d9d9',
	'metadata'             => array( 'name' => 'Add to calendar' ),
);

// One dropdown item's attributes per calendar. GatherPress fills in each
// link's real URL from its `#gatherpress-*-calendar` anchor; only the link
// text needs translating.
$calendar_links = array(
	array(
		'text'     => '<a href="#gatherpress-google-calendar" rel="noreferrer noopener nofollow" target="_blank">' . esc_html__( 'Google Calendar', 'wordcamporg' ) . '</a>',
		'metadata' => array( 'name' => 'Google Calendar' ),
	),
	array(
		'text'     => '<a href="#gatherpress-ical-calendar" rel="noreferrer noopener nofollow">' . esc_html__( 'iCal', 'wordcamporg' ) . '</a>',
		'metadata' => array( 'name' => 'iCal' ),
	),
	array(
		'text'     => '<a href="#gatherpress-outlook-calendar" rel="noreferrer noopener nofollow">' . esc_html__( 'Outlook', 'wordcamporg' ) . '</a>',
		'metadata' => array( 'name' => 'Outlook' ),
	),
	array(
		'text'     => '<a href="#gatherpress-yahoo-calendar" rel="noreferrer noopener nofollow" target="_blank">' . esc_html__( 'Yahoo Calendar', 'wordcamporg' ) . '</a>',
		'metadata' => array( 'name' => 'Yahoo Calendar' ),
	),
);
?>
<!-- wp:gatherpress/dropdown <?php echo serialize_block_attributes( $dropdown_attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- serialize_block_attributes() escapes what could end the block comment. ?> -->
<div class="wp-block-gatherpress-dropdown"><a class="wp-block-gatherpress-dropdown__trigger" href="#"><?php echo esc_html( $dropdown_label ); ?></a><div class="wp-block-gatherpress-dropdown__menu">
<?php foreach ( $calendar_links as $calendar_link ) : ?>
<!-- wp:gatherpress/dropdown-item <?php echo serialize_block_attributes( $calendar_link ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- serialize_block_attributes() escapes what could end the block comment. ?> -->
<div class="wp-block-gatherpress-dropdown-item"><?php echo wp_kses_post( $calendar_link['text'] ); ?></div>
<!-- /wp:gatherpress/dropdown-item -->
<?php endforeach; ?>
</div></div>
<!-- /wp:gatherpress/dropdown -->
