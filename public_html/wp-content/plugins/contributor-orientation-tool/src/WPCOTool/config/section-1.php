<?php
/**
 * Shortcode form section 1 config array
 */
return array(
	'headline'  => esc_html__( 'What do you do with WordPress?', 'contributor-orientation-tool' ),
	'questions' => array(
		array(
			'label' => esc_html__( 'I\'m a developer', 'contributor-orientation-tool' ), // Form field label
			'teams' => array(
				'support',
				'community',
				'core',
				'meta',
				'themes',
				'plugins',
				'documentation',
				'mobile',
				'accessibility',
				'tide',
				'cli',
			), // Form field value
			'img'=>'developer.png'
		),
		array(
			'label' => esc_html__( 'I\'m a designer', 'contributor-orientation-tool' ),
			'teams' => array(
				'support',
				'community',
				'documentation',
				'design',
				'mobile',
			),
			'img'=>'designer.png'
		),
		array(
			'label' => esc_html__( 'I\'m a content creator, blogger or marketeer', 'contributor-orientation-tool' ),
			'teams' => array(
				'support',
				'community',
				'polyglots',
				'training',
				'marketing',
				'tv',
				'documentation',
			),
			'img'=>'marketer.png'
		),
		array(
			'label' => esc_html__( 'I\'m a WordPress user / other', 'contributor-orientation-tool' ),
			'teams' => array(
				'support',
				'community',
				'polyglots',
				'marketing',
				'documentation',
			),
			'img'=>'user.png'
		),
	)
);
