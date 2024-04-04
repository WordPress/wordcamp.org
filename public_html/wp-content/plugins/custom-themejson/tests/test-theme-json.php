<?php

namespace WordCamp\CustomThemeJSON;
use WP_UnitTestCase;

defined( 'WPINC' ) || die();

/**
 * Class Test_Synchronize_Remote_CSS
 *
 * @group custom-themejson
 */
class Test_ThemeJSON_Overrided extends WP_UnitTestCase {
	/**
	 * Test get_current_theme_json function
	 */
	public function test_get_current_theme_json() {
		$current_data = Theme_JSON::get_current_theme_json();
		$this->assertSame( $current_data  );
	}
        /**
         * Test get_custom_file function
         */
        // public function test_override() {
        //         $file_path = Theme_JSON::get_custom_file();

}
