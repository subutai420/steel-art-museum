<?php
/**
 * Lehká cookie lišta se souhlasem (bez pluginu) a Google Consent Mode v2.
 *
 * - Volba je v cookie sam_consent (a = analytika, m = marketing, n = jen nezbytné), 180 dní.
 *   Vše běží v prohlížeči, takže funguje i se statickou cache stránek.
 * - Google Tag Manager se načítá vždy, ale s výchozím souhlasem „denied“ (Consent Mode v2);
 *   po volbě se pošle gtag('consent','update') a událost sam_consent_update do dataLayer.
 * - Pixel Facebooku (PixelYourSite) se spustí až po souhlasu s marketingem (skripty čekají
 *   jako type="text/plain").
 * - Atribuce objednávek WooCommerce (cookies sbjs_*) až po souhlasu s analytikou.
 * - Odkaz „Nastavení cookies“: libovolný prvek s atributem data-sam-consent-open.
 */

defined( 'ABSPATH' ) || exit;

final class SAM_Consent {
	const COOKIE = 'sam_consent';

	/** Skripty PixelYourSite, které čekají na souhlas s marketingem (v tomto pořadí). */
	const MARKETING_HANDLES = array( 'jquery-bind-first', 'js-cookie-pys', 'js-tld', 'pys' );

	public static function init() {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		add_action( 'wp_head', array( __CLASS__, 'consent_default' ), 0 );
		add_filter( 'script_loader_tag', array( __CLASS__, 'hold_script' ), 99, 2 );
		add_action( 'wp_footer', array( __CLASS__, 'banner' ), 50 );
		add_filter( 'wc_order_attribution_allow_tracking', '__return_false' );
	}

	public static function lang() {
		$lang = apply_filters( 'wpml_current_language', 'cs' );
		return 'en' === $lang ? 'en' : 'cs';
	}

	/** Výchozí stav souhlasu: musí být v hlavičce před Google Tag Managerem. */
	public static function consent_default() {
		?>
<script id="sam-consent-default">
window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}
(function(){var c=(document.cookie.match(/(?:^|; )sam_consent=([^;]*)/)||[])[1]||'',a=c.indexOf('a')>-1,m=c.indexOf('m')>-1,g='granted',d='denied';
gtag('consent','default',{ad_storage:m?g:d,ad_user_data:m?g:d,ad_personalization:m?g:d,analytics_storage:a?g:d,functionality_storage:g,security_storage:g,wait_for_update:500});
gtag('set','ads_data_redaction',!m);gtag('set','url_passthrough',true);})();
</script>
		<?php
	}

	public static function hold_script( $tag, $handle ) {
		if ( in_array( $handle, self::MARKETING_HANDLES, true ) ) {
			$tag = preg_replace( '/<script (?=[^>]*\bsrc=)/', '<script type="text/plain" data-sam-consent="m" ', $tag );
		}
		return $tag;
	}

	public static function banner() {
		$en      = 'en' === self::lang();
		$privacy = function_exists( 'sam_page_url' ) ? sam_page_url( 1148 ) : home_url( '/' );
		$t       = $en ? array(
			'title'   => 'Cookies',
			'text'    => 'We use cookies to measure traffic and to show you relevant ads. You can accept them, refuse them or choose which ones to allow.',
			'more'    => 'Privacy policy',
			'accept'  => 'Accept all',
			'reject'  => 'Necessary only',
			'set'     => 'Settings',
			'save'    => 'Save choice',
			'need'    => 'Necessary',
			'need_p'  => 'Cart, checkout and security. Always on.',
			'an'      => 'Analytics',
			'an_p'    => 'Anonymous traffic statistics (Google Analytics) and where orders come from.',
			'mk'      => 'Marketing',
			'mk_p'    => 'Ads on Facebook, Instagram and Google.',
		) : array(
			'title'   => 'Cookies',
			'text'    => 'Cookies používáme k měření návštěvnosti a k tomu, abychom vám ukazovali relevantní reklamu. Můžete je přijmout, odmítnout, nebo si vybrat.',
			'more'    => 'Ochrana osobních údajů',
			'accept'  => 'Přijmout vše',
			'reject'  => 'Jen nezbytné',
			'set'     => 'Nastavení',
			'save'    => 'Uložit výběr',
			'need'    => 'Nezbytné',
			'need_p'  => 'Košík, pokladna a zabezpečení. Vždy zapnuté.',
			'an'      => 'Analytika',
			'an_p'    => 'Anonymní statistiky návštěvnosti (Google Analytics) a odkud přicházejí objednávky.',
			'mk'      => 'Marketing',
			'mk_p'    => 'Reklama na Facebooku, Instagramu a Googlu.',
		);
		?>
<div class="sam-cc" id="sam-cc" role="dialog" aria-modal="false" aria-labelledby="sam-cc-h" hidden>
	<div class="sam-cc-in">
		<p class="sam-cc-h" id="sam-cc-h"><?php echo esc_html( $t['title'] ); ?></p>
		<p class="sam-cc-p"><?php echo esc_html( $t['text'] ); ?> <a href="<?php echo esc_url( $privacy ); ?>"><?php echo esc_html( $t['more'] ); ?></a></p>
		<div class="sam-cc-opts" id="sam-cc-opts" hidden>
			<label><input type="checkbox" checked disabled> <b><?php echo esc_html( $t['need'] ); ?></b> <span><?php echo esc_html( $t['need_p'] ); ?></span></label>
			<label><input type="checkbox" id="sam-cc-a"> <b><?php echo esc_html( $t['an'] ); ?></b> <span><?php echo esc_html( $t['an_p'] ); ?></span></label>
			<label><input type="checkbox" id="sam-cc-m"> <b><?php echo esc_html( $t['mk'] ); ?></b> <span><?php echo esc_html( $t['mk_p'] ); ?></span></label>
		</div>
		<div class="sam-cc-btns">
			<button type="button" class="sam-cc-b sam-cc-b--main" data-cc="am"><?php echo esc_html( $t['accept'] ); ?></button>
			<button type="button" class="sam-cc-b" data-cc="n"><?php echo esc_html( $t['reject'] ); ?></button>
			<button type="button" class="sam-cc-b sam-cc-b--link" id="sam-cc-set"><?php echo esc_html( $t['set'] ); ?></button>
			<button type="button" class="sam-cc-b" data-cc="save" id="sam-cc-save" hidden><?php echo esc_html( $t['save'] ); ?></button>
		</div>
	</div>
</div>
<style>
.sam-cc{position:fixed;left:16px;right:16px;bottom:16px;z-index:9999;display:flex;justify-content:center;pointer-events:none}
.sam-cc[hidden]{display:none}
.sam-cc-in{pointer-events:auto;max-width:640px;width:100%;background:#141414;color:#E8E8E8;border-radius:10px;padding:20px 22px;box-shadow:0 12px 40px rgba(0,0,0,.35);font:400 .95rem/1.5 Inter,system-ui,sans-serif}
.sam-cc-h{margin:0 0 6px;font:700 1.1rem/1.2 Barlow,system-ui,sans-serif;color:#fff}
.sam-cc-p{margin:0}
.sam-cc a{color:#fff}
.sam-cc-opts{display:grid;gap:10px;margin:14px 0 0}
.sam-cc-opts label{display:grid;grid-template-columns:auto 1fr;gap:2px 10px;align-items:start;cursor:pointer}
.sam-cc-opts input{margin:4px 0 0;width:18px;height:18px;accent-color:#F2B705}
.sam-cc-opts span{grid-column:2;color:#B8B8B8;font-size:.88rem}
.sam-cc-btns{display:flex;flex-wrap:wrap;gap:10px;margin:16px 0 0;align-items:center}
.sam-cc-b{min-height:44px;padding:10px 18px;border-radius:6px;border:2px solid #555;background:transparent;color:#fff;font:700 .95rem/1 Barlow,system-ui,sans-serif;cursor:pointer}
.sam-cc-b--main{background:#F2B705;border-color:#F2B705;color:#111}
.sam-cc-b--link{border-color:transparent;text-decoration:underline;padding-left:6px;padding-right:6px}
.sam-cc-b[hidden]{display:none}
@media (max-width:520px){.sam-cc{left:8px;right:8px;bottom:8px}.sam-cc-btns .sam-cc-b{flex:1 1 auto}}
</style>
<script id="sam-consent">
(function(){
var K='<?php echo esc_js( self::COOKIE ); ?>',box=document.getElementById('sam-cc');if(!box)return;
var get=function(){return(document.cookie.match(new RegExp('(?:^|; )'+K+'=([^;]*)'))||[])[1]||'';};
var opts=document.getElementById('sam-cc-opts'),save=document.getElementById('sam-cc-save'),ca=document.getElementById('sam-cc-a'),cm=document.getElementById('sam-cc-m');
var loaded=false;
function loadMarketing(){if(loaded)return;loaded=true;var list=[].slice.call(document.querySelectorAll('script[type="text/plain"][data-sam-consent="m"]'));
 (function next(){var s=list.shift();if(!s)return;var n=document.createElement('script');for(var i=0;i<s.attributes.length;i++){var at=s.attributes[i];if(at.name!=='type'&&at.name!=='data-sam-consent'&&at.name!=='defer')n.setAttribute(at.name,at.value);}
 if(n.src){n.async=false;n.onload=n.onerror=next;s.parentNode.replaceChild(n,s);}else{n.text=s.text;s.parentNode.replaceChild(n,s);next();}})();}
function apply(c,update){var a=c.indexOf('a')>-1,m=c.indexOf('m')>-1,g='granted',d='denied';
 if(update&&window.gtag){gtag('consent','update',{ad_storage:m?g:d,ad_user_data:m?g:d,ad_personalization:m?g:d,analytics_storage:a?g:d});gtag('set','ads_data_redaction',!m);}
 if(update&&window.dataLayer)dataLayer.push({event:'sam_consent_update',sam_analytics:a,sam_marketing:m});
 if(a){var tr=function(){if(window.wc_order_attribution&&wc_order_attribution.setOrderTracking)wc_order_attribution.setOrderTracking(true);};if(document.readyState==='complete')tr();else addEventListener('load',tr);}
 if(m)loadMarketing();}
function store(c){document.cookie=K+'='+c+';path=/;max-age='+(180*86400)+';SameSite=Lax'+(location.protocol==='https:'?';Secure':'');box.hidden=true;apply(c,true);}
function open(){var c=get();ca.checked=c.indexOf('a')>-1;cm.checked=c.indexOf('m')>-1;box.hidden=false;}
box.addEventListener('click',function(e){var b=e.target.closest('button');if(!b)return;
 if(b.id==='sam-cc-set'){opts.hidden=!opts.hidden;save.hidden=opts.hidden;return;}
 var v=b.getAttribute('data-cc');if(v==='save')v=(ca.checked?'a':'')+(cm.checked?'m':'')||'n';if(v)store(v);});
document.addEventListener('click',function(e){var o=e.target.closest('[data-sam-consent-open]');if(o){e.preventDefault();opts.hidden=false;save.hidden=false;open();}});
var c=get();if(c){apply(c,false);}else{box.hidden=false;}
})();
</script>
		<?php
	}
}

SAM_Consent::init();
