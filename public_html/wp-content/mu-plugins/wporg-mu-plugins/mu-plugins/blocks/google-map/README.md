# Google Map

Displays a Google Map with markers for each event. Markers will be clustered for performance and UX. Optionally, show a list of the same events, and a search feature.

Currently only supports programmatic usage in block theme templates etc. There's no UI available for selecting attributes and adding markers, but that can be added in the future.

This doesn't currently utilize all the abilities of the `google-map-react` lib, but we can expand it over time.

You can pass markers directly to the block via the `markers` attribute, or use a pre-defined event filter to display events matching certain criteria.


## Map Setup

```html
<!-- wp:wporg/google-map {"id":"my-map","apiKey":"WORDCAMP_DEV_GOOGLE_MAPS_API_KEY"} /-->
```

`id` will be used in the HTML element that wraps the map/list.

`apiKey` should be the _name_ of a constant, not the value. It's not private because it'll be exposed in the HTTP request to Google Maps, but it should still be stored in a constant in a config file instead of `post_content`. That allows for centralization, documentation, and tracking changes over time. It should be restricted in Google Cloud Console to only the sites where it will be used, to prevent abuse.

Next, choose how you want to provide the markers. You can pass them directly from your code, or use a pre-defined event filter. See below for more.


## Passing Markers Directly

Place something like the following in a block or pattern. If you'll be pulling events from a database, a block is better because Gutenberg loads all patterns at `init` on all pages, regardless of whether or not they're used on that page.

```php
<?php

$map_options = array(
	'id'      => 'my-map',
	'apiKey'  => 'MY_API_KEY_CONSTANT',
	'markers' => get_my_markers()
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

If you have a small number of markers, you can manually json-encode them and then put them directly in the post content:

```html
<!-- wp:wporg/google-map {"id":"wp20","apiKey":"WORDCAMP_DEV_GOOGLE_MAPS_API_KEY","markers":[{"id":"72190010","type":"meetup","title":"ONLINE DISCUSSION- Learn about your DIVI Theme- Divisociety.com","url":"https://www.meetup.com/milwaukee-wordpress-meetup/events/292286293","meetup":"Greater Milwaukee Area WordPress Meetup","location":"online","latitude":"43.04","longitude":"-87.92","tz_offset":"-21600","timestamp":1700006400},{"id":"72190007","type":"meetup","title":"Meetup Virtual - SEO MÃ¡s allÃ¡ del ranking","url":"https://www.meetup.com/wpsanjose/events/294644892","meetup":"WordPress Meetup San JosÃ©","location":"online","latitude":"9.93","longitude":"-84.08","tz_offset":"-21600","timestamp":1700010000},{"id":"72190008","type":"meetup","title":"WordPress Developer Night - #IEWP","url":"https://www.meetup.com/inlandempirewp/events/292287676","meetup":"Inland Empire WordPress Meetup Group","location":"online","latitude":"33.99","longitude":"-117.37","tz_offset":"-28800","timestamp":1700017200}]} /-->
```


## Passing Markers with Event Filters

Instead of passing markers directly to the block, you can pass a `filterSlug` attribute, which corresponds to a pre-defined set of events. Some filters also support passing a start/end date, so you can restrict events to those dates.

Filters can be setup for anything, but some common examples are watch parties for WP anniversaries and the State of the Word.

1. If you're not using an existing filter, then add a new one to `get_events()` and/or `filter_potential_events()`. You can also use the `google_map_event_filters_{$filter_slug}` WP filter to register an event filter outside of this plugin. That can be useful in circumstances where the data is only used on a specific site, like WordCamp.org's `google_map_event_filters_city-landing-pages` filter.
1. Add the following to a pattern in your theme.

	```php
	$map_options = array(
		'id'         => 'wp20',
		'apiKey'     => 'WORDCAMP_DEV_GOOGLE_MAPS_API_KEY',
		'filterSlug' => 'wp20',
		'startDate'  => 'April 21, 2023',
		'endDate'    => 'May 30, 2023',
	);

	?>

	<!-- wp:wporg/google-map <?php echo wp_json_encode( $map_options ); ?> /-->
	```

	Alternatively, you could take that JSON and manually put it in the post source like this:

	```html
	<!-- wp:wporg/google-map {"id":"all-upcoming","apiKey":"WORDCAMP_DEV_GOOGLE_MAPS_API_KEY","filterSlug":"all-upcoming"} /-->

	<!-- wp:wporg/google-map {"id":"sotw-2023","apiKey":"WORDCAMP_DEV_GOOGLE_MAPS_API_KEY","filterSlug":"sotw","startDate":"December 10, 2023","endDate":"January 12, 2024","className":"is-style-sotw-2023"} /-->

	<!-- wp:wporg/google-map {"id":"wp20","apiKey":"WORDCAMP_DEV_GOOGLE_MAPS_API_KEY","filterSlug":"wp20","startDate":"April 21, 2023","endDate":"May 30, 2023"} /-->
	```

1. View the page where the block is used. That will create the cron job that updates the data automatically in the future.
1. Run `wp cron event run prime_event_filters` to test the filtering. Look at each title, and add any false positives to `$false_positives` in `filter_potential_events()`. If any events that should be included were ignored, add a keyword from the title to `$keywords`. Run the command after those changes and make sure it's correct now.


## Live Search vs GET Search

If the map/list is shown in a context where all of the events fit onto the same page, then it's generally best to use the default "live search" feature. The map markers and list items will be filtered down in real time as the user types.

If there's too many to fit on one page, then it's generally better to submit the search form to the server, so that all of the possible events can be searched, not just the ones on the current page. You can do that by setting the `searchFormAction` attribute to the URL of the page where search results should be displayed. That should be a page that has this block in the post content.
