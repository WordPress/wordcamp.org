<?php
namespace WordCamp\CampTix\IndonesianPayments\Tests;

/** Load before CampTix's final WordPress test bootstrap. */
function load_plugin() {
	require_once dirname( __DIR__ ) . '/camptix-indonesian-payments.php';
}
tests_add_filter( 'muplugins_loaded', __NAMESPACE__ . '\load_plugin' );
