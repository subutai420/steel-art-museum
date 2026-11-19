<?php
/**
 * Záložní šablona (archivy, příspěvky). Web blog nemá, ale WordPress šablonu vyžaduje.
 */
defined( 'ABSPATH' ) || exit;

get_header();
?>
<main id="obsah" class="page">
	<header class="page-head"><h1 class="display"><?php echo esc_html( is_singular() ? get_the_title() : wp_strip_all_tags( get_the_archive_title() ) ); ?></h1></header>
	<div class="paper">
		<div class="entry">
			<?php
			if ( have_posts() ) {
				if ( is_singular() ) {
					the_post();
					the_content();
				} else {
					while ( have_posts() ) {
						the_post();
						printf( '<h2><a href="%s">%s</a></h2>', esc_url( get_permalink() ), esc_html( get_the_title() ) );
						the_excerpt();
					}
					the_posts_pagination();
				}
			}
			?>
		</div>
	</div>
</main>
<?php
get_footer();
