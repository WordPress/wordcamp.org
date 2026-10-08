<?php

class WCB_Body extends WCB_Elements {
	function before() {
		echo '<body ';
		body_class();
		echo '>';
		wp_body_open();
	}

	function after() {
		echo '</body>';
	}
}


