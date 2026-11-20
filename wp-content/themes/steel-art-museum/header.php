<?php
/**
 * Hlavička: horní pruh s otevírací dobou, adresou a hodnocením, sociální sítě v menu.
 */
defined( 'ABSPATH' ) || exit;

$sam_lang    = sam_lang();
$sam_sources = class_exists( 'SAM_Reviews' ) ? SAM_Reviews::sources() : array();
$sam_google  = $sam_sources['google'] ?? null;
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<a class="skip" href="#obsah"><?php echo esc_html( sam_t( 'skip' ) ); ?></a>
<div class="b-topbar">
	<span class="b-topbar-item"><?php echo sam_icon( 'clock' ); // phpcs:ignore ?> <?php echo esc_html( sam_t( 'b_open' ) ); ?></span>
	<a class="b-topbar-item" target="_blank" rel="noopener" href="https://maps.google.com/?q=Celetn%C3%A1+15,+Praha+1"><?php echo sam_icon( 'pin' ); // phpcs:ignore ?> <?php echo esc_html( sam_t( 'b_address' ) ); ?></a>
	<?php foreach ( $sam_sources as $sam_key => $sam_src ) : ?>
	<a class="b-topbar-item b-topbar-rating" href="<?php echo esc_url( $sam_src['url'] ); ?>" target="_blank" rel="noopener">
		<?php echo sam_rating_mark( $sam_key, $sam_src['rating'] ); // phpcs:ignore ?>
		<b><?php echo esc_html( sam_rating( $sam_src['rating'], $sam_lang ) ); ?></b> <?php echo esc_html( $sam_src['name'] ); ?><?php echo $sam_src['count'] ? ' · ' . esc_html( sprintf( sam_t( 'b_reviews' ), sam_number( $sam_src['count'], $sam_lang ) ) ) : ''; ?>
	</a>
	<?php endforeach; ?>
</div>
<header class="top b-top">
	<a class="logo" href="<?php echo esc_url( sam_home_url() ); ?>" aria-label="<?php echo esc_attr( sam_t( 'logo_label' ) ); ?>">
		<img src="<?php echo esc_url( sam_img( 'logo.webp' ) ); ?>" alt="" width="56" height="56">
	</a>
	<nav class="menu" id="menu" aria-label="<?php echo esc_attr( sam_t( 'menu_label' ) ); ?>">
		<?php
		wp_nav_menu( array(
			'theme_location' => 'primary',
			'container'      => false,
			'menu_class'     => 'nav',
			'depth'          => 1,
			'fallback_cb'    => 'sam_nav_fallback',
		) );
		echo sam_social_links( 'social-links b-social' ); // phpcs:ignore
		sam_lang_switch();
		?>
	</nav>
	<a class="b-btn b-btn--light top-cta" href="<?php echo esc_url( is_front_page() ? '#vstupenky' : sam_home_url( 'vstupenky' ) ); ?>"><?php echo esc_html( sam_t( 'b_tickets' ) ); ?></a>
	<button class="burger" type="button" aria-controls="menu" aria-expanded="false"><span class="burger-lines" aria-hidden="true"></span><span class="sr"><?php echo esc_html( sam_t( 'menu' ) ); ?></span></button>
</header>
