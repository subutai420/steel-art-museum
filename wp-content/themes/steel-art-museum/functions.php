<?php
/**
 * Steel Art Museum – šablona „Žár“ (černá ocel).
 *
 * inc/i18n.php     texty šablony CZ/EN (bez .po souborů, jazyk z WPML)
 * inc/tickets.php  data kalkulačky vstupenek a vložení do košíku
 */

defined( 'ABSPATH' ) || exit;

define( 'SAM_THEME_VERSION', '0.1.0' );
define( 'SAM_THEME_DIR', __DIR__ );
define( 'SAM_THEME_URI', get_template_directory_uri() );

require_once SAM_THEME_DIR . '/inc/i18n.php';
require_once SAM_THEME_DIR . '/inc/tickets.php';

add_action( 'after_setup_theme', function () {
	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'html5', array( 'search-form', 'gallery', 'caption', 'style', 'script', 'navigation-widgets' ) );
	add_theme_support( 'responsive-embeds' );
	add_theme_support( 'align-wide' );
	add_theme_support( 'woocommerce' );
	register_nav_menus( array(
		'primary' => 'Hlavní menu',
		'footer'  => 'Patička',
	) );
} );

/** Verze souboru podle data změny – prohlížeč si po nasazení stáhne novou verzi. */
function sam_asset( $path ) {
	$file = SAM_THEME_DIR . '/assets/' . $path;
	return array( SAM_THEME_URI . '/assets/' . $path, file_exists( $file ) ? (string) filemtime( $file ) : SAM_THEME_VERSION );
}

function sam_img( $file ) {
	return SAM_THEME_URI . '/assets/img/' . $file;
}

/** Stránka podle ID v aktuálním jazyce (WPML), když překlad chybí, vrátí originál. */
function sam_page_url( $id ) {
	$id = (int) apply_filters( 'wpml_object_id', $id, 'page', true );
	return get_permalink( $id ) ?: home_url( '/' );
}

function sam_home_url( $anchor = '' ) {
	$url = apply_filters( 'wpml_home_url', home_url( '/' ) );
	return $url . ( $anchor ? '#' . $anchor : '' );
}

function sam_is_shop_page() {
	return function_exists( 'is_woocommerce' ) && ( is_woocommerce() || is_cart() || is_checkout() || is_account_page() );
}

add_action( 'wp_enqueue_scripts', function () {
	wp_enqueue_style( 'sam-font', 'https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wdth,wght@12..96,75..100,400..800&display=swap', array(), null );
	list( $url, $ver ) = sam_asset( 'css/main.css' );
	wp_enqueue_style( 'sam-main', $url, array(), $ver );
	list( $url, $ver ) = sam_asset( 'js/main.js' );
	wp_enqueue_script( 'sam-main', $url, array(), $ver, array( 'strategy' => 'defer', 'in_footer' => true ) );

	if ( is_front_page() ) {
		list( $url, $ver ) = sam_asset( 'css/home.css' );
		wp_enqueue_style( 'sam-home', $url, array( 'sam-main' ), $ver );
		list( $url, $ver ) = sam_asset( 'js/home.js' );
		wp_enqueue_script( 'sam-home', $url, array(), $ver, array( 'strategy' => 'defer', 'in_footer' => true ) );
		wp_add_inline_script( 'sam-home', 'window.SAM_TICKETS=' . wp_json_encode( sam_ticket_offer( sam_lang() ) ) . ';', 'before' );
	}

	// Mimo obchod nepotřebujeme styly a skripty WooCommerce (rychlost).
	if ( ! sam_is_shop_page() ) {
		wp_dequeue_style( 'wc-blocks-style' );
		wp_dequeue_script( 'wc-cart-fragments' );
		wp_dequeue_script( 'woocommerce' );
		wp_dequeue_script( 'wc-add-to-cart' );
	}
}, 20 );

add_filter( 'woocommerce_enqueue_styles', function ( $styles ) {
	return sam_is_shop_page() ? $styles : array();
} );

add_filter( 'style_loader_tag', function ( $tag, $handle ) {
	if ( 'sam-font' === $handle ) {
		$tag = '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n" . $tag;
	}
	return $tag;
}, 10, 2 );

// Úvodní fotka se načítá hned (LCP).
add_action( 'wp_head', function () {
	if ( is_front_page() ) {
		printf(
			'<link rel="preload" as="image" href="%s" imagesrcset="%s" imagesizes="100vw" fetchpriority="high">' . "\n",
			esc_url( sam_img( 'fotky00086405-1600.webp' ) ),
			esc_attr( sam_img( 'fotky00086405-800.webp' ) . ' 800w, ' . sam_img( 'fotky00086405-1600.webp' ) . ' 1600w, ' . sam_img( 'fotky00086405-2400.webp' ) . ' 2400w' )
		);
	}
	printf( '<link rel="icon" href="%s">' . "\n", esc_url( sam_img( 'logo.png' ) ) );
	echo '<meta name="theme-color" content="#0F1012">' . "\n";
}, 2 );

// Emoji skripty WordPressu nepotřebujeme.
remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
remove_action( 'wp_print_styles', 'print_emoji_styles' );
remove_action( 'wp_head', 'wp_generator' );

add_filter( 'body_class', function ( $classes ) {
	if ( sam_is_shop_page() ) {
		$classes[] = 'sam-shop';
	}
	return $classes;
} );

/**
 * Hlavní menu: přepínač jazyka WPML z menu vyhodíme (šablona má vlastní) a odkazy
 * na kotvy domovské stránky (#co-uvidite) necháme fungovat i z podstránek.
 */
add_filter( 'wp_nav_menu_objects', function ( $items, $args ) {
	if ( empty( $args->theme_location ) || 'primary' !== $args->theme_location ) {
		return $items;
	}
	// Kotvy, které na nové domovské stránce existují (stará měla i #video aj.).
	$anchors = array( 'vstupenky', 'co-uvidite', 'historie', 'navsteva', 'skoly' );
	return array_values( array_filter( $items, function ( $item ) use ( $anchors ) {
		if ( 'wpml_ls_menu_item' === $item->type || false !== strpos( implode( ' ', (array) $item->classes ), 'wpml-ls' ) ) {
			return false;
		}
		$hash = (string) wp_parse_url( $item->url, PHP_URL_FRAGMENT );
		return '' === $hash || in_array( $hash, $anchors, true );
	} ) );
}, 10, 2 );

function sam_nav_fallback() {
	$links = array(
		array( sam_home_url( 'co-uvidite' ), sam_t( 'nav_exhibition' ) ),
		array( sam_home_url( 'historie' ), sam_t( 'nav_story' ) ),
		array( sam_page_url( 2 ), sam_t( 'nav_faq' ) ),
		array( sam_page_url( 45 ), sam_t( 'nav_schools' ) ),
	);
	echo '<ul class="nav">';
	foreach ( $links as $l ) {
		printf( '<li><a href="%s">%s</a></li>', esc_url( $l[0] ), esc_html( $l[1] ) );
	}
	echo '</ul>';
}

/** Odkaz na druhou jazykovou verzi aktuální stránky. */
function sam_lang_switch() {
	$langs = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );
	if ( empty( $langs ) || ! is_array( $langs ) ) {
		return;
	}
	foreach ( $langs as $code => $l ) {
		if ( ! empty( $l['active'] ) ) {
			continue;
		}
		$label = 'en' === $code ? 'English' : 'Česky';
		printf( '<a class="lang" href="%s" lang="%s" hreflang="%s">%s</a>', esc_url( $l['url'] ), esc_attr( $code ), esc_attr( $code ), esc_html( $label ) );
	}
}

/** Stránky, kde v GeneratePressu byl skrytý nadpis, ho mají skrytý i tady. */
function sam_show_page_title() {
	$id = get_queried_object_id();
	return ! ( $id && get_post_meta( $id, '_generate-disable-headline', true ) );
}

// WooCommerce: vlastní obal místo výchozích divů.
remove_action( 'woocommerce_before_main_content', 'woocommerce_output_content_wrapper', 10 );
remove_action( 'woocommerce_after_main_content', 'woocommerce_output_content_wrapper_end', 10 );
remove_action( 'woocommerce_sidebar', 'woocommerce_get_sidebar', 10 );
