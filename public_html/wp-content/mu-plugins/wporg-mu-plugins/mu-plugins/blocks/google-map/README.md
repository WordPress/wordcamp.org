# Google Map

Displays a Google Map with markers for each event. Markers will be clustered for performance and UX. Optionally, show a list of the same events, and a search feature.

Currently only supports programmatic usage in block theme templates etc. There's no UI available for adding markers.

This doesn't currently utilize all the abilities of the `google-map-react` lib, but we can expand it over time.


## Usage

Place something like this in a pattern:

```php
<?php

$map_options = array(
	'id'      => 'all-upcoming-events',
	'markers' => get_all_upcoming_events(),
);

?>

<!-- wp:wporg/google-map <?php echo wp_json_encode( $map_options ); ?> /-->
```

`markers` should be an array of objects with the fields in the example below. The `timestamp` field should be a true Unix timestamp, meaning it assumes UTC. The `wporg_events` database table is one potential source for the events, but you can pass anything.

```php
array(
	0 => (object) array( ‘id’ => ‘72190236’, ‘type’ => ‘meetup’, ‘title’ => ‘WordPress For Beginners – WPSyd’, ‘url’ => ‘https://www.meetup.com/wordpress-sydney/events/294365830’, ‘meetup’ => ‘WordPress Sydney’, ‘location’ => ‘Sydney, Australia’, ‘latitude’ => ‘-33.865295’, ‘longitude’ => ‘151.2053’, ‘timestamp’ => 1693209600 ),
	1 => (object) array( ‘id’ => ‘72190237’, ‘type’ => ‘meetup’, ‘title’ => ‘WordPress Help Desk’, ‘url’ => ‘https://www.meetup.com/wordpress-gwinnett/events/292032515’, ‘meetup’ => ‘WordPress Gwinnett’, ‘location’ => ‘online’, ‘latitude’ => ‘33.94’, ‘longitude’ => ‘-83.96’, ‘timestamp’ => 1693260000 ),
	2 => (object) array ( 'id' => '72189909', 'type' => 'wordcamp', 'title' => 'WordCamp Jinja 2023', 'url' => 'https://jinja.wordcamp.org/2023/', 'meetup' => NULL, 'location' => 'Jinja City, Uganda', 'latitude' => '0.5862795', 'longitude' => '33.4589384', 'timestamp' => 1693803600, ),
)
```
