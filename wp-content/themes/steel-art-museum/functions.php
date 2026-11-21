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
require_once SAM_THEME_DIR . '/inc/i18n-b.php';

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
	wp_enqueue_style( 'sam-font', 'https://fonts.googleapis.com/css2?family=Barlow:wght@500;600;700;800&family=Inter:wght@400;500;600&display=swap', array(), null );
	list( $url, $ver ) = sam_asset( 'css/main.css' );
	wp_enqueue_style( 'sam-main', $url, array(), $ver );
	list( $url, $ver ) = sam_asset( 'css/b.css' );
	wp_enqueue_style( 'sam-b', $url, array( 'sam-main' ), $ver );
	list( $url, $ver ) = sam_asset( 'js/main.js' );
	wp_enqueue_script( 'sam-main', $url, array(), $ver, array( 'strategy' => 'defer', 'in_footer' => true ) );

	if ( sam_is_home() ) {
		list( $url, $ver ) = sam_asset( 'js/home.js' );
		wp_enqueue_script( 'sam-home', $url, array(), $ver, array( 'strategy' => 'defer', 'in_footer' => true ) );
		$offer              = sam_ticket_offer( sam_lang() );
		$offer['boxOffice'] = true;
		wp_add_inline_script( 'sam-home', 'window.SAM_TICKETS=' . wp_json_encode( $offer ) . ';', 'before' );
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
		// Písma neblokují vykreslení (display=swap): styl se načte na pozadí, bez JS jako běžný styl.
		$async = str_replace( "media='all'", "media='print' onload=\"this.media='all'\"", $tag );
		$tag   = '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n" . $async . '<noscript>' . $tag . '</noscript>' . "\n";
	}
	return $tag;
}, 10, 2 );

// Plakát úvodního videa se načítá hned (LCP).
add_action( 'wp_head', function () {
	if ( sam_is_home() ) {
		printf(
			'<link rel="preload" as="image" href="%s" imagesrcset="%s" imagesizes="100vw" fetchpriority="high">' . "\n",
			esc_url( sam_img( 'fotky00086405-800.webp' ) ),
			esc_attr( sam_img( 'fotky00086405-800.webp' ) . ' 800w, ' . sam_img( 'fotky00086405-1600.webp' ) . ' 1600w' )
		);
	}
	printf( '<link rel="icon" href="%s">' . "\n", esc_url( sam_img( 'logo.png' ) ) );
	echo '<meta name="theme-color" content="#141414">' . "\n";
}, 2 );

// Emoji skripty WordPressu nepotřebujeme.
remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
remove_action( 'wp_print_styles', 'print_emoji_styles' );
remove_action( 'wp_head', 'wp_generator' );

add_filter( 'body_class', function ( $classes ) {
	$classes[] = 'sam-b';
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

/* ---------------- Domovská stránka ---------------- */

/** Domovská stránka (i dočasná srovnávací /novy-design/ se šablonou home-b). */
function sam_is_home() {
	return is_front_page() || is_page_template( 'page-templates/home-b.php' );
}

// Na domovské stránce vedou kotvy z menu (#co-uvidite, #historie) přímo na sekce.
add_filter( 'nav_menu_link_attributes', function ( $atts ) {
	if ( sam_is_home() && ! empty( $atts['href'] ) && false !== strpos( $atts['href'], '#' ) ) {
		$atts['href'] = '#' . substr( $atts['href'], strpos( $atts['href'], '#' ) + 1 );
		unset( $atts['aria-current'] );
	}
	return $atts;
} );

/** Malé ikony (inline SVG, bez knihovny). */
function sam_icon( $name ) {
	$icons = array(
		'facebook'  => '<svg viewBox="0 0 24 24" aria-hidden="true" width="20" height="20"><path fill="currentColor" d="M13.5 21v-8h2.7l.4-3.1h-3.1V7.9c0-.9.3-1.5 1.6-1.5h1.7V3.6c-.3 0-1.3-.1-2.4-.1-2.4 0-4 1.5-4 4.1v2.3H7.6V13h2.8v8h3.1z"/></svg>',
		'instagram' => '<svg viewBox="0 0 24 24" aria-hidden="true" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.5" cy="6.5" r="1.2" fill="currentColor" stroke="none"/></svg>',
		'clock'     => '<svg viewBox="0 0 24 24" aria-hidden="true" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>',
		'pin'       => '<svg viewBox="0 0 24 24" aria-hidden="true" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 21s-7-6.2-7-11a7 7 0 0 1 14 0c0 4.8-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/></svg>',
		'star'      => '<svg viewBox="0 0 24 24" aria-hidden="true" width="16" height="16"><path fill="currentColor" d="M12 2.5l2.9 6.1 6.6.8-4.9 4.6 1.3 6.5L12 17.3 6.1 20.5l1.3-6.5L2.5 9.4l6.6-.8z"/></svg>',
	);
	return $icons[ $name ] ?? '';
}

function sam_stars( $rating ) {
	$out = '<span class="stars" aria-hidden="true">';
	for ( $i = 1; $i <= 5; $i++ ) {
		$out .= '<span class="star' . ( $rating >= $i - 0.25 ? ' on' : '' ) . '">' . sam_icon( 'star' ) . '</span>';
	}
	return $out . '</span>';
}

/** Hodnocení Tripadvisoru se zobrazuje „bublinami“, ne hvězdičkami. */
function sam_bubbles( $rating ) {
	$out = '<span class="bubbles" aria-hidden="true">';
	for ( $i = 1; $i <= 5; $i++ ) {
		$fill = $rating >= $i - 0.25 ? 'on' : ( $rating >= $i - 0.75 ? 'half' : '' );
		$out .= '<span class="bubble ' . $fill . '"></span>';
	}
	return $out . '</span>';
}

/** Hvězdičky (Google) nebo bubliny (Tripadvisor) podle zdroje. */
function sam_rating_mark( $source, $rating ) {
	return 'tripadvisor' === $source ? sam_bubbles( $rating ) : sam_stars( $rating );
}

function sam_number( $n, $lang ) {
	return 'en' === $lang ? number_format( (float) $n, 0, '.', ',' ) : number_format( (float) $n, 0, ',', "\u{00A0}" );
}

function sam_rating( $r, $lang ) {
	return 'en' === $lang ? number_format( (float) $r, 1 ) : number_format( (float) $r, 1, ',', '' );
}

function sam_social_links( $class = 'social-links' ) {
	return '<span class="' . esc_attr( $class ) . '"><a href="https://facebook.com/steelartmuseum" aria-label="Facebook" rel="noopener">' . sam_icon( 'facebook' ) . '</a><a href="https://www.instagram.com/steelartmuseum/" aria-label="Instagram" rel="noopener">' . sam_icon( 'instagram' ) . '</a></span>';
}

// WooCommerce: vlastní obal místo výchozích divů.
remove_action( 'woocommerce_before_main_content', 'woocommerce_output_content_wrapper', 10 );
remove_action( 'woocommerce_after_main_content', 'woocommerce_output_content_wrapper_end', 10 );
remove_action( 'woocommerce_sidebar', 'woocommerce_get_sidebar', 10 );

/*
 * Rychlost: styly a skripty pluginů, které šablona nepoužívá.
 * - GP Premium (moduly pro GeneratePress: sticky menu, off-canvas, ikony) – šablona má vlastní hlavičku.
 * - WP Review Slider Pro – recenze má web vlastní (sam-core/reviews.php).
 * - WPML/WCML přepínače jazyka a měny – šablona má vlastní odkaz na druhý jazyk.
 * GenerateBlocks zůstává (obsah stránek je z jeho bloků).
 */
function sam_unused_assets() {
	$styles  = array( 'generate-sticky', 'generate-offside', 'gp-premium-icons', 'generate-navigation-branding', 'wprevpro_w3', 'wcml-dropdown-0', 'wpml-legacy-horizontal-list-0', 'wpml-menu-item-0' );
	$scripts = array( 'generate-sticky', 'generate-offside', 'wp-review-slider-pro_slick-min', 'wp-review-slider-pro_unslider_comb-min', 'wp-review-slider-pro_plublic-min' );
	if ( ! sam_is_shop_page() ) {
		$styles  = array_merge( $styles, array( 'generate-woocommerce', 'generate-woocommerce-mobile' ) );
		$scripts = array_merge( $scripts, array( 'generate-woocommerce', 'cart-widget', 'wc-cart-fragments' ) );
	}
	foreach ( $styles as $h ) {
		wp_dequeue_style( $h );
	}
	foreach ( $scripts as $h ) {
		wp_dequeue_script( $h );
	}
}
add_action( 'wp_enqueue_scripts', 'sam_unused_assets', 999 );
add_action( 'wp_print_styles', 'sam_unused_assets', 1 );

// jQuery do patičky: v hlavičce by blokoval vykreslení (potřebují ho jen skripty v patičce).
add_action( 'wp_enqueue_scripts', function () {
	if ( is_admin() ) {
		return;
	}
	foreach ( array( 'jquery', 'jquery-core', 'jquery-migrate' ) as $h ) {
		wp_scripts()->add_data( $h, 'group', 1 );
	}
}, 1000 );
