<?php
/**
 * Shortcode form teams
 */
$teams = array(
	'support' => array(
		'name'          => esc_html__( 'Support', 'contributor-orientation-tool' ),
		'description'   => esc_html__( 'Answering a question in the support forums or IRC is one of the easiest ways to start contributing. Everyone knows the answer to something!', 'contributor-orientation-tool' ),
		'icon'          => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><rect x="0" fill="none" width="20" height="20"/><g><path d="M11 6h-.82C9.07 6 8 7.2 8 8.16V10l-3 3v-3H3c-1.1 0-2-.9-2-2V3c0-1.1.9-2 2-2h6c1.1 0 2 .9 2 2v3zm0 1h6c1.1 0 2 .9 2 2v5c0 1.1-.9 2-2 2h-2v3l-3-3h-1c-1.1 0-2-.9-2-2V9c0-1.1.9-2 2-2z"/></g></svg>', // Team icon svg code
		'url'           => 'https://make.wordpress.org/support/handbook/getting-started/', // Url to the team WordPress.org page for the results page
	), // Data for the final results page, can have multiple teams in single result,
	'community' => array(
		'name'          => esc_html__( 'Community', 'contributor-orientation-tool' ),
		'description'   => esc_html__( 'If you’re interested in organizing a meetup or a WordCamp, the community blog is a great place to get started. There are groups working to support events, to create outreach and training programs, and generally support the community.', 'contributor-orientation-tool' ),
		'icon'          => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><rect x="0" fill="none" width="20" height="20"/><g><path d="M8.03 4.46c-.29 1.28.55 3.46 1.97 3.46 1.41 0 2.25-2.18 1.96-3.46-.22-.98-1.08-1.63-1.96-1.63-.89 0-1.74.65-1.97 1.63zm-4.13.9c-.25 1.08.47 2.93 1.67 2.93s1.92-1.85 1.67-2.93c-.19-.83-.92-1.39-1.67-1.39s-1.48.56-1.67 1.39zm8.86 0c-.25 1.08.47 2.93 1.66 2.93 1.2 0 1.92-1.85 1.67-2.93-.19-.83-.92-1.39-1.67-1.39-.74 0-1.47.56-1.66 1.39zm-.59 11.43l1.25-4.3C14.2 10 12.71 8.47 10 8.47c-2.72 0-4.21 1.53-3.44 4.02l1.26 4.3C8.05 17.51 9 18 10 18c.98 0 1.94-.49 2.17-1.21zm-6.1-7.63c-.49.67-.96 1.83-.42 3.59l1.12 3.79c-.34.2-.77.31-1.2.31-.85 0-1.65-.41-1.85-1.03l-1.07-3.65c-.65-2.11.61-3.4 2.92-3.4.27 0 .54.02.79.06-.1.1-.2.22-.29.33zm8.35-.39c2.31 0 3.58 1.29 2.92 3.4l-1.07 3.65c-.2.62-1 1.03-1.85 1.03-.43 0-.86-.11-1.2-.31l1.11-3.77c.55-1.78.08-2.94-.42-3.61-.08-.11-.18-.23-.28-.33.25-.04.51-.06.79-.06z"/></g></svg>',
		'url'           => 'https://make.wordpress.org/community/handbook/',
	),
	'polyglots' => array(
		'name'          => esc_html__( 'Polyglots', 'contributor-orientation-tool' ),
		'description'   => esc_html__( 'WordPress is used all over the world and in many different languages. If you’re a polyglot, help out by translating WordPress into your own language. You can also assist with creating the tools that make translations easier.', 'contributor-orientation-tool' ),
		'icon'          => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><rect x="0" fill="none" width="20" height="20"/><g><path d="M11 7H9.49c-.63 0-1.25.3-1.59.7L7 5H4.13l-2.39 7h1.69l.74-2H7v4H2c-1.1 0-2-.9-2-2V5c0-1.1.9-2 2-2h7c1.1 0 2 .9 2 2v2zM6.51 9H4.49l1-2.93zM10 8h7c1.1 0 2 .9 2 2v7c0 1.1-.9 2-2 2h-7c-1.1 0-2-.9-2-2v-7c0-1.1.9-2 2-2zm7.25 5v-1.08h-3.17V9.75h-1.16v2.17H9.75V13h1.28c.11.85.56 1.85 1.28 2.62-.87.36-1.89.62-2.31.62-.01.02.22.97.2 1.46.84 0 2.21-.5 3.28-1.15 1.09.65 2.48 1.15 3.34 1.15-.02-.49.2-1.44.2-1.46-.43 0-1.49-.27-2.38-.63.7-.77 1.14-1.77 1.25-2.61h1.36zm-3.81 1.93c-.5-.46-.85-1.13-1.01-1.93h2.09c-.17.8-.51 1.47-1 1.93l-.04.03s-.03-.02-.04-.03z"/></g></svg>',
		'url'           => 'https://make.wordpress.org/polyglots/handbook/about/get-involved/getting-started-at-a-contributor-day/',
	),
	'training' => array(
		'name'          => esc_html__( 'Training', 'contributor-orientation-tool' ),
		'description'   => esc_html__( 'The training team creates downloadable lesson plans and related materials for instructors to use in a live workshop environment. If you enjoy teaching people how to use and build stuff for WordPress, immediately stop what you’re doing and join our team!', 'contributor-orientation-tool' ),
		'icon'          => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><rect x="0" fill="none" width="20" height="20"/><g><path d="M10 10L2.54 7.02 3 18H1l.48-11.41L0 6l10-4 10 4zm0-5c-.55 0-1 .22-1 .5s.45.5 1 .5 1-.22 1-.5-.45-.5-1-.5zm0 6l5.57-2.23c.71.94 1.2 2.07 1.36 3.3-.3-.04-.61-.07-.93-.07-2.55 0-4.78 1.37-6 3.41C8.78 13.37 6.55 12 4 12c-.32 0-.63.03-.93.07.16-1.23.65-2.36 1.36-3.3z"/></g></svg>',
		'url'           => 'https://make.wordpress.org/training/handbook/getting-started/contributor-day-info/',
	),
	'marketing' => array(
		'name'          => esc_html__( 'Marketing', 'contributor-orientation-tool' ),
		'description'   => esc_html__( 'Our vision for the Marketing Team is to be the go-to resource for strategy and content for other WordPress teams.', 'contributor-orientation-tool' ),
		'icon'          => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><rect x="0" fill="none" width="20" height="20"/><g><path d="M10 1c7 0 9 2.91 9 6.5S17 14 10 14s-9-2.91-9-6.5S3 1 10 1zM5.5 9C6.33 9 7 8.33 7 7.5S6.33 6 5.5 6 4 6.67 4 7.5 4.67 9 5.5 9zM10 9c.83 0 1.5-.67 1.5-1.5S10.83 6 10 6s-1.5.67-1.5 1.5S9.17 9 10 9zm4.5 0c.83 0 1.5-.67 1.5-1.5S15.33 6 14.5 6 13 6.67 13 7.5 13.67 9 14.5 9zM6 14.5c.83 0 1.5.67 1.5 1.5s-.67 1.5-1.5 1.5-1.5-.67-1.5-1.5.67-1.5 1.5-1.5zm-3 2c.55 0 1 .45 1 1s-.45 1-1 1-1-.45-1-1 .45-1 1-1z"/></g></svg>',
		'url'           => 'https://make.wordpress.org/marketing/handbook/getting-involved/',
	),
	'tv' => array(
		'name'          => esc_html__( 'TV', 'contributor-orientation-tool' ),
		'description'   => esc_html__( 'The TV team reviews and approves every video submitted to WordPress.tv. They also help WordCamps with video post-production and are responsible for the captioning and subtitling of published videos. Reviewing videos is a great way to learn about WordPress and help the community: experience is not required to get involved.', 'contributor-orientation-tool' ),
		'icon'          => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><rect x="0" fill="none" width="20" height="20"/><g><path d="M12 13V7c0-1.1-.9-2-2-2H3c-1.1 0-2 .9-2 2v6c0 1.1.9 2 2 2h7c1.1 0 2-.9 2-2zm1-2.5l6 4.5V5l-6 4.5v1z"/></g></svg>',
		'url'           => 'https://make.wordpress.org/tv/handbook/',
	),
	'core' => array(
		'name'          => esc_html__( 'Core', 'contributor-orientation-tool' ),
		'description'   => esc_html__( 'The core team makes WordPress. Whether you’re a seasoned PHP developer or are just learning to code, we’d love to have you on board. You can write code, fix bugs, debate decisions, and help with development.', 'contributor-orientation-tool' ),
		'icon'          => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><rect x="0" fill="none" width="20" height="20"/><g><path d="M9 6l-4 4 4 4-1 2-6-6 6-6zm2 8l4-4-4-4 1-2 6 6-6 6z"/></g></svg>',
		'url'           => 'https://make.wordpress.org/core/handbook/about/getting-started-at-a-contributor-day/',
	),
	'meta' => array(
		'name'          => esc_html__( 'Meta', 'contributor-orientation-tool' ),
		'description'   => esc_html__( 'The Meta team makes WordPress.org, provides support, and builds tools for use by all the contributor groups. If you want to help make WordPress.org better, sign up for updates from the Meta blog.', 'contributor-orientation-tool' ),
		'icon'          => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><rect x="0" fill="none" width="20" height="20"/><g><path d="M18 13h1c.55 0 1 .45 1 1.01v2.98c0 .56-.45 1.01-1 1.01h-4c-.55 0-1-.45-1-1.01v-2.98c0-.56.45-1.01 1-1.01h1v-2h-5v2h1c.55 0 1 .45 1 1.01v2.98c0 .56-.45 1.01-1 1.01H8c-.55 0-1-.45-1-1.01v-2.98c0-.56.45-1.01 1-1.01h1v-2H4v2h1c.55 0 1 .45 1 1.01v2.98C6 17.55 5.55 18 5 18H1c-.55 0-1-.45-1-1.01v-2.98C0 13.45.45 13 1 13h1v-2c0-1.1.9-2 2-2h5V7H8c-.55 0-1-.45-1-1.01V3.01C7 2.45 7.45 2 8 2h4c.55 0 1 .45 1 1.01v2.98C13 6.55 12.55 7 12 7h-1v2h5c1.1 0 2 .9 2 2v2z"/></g></svg>',
		'url'           => 'https://make.wordpress.org/meta/handbook/about/contributor-day/',
	),
	'themes' => array(
		'name'          => esc_html__( 'Themes', 'contributor-orientation-tool' ),
		'description'   => esc_html__( 'The Theme Review Team reviews and approves every Theme submitted to the WordPress Theme repository. Reviewing Themes sharpens your own Theme development skills. You can help out and join the discussion on the blog.', 'contributor-orientation-tool' ),
		'icon'          => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><rect x="0" fill="none" width="20" height="20"/><g><path d="M14.48 11.06L7.41 3.99l1.5-1.5c.5-.56 2.3-.47 3.51.32 1.21.8 1.43 1.28 2.91 2.1 1.18.64 2.45 1.26 4.45.85zm-.71.71L6.7 4.7 4.93 6.47c-.39.39-.39 1.02 0 1.41l1.06 1.06c.39.39.39 1.03 0 1.42-.6.6-1.43 1.11-2.21 1.69-.35.26-.7.53-1.01.84C1.43 14.23.4 16.08 1.4 17.07c.99 1 2.84-.03 4.18-1.36.31-.31.58-.66.85-1.02.57-.78 1.08-1.61 1.69-2.21.39-.39 1.02-.39 1.41 0l1.06 1.06c.39.39 1.02.39 1.41 0z"/></g></svg>',
		'url'           => 'https://make.wordpress.org/themes/handbook/get-involved/getting-started-at-a-contribution-day/',
	),
	'plugins' => array(
		'name'          => esc_html__( 'Plugins', 'contributor-orientation-tool' ),
		'description'   => esc_html__( 'If you are a Plugin developer, subscribe to the Plugin review team blog to keep up with the latest updates, find resources, and learn about any issues around Plugin development.', 'contributor-orientation-tool' ),
		'icon'          => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><rect x="0" fill="none" width="20" height="20"/><g><path d="M13.11 4.36L9.87 7.6 8 5.73l3.24-3.24c.35-.34 1.05-.2 1.56.32.52.51.66 1.21.31 1.55zm-8 1.77l.91-1.12 9.01 9.01-1.19.84c-.71.71-2.63 1.16-3.82 1.16H6.14L4.9 17.26c-.59.59-1.54.59-2.12 0-.59-.58-.59-1.53 0-2.12l1.24-1.24v-3.88c0-1.13.4-3.19 1.09-3.89zm7.26 3.97l3.24-3.24c.34-.35 1.04-.21 1.55.31.52.51.66 1.21.31 1.55l-3.24 3.25z"/></g></svg>',
		'url'           => 'https://make.wordpress.org/plugins/handbook/',
	),
	'documentation' => array(
		'name'          => esc_html__( 'Documentation', 'contributor-orientation-tool' ),
		'description'   => esc_html__( 'Good documentation lets people help themselves when they get stuck. The docs team is responsible for creating documentation and is always on the look-out for writers.', 'contributor-orientation-tool' ),
		'icon'          => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><rect x="0" fill="none" width="20" height="20"/><g><path d="M6 15V2h10v13H6zm-1 1h8v2H3V5h2v11z"/></g></svg>',
		'url'           => 'https://make.wordpress.org/docs/handbook/get-involved/getting-started-at-a-contributor-day/',
	),
	'design'        => array(
		'name'          => esc_html__( 'Design', 'contributor-orientation-tool' ),
		'description'   => esc_html__( 'The design group is focused on the designing and developing the user interface. It’s a home for designers and UXers alike. There are regular discussions about mockups, design, and user testing.', 'contributor-orientation-tool' ),
		'icon'          => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><rect x="0" fill="none" width="20" height="20"/><g><path d="M8.55 3.06c1.01.34-1.95 2.01-.1 3.13 1.04.63 3.31-2.22 4.45-2.86.97-.54 2.67-.65 3.53 1.23 1.09 2.38.14 8.57-3.79 11.06-3.97 2.5-8.97 1.23-10.7-2.66-2.01-4.53 3.12-11.09 6.61-9.9zm1.21 6.45c.73 1.64 4.7-.5 3.79-2.8-.59-1.49-4.48 1.25-3.79 2.8z"/></g></svg>',
		'url'           => 'https://make.wordpress.org/design/handbook/get-involved/first-steps/',
	),
	'mobile'        => array(
		'name'          => esc_html__( 'Mobile', 'contributor-orientation-tool' ),
		'description'   => esc_html__( 'The mobile team builds the iOS and Android apps. Lend them your Java, Objective-C, or Swift skills. The team also needs designers, UX experts, and testers to give users an smooth experience on every device.', 'contributor-orientation-tool' ),
		'icon'          => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><rect x="0" fill="none" width="20" height="20"/><g><path d="M6 2h8c.55 0 1 .45 1 1v14c0 .55-.45 1-1 1H6c-.55 0-1-.45-1-1V3c0-.55.45-1 1-1zm7 12V4H7v10h6zM8 5h4l-4 5V5z"/></g></svg>',
		'url'           => 'https://make.wordpress.org/mobile/',
	),
	'accessibility' => array(
		'name'          => esc_html__( 'Accessibility', 'contributor-orientation-tool' ),
		'description'   => esc_html__( 'The a11y group provides accessibility expertise across the project. They make sure that WordPress core and all of WordPress’ resources are accessible.', 'contributor-orientation-tool' ),
		'icon'          => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><rect x="0" fill="none" width="20" height="20"/><g><path d="M10 2.6c.83 0 1.5.67 1.5 1.5s-.67 1.51-1.5 1.51c-.82 0-1.5-.68-1.5-1.51s.68-1.5 1.5-1.5zM3.4 7.36c0-.65 6.6-.76 6.6-.76s6.6.11 6.6.76-4.47 1.4-4.47 1.4 1.69 8.14 1.06 8.38c-.62.24-3.19-5.19-3.19-5.19s-2.56 5.43-3.18 5.19c-.63-.24 1.06-8.38 1.06-8.38S3.4 8.01 3.4 7.36z"/></g></svg>',
		'url'            => 'https://make.wordpress.org/accessibility/handbook/get-involved/getting-started-at-a-contributor-day/',
	),
	'tide' => array(
		'name'          => esc_html__( 'Tide', 'contributor-orientation-tool' ),
		'description'   => esc_html__( 'Tide is a series of automated tests run against every plugin and theme in the directory and then displays PHP compatibility and test errors/warnings in the directory.', 'contributor-orientation-tool' ),
		'icon'          => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><rect x="0" fill="none" width="20" height="20"/><g><path d="M17 7.2V3H3v7.1c2.6-.5 4.5-1.5 6.4-2.6.2-.2.4-.3.6-.5v3c-1.9 1.1-4 2.2-7 2.8V17h14V9.9c-2.6.5-4.4 1.5-6.2 2.6-.3.1-.5.3-.8.4V10c2-1.1 4-2.2 7-2.8z"/></g></svg>',
		'url'           => 'https://make.wordpress.org/tide/feedback-support/',
	),
	'cli' => array(
		'name'          => esc_html__( 'CLI', 'contributor-orientation-tool' ),
		'description'   => esc_html__( 'WP-CLI is the official command line tool for interacting with and managing your WordPress sites.', 'contributor-orientation-tool' ),
		'icon'          => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><rect x="0" fill="none" width="20" height="20"/><g><path d="M17 7.2V3H3v7.1c2.6-.5 4.5-1.5 6.4-2.6.2-.2.4-.3.6-.5v3c-1.9 1.1-4 2.2-7 2.8V17h14V9.9c-2.6.5-4.4 1.5-6.2 2.6-.3.1-.5.3-.8.4V10c2-1.1 4-2.2 7-2.8z"/></g></svg>',
		'url'           => 'https://make.wordpress.org/cli/handbook/contributing/',
	),
);

ksort( $teams );

return $teams;
