<?php
// phpcs:ignoreFile
/**
 * CampTix Admin Tools
 *
 * Handles the Tickets > Tools admin page, including Summarize, Revenue,
 * Export, Notify, and Refund tabs.
 *
 * @since 2.0.0
 */
class CampTix_Admin_Tools {

	/**
	 * @var CampTix_Plugin
	 */
	protected $plugin;

	/**
	 * @param CampTix_Plugin $plugin The main CampTix plugin instance.
	 */
	public function __construct( CampTix_Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * This is taken out here to illustrate how a third-party plugin or
	 * theme can hook into CampTix to add their own Summarize fields. This method
	 * grabs all the available tickets questions and adds them to Summarize.
	 */
	function summarize_extra_fields() {
		if ( 'summarize' != $this->get_tools_section() )
			return;

		// Adds all questions to Summarize and register the callback that counts all the things.
		add_filter( 'camptix_summary_fields', array( $this, 'camptix_summary_fields_extras' ) );
		add_action( 'camptix_summarize_by_field', array( $this, 'camptix_summarize_by_field_extras' ), 10, 3 );
	}

	/**
	 * Filters camptix_summary_fields to add user-defined
	 * questions to the Summarize list.
	 */
	function camptix_summary_fields_extras( $fields ) {
		$questions = $this->plugin->get_all_questions();
		foreach ( $questions as $question )
			$fields[ 'tix_q_' . $question->ID ] = apply_filters( 'the_title', $question->post_title );

		return $fields;
	}

	/**
	 * Runs during camptix_summarize_by_field, fetches answers from
	 * attendee objects and increments summary.
	 */
	function camptix_summarize_by_field_extras( $summarize_by, &$summary, $attendee ) {
		if ( 'tix_q_' != substr( $summarize_by, 0, 6 ) )
			return;

		$key = substr( $summarize_by, 6 );
		$answers = $this->plugin->get_attendee_answers( $attendee->ID );

		if ( isset( $answers[ $key ] ) && ! empty( $answers[ $key ] ) )
			$this->plugin->increment_summary( $summary, $answers[ $key ] );
		else
			$this->plugin->increment_summary( $summary, __( 'None', 'wordcamporg' ) );
	}

	/**
	 * The Tickets > Tools screen, doesn't use the settings API, but does use tabs.
	 */
	function menu_tools() {
		$options = $this->plugin->get_options();
		?>
		<div class="wrap">
			<h1><?php _e( 'CampTix Tools', 'wordcamporg' ); ?></h1>
			<?php settings_errors(); ?>
			<h3 class="nav-tab-wrapper"><?php $this->menu_tools_tabs(); ?></h3>
			<?php
				$section = $this->get_tools_section();
				if ( $section == 'summarize' )
					$this->menu_tools_summarize();
				elseif ( $section == 'revenue' )
					$this->menu_tools_revenue();
				elseif ( $section == 'export' )
					$this->menu_tools_export();
				elseif ( $section == 'notify' )
					$this->menu_tools_notify();
				elseif ( $section == 'refund' && ! $options['archived'] )
					$this->menu_tools_refund();
				else
					do_action( 'camptix_menu_tools_' . $section );
			?>
		</div>
		<?php
	}

	/**
	 * Remember the tabs in Tickets > Tools? This tells
	 * us which tab is currently active.
	 */
	function get_tools_section() {
		if ( isset( $_REQUEST['tix_section'] ) )
			return strtolower( $_REQUEST['tix_section'] );

		return 'summarize';
	}

	/**
	 * Tabs for Tickets > Tools, outputs the markup.
	 */
	function menu_tools_tabs() {
		$options = $this->plugin->get_options();
		$current_section = $this->get_tools_section();
		$sections = apply_filters( 'camptix_menu_tools_tabs', array(
			'summarize' => __( 'Summarize', 'wordcamporg' ),
			'revenue' => __( 'Revenue', 'wordcamporg' ),
			'export' => __( 'Export', 'wordcamporg' ),
			'notify' => __( 'Notify', 'wordcamporg' ),
		) );

		if ( current_user_can( $this->plugin->caps['refund_all'] ) && ! $options['archived'] && $options['refund_all_enabled'] )
			$sections['refund'] = __( 'Refund', 'wordcamporg' );

		foreach ( $sections as $section_key => $section_caption ) {
			$active = $current_section === $section_key ? 'nav-tab-active' : '';
			$url = add_query_arg( 'tix_section', $section_key );
			echo '<a class="nav-tab ' . esc_attr( $active ) . '" href="' . esc_url( $url ) . '">' . esc_html( $section_caption ) . '</a>';
		}
	}

	/**
	 * Tools > Summarize, the screen that outputs the summary tables,
	 * provides an export option, powered by the summarize_admin_init method,
	 * hooked (almost) at admin_init, because of additional headers. Doesn't use
	 * the Settings API so check for nonces/referrers and caps.
	 * @see summarize_admin_init()
	 */
	function menu_tools_summarize() {
		$summarize_by = isset( $_POST['tix_summarize_by'] ) ? $_POST['tix_summarize_by'] : 'ticket';
		?>
		<form method="post" action="<?php echo esc_url( add_query_arg( 'tix_summarize', 1 ) ); ?>">
			<table class="form-table">
				<tbody>
					<tr>
						<th scope="row"><?php _e( 'Summarize by', 'wordcamporg' ); ?></th>
						<td>
							<select name="tix_summarize_by">
								<?php foreach ( $this->plugin->get_available_summary_fields() as $value => $caption ) : ?>
									<?php
										if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) )
											$caption = mb_strlen( $caption ) > 30 ? mb_substr( $caption, 0, 30 ) . '...' : $caption;
										else
											$caption = strlen( $caption ) > 30 ? substr( $caption, 0, 30 ) . '...' : $caption;
									?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $value, $summarize_by ); ?>><?php echo esc_html( $caption ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				</tbody>
			</table>
			<p class="submit">
				<?php wp_nonce_field( 'tix_summarize' ); ?>
				<input type="hidden" name="tix_summarize_submit" value="1" />
				<input type="submit" class="button-primary" value="<?php esc_attr_e( 'Show Summary', 'wordcamporg' ); ?>" />
				<input type="submit" name="tix_export_summary" value="<?php esc_attr_e( 'Export Summary to CSV', 'wordcamporg' ); ?>" class="button" />
			</p>
		</form>

		<?php if ( isset( $_POST['tix_summarize_submit'] ) && check_admin_referer( 'tix_summarize' ) && array_key_exists( $summarize_by, $this->plugin->get_available_summary_fields() ) ) : ?>
		<?php
			$fields = $this->plugin->get_available_summary_fields();
			$summary = $this->plugin->get_summary( $summarize_by );
			$summary_title = $fields[ $summarize_by ];
			$alt = '';

			$rows = array();
			foreach ( $summary as $entry )
				$rows[] = array(
					esc_html( $summary_title ) => esc_html( $entry['label'] ),
					__( 'Count', 'wordcamporg' ) => esc_html( $entry['count'] )
				);

			// Render the widefat table.
			$this->plugin->table( $rows, 'widefat tix-summarize' );
		?>

		<?php endif; // summarize_submit ?>
		<?php
	}

	/**
	 * Hooked at (almost) admin_init, fired if one requested a
	 * Summarize export. Serves the download file.
	 * @see menu_tools_summarize()
	 */
	function summarize_admin_init() {
		if ( ! current_user_can( $this->plugin->caps['manage_tools'] ) || 'summarize' != $this->get_tools_section() )
			return;

		if ( isset( $_POST['tix_export_summary'], $_POST['tix_summarize_by'] ) && check_admin_referer( 'tix_summarize' ) ) {
			$summarize_by = $_POST['tix_summarize_by'];
			if ( ! array_key_exists( $summarize_by, $this->plugin->get_available_summary_fields() ) )
				return;

			$fields = $this->plugin->get_available_summary_fields();
			$summary = $this->plugin->get_summary( $summarize_by );
			$summary_title = $fields[ $summarize_by ];
			$filename = sprintf( 'camptix-summary-%s-%s.csv', sanitize_title_with_dashes( $summary_title ), date( 'Y-m-d' ) );

			header( 'Content-Type: text/csv' );
			header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
			header( "Cache-control: private" );
			header( 'Pragma: private' );
			header( "Expires: Mon, 26 Jul 1997 05:00:00 GMT" );

			$stream = fopen( "php://output", 'w' );

			$headers = array( $summary_title, __( 'Count', 'wordcamporg' ) );
			fputcsv( $stream, CampTix_Plugin::esc_csv( $headers ), ',', '"', '\\', "\n" );
			foreach ( $summary as $entry ) {
				fputcsv( $stream, CampTix_Plugin::esc_csv( $entry ), ',', '"', '\\', "\n" );
			}

			fclose( $stream );
			die();
		}
	}

	function menu_tools_revenue() {
		$results = $this->plugin->generate_revenue_report_data();

		if ( $results['totals']->revenue != $results['actual_total'] ) {
			printf(
				'<div class="updated settings-error below-h2"><p>%s</p></div>',
				sprintf(
					__( '<strong>Woah!</strong> The revenue total does not match with the transactions total. The actual total is: <strong>%s</strong>. Something somewhere has gone wrong, please report this.', 'wordcamporg' ),
					esc_html( $this->plugin->append_currency( $results['actual_total'] ) )
				)
			);
		}

		$this->plugin->table( $results['rows'], 'widefat tix-revenue-summary' );
		printf( '<p><span class="description">' . __( 'Revenue report generated in %s seconds.', 'wordcamporg' ) . '</span></p>', $results['run_time'] );
	}

	/**
	 * Export tools menu, nothing funky here.
	 * @see export_admin_init()
	 */
	function menu_tools_export() {
		?>
		<form method="post" action="<?php echo esc_url( add_query_arg( 'tix_export', 1 ) ); ?>">
			<table class="form-table">
				<tbody>
					<tr>
						<th scope="row"><?php _e( 'Export all attendees data to', 'wordcamporg' ); ?></th>
						<td>
							<select name="tix_export_to">
								<option value="csv">CSV</option>
								<option value="xml">XML</option>
							</select>
						</td>
					</tr>
				</tbody>
			</table>
			<p class="submit">
				<?php wp_nonce_field( 'tix_export' ); ?>
				<input type="hidden" name="tix_export_submit" value="1" />
				<input type="submit" class="button-primary" value="<?php esc_attr_e( 'Export', 'wordcamporg' ); ?>" />
			</p>
		</form>
		<?php
	}

	/**
	 * Fired at almost admin_init, used to serve the export download file.
	 * @see menu_tools_export()
	 */
	function export_admin_init() {
		global $post;

		if ( ! current_user_can( $this->plugin->caps['manage_tools'] ) || 'export' != $this->get_tools_section() )
			return;

		if ( isset( $_POST['tix_export_submit'], $_POST['tix_export_to'] ) && check_admin_referer( 'tix_export' ) ) {

			$format = strtolower( trim( $_POST['tix_export_to'] ) );
			if ( ! in_array( $format, array( 'xml', 'csv' ) ) ) {
				add_settings_error( 'tix', 'error', __( 'Format not supported.', 'wordcamporg' ), 'error' );
				return;
			}

			$content_types = array(
				'xml' => 'text/xml',
				'csv' => 'text/csv',
			);

			// Get some useful info to use as filename, to avoid confusion.
			$site_info = WP_Site::get_instance( get_current_blog_id() );
			$domain    = str_replace( '.', '-', $site_info->domain );
			$path      = str_replace( '/', '', $site_info->path );

			$filename = sprintf( 'camptix-export-' . $domain . '-' . $path . '-%s.%s', date( 'Y-m-d' ), $format );

			header( 'Content-Type: ' . $content_types[$format] );
			header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
			header( "Cache-control: private" );
			header( 'Pragma: private' );
			header( "Expires: Mon, 26 Jul 1997 05:00:00 GMT" );

			echo $this->plugin->generate_attendee_report( $format );
			die();
		}
	}

	/**
	 * Notify tools menu, allows to create, preview and send an e-mail
	 * to all attendees. See also: notify shortcodes.
	 */
	function menu_tools_notify() {
		global $post, $shortcode_tags;

		// Use this array to store existing form data.
		$form_data = array(
			'subject' => '',
			'body' => '',
			'tickets' => array(),
		);

		if ( isset( $_POST['tix_notify_attendees'] ) && check_admin_referer( 'tix_notify_attendees' ) ) {
			$errors = array();
			$_POST = wp_unslash( $_POST );

			// Error handling.
			if ( empty( $_POST['tix_notify_subject'] ) )
				$errors[] = __( 'Please enter a subject line.', 'wordcamporg' );

			if ( empty( $_POST['tix_notify_body'] ) )
				$errors[] = __( 'Please enter the e-mail body.', 'wordcamporg' );

			if ( empty( $_POST['tix-notify-segment-query'] ) )
				$errors[] = __( 'At least one segment condition must be defined.', 'wordcamporg' );

			if ( empty( $_POST['tix-notify-segment-match'] ) )
				$errors[] = __( 'Please select a segment match mode' );

			$conditions = json_decode( $_POST['tix-notify-segment-query'], true );
			if ( ! is_array( $conditions ) || count( $conditions ) < 1 )
				$errors[] = __( 'At least one segment condition must be defined.', 'wordcamporg' );

			$recipients = $this->plugin->get_segment( $_POST['tix-notify-segment-match'], $conditions );

			if ( count( $recipients ) < 1 ) {
				$errors[] = __( 'The selected segment does not match any recipients. Please try again.', 'wordcamporg' );
			}

			// If everything went well.
			if ( count( $errors ) == 0 && isset( $_POST['tix_notify_submit'] ) && $_POST['tix_notify_submit'] ) {
				$subject = sanitize_text_field( wp_kses_post( $_POST['tix_notify_subject'] ) );
				$body = wp_kses_post( $_POST['tix_notify_body'] );

				// Create a new e-mail job.
				$email_id = wp_insert_post( array(
					'post_type' => 'tix_email',
					'post_status' => 'pending',
					'post_title' => $subject,
					'post_content' => $body,
				) );

				// Add recipients as post meta.
				if ( $email_id ) {
					add_settings_error( 'camptix', 'none', sprintf( __( 'Your e-mail job has been queued for %s recipients.', 'wordcamporg' ), count( $recipients ) ), 'updated' );
					$this->plugin->log( sprintf( 'Created e-mail job with %s recipients.', count( $recipients ) ), $email_id, null, 'notify' );

					foreach ( $recipients as $recipient_id )
						add_post_meta( $email_id, 'tix_email_recipient_id', $recipient_id );

					update_post_meta( $email_id, 'tix_email_recipients_backup', $recipients ); // for logging purposes
					unset( $recipients );
				}
			} else { // errors or preview

				if ( count( $errors ) > 0 ) {
					foreach ( $errors as $error ) {
						add_settings_error( 'camptix', false, $error );
					}
				} elseif ( ! empty( $_POST['tix_notify_preview'] ) ) {
					add_settings_error( 'camptix', 'none', sprintf( __( 'Your segment matched %s recipients.', 'wordcamporg' ), count( $recipients ) ), 'updated' );
				}

				// Keep form data.
				$form_data['subject'] = wp_kses_post( $_POST['tix_notify_subject'] );
				$form_data['body'] = wp_kses_post( $_POST['tix_notify_body'] );
				if ( isset( $_POST['tix_notify_tickets'] ) )
					$form_data['tickets'] = array_map( 'absint', (array) $_POST['tix_notify_tickets'] );
			}
		}

		// Remove all standard shortcodes.
		$this->plugin->removed_shortcodes = $shortcode_tags;
		remove_all_shortcodes();

		$tickets_query = new WP_Query( array(
			'post_type' => 'tix_ticket',
			'post_status' => 'any',
			'posts_per_page' => -1,
		) );

		do_action( 'camptix_init_notify_shortcodes' );
		?>
		<?php settings_errors( 'camptix' ); ?>

		<form method="post" action="<?php echo esc_url( add_query_arg( 'tix_notify_attendees', 1 ) ); ?>">
			<table class="form-table">
				<tbody>
					<tr>
						<th scope="row"><?php _e( 'To', 'wordcamporg' ); ?></th>
						<td>
							<div class="tix-notify-segment">
								<input type="hidden" id="tix-notify-segment-query" name="tix-notify-segment-query" value="" />

								<div class="tix-match">
									<?php
										$match = ! empty( $_POST['tix-notify-segment-match'] ) ? $_POST['tix-notify-segment-match'] : 'OR';
									?>
									<?php printf( _x( 'Attendees matching %s of the following:', 'Placeholder is all/any', 'wordcamporg' ),
										'<select name="tix-notify-segment-match">
											<option value="AND" ' . selected( $match, 'AND', false ) . '>' .
												_x( 'all', 'Attendees matching X of the following', 'wordcamporg' ) . '</option>
											<option value="OR" ' . selected( $match, 'OR', false ) . '>' .
												_x( 'any', 'Attendees matching X of the following', 'wordcamporg' ) . '</option>
										</select>' ); ?>
								</div>

								<div class="tix-segments">
								</div>

								<div class="tix-add-segment-condition">
									<a href="#"><?php _e( 'Add Condition &rarr;', 'wordcamporg' ); ?></a>
								</div>

								<!--<p><a href="#" class="button"><?php _e( 'Test Segment' ); ?></a></p>-->
							</div>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php _e( 'Subject', 'wordcamporg' ); ?></th>
						<td>
							<input type="text" name="tix_notify_subject" value="<?php echo esc_attr( $form_data['subject'] ); ?>" class="large-text" />
						</td>
					</tr>
					<tr>
						<th scope="row"><?php _e( 'Message', 'wordcamporg' ); ?></th>
						<td>
							<textarea rows="10" name="tix_notify_body" id="tix-notify-body" class="large-text"><?php echo esc_textarea( $form_data['body'] ); ?></textarea><br />
							<?php if ( ! empty( $shortcode_tags ) ) : ?>
							<p class=""><?php _e( 'You can use the following shortcodes:', 'wordcamporg' ); ?>
								<?php foreach ( $shortcode_tags as $key => $tag ) : ?>
								<a href="#" class="tix-notify-shortcode"><code>[<?php echo esc_html( $key ); ?>]</code></a>
								<?php endforeach; ?>
							</p>
							<?php endif; ?>

							<?php if ( CampTix_Plugin::html_mail_enabled() ) : ?>
								<p>
									<?php _e( 'You can use the following HTML tags:', 'wordcamporg' ); ?>
									<?php echo esc_html( CampTix_Plugin::get_allowed_html_mail_tags( 'display' ) ); ?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
					<?php if ( isset( $_POST['tix_notify_preview'], $form_data ) ) : ?>
					<?php
						$attendees_ids = get_posts( array(
							'post_type' => 'tix_attendee',
							'post_status' => array( 'publish' ),
							'posts_per_page' => 1,
							'orderby' => 'rand',
							'fields' => 'ids',
						) );

						if ( $attendees_ids )
							$this->plugin->tmp( 'attendee_id', array_shift( $attendees_ids ) );

						$subject = do_shortcode( $form_data['subject'] );
						$content = do_shortcode( $form_data['body'] );

						$this->plugin->tmp( 'attendee_id', false );
					?>
					<tr>
						<th scope="row">Preview</th>
						<td>
							<div id="tix-notify-preview">
								<p><strong><?php echo esc_html( $subject ); ?></strong></p>
								<div>
									<?php
										if ( CampTix_Plugin::html_mail_enabled() ) {
											echo CampTix_Plugin::sanitize_format_html_message( $content );
										} else {
											echo nl2br( esc_html( $content ) );
										}
									?>
								</div>
							</div>
						</td>
					</tr>
					<?php endif; ?>
				</tbody>
			</table>
			<p class="submit">
				<?php wp_nonce_field( 'tix_notify_attendees' ); ?>
				<input type="hidden" name="tix_notify_attendees" value="1" />

				<div style="position: absolute; left: -9999px;">
					<?php /* Hit Preview, not Send, if the form is submitted with Enter. */ ?>
					<?php submit_button( __( 'Preview', 'wordcamporg' ), 'button', 'tix_notify_preview', false ); ?>
				</div>
				<?php submit_button( __( 'Send E-mails', 'wordcamporg' ), 'primary', 'tix_notify_submit', false ); ?>
				<?php submit_button( __( 'Preview', 'wordcamporg' ), 'button', 'tix_notify_preview', false ); ?>
			</p>
		</form>

		<!-- Notify Segment Item -->
		<script type="text/template" id="camptix-tmpl-notify-segment-item">
			<div class="tix-segment">
				<a href="#" class="dashicons dashicons-dismiss tix-delete-segment-condition"></a>
				<div class="segment-field-wrap">
					<select class="segment-field">
						<# _.each( data.fields, function( field ) { #>
							<# var selected = field.option_value == data.model.field ? 'selected' : ''; #>
							<option value="{{ field.option_value }}" {{ selected }}>{{ field.caption }}</option>
						<# }); #>
					</select>
				</div>

				<div class="segment-op-wrap">
					<select class="segment-op">
						<# _.each( data.ops, function( op ) { #>
							<# var selected = op == data.model.op ? 'selected' : ''; #>
							<option value="{{ op }}" {{ selected }} >{{ op }}</option>
						<# }); #>
					</select>
				</div>

				<div class="segment-value-wrap">
					<# if ( data.type == 'select' ) { #>
					<select class="segment-value">
						<# _.each( data.values, function( value ) { #>
							<# var selected = value.value == data.model.value ? 'selected' : ''; #>
							<option value="{{ value.value }}" {{ selected }} >{{ value.caption }}</option>
						<# }); #>
					</select>
					<# } else if ( data.type == 'text' ) { #>
					<input type="text" class="segment-value regular-text" value="{{ data.model.value }}" />
					<# } #>
				</div>

				<div class="clear"></div>
			</div>
		</script>

		<script>
		(function($){
			$(document).trigger( 'load-notify-segments.camptix' );

			camptix.collections.segmentFields.add( new camptix.models.SegmentField({
				caption: 'Purchased ticket',
				option_value: 'ticket',
				type: 'select',
				ops: [ 'is', 'is not' ],
				values: <?php
					$values = array();
					while ( $tickets_query->have_posts() ) {
						$tickets_query->the_post();
						$values[] = array(
							'caption' => html_entity_decode( get_the_title() ),
							'value' => (string) get_the_ID(),
						);
					}

					echo json_encode( $values );
				?>
			}));

			camptix.collections.segmentFields.add( new camptix.models.SegmentField({
				caption: 'Purchase date',
				option_value: 'date',
				type: 'text',
				ops: [ 'before', 'after' ]
			}));

			<?php foreach ( $this->plugin->get_all_questions() as $question ) : ?>

				<?php
					// Segmenting supported by these types. only
					if ( ! in_array( get_post_meta( $question->ID, 'tix_type', true ), array( 'select', 'radio', 'checkbox', 'text' ) ) )
						continue;
				?>

				camptix.collections.segmentFields.add( new camptix.models.SegmentField({
					caption: '<?php echo esc_js( $question->post_title ); ?>',
					option_value: '<?php echo esc_js( sprintf( 'tix-question-%d', $question->ID ) ); ?>',

					<?php $type = get_post_meta( $question->ID, 'tix_type', true ); ?>
					<?php if ( in_array( $type, array( 'select', 'radio' ) ) ) : ?>

						type: 'select',
						ops: [ 'is', 'is not' ],
						values: <?php
							$values = array();
							foreach ( (array) get_post_meta( $question->ID, 'tix_values', true ) as $value ) {
								$values[] = array(
									'caption' => html_entity_decode( $value ),
									'value' => $value,
								);
							}

							echo json_encode( $values );
						?>,

					<?php elseif ( $type == 'checkbox' ) : ?>

						type: 'select',
						ops: [ 'is', 'is not' ],
						values: <?php
							$values = array( array( 'caption' => 'None', 'value' => -1 ) );
							$question_values = (array) get_post_meta( $question->ID, 'tix_values', true );

							if ( ! empty( $question_values ) ) {
								foreach ( (array) get_post_meta( $question->ID, 'tix_values', true ) as $value ) {
									$values[] = array(
										'caption' => html_entity_decode( $value ),
										'value' => $value,
									);
								}
							} else {
								$values[] = array(
									'caption' => __( 'Yes', 'wordcamporg' ),
									'value' => 'Yes',
								);
							}

							echo json_encode( $values );
						?>,

					<?php elseif ( $type == 'text' ) : ?>

						type: 'text',
						ops: [ 'is', 'is not', 'contains', 'does not contain', 'starts with', 'does not start with' ],

					<?php endif; ?>

					noop: null
				}));

			<?php endforeach; ?>

			camptix.collections.segmentFields.add( new camptix.models.SegmentField({
				caption: 'Coupon code used',
				option_value: 'coupon',
				type: 'select',
				ops: [ 'is', 'is not' ],
				values: <?php
					$values = array();
					foreach ( $this->plugin->get_all_coupons() as $coupon ) {
						$values[] = array(
							'caption' => $coupon->post_title,
							'value' => (string) $coupon->ID,
						);
					}

					echo json_encode( $values );
				?>
			}));


			// Add POST'ed conditions.
			<?php if ( ! empty( $conditions ) ) : ?>
				<?php foreach ( $conditions as $condition ) : ?>
					camptix.collections.segments.add(
						new camptix.models.Segment(<?php echo json_encode( $condition ); ?>)
					);
				<?php endforeach; ?>
			<?php else : ?>
				camptix.collections.segments.add( new camptix.models.Segment() );
			<?php endif; ?>

		}(jQuery));
		</script>

		<?php

		// Bring back the original shortcodes.
		$shortcode_tags = $this->plugin->removed_shortcodes;
		$this->plugin->removed_shortcodes = array();

		$history_query = new WP_Query( array(
			'post_type' => 'tix_email',
			'post_status' => 'any',
			'posts_per_page' => -1,
			'order' => 'ASC',
		) );

		if ( $history_query->have_posts() ) {
			echo '<h3>' . __( 'History', 'wordcamporg' ) . '</h3>';
			$rows = array();
			while ( $history_query->have_posts() ) {
				$history_query->the_post();
				$rows[] = array(
					__( 'Subject', 'wordcamporg' ) => get_the_title(),
					__( 'Updated', 'wordcamporg' ) => sprintf( __( '%1$s at %2$s', 'wordcamporg' ), get_the_date(), get_the_time() ),
					__( 'Author', 'wordcamporg' ) => get_the_author(),
					__( 'Status', 'wordcamporg' ) => $post->post_status,
				);
			}
			$this->plugin->table( $rows, 'widefat tix-email-history' );
		}
	}

	function menu_tools_refund() {
		$options = $this->plugin->get_options();
		if ( ! current_user_can( $this->plugin->caps['refund_all'] ) || ! $options['refund_all_enabled'] )
			return;

		if ( get_option( 'camptix_doing_refunds', false ) )
			return $this->menu_tools_refund_busy();

		if ( ! $this->payment_modules_support_refund_all() )
			return $this->menu_tools_refund_unavailable();

		?>
		<form method="post" action="<?php echo esc_url( add_query_arg( 'tix_refund_all', 1 ) ); ?>">
			<table class="form-table">
				<tbody>
					<tr>
						<th scope="row"><?php _e( 'Refund all transactions', 'wordcamporg' ); ?></th>
						<td>
							<label><input name="tix_refund_checkbox_1" value="1" type="checkbox" /> <?php _e( 'Refund all transactions', 'wordcamporg' ); ?></label><br />
							<label><input name="tix_refund_checkbox_2" value="1" type="checkbox" /> <?php _e( 'Seriously, refund them all', 'wordcamporg' ); ?></label><br />
							<label><input name="tix_refund_checkbox_3" value="1" type="checkbox" /> <?php _e( "I know what I'm doing, please refund", 'wordcamporg' ); ?></label><br />
							<label><input name="tix_refund_checkbox_4" value="1" type="checkbox" /> <?php _e( 'I know this may result in money loss, refund anyway', 'wordcamporg' ); ?></label><br />
							<label><input name="tix_refund_checkbox_5" value="1" type="checkbox" /> <?php _e( 'I will not blame Konstantin if something goes wrong', 'wordcamporg' ); ?></label><br />
						</td>
					</tr>
				</tbody>
			</table>
			<p class="submit">
				<?php wp_nonce_field( 'tix_refund_all' ); ?>
				<input type="hidden" name="tix_refund_all_submit" value="1" />
				<input type="submit" class="button-primary" value="<?php esc_attr_e( 'Refund Transactions', 'wordcamporg' ); ?>" />
			</p>
		</form>
		<?php
	}

	/**
	 * Runs before the page markup is printed so can add settings errors.
	 */
	function menu_tools_refund_admin_init() {
		if ( ! current_user_can( $this->plugin->caps['refund_all'] ) || 'refund' != $this->get_tools_section() )
			return;

		// Display results of completed refund-all job
		$total_results = get_option( 'camptix_refund_all_results' );
		if ( isset( $total_results['status'] ) && 'completed' == $total_results['status'] ) {
			add_settings_error(
				'camptix',
				'none',
				sprintf(
					__( 'CampTix has finished attempting to refund all transactions. The results were:<br /><br /> &bull;Succeeded: %1$d<br /> &bull;Failed: %2$d', 'wordcamporg' ),
					$total_results['succeeded'],
					$total_results['failed']
				),
				'updated'
			);	// not using proper <p> and <ul> markup because settings_errors() forces the entire message inside a <p>, which would be invalid
			delete_option( 'camptix_refund_all_results' );
		}

		// Process form submission
		if ( ! isset( $_POST['tix_refund_all_submit'] ) )
			return;

		check_admin_referer( 'tix_refund_all' );

		$checkboxes = array(
			'tix_refund_checkbox_1',
			'tix_refund_checkbox_2',
			'tix_refund_checkbox_3',
			'tix_refund_checkbox_4',
			'tix_refund_checkbox_5',
		);

		foreach ( $checkboxes as $checkbox ) {
			if ( ! isset( $_POST[ $checkbox ] ) || $_POST[ $checkbox ] != '1' ) {
				add_settings_error( 'camptix', 'none', __( 'Looks like you have missed a checkbox or two. Try again!', 'wordcamporg' ), 'error' );
				return;
			}
		}

		$current_user = wp_get_current_user();
		$this->plugin->log( sprintf( 'Setting all transactions to refund, thanks %s.', $current_user->user_login ), 0, null, 'refund' );
		update_option( 'camptix_doing_refunds', true );
		update_option( 'camptix_refund_all_results', array( 'status' => 'pending', 'succeeded' => 0, 'failed' => 0 ) );

		$count = 0;
		$paged = 1;
		while ( $attendees = get_posts( array(
			'post_type' => 'tix_attendee',
			'posts_per_page' => 200,
			'post_status' => array( 'publish' ),
			'paged' => $paged++,
			'orderby' => 'ID',
			'fields' => 'ids',
			'order' => 'ASC',
			'cache_results' => false,
		) ) ) {

			// Mark attendee for refund
			foreach ( $attendees as $attendee_id ) {
				update_post_meta( $attendee_id, 'tix_pending_refund', 1 );
				$this->plugin->log( sprintf( 'Attendee set to refund by %s', $current_user->user_login ), $attendee_id, null, 'refund' );
				$count++;
			}
		}

		add_settings_error( 'camptix', 'none', sprintf( __( 'A refund job has been queued for %d attendees.', 'wordcamporg' ), $count ), 'updated' );
	}

	/**
	 * Runs on Refund tab if a refund job is in progress.
	 */
	function menu_tools_refund_busy() {
		$query = new WP_Query( array(
			'post_type' => 'tix_attendee',
			'posts_per_page' => 1,
			'post_status' => array( 'publish' ),
			'orderby' => 'ID',
			'order' => 'ASC',
			'meta_query' => array(
				array(
					'key' => 'tix_pending_refund',
					'compare' => '=',
					'value' => 1,
				),
			),
		) );
		$found_posts = $query->found_posts;
		?>
		<p>
			<?php
			printf(
				esc_html__( 'A refund job is in progress, with %1$d attendees left in the queue. Next run in %2$d seconds.', 'wordcamporg' ),
				absint( $found_posts ),
				absint( wp_next_scheduled( 'tix_scheduled_every_ten_minutes' ) - time() )
			);
			?>
		</p>
		<?php
		// @todo sometimes the time returned is a negative value, then fixes next load
		// @todo still says refund job in progress every with 0 attendees left. then clears next run. probably b/c last batch doesn't check to see if it's the last one
	}

	/*
	 * Returns true if at least one of the enabled payment modules supports refunding all tickets
	 */
	function payment_modules_support_refund_all() {
		$supported = false;
		$payment_methods = $this->plugin->get_enabled_payment_methods();

		if ( $payment_methods ) {
			foreach ( $payment_methods as $key => $name ) {
				$method = $this->plugin->get_payment_method_by_id( $key );

				if ( $method && $method->supports_feature( 'refund-all' ) ) {
					$supported = true;
					break;
				}
			}
		}

		return $supported;
	}

	/**
	 * Runs on Refund tab if none of the current payment modules support refunding all tickets
	 */
	function menu_tools_refund_unavailable() {
		?>
		<p><?php echo __( 'None of the enabled payment modules support refunding all tickets.', 'wordcamporg' ); ?></p>
		<?php
	}
}
