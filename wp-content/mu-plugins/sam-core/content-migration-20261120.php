<?php
/**
 * SEO titulky a popisy (Rank Math) pro úvodní stránku CZ/EN, ceník a kontakt.
 * Rank Math je dřív skládal automaticky z obsahu („ceník na pokladně (platba na místě)“).
 * Běží jednou, i na ostré databázi po přechodu. Ručně vyplněné hodnoty nepřepisuje.
 */

defined( 'ABSPATH' ) || exit;

final class SAM_Content_Migration_20261120 {
	const OPTION = 'sam_content_migration_20261120_done';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'run' ), 42 );
	}

	public static function data() {
		return array(
			42   => array( // úvodní stránka CZ
				'Steel Art Museum Praha – interaktivní muzeum ocelových soch',
				'Přes 120 soch v životní velikosti z recyklované oceli v centru Prahy. Na sochy se tu sahá: zvedněte Thorovo kladivo a vyfoťte se s hrdiny. Otevřeno denně 10–21 h, Celetná 15.',
			),
			1827 => array( // úvodní stránka EN
				'Steel Art Museum Prague – interactive museum of steel sculptures',
				'Over 120 life-size sculptures made from recycled steel in the centre of Prague. Touching allowed: lift Thor’s hammer and take photos with your heroes. Open daily 10 am–9 pm, Celetná 15.',
			),
			1529 => array( // ceník
				'Ceník vstupného – Steel Art Museum Praha',
				'Vstupné do Steel Art Museum: dospělí, děti, studenti, senioři, rodinné a skupinové vstupenky. Online levněji, děti do 3 let zdarma.',
			),
			51   => array( // kontakt
				'Kontakt – Steel Art Museum Praha',
				'Steel Art Museum, Celetná 15, Praha 1. Otevřeno denně 10–21 h, i o svátcích. Telefon +420 727 850 257, e-mail info@steelartmuseum.eu.',
			),
		);
	}

	public static function run() {
		if ( get_option( self::OPTION ) ) {
			return;
		}
		$done = array();
		foreach ( self::data() as $id => $meta ) {
			if ( 'page' !== get_post_type( $id ) ) {
				continue;
			}
			if ( '' === (string) get_post_meta( $id, 'rank_math_title', true ) ) {
				update_post_meta( $id, 'rank_math_title', $meta[0] );
			}
			if ( '' === (string) get_post_meta( $id, 'rank_math_description', true ) ) {
				update_post_meta( $id, 'rank_math_description', $meta[1] );
			}
			$done[] = $id;
		}
		update_option( self::OPTION, gmdate( 'c' ) . ' pages:' . implode( ',', $done ), false );
	}
}
SAM_Content_Migration_20261120::init();
