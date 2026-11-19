<?php
/**
 * Běžná stránka: tmavý nadpis, obsah na světlém „papíře“ (obsah stránek je z bloků
 * GenerateBlocks a počítá se světlým pozadím).
 */
defined( 'ABSPATH' ) || exit;

get_header();
while ( have_posts() ) :
	the_post();
	?>
<main id="obsah" class="page">
	<?php if ( sam_show_page_title() ) : ?>
	<header class="page-head"><h1 class="display"><?php the_title(); ?></h1></header>
	<?php endif; ?>
	<div class="paper">
		<div class="entry">
			<?php the_content(); ?>
		</div>
	</div>
</main>
	<?php
endwhile;
get_footer();
