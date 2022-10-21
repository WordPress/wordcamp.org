<?php

namespace WPCOTool\Frontend;

/**
 * Class Team
 * Represents WP.org team
 *
 * @package WPCOTool\Frontend
 */
class Team {

	/**
	 * Team id. Usually team name
	 * @var string
	 */
	private $id;

	/**
	 * Team name
	 * @var string
	 */
	private $name;

	/**
	 * Team description
	 * @var string
	 */
	private $description;

	/**
	 * Team icon (dashicons SVG code)
	 * @see https://github.com/WordPress/dashicons/tree/master/svg-min
	 * @var string
	 */
	private $icon;

	/**
	 * Url to the team page on WordPress.org
	 * @var string
	 */
	private $url;

	/**
	 * Team constructor.
	 *
	 * @param string $id Team id
	 * @param string $name Team name
	 * @param string $description Team description
	 * @param string $icon Team icon (dashicons)
	 * @param string $url Url to the team page on WordPress.org
	 */
	public function __construct( string $id, string $name, string $description = '', string $icon = '', string $url = '' ) {

		$this->id = sanitize_text_field( $id );
		$this->name = sanitize_text_field( $name );
		$this->description = sanitize_text_field( $description );
		$this->icon = $icon;
		$this->url = sanitize_text_field( $url );

	}

	/**
	 * Return team id
	 * @return string
	 */
	public function get_id() {
		return $this->id;
	}

	/**
	 * Return team name
	 * @return string
	 */
	public function get_name() {
		return $this->name;
	}

	/**
	 * Return team description
	 * @return string
	 */
	public function get_description() {
		return $this->description;
	}

	/**
	 * Return team icon
	 * @return string
	 */
	public function get_icon() {
		return $this->icon;
	}

	/**
	 * Return team page url on WordPress.org
	 * @return string
	 */
	public function get_url() {
		return $this->url;
	}

}
