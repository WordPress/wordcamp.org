<?php

namespace WordCamp\RemoteCSS;
defined( 'WPINC' ) || die();

?>

<ol>
	<li>
		<p>
			<?php echo wp_kses_data( __( '<strong>Publish your CSS file</strong> to one of our supported platforms.', 'wordcamporg' ) ); ?>
		</p>

		<p>
			<?php printf(
				// translators: %s: WordPress Meta Trac URL.
				wp_kses_data( __(
					'Due to security constraints, only certain third-party platforms can be used. We currently only support GitHub, but more platforms can be added if there\'s interest from organizers. To request an additional platform, please <a href="%s">create a ticket</a> on Meta Trac.',
					'wordcamporg'
				) ),
				'https://meta.trac.wordpress.org/newticket'
			); ?>
		</p>

		<p>
			<?php esc_html_e( "If you're using SASS or LESS, you'll need to compile it into vanilla CSS and publish that file.", 'wordcamporg' ); ?>
		</p>
	</li>

	<li>
		<p>
			<?php echo wp_kses_data( __( '<strong>Enter the URL</strong> for the CSS file into the input box below.', 'wordcamporg' ) ); ?>
		</p>

		<p>
			<?php esc_html_e(
				"If you're using GitHub, you can enter the URL in any of the following formats, but we'll convert them to use the GitHub API.",
				'wordcamporg'
			); ?>
		</p>

		<ul>
			<li>
				<?php esc_html_e( 'Web-based file browser:', 'wordcamporg' ); ?>
				<code>https://github.com/WordPress/example.wordcamp.org-<?php echo esc_html( gmdate( 'Y' ) ); ?>/blob/master/style.css</code>
			</li>

			<li>
				<?php esc_html_e( 'Raw file:', 'wordcamporg' ); ?>
				<code>https://raw.githubusercontent.com/WordPress/example.wordcamp.org-<?php echo esc_html( gmdate( 'Y' ) ); ?>/master/style.css</code>
			</li>

			<li>
				<?php esc_html_e( 'API:', 'wordcamporg' ); ?>
				<code>https://api.github.com/repos/WordPress/example.wordcamp.org-<?php echo esc_html( gmdate( 'Y' ) ); ?>/contents/style.css</code>
			</li>
		</ul>
	</li>

	<li>
		<p><?php echo wp_kses_data( __( 'Click the <strong>Update</strong> button.', 'wordcamporg' ) ); ?></p>

		<p>
			<?php esc_html_e(
				"WordCamp.org will download the file, sanitize it, minify it, and store a local copy, then enqueue the local copy as a stylesheet alongside your theme's default stylesheet.",
				'wordcamporg'
			); ?>
		</p>
	</li>

	<li>
		<?php echo wp_kses_data( __(
			'The local copy will need to be <strong>synchronized</strong> whenever you make a change to the file. You can either update manually by pushing the <strong>Update</strong> button again, or update automatically by setting up a webhook. For instructions on setting up a webhook, open the <strong>Automated Synchronization</strong> tab.',
			'wordcamporg'
		) ); ?>
	</li>
</ol>

<p>
	<?php printf(
		// translators: %s: WordPress Slack URL.
		wp_kses_data( __(
			'If you run into any problems, you can ask for help in the <code>#meta-wordcamp</code> channel on <a href="%s">Slack</a>.',
			'wordcamporg'
		) ),
		'https://chat.wordpress.org'
	); ?>
</p>
