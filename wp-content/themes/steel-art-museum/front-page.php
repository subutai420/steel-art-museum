<?php
/**
 * Domovská stránka (CZ i EN přes WPML). Obsah je v šabloně, texty v inc/i18n.php.
 */
defined( 'ABSPATH' ) || exit;

get_header();

$lang   = sam_lang();
$offer  = sam_ticket_offer( $lang );
$valid  = sam_tickets_valid_until_text( $lang );
$prices = sam_box_office_prices( $lang );
$img    = 'sam_img';
?>
<main id="obsah">
<section class="hero">
	<img class="hero-img" src="<?php echo esc_url( $img( 'fotky00086405-1600.webp' ) ); ?>" srcset="<?php echo esc_attr( $img( 'fotky00086405-800.webp' ) . ' 800w, ' . $img( 'fotky00086405-1600.webp' ) . ' 1600w, ' . $img( 'fotky00086405-2400.webp' ) . ' 2400w' ); ?>" sizes="100vw" alt="<?php echo esc_attr( sam_t( 'hero_alt' ) ); ?>" fetchpriority="high" width="2400" height="1350">
	<div class="hero-in">
		<h1 class="display"><span><?php echo esc_html( sam_t( 'hero_1' ) ); ?></span> <span><?php echo esc_html( sam_t( 'hero_2' ) ); ?></span></h1>
		<div class="hero-row">
			<div>
				<p><?php echo esc_html( sam_t( 'hero_text' ) ); ?></p>
				<p class="hero-facts"><span><?php echo wp_kses_post( sam_t( 'hours_short' ) ); ?></span><span><?php echo wp_kses_post( sam_t( 'address_short' ) ); ?></span></p>
			</div>
			<div class="hero-cta"><a class="pill" href="#vstupenky"><?php echo esc_html( sam_t( 'buy' ) ); ?></a><a class="pill ghost" href="#co-uvidite"><?php echo esc_html( sam_t( 'hero_more' ) ); ?></a></div>
		</div>
	</div>
</section>

<section class="tickets" id="vstupenky" aria-labelledby="tk-h">
	<div class="tk">
		<div class="tk-promo">
			<?php if ( $offer['promo'] ) : ?>
			<h2 class="display" id="tk-h"><?php echo wp_kses_post( sam_t( 'promo_h' ) ); ?></h2>
			<p><?php echo esc_html( sam_t( 'promo_p', $valid ) ); ?></p>
			<div class="clock" role="timer" aria-label="<?php echo esc_attr( sam_t( 'promo_clock' ) ); ?>" data-end="<?php echo esc_attr( $offer['end'] ); ?>">
				<div><b data-cd="d">–</b><small><?php echo esc_html( sam_t( 'days' ) ); ?></small></div>
				<div><b data-cd="h">–</b><small><?php echo esc_html( sam_t( 'hours' ) ); ?></small></div>
				<div><b data-cd="m">–</b><small><?php echo esc_html( sam_t( 'minutes' ) ); ?></small></div>
			</div>
			<?php else : ?>
			<h2 class="display" id="tk-h"><?php echo wp_kses_post( sam_t( 'online_h' ) ); ?></h2>
			<p><?php echo esc_html( sam_t( 'online_p', $valid ) ); ?></p>
			<?php endif; ?>
		</div>
		<div class="tk-calc">
			<h3 class="sr"><?php echo esc_html( sam_t( 'calc_h' ) ); ?></h3>
			<ul class="rows" id="rows"></ul>
			<div class="sum">
				<p class="msg" id="msg" aria-live="polite"></p>
				<p class="total"><small><?php echo esc_html( sam_t( 'total' ) ); ?></small><span id="was" class="was"></span><span id="total">–</span></p>
				<button class="pill" id="buy" type="button" disabled><?php echo esc_html( sam_t( 'buy' ) ); ?></button>
			</div>
			<ul class="notes">
				<li><?php echo esc_html( sam_t( 'note_under3' ) ); ?></li>
				<?php if ( $offer['promo'] ) : ?><li><?php echo esc_html( sam_t( 'note_promo' ) ); ?></li><?php endif; ?>
				<li><?php echo esc_html( sam_t( 'note_groups' ) ); ?></li>
			</ul>
		</div>
	</div>
</section>

<section class="try" aria-labelledby="try-h">
	<div>
		<h2 class="display h2" id="try-h"><?php echo esc_html( sam_t( 'try_h' ) ); ?></h2>
		<div class="choices" role="group" aria-label="<?php echo esc_attr( sam_t( 'try_group' ) ); ?>">
			<?php
			$tries = array(
				array( '6l4a0460', 'try_1' ),
				array( 'soubor-1', 'try_2' ),
				array( '6l4a0465', 'try_3' ),
			);
			foreach ( $tries as $i => $t ) {
				printf(
					'<button type="button" aria-pressed="%s" data-img="%s" data-alt="%s" data-text="%s">%s</button>',
					0 === $i ? 'true' : 'false',
					esc_url( $img( $t[0] . '-1600.webp' ) ),
					esc_attr( sam_t( $t[1] . '_alt' ) ),
					esc_attr( sam_t( $t[1] . '_t' ) ),
					esc_html( sam_t( $t[1] ) )
				);
			}
			?>
		</div>
		<p class="try-text" id="try-text" aria-live="polite"><?php echo esc_html( sam_t( 'try_1_t' ) ); ?></p>
	</div>
	<div class="try-photo"><img id="try-img" src="<?php echo esc_url( $img( '6l4a0460-1600.webp' ) ); ?>" srcset="<?php echo esc_attr( $img( '6l4a0460-800.webp' ) . ' 800w, ' . $img( '6l4a0460-1600.webp' ) . ' 1600w' ); ?>" sizes="(max-width:1000px) 100vw, 58vw" alt="<?php echo esc_attr( sam_t( 'try_1_alt' ) ); ?>" loading="lazy" width="1600" height="1200"></div>
</section>

<section class="coll" id="co-uvidite" aria-labelledby="coll-h">
	<div class="coll-head">
		<h2 class="display h2" id="coll-h"><?php echo esc_html( sam_t( 'coll_h' ) ); ?></h2>
		<p class="lead"><?php echo esc_html( sam_t( 'coll_p' ) ); ?></p>
	</div>
	<div class="grid">
		<figure class="tile t1"><img src="<?php echo esc_url( $img( '6l4a0462-1600.webp' ) ); ?>" srcset="<?php echo esc_attr( $img( '6l4a0462-800.webp' ) . ' 800w, ' . $img( '6l4a0462-1600.webp' ) . ' 1600w' ); ?>" sizes="(max-width:1000px) 100vw, 66vw" alt="<?php echo esc_attr( sam_t( 'tile_1_alt' ) ); ?>" loading="lazy"><h3 class="display"><?php echo esc_html( sam_t( 'tile_1' ) ); ?></h3></figure>
		<figure class="tile t2"><img src="<?php echo esc_url( $img( 'reels_1_01_03_32_13-800.webp' ) ); ?>" alt="<?php echo esc_attr( sam_t( 'tile_2_alt' ) ); ?>" loading="lazy"><h3 class="display"><?php echo esc_html( sam_t( 'tile_2' ) ); ?></h3></figure>
		<figure class="tile t3"><img src="<?php echo esc_url( $img( '6l4a0332-800.webp' ) ); ?>" alt="<?php echo esc_attr( sam_t( 'tile_3_alt' ) ); ?>" loading="lazy"><h3 class="display"><?php echo esc_html( sam_t( 'tile_3' ) ); ?></h3></figure>
		<figure class="tile t4"><img src="<?php echo esc_url( $img( '6l4a5712-800.webp' ) ); ?>" alt="<?php echo esc_attr( sam_t( 'tile_4_alt' ) ); ?>" loading="lazy"><h3 class="display"><?php echo esc_html( sam_t( 'tile_4' ) ); ?></h3></figure>
		<div class="tile t5"><h3 class="display"><?php echo esc_html( sam_t( 'tile_5' ) ); ?></h3><p><?php echo esc_html( sam_t( 'tile_5_p' ) ); ?></p></div>
	</div>
</section>

<section class="story" id="historie" aria-labelledby="story-h">
	<h2 class="big" id="story-h"><?php echo esc_html( sam_t( 'story_h' ) ); ?></h2>
	<div class="txt">
		<p><?php echo esc_html( sam_t( 'story_p1' ) ); ?></p>
		<p><?php echo esc_html( sam_t( 'story_p2' ) ); ?></p>
	</div>
	<div class="figures">
		<p><b>120</b><?php echo esc_html( sam_t( 'fig_1' ) ); ?></p>
		<p><b>500+</b><?php echo esc_html( sam_t( 'fig_2' ) ); ?></p>
		<p><b><?php echo 'en' === $lang ? '1,500 m²' : '1 500 m²'; ?></b><?php echo esc_html( sam_t( 'fig_3' ) ); ?></p>
		<p><b><?php echo 'en' === $lang ? '1,000,000' : '1 000 000'; ?></b><?php echo esc_html( sam_t( 'fig_4' ) ); ?></p>
	</div>
</section>

<section class="visit" id="navsteva" aria-labelledby="visit-h">
	<h2 class="display h2" id="visit-h"><?php echo esc_html( sam_t( 'visit_h' ) ); ?></h2>
	<div class="visit-grid">
		<div>
			<h3><?php echo esc_html( sam_t( 'visit_hours' ) ); ?></h3>
			<p><?php echo esc_html( sam_t( 'visit_hours_p' ) ); ?></p>
			<h3 class="mt"><?php echo esc_html( sam_t( 'visit_addr' ) ); ?></h3>
			<p><?php echo esc_html( sam_t( 'visit_addr_p' ) ); ?> <a href="https://maps.google.com/?q=Celetn%C3%A1+15,+Praha+1"><?php echo esc_html( sam_t( 'visit_map' ) ); ?></a></p>
		</div>
		<div>
			<h3><?php echo esc_html( sam_t( 'visit_good' ) ); ?></h3>
			<ul>
				<?php foreach ( sam_t( 'visit_li' ) as $li ) : ?>
				<li><?php echo esc_html( $li ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
		<div>
			<h3><?php echo esc_html( sam_t( 'visit_prices' ) ); ?></h3>
			<table class="prices">
				<tr><td><?php echo esc_html( sam_t( 'p_adult' ) ); ?></td><td><?php echo esc_html( $prices['adult'] ); ?></td></tr>
				<tr><td><?php echo esc_html( sam_t( 'p_reduced' ) ); ?></td><td><?php echo esc_html( $prices['reduced'] ); ?></td></tr>
				<tr><td><?php echo esc_html( sam_t( 'p_child' ) ); ?></td><td><?php echo esc_html( $prices['child'] ); ?></td></tr>
				<tr><td><?php echo esc_html( sam_t( 'p_under3' ) ); ?></td><td><?php echo esc_html( sam_t( 'free' ) ); ?></td></tr>
				<?php if ( $prices['fam21'] ) : ?><tr class="gap"><td><?php echo esc_html( sam_t( 'p_fam', '2 + 1' ) ); ?></td><td><?php echo esc_html( $prices['fam21'] ); ?></td></tr><?php endif; ?>
				<?php if ( $prices['fam22'] ) : ?><tr><td><?php echo esc_html( sam_t( 'p_fam', '2 + 2' ) ); ?></td><td><?php echo esc_html( $prices['fam22'] ); ?></td></tr><?php endif; ?>
				<?php if ( $prices['fam23'] ) : ?><tr><td><?php echo esc_html( sam_t( 'p_fam', '2 + 3' ) ); ?></td><td><?php echo esc_html( $prices['fam23'] ); ?></td></tr><?php endif; ?>
				<?php if ( $prices['group'] ) : ?><tr><td><?php echo esc_html( sam_t( 'p_group' ) ); ?></td><td><?php echo esc_html( $prices['group'] ); ?></td></tr><?php endif; ?>
			</table>
			<p class="mt-s"><a href="<?php echo esc_url( sam_page_url( 1529 ) ); ?>"><?php echo esc_html( sam_t( 'visit_all_prices' ) ); ?></a></p>
		</div>
	</div>
</section>

<section class="schools" id="skoly" aria-labelledby="sch-h">
	<div class="schools-in">
		<div>
			<h2 class="display h2" id="sch-h"><?php echo esc_html( sam_t( 'sch_h' ) ); ?></h2>
			<p class="mt"><?php echo esc_html( sam_t( 'sch_p1' ) ); ?></p>
			<p><?php echo esc_html( sam_t( 'sch_p2' ) ); ?></p>
			<a class="pill dark" href="<?php echo esc_url( sam_page_url( 45 ) ); ?>"><?php echo esc_html( sam_t( 'sch_more' ) ); ?></a>
		</div>
		<div class="school-prices">
			<div><b><?php echo 'en' === $lang ? 'CZK 135' : '135 Kč'; ?></b><?php echo esc_html( sam_t( 'sch_a' ) ); ?></div>
			<div><b><?php echo 'en' === $lang ? 'CZK 169' : '169 Kč'; ?></b><?php echo esc_html( sam_t( 'sch_b' ) ); ?></div>
			<div><b><?php echo esc_html( sam_t( 'sch_free' ) ); ?></b><?php echo esc_html( sam_t( 'sch_c' ) ); ?></div>
		</div>
	</div>
</section>

<section class="end" aria-labelledby="end-h">
	<div>
		<h2 class="display" id="end-h"><?php echo esc_html( sam_t( 'end_h' ) ); ?></h2>
		<p class="mt"><a class="pill" href="#vstupenky"><?php echo esc_html( sam_t( 'buy' ) ); ?></a></p>
	</div>
	<div>
		<p class="rating"><?php echo wp_kses_post( sam_t( 'rating' ) ); ?></p>
		<p class="contact"><?php echo esc_html( sam_t( 'questions' ) ); ?>: <a href="mailto:info@steelartmuseum.eu">info@steelartmuseum.eu</a><br><?php echo esc_html( sam_t( 'phone' ) ); ?>: <a href="tel:+420727850257">+420 727 850 257</a></p>
	</div>
</section>
</main>

<div class="bar" id="bar" aria-hidden="true">
	<span id="bar-txt"></span>
	<a class="pill" href="#vstupenky" tabindex="-1"><?php echo esc_html( sam_t( 'buy_short' ) ); ?></a>
</div>
<?php
get_footer();
