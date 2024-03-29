<?php

use PHPUnit\Framework\TestCase;

class ThemeJSONTest extends TestCase {

	public function testOverrideThemeJSON() {
		// Set up the test environment
		$themeJsonPath    = '/path/to/existing/theme.json';
		$newThemeJsonPath = '/path/to/new/theme.json';

		// Create an instance of the ThemeJSON class
		$themeJson = new ThemeJSON();

		// Call the method to override the theme.json
		$themeJson->overrideThemeJSON($themeJsonPath, $newThemeJsonPath);

		// Assert that the new theme.json file exists
		$this->assertFileExists($newThemeJsonPath);

		// Assert that the content of the new theme.json file is correct
		$expectedContent = file_get_contents($newThemeJsonPath);
		$this->assertEquals($expectedContent, $themeJson->getThemeJSONContent());
	}
}
