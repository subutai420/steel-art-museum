<?php
/**
 * Texty bez jmen: pryč jméno zakladatele a názvy postav chráněných autorským právem
 * (úvodní stránka CZ/EN a Magenta Moments CZ/EN, bloky GenerateBlocks podle uniqueId)
 * a SEO popisy úvodní stránky. Běží jednou, i na ostré databázi po přechodu.
 * Původní obsah stránky je v metadatech _sam_content_backup_20261121.
 */

defined( 'ABSPATH' ) || exit;

final class SAM_Content_Migration_20261121 {
	const OPTION = 'sam_content_migration_20261121_done';

	/** Stránka → jazyk nových textů (EN Magenta má dnes český obsah, dostane anglický). */
	const PAGES = array( 42 => 'cs', 21248 => 'cs', 1827 => 'en', 26283 => 'en' );

	public static function init() {
		add_action( 'init', array( __CLASS__, 'run' ), 43 );
	}

	public static function blocks() {
		return array(
			'ccb54542' => array(
				'cs' => '<strong>U nás v muzeu můžete cokoliv – vezměte do ruky ocelové kladivo, sedněte si do auta, nebo vyzkoušejte, jaké to je držet pirátský meč.</strong>',
				'en' => '<strong>In our museum you can do anything – pick up a steel hammer, sit in a car or find out what it feels like to hold a pirate sword.</strong>',
			),
			'fdc6f1f2' => array(
				'cs' => 'Obří robot s mečem a štítem je vyšší než dospělý člověk a vedle něj stojí další hrdinové jako z filmového plátna.<br><br>Výstava ocelových soch akčních hrdinů je místem, kde se mísí <strong>obdiv, humor a trocha surrealismu</strong>. Ať už jste fanoušek superhrdinů, nebo jen milovník umění, tahle výstava vám zaručeně vykouzlí úsměv na tváři.',
				'en' => 'The robot with a sword and shield is taller than an adult, surrounded by heroes straight off the big screen.<br><br>The exhibition of steel action heroes is a place where <strong>admiration, humour and a bit of surrealism</strong> mix. Whether you are a superhero fan or just an art lover, it is guaranteed to put a smile on your face.',
			),
			'ba4ffdc0' => array(
				'cs' => 'Sochy <strong>anglické královské gardy nebo slavných zpěváků</strong>, vyrobené z oceli, spojují historický majestát s ikonickým uměním moderní doby. Každá socha je zpracovaná s precizností, ať jde o slavnou uniformu gardistů, nebo typický taneční pohyb hvězdy na pódiu.',
				'en' => 'Steel statues of <strong>the English Royal Guard and famous singers</strong> combine historical grandeur with iconic modern art. Each one is crafted with precision, from the famous Guardsmen’s uniform to a star’s signature dance move on stage.',
			),
			'26e548fb' => array(
				'cs' => 'Ocelové sochy inspirované <strong>světem animovaných filmů a pohádek</strong> přinášejí do umění hravost a nostalgii.<br><br>Veselé žluté postavičky, dobrosrdeční obři, robotíci s velkýma očima plnýma zvědavosti a další – všechny jsou mistrně zpracované z oceli, která jim dává nový, nadčasový rozměr. Dokazují, že ocel může být stejně hravá a dojemná jako animovaný svět.',
				'en' => 'Steel sculptures inspired by <strong>the world of cartoons and fairy tales</strong> bring playfulness and nostalgia to art.<br><br>Funny yellow characters, good-natured giants, little robots with big curious eyes and more – all masterfully crafted in steel that gives them a new, timeless dimension. They prove that steel can be as playful and moving as the animated world.',
			),
			'474b80a2' => array(
				'cs' => 'Galerie Ocelových Figurín (GSF) je světovým unikátem díky sochám v životní velikosti, které jsou vyrobené z recyklovaného šrotu. Nápad na tento projekt vznikl v Polsku na šrotišti nedaleko Varšavy.<br><br>Zakladatelé projektu ho od roku 2011 rozšiřují po celém světě. Právě na šrotišti vznikla jejich první socha – konferenční stolek vyrobený z motoru V8.<br><br>Celý nápad vznikl jako reakce na tehdejší krizi na trhu se šrotem, kdy jeho cena velice klesla. Zakladatelé přemýšleli, jak velké množství šrotu využít, až je napadlo proměnit ho ve výtvarné umění v podobě ocelových figurín. Inspirací jim byly vzpomínky a sny v kombinaci s vášní pro auto-moto svět.',
				'en' => 'The Gallery of Steel Figures (GSF) is unique in the world for its life-size sculptures made from recycled scrap metal. The idea originated in Poland, at a scrapyard near Warsaw.<br><br>Since 2011 the founders of the project have been bringing it to cities around the world. Their very first piece was made right at the scrapyard: a coffee table built from a V8 engine.<br><br>The idea came about as a reaction to the crisis in the scrap market, when the price of scrap metal fell dramatically. The founders were looking for a way to use the huge amount of scrap and came up with turning it into art in the form of steel figures. Their inspiration came from memories and dreams combined with a passion for cars and motorbikes.',
			),
		);
	}

	public static function seo() {
		return array(
			42   => array(
				'Přes 120 soch v životní velikosti z recyklované oceli v centru Prahy. Na sochy se tu sahá: zvedněte Thorovo kladivo a vyfoťte se s hrdiny. Otevřeno denně 10–21 h, Celetná 15.',
				'Přes 120 soch v životní velikosti z recyklované oceli v centru Prahy. Na sochy se tu sahá: sedněte si do auta, zvedněte kladivo a vyfoťte se s hrdiny. Otevřeno denně 10–21 h, Celetná 15.',
			),
			1827 => array(
				'Over 120 life-size sculptures made from recycled steel in the centre of Prague. Touching allowed: lift Thor’s hammer and take photos with your heroes. Open daily 10 am–9 pm, Celetná 15.',
				'Over 120 life-size sculptures made from recycled steel in the centre of Prague. Touching allowed: sit in a car, lift the hammer and take photos with your heroes. Open daily 10 am–9 pm, Celetná 15.',
			),
		);
	}

	public static function run() {
		if ( get_option( self::OPTION ) ) {
			return;
		}
		global $wpdb;
		$log = array();
		foreach ( self::PAGES as $id => $lang ) {
			$content = get_post_field( 'post_content', $id, 'raw' );
			if ( ! $content ) {
				continue;
			}
			$new = $content;
			foreach ( self::blocks() as $uid => $texts ) {
				$new = preg_replace_callback(
					'#(<p class="gb-headline gb-headline-' . $uid . ' gb-headline-text">)(.*?)(</p>)#s',
					static function ( $m ) use ( $texts, $lang ) {
						return $m[1] . $texts[ $lang ] . $m[3];
					},
					$new
				);
			}
			if ( $new !== $content ) {
				update_post_meta( $id, '_sam_content_backup_20261121', wp_slash( $content ) );
				// Přímo do databáze, aby obsah nešel přes kses (běží i při návštěvě nepřihlášeného).
				$wpdb->update( $wpdb->posts, array( 'post_content' => $new ), array( 'ID' => $id ) );
				clean_post_cache( $id );
				$log[] = $id;
			}
		}
		foreach ( self::seo() as $id => $pair ) {
			$current = (string) get_post_meta( $id, 'rank_math_description', true );
			if ( '' === $current || $pair[0] === $current ) {
				update_post_meta( $id, 'rank_math_description', $pair[1] );
			}
		}
		update_option( self::OPTION, gmdate( 'c' ) . ' pages:' . implode( ',', $log ), false );
	}
}
SAM_Content_Migration_20261121::init();
