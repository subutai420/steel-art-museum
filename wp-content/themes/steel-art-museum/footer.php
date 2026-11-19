<?php defined( 'ABSPATH' ) || exit; ?>
<footer class="foot">
	<div class="foot-grid">
		<div>
			<h2 class="foot-h"><?php echo esc_html( sam_t( 'foot_visit' ) ); ?></h2>
			<p><?php echo esc_html( sam_t( 'visit_hours' ) ); ?><br><?php echo esc_html( sam_t( 'visit_addr' ) ); ?></p>
			<p><a href="https://maps.google.com/?q=Celetn%C3%A1+15,+Praha+1"><?php echo esc_html( sam_t( 'visit_map' ) ); ?></a></p>
		</div>
		<div>
			<h2 class="foot-h"><?php echo esc_html( sam_t( 'foot_contact' ) ); ?></h2>
			<p><a href="mailto:info@steelartmuseum.eu">info@steelartmuseum.eu</a><br><a href="tel:+420727850257">+420 727 850 257</a></p>
			<p class="social"><a href="https://facebook.com/steelartmuseum">Facebook</a><a href="https://www.instagram.com/steelartmuseum/">Instagram</a></p>
		</div>
		<div>
			<h2 class="foot-h"><?php echo esc_html( sam_t( 'foot_info' ) ); ?></h2>
			<?php if ( has_nav_menu( 'footer' ) ) : ?>
				<?php wp_nav_menu( array( 'theme_location' => 'footer', 'container' => false, 'menu_class' => 'foot-links', 'depth' => 1 ) ); ?>
			<?php else : ?>
			<ul class="foot-links">
				<li><a href="<?php echo esc_url( sam_page_url( 1529 ) ); ?>"><?php echo esc_html( sam_t( 'price_list' ) ); ?></a></li>
				<li><a href="<?php echo esc_url( sam_page_url( 2 ) ); ?>"><?php echo esc_html( sam_t( 'nav_faq' ) ); ?></a></li>
				<li><a href="<?php echo esc_url( sam_page_url( 51 ) ); ?>"><?php echo esc_html( sam_t( 'contact_page' ) ); ?></a></li>
				<li><a href="<?php echo esc_url( sam_page_url( 1147 ) ); ?>"><?php echo esc_html( sam_t( 'terms' ) ); ?></a></li>
				<li><a href="<?php echo esc_url( sam_page_url( 1148 ) ); ?>"><?php echo esc_html( sam_t( 'privacy' ) ); ?></a></li>
			</ul>
			<?php endif; ?>
		</div>
	</div>
	<div class="foot-bottom">
		<span>© <?php echo esc_html( wp_date( 'Y' ) ); ?> Steel Art Museum, Celetná 15, Praha 1</span>
		<span><a href="https://www.kudyznudy.cz/"><?php echo esc_html( sam_t( 'kudyznudy' ) ); ?></a></span>
		<span><?php echo wp_kses_post( sam_t( 'made_by', '<a href="https://danielbubak.cz/">Daniel Bubak</a>' ) ); ?></span>
	</div>
</footer>
<?php wp_footer(); ?>
</body>
</html>
