<?php
/**
 * Shortcode form section 3 config array
 */
return array(
	'headline'  => esc_html__( 'Are these areas you have experience with or are eager to try?', 'contributor-orientation-tool' ),
	'questions' => array(
		array(
			'label' => esc_html__( 'Q&A, helping others use programmes, support', 'contributor-orientation-tool' ), // Form field label
			'teams' => array(
				'support',
			),
			'img'=>'support.png' // Form field value
		),
		array(
			'label' => esc_html__( 'Organizing events, groups, etc.', 'contributor-orientation-tool' ),
			'teams' => array(
				'community',
			),
			'img'=>'community.png'
		),
		array(
			'label' => esc_html__( 'Writing translations', 'contributor-orientation-tool' ),
			'teams' => array(
				'polyglots',
			),
			'img'=>'polyglots.png'
		),
		array(
			'label' => esc_html__( 'Teaching others', 'contributor-orientation-tool' ),
			'teams' => array(
				'training',
			),
			'img'=>'training.png'
		),
		array(
			'label' => esc_html__( 'Marketing', 'contributor-orientation-tool' ),
			'teams' => array(
				'marketing',
			),
			'img'=>'marketer.png'
		),
		array(
			'label' => esc_html__( 'Photography, video recording or processing', 'contributor-orientation-tool' ),
			'teams' => array(
				'tv',
			),
			'img'=>'tv.png'
		),
		array(
			'label' => esc_html__( 'Writing code, fixing bugs, writing developer documentation etc.', 'contributor-orientation-tool' ),
			'teams' => array(
				'core',
				'meta',
				'cli',
			),
			'img'=>'core.png'
		),
		array(
			'label' => esc_html__( 'Creating or editing WordPress themes', 'contributor-orientation-tool' ),
			'teams' => array(
				'themes',
			),
			'img'=>'themes.png'
		),
		array(
			'label' => esc_html__( 'Creating or editing WordPress plugins', 'contributor-orientation-tool' ),
			'teams' => array(
				'plugins',
			),
			'img'=>'plugins.png'
		),
		array(
			'label' => esc_html__( 'Writing content, creating documentation for projects or software', 'contributor-orientation-tool' ),
			'teams' => array(
				'documentation',
			),
			'img'=>'documentation.png'
		),
		array(
			'label' => esc_html__( 'Web design, UX or UI design', 'contributor-orientation-tool' ),
			'teams' => array(
				'design',
			),
			'img'=>'designer.png'
		),
		array(
			'label' => esc_html__( 'Mobile apps development or design', 'contributor-orientation-tool' ),
			'teams' => array(
				'mobile',
			),
			'img'=>'mobile.png'
		),
		array(
			'label' => esc_html__( 'Implementing accessibility standards', 'contributor-orientation-tool' ),
			'teams' => array(
				'accessibility',
			),
			'img'=>'accessibility.png'
		),
		array(
			'label' => esc_html__( 'Writing automated tests', 'contributor-orientation-tool' ),
			'teams' => array(
				'tide',
				'cli',
			),
			'img'=>'test.png'
		),
	)
);
