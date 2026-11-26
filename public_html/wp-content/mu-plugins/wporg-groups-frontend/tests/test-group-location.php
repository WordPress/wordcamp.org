<?php

namespace WordCamp\Groups\Tests;

use GatherPress\Core\Geocoding;
use WordCamp\Groups\Frontend\Group_Location;

defined( 'WPINC' ) || die();

require_once __DIR__ . '/class-groups-testcase.php';

/**
 * @group groups
 */
class Test_Group_Location extends Groups_TestCase {
	/**
	 * @var string[] Geocoding requests made during the test.
	 */
	protected $requests = array();

	/**
	 * @var array Photon features the fake geocoder answers with.
	 */
	protected $features = array();

	/**
	 * Answer geocoding requests from `$features`.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->requests = array();
		$this->features = array( $this->feature( 52.520008, 13.404954, 'DE' ) );

		add_filter( 'pre_http_request', array( $this, 'fake_geocoder' ), 10, 3 );
	}

	/**
	 * Stand in for Photon, GatherPress's geocoding service.
	 *
	 * @param false|array $preempt
	 * @param array       $args
	 * @param string      $url
	 * @return false|array
	 */
	public function fake_geocoder( $preempt, $args, $url ) {
		if ( ! str_starts_with( $url, Geocoding::PHOTON_API_URL ) ) {
			return $preempt;
		}

		$this->requests[] = $url;

		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( array( 'features' => $this->features ) ),
			'response' => array( 'code' => 200 ),
			'cookies'  => array(),
		);
	}

	/**
	 * A Photon feature at a point in a country.
	 */
	protected function feature( float $lat, float $lng, string $country_code ): array {
		return array(
			'geometry'   => array( 'coordinates' => array( $lng, $lat ) ),
			'properties' => array( 'countrycode' => $country_code ),
		);
	}

	/**
	 * Save an in-person location.
	 */
	protected function save_city( string $city, string $country_code ): void {
		Group_Location\save_location(
			array(
				'type'        => Group_Location\TYPE_PHYSICAL,
				'city'        => $city,
				'countryCode' => $country_code,
			)
		);
	}

	/**
	 * Saving a city looks it up with its country and keeps the point.
	 */
	public function test_saving_a_city_stores_its_coordinates() {
		$this->save_city( 'Berlin', 'DE' );

		$this->assertSame(
			array(
				'latitude'  => 52.52,
				'longitude' => 13.405,
			),
			Group_Location\get_coordinates()
		);

		$this->assertCount( 1, $this->requests );
		$this->assertStringContainsString( 'q=Berlin, Germany&', $this->requests[0] );
	}

	/**
	 * Only a changed city is looked up again, and an online group has no point.
	 */
	public function test_coordinates_follow_location_changes() {
		$this->save_city( 'Berlin', 'DE' );
		$this->save_city( 'Berlin', 'DE' );

		$this->assertCount( 1, $this->requests );

		$this->features = array( $this->feature( 41.0082, 28.9784, 'TR' ) );
		$this->save_city( 'İstanbul', 'TR' );

		$this->assertCount( 2, $this->requests );
		$this->assertSame( 41.0082, Group_Location\get_coordinates()['latitude'] );

		Group_Location\save_location( array( 'type' => Group_Location\TYPE_ONLINE ) );

		$this->assertNull( Group_Location\get_coordinates() );
	}

	/**
	 * A city found only in another country leaves no point, rather than the old one.
	 */
	public function test_city_in_another_country_leaves_no_coordinates() {
		$this->save_city( 'Berlin', 'DE' );

		$this->features = array( $this->feature( 44.4689, -71.1851, 'US' ) );
		$this->save_city( 'Berlin', 'TR' );

		$this->assertNull( Group_Location\get_coordinates() );
	}
}
