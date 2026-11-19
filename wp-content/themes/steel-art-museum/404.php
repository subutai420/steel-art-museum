<?php
defined( 'ABSPATH' ) || exit;

get_header();
?>
<main id="obsah" class="page not-found">
	<header class="page-head">
		<h1 class="display"><?php echo esc_html( sam_t( 'not_found_h' ) ); ?></h1>
		<p class="lead"><?php echo esc_html( sam_t( 'not_found_p' ) ); ?></p>
		<p class="actions"><a class="pill" href="<?php echo esc_url( sam_home_url( 'vstupenky' ) ); ?>"><?php echo esc_html( sam_t( 'buy' ) ); ?></a><a class="pill ghost" href="<?php echo esc_url( sam_home_url() ); ?>"><?php echo esc_html( sam_t( 'home' ) ); ?></a></p>
	</header>
</main>
<?php
get_footer();
