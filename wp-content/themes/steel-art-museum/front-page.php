<?php
/**
 * Domovská stránka (CZ i EN přes WPML). Blíž původnímu webu: video v úvodu, zážitek a fotky, recenze, výstava, vstupenky níž
 * s ceníkem na pokladně, historie a praktické informace. Kalkulačka a košík jsou stejné
 * jako u varianty A (inc/tickets.php, assets/js/home.js).
 */
defined( 'ABSPATH' ) || exit;

get_header();

$lang    = sam_lang();
$en      = 'en' === $lang;
$offer   = sam_ticket_offer( $lang );
$valid   = sam_tickets_valid_until_text( $lang );
$prices  = sam_box_office_prices( $lang );
$sources = class_exists( 'SAM_Reviews' ) ? SAM_Reviews::sources() : array();
$reviews = class_exists( 'SAM_Reviews' ) ? SAM_Reviews::items( 12, $lang ) : array();
$uploads = wp_get_upload_dir()['baseurl'];

/** Fotka z knihovny médií (srcset podle velikostí WordPressu). */
$photo = static function ( $id, $alt, $sizes, $class = '' ) {
	return wp_get_attachment_image( $id, 'large', false, array( 'alt' => $alt, 'sizes' => $sizes, 'loading' => 'lazy', 'class' => $class ) );
};
?>
<main id="obsah" class="b-main">

<section class="b-hero">
	<video class="b-hero-video" muted loop playsinline preload="none" poster="<?php echo esc_url( sam_img( 'fotky00086405-1600.webp' ) ); ?>"
		data-desktop="<?php echo esc_url( $uploads . '/2026/02/hp_desktop.webm' ); ?>" data-mobile="<?php echo esc_url( $uploads . '/2026/02/hp_mob.webm' ); ?>"></video>
	<div class="b-hero-in">
		<h1><?php echo esc_html( sam_t( 'b_hero_h' ) ); ?></h1>
		<p class="b-hero-sub"><?php echo esc_html( sam_t( 'b_hero_p' ) ); ?></p>
		<?php if ( $sources ) : ?>
		<p class="b-hero-ratings">
			<?php foreach ( $sources as $key => $s ) : ?>
			<a href="<?php echo esc_url( $s['url'] ); ?>" target="_blank" rel="noopener"><?php echo sam_rating_mark( $key, $s['rating'] ); // phpcs:ignore ?> <b><?php echo esc_html( sam_rating( $s['rating'], $lang ) ); ?></b> <?php echo esc_html( $s['name'] ); ?></a>
			<?php endforeach; ?>
		</p>
		<?php endif; ?>
		<p class="b-hero-cta"><a class="b-btn" href="#zazitek"><?php echo esc_html( sam_t( 'b_hero_cta' ) ); ?></a></p>
	</div>
</section>

<section class="b-stats" aria-label="Steel Art Museum v číslech">
	<p><b><?php echo $en ? '120+' : 'Přes 120'; ?></b><?php echo esc_html( sam_t( 'b_stat_1' ) ); ?></p>
	<p><b><?php echo $en ? '1,000,000' : '1 000 000'; ?></b><?php echo esc_html( sam_t( 'b_stat_2' ) ); ?></p>
	<p><b><?php echo $en ? '1,500 m²' : '1 500 m²'; ?></b><?php echo esc_html( sam_t( 'b_stat_3' ) ); ?></p>
	<p><b><?php echo $en ? '150 mil.' : '150 mil. Kč'; ?></b><?php echo esc_html( sam_t( 'b_stat_4' ) ); ?></p>
</section>

<section class="b-sec b-exp" id="zazitek" aria-labelledby="exp-h">
	<div class="b-head">
		<p class="b-kicker"><?php echo esc_html( sam_t( 'b_exp_kicker' ) ); ?></p>
		<h2 id="exp-h"><?php echo esc_html( sam_t( 'b_exp_h' ) ); ?></h2>
		<p class="b-lead"><?php echo esc_html( sam_t( 'b_exp_p' ) ); ?></p>
	</div>
	<div class="b-carousel" data-carousel>
		<div class="b-car-track" tabindex="0" role="region" aria-roledescription="carousel" aria-label="<?php echo esc_attr( sam_t( 'b_exp_h' ) ); ?>">
		<?php
		$slides = array();
		foreach ( sam_t( 'b_slides' ) as $key => $s ) {
			$slides[] = array( sam_img( 'zazitek-' . $key . '-800.webp' ), sam_img( 'zazitek-' . $key . '-1600.webp' ), $s[0], $s[1], $s[2] );
		}
		foreach ( array( array( '6l4a0460', 'b_exp_1', 'try_1_alt' ), array( 'soubor-1', 'b_exp_2', 'try_2_alt' ), array( '6l4a0465', 'b_exp_3', 'try_3_alt' ) ) as $c ) {
			$slides[] = array( sam_img( $c[0] . '-800.webp' ), sam_img( $c[0] . '-1600.webp' ), sam_t( $c[1] ), sam_t( $c[1] . '_p' ), sam_t( $c[2] ) );
		}
		foreach ( $slides as $i => $c ) :
			?>
		<figure class="b-card b-slide">
			<img src="<?php echo esc_url( $c[0] ); ?>" srcset="<?php echo esc_attr( $c[0] . ' 800w, ' . $c[1] . ' 1600w' ); ?>" sizes="(max-width:760px) 85vw, (max-width:1000px) 46vw, 400px" alt="<?php echo esc_attr( $c[4] ); ?>" width="800" height="600" loading="<?php echo $i < 3 ? 'eager' : 'lazy'; ?>">
			<figcaption><h3><?php echo esc_html( $c[2] ); ?></h3><p><?php echo esc_html( $c[3] ); ?></p></figcaption>
		</figure>
		<?php endforeach; ?>
		<figure class="b-card b-slide">
			<?php echo $photo( 1577, $en ? 'Steel Optimus Prime, E.T. and Bumblebee' : 'Ocelový Optimus Prime, E.T. a Bumblebee', '(max-width:760px) 85vw, 400px' ); // phpcs:ignore ?>
			<figcaption><h3><?php echo esc_html( sam_t( 'b_exp_4' ) ); ?></h3><p><?php echo esc_html( sam_t( 'b_exp_4_p' ) ); ?></p></figcaption>
		</figure>
		</div>
		<div class="b-car-nav">
			<button type="button" class="b-car-btn" data-dir="-1" aria-label="<?php echo esc_attr( sam_t( 'b_prev' ) ); ?>"><span aria-hidden="true">←</span></button>
			<div class="b-car-dots" aria-hidden="true"></div>
			<button type="button" class="b-car-btn" data-dir="1" aria-label="<?php echo esc_attr( sam_t( 'b_next' ) ); ?>"><span aria-hidden="true">→</span></button>
		</div>
	</div>
</section>

<?php if ( $sources || $reviews ) : ?>
<section class="b-sec b-reviews" id="recenze" aria-labelledby="rev-h">
	<div class="b-head b-head--row">
		<div>
			<p class="b-kicker"><?php echo esc_html( sam_t( 'b_rev_kicker' ) ); ?></p>
			<h2 id="rev-h"><?php echo esc_html( sam_t( 'b_rev_h' ) ); ?></h2>
		</div>
		<div class="b-scores">
			<?php foreach ( $sources as $key => $s ) : ?>
			<a class="b-score b-score--<?php echo esc_attr( $key ); ?>" href="<?php echo esc_url( $s['url'] ); ?>" target="_blank" rel="noopener">
				<span class="b-score-num"><?php echo esc_html( sam_rating( $s['rating'], $lang ) ); ?></span>
				<span><?php echo sam_rating_mark( $key, $s['rating'] ); // phpcs:ignore ?><span class="b-score-src"><?php echo esc_html( $s['name'] ); ?><?php echo $s['count'] ? ' · ' . esc_html( sprintf( sam_t( 'b_rev_based' ), sam_number( $s['count'], $lang ) ) ) : ''; ?></span></span>
			</a>
			<?php endforeach; ?>
		</div>
	</div>
	<?php if ( $reviews ) : ?>
	<div class="b-rev-track" tabindex="0" aria-label="<?php echo esc_attr( sam_t( 'b_rev_h' ) ); ?>">
		<?php foreach ( $reviews as $r ) : ?>
		<blockquote class="b-rev b-rev--<?php echo esc_attr( $r['source'] ); ?>">
			<?php echo sam_rating_mark( $r['source'], (int) $r['rating'] ); // phpcs:ignore ?>
			<p><?php echo esc_html( $r['text'] ); ?></p>
			<footer><b><?php echo esc_html( $r['author'] ); ?></b> · <?php echo esc_html( 'tripadvisor' === $r['source'] ? 'Tripadvisor' : ucfirst( $r['source'] ) ); ?></footer>
		</blockquote>
		<?php endforeach; ?>
	</div>
	<?php endif; ?>
	<?php if ( $sources ) : ?>
	<p class="b-center b-rev-links">
		<?php foreach ( $sources as $s ) : ?>
		<a class="b-link" href="<?php echo esc_url( $s['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( sam_t( 'b_rev_all' ) . ' ' . sprintf( sam_t( 'b_rev_on' ), $s['name'] ) ); ?></a>
		<?php endforeach; ?>
	</p>
	<?php endif; ?>
</section>
<?php endif; ?>

<section class="b-sec b-feed" id="fotky" aria-labelledby="feed-h">
	<div class="b-feed-head">
		<a class="b-feed-profile" href="https://www.instagram.com/steelartmuseum/" target="_blank" rel="noopener">
			<img src="<?php echo esc_url( sam_img( 'logo.webp' ) ); ?>" alt="" width="56" height="56">
			<span><b>steelartmuseum</b><span id="feed-h"><?php echo esc_html( sam_t( 'b_feed_h' ) ); ?></span></span>
		</a>
		<a class="b-btn b-btn--ig" href="https://www.instagram.com/steelartmuseum/" target="_blank" rel="noopener"><?php echo sam_icon( 'instagram' ); // phpcs:ignore ?> <?php echo esc_html( sam_t( 'b_feed_follow' ) ); ?></a>
	</div>
	<ul class="b-feed-grid">
		<?php foreach ( sam_t( 'b_feed' ) as $feed_id => $feed_caption ) : ?>
		<li>
			<a class="b-post" href="https://www.instagram.com/steelartmuseum/" target="_blank" rel="noopener" aria-label="<?php echo esc_attr( $feed_caption . ' – Instagram' ); ?>">
				<?php echo wp_get_attachment_image( $feed_id, 'medium_large', false, array( 'alt' => $feed_caption, 'sizes' => '(max-width:760px) 34vw, 17vw', 'loading' => 'lazy' ) ); // phpcs:ignore ?>
				<span class="b-post-cap"><?php echo esc_html( $feed_caption ); ?></span>
			</a>
		</li>
		<?php endforeach; ?>
	</ul>
	<p class="b-center"><a class="b-link" href="https://www.instagram.com/steelartmuseum/" target="_blank" rel="noopener"><?php echo esc_html( sam_t( 'b_feed_more' ) ); ?></a></p>
</section>

<section class="b-sec b-see" id="co-uvidite" aria-labelledby="see-h">
	<div class="b-head">
		<h2 id="see-h"><?php echo esc_html( sam_t( 'b_see_h' ) ); ?></h2>
		<p class="b-lead"><?php echo esc_html( sam_t( 'b_see_p' ) ); ?></p>
	</div>
	<?php
	$cats = array(
		array( 1584, 'b_cat_1', $en ? 'Steel Optimus Prime with a sword' : 'Ocelový Optimus Prime s mečem' ),
		array( 1582, 'b_cat_2', $en ? 'Steel replica of a Lamborghini' : 'Ocelová replika Lamborghini' ),
		array( 1583, 'b_cat_3', $en ? 'Steel Elvis and Michael Jackson' : 'Ocelový Elvis a Michael Jackson' ),
		array( 1601, 'b_cat_4', $en ? 'Steel dinosaurs over a lava floor' : 'Ocelový triceratops a další dinosauři nad lávou' ),
		array( 1077, 'b_cat_5', $en ? 'Children with steel Minions' : 'Děti s ocelovými Mimoni' ),
	);
	foreach ( $cats as $i => $cat ) :
		?>
	<article class="b-cat<?php echo $i % 2 ? ' b-cat--rev' : ''; ?>">
		<div class="b-cat-img"><?php echo $photo( $cat[0], $cat[2], '(max-width:760px) 100vw, 50vw' ); // phpcs:ignore ?></div>
		<div class="b-cat-txt">
			<h3><?php echo esc_html( sam_t( $cat[1] ) ); ?></h3>
			<p><?php echo esc_html( sam_t( $cat[1] . '_p' ) ); ?></p>
		</div>
	</article>
	<?php endforeach; ?>
</section>

<section class="b-family" aria-labelledby="fam-h">
	<div class="b-family-img"><img src="<?php echo esc_url( sam_img( '6l4a5712-800.webp' ) ); ?>" alt="<?php echo esc_attr( sam_t( 'tile_4_alt' ) ); ?>" loading="lazy"></div>
	<div class="b-family-txt">
		<h2 id="fam-h"><?php echo esc_html( sam_t( 'b_family_h' ) ); ?></h2>
		<p><?php echo esc_html( sam_t( 'b_family_p' ) ); ?></p>
		<ul><?php foreach ( sam_t( 'b_family_li' ) as $li ) : ?><li><?php echo esc_html( $li ); ?></li><?php endforeach; ?></ul>
	</div>
</section>

<section class="b-sec b-tickets" id="vstupenky" aria-labelledby="tk-h">
	<div class="b-head">
		<p class="b-kicker"><?php echo esc_html( sam_t( 'b_tk_kicker' ) ); ?></p>
		<h2 id="tk-h"><?php echo esc_html( sam_t( 'b_tk_h' ) ); ?></h2>
		<p class="b-lead"><?php echo esc_html( sam_t( 'b_tk_p', $valid ) ); ?></p>
		<?php if ( $offer['promo'] && sam_promo_banner( $lang ) ) : ?><p class="b-promo"><?php echo esc_html( sam_promo_banner( $lang ) ); ?></p><?php endif; ?>
	</div>
	<div class="b-tk">
		<div class="b-tk-calc">
			<ul class="rows" id="rows"></ul>
			<div class="sum">
				<p class="msg" id="msg" aria-live="polite"></p>
				<p class="total"><small><?php echo esc_html( sam_t( 'total' ) ); ?></small><span id="was" class="was"></span><span id="total">–</span></p>
				<button class="b-btn b-btn--buy" id="buy" type="button" disabled><?php echo esc_html( sam_t( 'buy' ) ); ?></button>
			</div>
		</div>
		<aside class="b-tk-box" aria-labelledby="box-h">
			<h3 id="box-h"><?php echo esc_html( sam_t( 'b_box_h' ) ); ?></h3>
			<table class="b-prices">
				<thead><tr><th></th><th><?php echo esc_html( sam_t( 'b_box_online' ) ); ?></th><th><?php echo esc_html( sam_t( 'b_box_door' ) ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $offer['rows'] as $row ) : ?>
					<tr><td><?php echo esc_html( $row['n'] ); ?></td><td><?php echo esc_html( sam_money( $row['p'], $lang ) ); ?></td><td><?php echo esc_html( sam_money( $row['r'], $lang ) ); ?></td></tr>
				<?php endforeach; ?>
				<tr><td><?php echo esc_html( sam_t( 'p_under3' ) ); ?></td><td colspan="2"><?php echo esc_html( sam_t( 'free' ) ); ?></td></tr>
				</tbody>
			</table>
			<h4><?php echo esc_html( sam_t( 'b_box_more' ) ); ?></h4>
			<table class="b-prices b-prices--small">
				<?php if ( $prices['fam21'] ) : ?><tr><td><?php echo esc_html( sam_t( 'p_fam', '2 + 1' ) ); ?></td><td><?php echo esc_html( $prices['fam21'] ); ?></td></tr><?php endif; ?>
				<?php if ( $prices['fam22'] ) : ?><tr><td><?php echo esc_html( sam_t( 'p_fam', '2 + 2' ) ); ?></td><td><?php echo esc_html( $prices['fam22'] ); ?></td></tr><?php endif; ?>
				<?php if ( $prices['fam23'] ) : ?><tr><td><?php echo esc_html( sam_t( 'p_fam', '2 + 3' ) ); ?></td><td><?php echo esc_html( $prices['fam23'] ); ?></td></tr><?php endif; ?>
				<?php if ( $prices['group'] ) : ?><tr><td><?php echo esc_html( sam_t( 'p_group' ) ); ?></td><td><?php echo esc_html( $prices['group'] ); ?></td></tr><?php endif; ?>
			</table>
			<ul class="b-notes">
				<li><?php echo esc_html( sam_t( 'note_under3' ) ); ?></li>
				<?php if ( $offer['promo'] ) : ?><li><?php echo esc_html( sam_promo_note( $lang ) ); ?></li><?php endif; ?>
				<li><?php echo esc_html( sam_t( 'note_groups' ) ); ?></li>
			</ul>
		</aside>
	</div>
</section>

<section class="b-sec b-story" id="historie" aria-labelledby="story-h">
	<div class="b-story-txt">
		<p class="b-kicker"><?php echo esc_html( sam_t( 'b_story_kicker' ) ); ?></p>
		<h2 id="story-h"><?php echo esc_html( sam_t( 'b_story_h' ) ); ?></h2>
		<p><?php echo esc_html( sam_t( 'b_story_p1' ) ); ?></p>
		<p><?php echo esc_html( sam_t( 'b_story_p2' ) ); ?></p>
	</div>
	<div class="b-story-img"><img src="<?php echo esc_url( sam_img( '6l4a0462-800.webp' ) ); ?>" srcset="<?php echo esc_attr( sam_img( '6l4a0462-800.webp' ) . ' 800w, ' . sam_img( '6l4a0462-1600.webp' ) . ' 1600w' ); ?>" sizes="(max-width:760px) 100vw, 50vw" alt="<?php echo esc_attr( sam_t( 'tile_1_alt' ) ); ?>" loading="lazy"></div>
</section>

<section class="b-sec b-visit" id="navsteva" aria-labelledby="visit-h">
	<h2 id="visit-h"><?php echo esc_html( sam_t( 'b_visit_h' ) ); ?></h2>
	<div class="b-visit-grid">
		<div>
			<h3><?php echo esc_html( sam_t( 'visit_hours' ) ); ?></h3>
			<p><?php echo esc_html( sam_t( 'visit_hours_p' ) ); ?></p>
			<h3><?php echo esc_html( sam_t( 'visit_addr' ) ); ?></h3>
			<p><?php echo esc_html( sam_t( 'visit_addr_p' ) ); ?> <a href="https://maps.google.com/?q=Celetn%C3%A1+15,+Praha+1"><?php echo esc_html( sam_t( 'visit_map' ) ); ?></a></p>
		</div>
		<div>
			<h3><?php echo esc_html( sam_t( 'visit_good' ) ); ?></h3>
			<ul><?php foreach ( sam_t( 'visit_li' ) as $li ) : ?><li><?php echo esc_html( $li ); ?></li><?php endforeach; ?></ul>
		</div>
		<div class="b-follow">
			<h3><?php echo esc_html( sam_t( 'b_follow_h' ) ); ?></h3>
			<p><?php echo esc_html( sam_t( 'b_follow_p' ) ); ?></p>
			<p class="b-follow-links">
				<a class="b-btn b-btn--outline" href="https://www.instagram.com/steelartmuseum/" rel="noopener"><?php echo sam_icon( 'instagram' ); // phpcs:ignore ?> Instagram</a>
				<a class="b-btn b-btn--outline" href="https://facebook.com/steelartmuseum" rel="noopener"><?php echo sam_icon( 'facebook' ); // phpcs:ignore ?> Facebook</a>
			</p>
			<h3><?php echo esc_html( sam_t( 'b_q_h' ) ); ?></h3>
			<p><a href="tel:+420727850257">+420 727 850 257</a> · <a href="mailto:info@steelartmuseum.eu">info@steelartmuseum.eu</a></p>
		</div>
	</div>
</section>
</main>
<script>
/* Úvodní video: menší verze pro mobil, načte se až po vykreslení stránky (jako na původním webu). */
(function(){var v=document.querySelector('.b-hero-video');if(!v||matchMedia('(prefers-reduced-motion: reduce)').matches)return;
addEventListener('load',function(){v.src=innerWidth<768?v.dataset.mobile:v.dataset.desktop;v.play().catch(function(){});});})();
</script>
<?php
get_footer();
