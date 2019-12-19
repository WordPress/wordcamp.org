<?php
/**
 * Something here
 */

global $camptix, $wp_scripts, $wp_styles;

$camptix_tickets = $camptix->tmp( 'attendance_tickets' );
$camptix_options = $camptix->get_options();
?>
<html>
<head>
	<title>
		<?php
		printf(
			esc_html__( '%s Attendance', 'wordcamporg' ),
			esc_html( $camptix_options['event_name'] )
		);
		?>
	</title>

	<?php $wp_scripts->do_items( array( 'camptix-attendance-ui' ) ); ?>
	<?php $wp_styles->do_items( array( 'camptix-attendance-ui' ) ); ?>
	<script>
		_camptixAttendanceSecret = '<?php echo esc_js( $_GET['camptix-attendance'] ); ?>';
		_camptixAttendanceTickets = [ <?php echo esc_js( implode( ', ', array_map( 'absint', wp_list_pluck( $camptix_tickets, 'ID' ) ) ) ); ?> ];
	</script>

	<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=0" />
	<meta name="referrer" content="never" />
</head>
<body>
	<script id="tmpl-attendee" type="text/template">
		<div class="spinner-container"><span class="spinner"></span></div>
		<a href="#" class="status toggle <# if ( data.status ) { #> yes <# } #>"><div class="dashicons dashicons-admin-users"></div></a>

		<span class="name">
			<# if ( 'lastName' == data.sort ) { #>
				{{ data.lastName }}, {{ data.firstName }}
			<# } else { #>
				{{ data.firstName }} {{ data.lastName }}
			<# } #>
		</span>
	</script>

	<script id="tmpl-attendee-toggle" type="text/template">
		<img src="{{ data.avatar }}" />
		<p>Did <strong>{{ data.firstName }} {{ data.lastName }}</strong> attend <?php echo esc_html( $camptix_options['event_name'] ); ?>?</p>

		<div class="yes-no-container">
			<a href="#" class="yes">Yes</a>
			<a href="#" class="no">No</a>
		</div>

		<div class="extras">
			 <# for ( var i in data.extras ) {
				var item = data.extras[i];
				if ( item.length > 1 ) { #>
					<strong>{{ item[0] }}:</strong> {{ item[1] }}<br>
				<# } else { #>
					{{ item[0] }}<br>
				<# } #>
			 <# } #>
		</div>

		<a href="#" class="close dashicons dashicons-no"></a>
	</script>

	<script id="tmpl-application" type="text/template">
		<div class="overlay"></div>

		<header>
			<div class="menu">
				<a href="#" class="dashicons dashicons-menu"></a>
				<div class="submenu">
					<a href="#" class="search">Search</a>
					<a href="#" class="filter">Sort & Filter</a>
					<a href="#" class="refresh">Refresh</a>
				</div>
			</div>
			<h1><?php echo esc_html( $camptix_options['event_name'] ); ?></h1>
		</header>

		<div id="attendees-list-wrapper">
			<ul class="attendees-list">
				<li class="loading">
					<div class="spinner-container"><span class="spinner"></span></div>
					<span>Loading...</span>
				</li>
			</ul>
		</div>
	</script>

	<script id="tmpl-attendee-search" type="text/template">
		<a href="#" class="close dashicons dashicons-no"></a>
		<div class="wrapper">
			<input type="text" autocomplete="off" placeholder="Search" />
		</div>
	</script>

	<script id="tmpl-attendee-filter" type="text/template">
		<a href="#" class="close dashicons dashicons-no"></a>
		<div class="wrapper">
			<h1>Sort & Filter</h1>

			<h1 class="section-title">Sort Attendees By</h1>
			<ul class="filter-sort section-controls">
				<li data-sort="firstName" <# if ( data.sort == 'firstName' ) { #> class="selected" <# } #> >First Name</li>
				<li data-sort="lastName" <# if ( data.sort == 'lastName' ) { #> class="selected" <# } #> >Last Name</li>
				<li data-sort="orderDate" <# if ( data.sort == 'orderDate' ) { #> class="selected" <# } #> >Order Date</li>
			</ul>

			<h1 class="section-title">Attendance</h1>
			<ul class="filter-attendance section-controls">
				<li data-attendance="none" <# if ( data.attendance == 'none' ) { #> class="selected" <# } #> >All</li>
				<li data-attendance="attending" <# if ( data.attendance == 'attending' ) { #> class="selected" <# } #> >Attending</li>
				<li data-attendance="not-attending" <# if ( data.attendance == 'not-attending' ) { #> class="selected" <# } #> >Not Attending</li>
			</ul>

			<h1 class="section-title">Tickets</h1>
			<ul class="filter-tickets section-controls">
				<?php foreach ( $camptix_tickets as $ticket ) : ?>
				<li data-ticket-id="<?php echo absint( $ticket->ID ); ?>" <# if ( _.contains( data.tickets, <?php echo absint( $ticket->ID ); ?> ) ) { #> class="selected" <# } #> ><?php echo esc_html( $ticket->post_title ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
	</script>
</body>
