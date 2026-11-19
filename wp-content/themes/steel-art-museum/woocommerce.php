<?php
/**
 * Obal pro stránky WooCommerce (obchod, produkt). Košík a pokladna jsou běžné stránky (page.php).
 */
defined( 'ABSPATH' ) || exit;

get_header();
?>
<main id="obsah" class="page">
	<div class="paper">
		<div class="entry woocommerce-wrap">
			<?php woocommerce_content(); ?>
		</div>
	</div>
</main>
<?php
get_footer();
