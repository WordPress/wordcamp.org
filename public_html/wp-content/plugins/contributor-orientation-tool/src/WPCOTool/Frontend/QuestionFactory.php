<?php

namespace WPCOTool\Frontend;

/**
 * Class QuestionFactory responsible for creating Question objects
 * @package WPCOTool\Frontend
 */
class QuestionFactory {

	/**
	 * Constructor
	 *
	 * @param string $name Form field name attribute
	 * @param string $label Question label displayed to the user in the form
	 * @param array $teams List of teams related to this label
	 *
	 * @return Question
	 */
	public static function create( string $label, array $teams ) {

		return new Question( $label, $teams );

	}

}
