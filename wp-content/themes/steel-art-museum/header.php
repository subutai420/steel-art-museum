<?php defined( 'ABSPATH' ) || exit; ?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<?php if ( is_front_page() ) : ?>
<a class="skip" href="#vstupenky"><?php echo esc_html( sam_t( 'skip_tickets' ) ); ?></a>
<?php else : ?>
<a class="skip" href="#obsah"><?php echo esc_html( sam_t( 'skip' ) ); ?></a>
<?php endif; ?>
<header class="top<?php echo is_front_page() ? ' top--over' : ''; ?>">
	<a class="logo" href="<?php echo esc_url( sam_home_url() ); ?>" aria-label="<?php echo esc_attr( sam_t( 'logo_label' ) ); ?>">
		<img src="<?php echo esc_url( sam_img( 'logo.webp' ) ); ?>" alt="" width="56" height="56"><span>Steel Art<br>Museum</span>
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
		sam_lang_switch();
		?>
	</nav>
	<a class="pill top-cta" href="<?php echo esc_url( sam_home_url( 'vstupenky' ) ); ?>"><?php echo esc_html( sam_t( 'buy' ) ); ?></a>
	<button class="burger" type="button" aria-controls="menu" aria-expanded="false"><span class="burger-lines" aria-hidden="true"></span><span class="sr"><?php echo esc_html( sam_t( 'menu' ) ); ?></span></button>
</header>
