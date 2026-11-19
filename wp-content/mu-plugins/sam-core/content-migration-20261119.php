<?php
/**
 * Úprava obsahu stránky Kontakt (ID 51), běží jednou a po ostrém přechodu sama znovu
 * na čerstvé databázi (příznak je v databázi, ne v kódu):
 *
 * 1. Formulář Gutena Forms (plugin chybí, formulář neodesílal) → [sam_contact_form].
 * 2. Pryč nevykreslený shortcode [trustindex …] (plugin chybí, na stránce byl vidět jako text).
 * 3. Pryč blok mapy WP Google Maps (plugin chybí, blok nic nevykresloval).
 *
 * Původní obsah se uloží do metadat _sam_content_backup_20261119.
 */

defined( 'ABSPATH' ) || exit;

final class SAM_Content_Migration_20261119 {
	const OPTION  = 'sam_content_migration_20261119_done';
	const PAGE_ID = 51;

	public static function init() {
		add_action( 'init', array( __CLASS__, 'run' ), 40 );
	}

	public static function run() {
		if ( get_option( self::OPTION ) ) {
			return;
		}
		$post = get_post( self::PAGE_ID );
		if ( ! $post || 'page' !== $post->post_type ) {
			update_option( self::OPTION, 'skipped:no-page', false );
			return;
		}

		$content = self::migrate( $post->post_content );
		if ( $content !== $post->post_content ) {
			global $wpdb;
			update_post_meta( self::PAGE_ID, '_sam_content_backup_20261119', wp_slash( $post->post_content ) );
			// Přímo do databáze: wp_update_post by u nepřihlášeného návštěvníka obsah prohnal kses.
			$wpdb->update( $wpdb->posts, array( 'post_content' => $content ), array( 'ID' => self::PAGE_ID ) );
			clean_post_cache( self::PAGE_ID );
		}
		update_option( self::OPTION, gmdate( 'c' ), false );
	}

	public static function migrate( $content ) {
		$content = preg_replace(
			'#<!-- wp:gutena/forms .*?<!-- /wp:gutena/forms -->#s',
			"<!-- wp:shortcode -->\n[sam_contact_form]\n<!-- /wp:shortcode -->",
			$content
		);
		// Kontejner GenerateBlocks, ve kterém je jen shortcode trustindex / jen blok mapy.
		$content = preg_replace(
			'#<!-- wp:generateblocks/container [^>]*-->\s*<!-- wp:shortcode -->\s*\[trustindex[^\]]*\]\s*<!-- /wp:shortcode -->\s*<!-- /wp:generateblocks/container -->\s*#s',
			'',
			$content
		);
		$content = preg_replace(
			'#<!-- wp:generateblocks/container [^>]*-->\s*<!-- wp:wpgmza-standalone/map-block [^>]*/-->\s*<!-- /wp:generateblocks/container -->\s*#s',
			'',
			$content
		);
		return $content;
	}
}
SAM_Content_Migration_20261119::init();
