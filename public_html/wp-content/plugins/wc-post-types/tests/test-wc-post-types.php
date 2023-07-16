<?php

namespace WordCamp\WC_Post_Types\Tests;
use WordCamp_Post_Types_Plugin;
use WP_UnitTestCase, WP_UnitTest_Factory;

defined( 'WPINC' ) || die();

/**
 * @group wc-post-types
 */
class Test_WordCamp_New_Site extends WP_UnitTestCase {
	/**
	 * Set up shared fixtures for these tests.
	 */
	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) : void {
		$factory->post->create( array(
			'post_type'  => 'wcb_sponsor',
			'meta_input' => array(
				'_wcpt_sponsor_website' => 'https://godaddy.com/pro',
			),
		) );

		$factory->post->create( array(
			'post_type'  => 'wcb_sponsor',
			'meta_input' => array(
				'_wcpt_sponsor_website' => 'https://www.bluehost.com/',
			),
		) );

		// No website value.
		$factory->post->create( array(
			'post_type' => 'wcb_sponsor',
		) );

		// Empty website value.
		$factory->post->create( array(
			'post_type'  => 'wcb_sponsor',
			'meta_input' => array(
				'_wcpt_sponsor_website' => '',
			),
		) );
	}

	/**
	 * @covers WordCamp_Post_Types_Plugin::add_nofollow_to_sponsor_links
	 * @covers WordCamp_Post_Types_Plugin::get_sponsor_domains
	 *
	 * @dataProvider data_add_nofollow_to_sponsor_links
	 */
	public function test_add_nofollow_to_sponsor_links( string $content, string $expected ) : void {
		$actual = WordCamp_Post_Types_Plugin::add_nofollow_to_sponsor_links( $content );

		$this->assertSame( $expected, $actual );
	}

	/**
	 * Test cases for test_add_nofollow_to_sponsor_links().
	 */
	public function data_add_nofollow_to_sponsor_links() : array {
		return array(
			// Based on https://santaclarita.wordcamp.org/2021/sponsor/godaddy-pro/
			'sponsor with existing rel, sponsor with empty rel, non-sponsor' => array(
				'content'  => '<p><strong><a rel="noreferrer noopener" href="https://godaddy.com/pro/hub-dashboard?utm_source=wcglobal_2021_sponsorship&amp;utm_medium=events&amp;utm_campaign=en-us_events_prg_awa_partners_part_open_001" target="_blank">GoDaddy Pro</a></strong> was built by and for website designers and developers. Whether you’re new to web design or growing your existing business, you’ll find free tools, products, guidance, and expert support to help you more efficiently create and maintain beautiful sites — and wow clients!</p>
								<p>this is an extra link that shouldn\'t be changed because it\'s not a sponsor: <a href="https://en.wikipedia.org/wiki/Super_Bock_Arena_-_Pavilh%C3%A3o_Rosa_Mota">venue</a> </p>
								<p>Learn more about <a href="https://godaddy.com/pro/hub-dashboard?utm_source=wcglobal_2021_sponsorship&amp;utm_medium=events&amp;utm_campaign=en-us_events_prg_awa_partners_part_open_001"><strong>GoDaddy Pro</strong></a> today. </p>',
				'expected' => '<p><strong><a href="https://godaddy.com/pro/hub-dashboard?utm_source=wcglobal_2021_sponsorship&amp;utm_medium=events&amp;utm_campaign=en-us_events_prg_awa_partners_part_open_001" target="_blank" rel="noreferrer noopener nofollow">GoDaddy Pro</a></strong> was built by and for website designers and developers. Whether you’re new to web design or growing your existing business, you’ll find free tools, products, guidance, and expert support to help you more efficiently create and maintain beautiful sites — and wow clients!</p>
								<p>this is an extra link that shouldn\'t be changed because it\'s not a sponsor: <a href="https://en.wikipedia.org/wiki/Super_Bock_Arena_-_Pavilh%C3%A3o_Rosa_Mota">venue</a> </p>
								<p>Learn more about <a href="https://godaddy.com/pro/hub-dashboard?utm_source=wcglobal_2021_sponsorship&amp;utm_medium=events&amp;utm_campaign=en-us_events_prg_awa_partners_part_open_001" rel="nofollow"><strong>GoDaddy Pro</strong></a> today. </p>',
			),

			'www.{domain} works for sponsor with canonical domain' => array(
				'content'  => '<p><a href="https://godaddy.com/pro/">GoDaddy Pro</a> lorum ipsum.</p>
								<p>Learn more about <a href="https://www.godaddy.com/pro/">GoDaddy Pro</a> lorum ipsum.</p>',
				'expected' => '<p><a href="https://godaddy.com/pro/" rel="nofollow">GoDaddy Pro</a> lorum ipsum.</p>
								<p>Learn more about <a href="https://www.godaddy.com/pro/" rel="nofollow">GoDaddy Pro</a> lorum ipsum.</p>',
			),

			'canonical domain works for sponsor with www.domain' => array(
				'content'  => '<p><a href="https://bluehost.com/">BlueHost</a> lorum ipsum.</p>
								<p>Learn more about <a href="https://www.bluehost.com/">BlueHost</a> lorum ipsum.</p>',
				'expected' => '<p><a href="https://bluehost.com/" rel="nofollow">BlueHost</a> lorum ipsum.</p>
								<p>Learn more about <a href="https://www.bluehost.com/" rel="nofollow">BlueHost</a> lorum ipsum.</p>',
			),
		);
	}
}
