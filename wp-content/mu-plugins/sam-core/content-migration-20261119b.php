<?php
/**
 * Jeden kontaktní e-mail na celém webu: info@steelartmuseum.cz → info@steelartmuseum.eu
 * (Kontakt, obchodní podmínky, ochrana osobních údajů). Běží jednou, i na ostré databázi po přechodu.
 * Původní obsah stránky je v metadatech _sam_content_backup_20261119b.
 */

defined( 'ABSPATH' ) || exit;

final class SAM_Content_Migration_20261119b {
	const OPTION = 'sam_content_migration_20261119b_done';
	const FROM   = 'info@steelartmuseum.cz';
	const TO     = 'info@steelartmuseum.eu';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'run' ), 41 );
	}

	public static function run() {
		if ( get_option( self::OPTION ) ) {
			return;
		}
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status IN ('publish','draft','private') AND post_content LIKE %s",
			'%' . $wpdb->esc_like( self::FROM ) . '%'
		) );
		foreach ( $ids as $id ) {
			$content = get_post_field( 'post_content', $id, 'raw' );
			update_post_meta( $id, '_sam_content_backup_20261119b', wp_slash( $content ) );
			// Přímo do databáze, aby obsah nešel přes kses (běží i při návštěvě nepřihlášeného).
			$wpdb->update( $wpdb->posts, array( 'post_content' => str_ireplace( self::FROM, self::TO, $content ) ), array( 'ID' => $id ) );
			clean_post_cache( $id );
		}
		update_option( self::OPTION, gmdate( 'c' ) . ' pages:' . implode( ',', $ids ), false );
	}
}
SAM_Content_Migration_20261119b::init();
