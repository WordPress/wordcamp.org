<?php
namespace WordCamp\Blocks\Sessions;
defined( 'WPINC' ) || die();

/** @var array  $attributes */
/** @var array  $sesions */
/** @var string $container_classes */

?>

<?php if ( ! empty( $speakers ) ) : ?>
	<ul class="<?php echo esc_attr( $container_classes ); ?>">

	</ul>
<?php endif; ?>
