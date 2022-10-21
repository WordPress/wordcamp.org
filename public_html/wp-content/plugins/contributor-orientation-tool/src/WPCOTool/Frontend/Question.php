<?php

namespace WPCOTool\Frontend;

/**
 * Class Question responsible for form fields data
 * @package WPCOTool\Frontend
 */
class Question {

	/**
	 * Question label displayed to the user in the form
	 * @var string
	 */
	private $label;

	/**
	 * List of teams related to this label
	 * @var array
	 */
	private $teams;

	/**
	 * Question constructor.
	 *
	 * @param string $label Question label displayed to the user in the form
	 * @param array $teams List of teams related to this label
	 */
	public function __construct( string $label, array $teams ) {

		$this->label = $label;
		$this->teams = $teams;

	}

	/**
	 * Return form field label
	 * @return string
	 */
	public function get_label() {

		return $this->label;

	}

	/**
	 * Return teams array. Form field value
	 * @return array
	 */
	public function get_teams() {

		return $this->teams;

	}

}
