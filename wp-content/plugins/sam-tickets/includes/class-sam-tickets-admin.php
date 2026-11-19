<?php

defined( 'ABSPATH' ) || exit;

final class SAM_Tickets_Admin {
	private $repository;
	private $service;
	private $legacy;

	public function __construct( SAM_Tickets_Repository $repository, SAM_Tickets_Service $service, SAM_Tickets_Legacy $legacy ) {
		$this->repository = $repository;
		$this->service    = $service;
		$this->legacy     = $legacy;
	}

	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_menu', array( $this, 'restrict_cashier_menu' ), 999 );
		add_action( 'admin_init', array( $this, 'restrict_cashier_admin' ), 1 );
		add_action( 'admin_page_access_denied', array( $this, 'redirect_cashier_access_denied' ) );
		add_action( 'admin_bar_menu', array( $this, 'restrict_cashier_admin_bar' ), 999 );
		add_action( 'admin_post_sam_create_manual_ticket', array( $this, 'handle_manual_ticket' ) );
		add_action( 'admin_post_sam_tickets_save_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'admin_post_sam_tickets_send_report', array( $this, 'handle_send_report' ) );
		add_action( 'admin_post_sam_tickets_import_legacy', array( $this, 'handle_import_legacy' ) );
		add_action( 'admin_notices', array( $this, 'validity_notice' ) );
		add_filter( 'login_redirect', array( $this, 'cashier_login_redirect' ), 99, 3 );
		add_filter( 'woocommerce_login_redirect', array( $this, 'cashier_woocommerce_login_redirect' ), 99, 2 );
		add_filter( 'woocommerce_prevent_admin_access', array( $this, 'allow_cashier_admin_access' ), 99 );
		add_filter( 'show_admin_bar', array( $this, 'hide_cashier_frontend_admin_bar' ) );
	}

	public function cashier_login_redirect( $redirect_to, $requested_redirect_to, $user ) {
		if ( $this->is_cashier_user( $user ) ) {
			return $this->scanner_url();
		}
		return $redirect_to;
	}

	public function cashier_woocommerce_login_redirect( $redirect_to, $user ) {
		if ( $this->is_cashier_user( $user ) ) {
			return $this->scanner_url();
		}
		return $redirect_to;
	}

	public function allow_cashier_admin_access( $prevent_access ) {
		return $this->is_cashier_user() ? false : $prevent_access;
	}

	public function hide_cashier_frontend_admin_bar( $show ) {
		return $this->is_cashier_user() ? false : $show;
	}

	public function restrict_cashier_admin() {
		if ( ! $this->is_cashier_user() || wp_doing_ajax() ) {
			return;
		}

		global $pagenow;
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( 'admin.php' === $pagenow && 'sam-ticket-scanner' === $page ) {
			return;
		}
		if ( 'profile.php' === $pagenow ) {
			return; // pokladní si může změnit heslo
		}

		wp_safe_redirect( $this->scanner_url() );
		exit;
	}

	public function restrict_cashier_menu() {
		if ( ! $this->is_cashier_user() ) {
			return;
		}

		global $menu;
		foreach ( (array) $menu as $item ) {
			$slug = isset( $item[2] ) ? (string) $item[2] : '';
			if ( $slug && 'sam-ticket-scanner' !== $slug ) {
				remove_menu_page( $slug );
			}
		}
	}

	public function redirect_cashier_access_denied() {
		if ( $this->is_cashier_user() ) {
			wp_safe_redirect( $this->scanner_url() );
			exit;
		}
	}

	public function restrict_cashier_admin_bar( $wp_admin_bar ) {
		if ( ! $this->is_cashier_user() || ! is_object( $wp_admin_bar ) || ! is_callable( array( $wp_admin_bar, 'get_nodes' ) ) ) {
			return;
		}

		$allowed_nodes = array( 'my-account', 'user-actions', 'logout' );
		foreach ( (array) $wp_admin_bar->get_nodes() as $node ) {
			if ( ! in_array( $node->id, $allowed_nodes, true ) ) {
				$wp_admin_bar->remove_node( $node->id );
			}
		}
	}

	private function scanner_url() {
		return admin_url( 'admin.php?page=sam-ticket-scanner' );
	}

	private function is_cashier_user( $user = null ) {
		if ( is_wp_error( $user ) ) {
			return false;
		}
		if ( ! $user instanceof WP_User ) {
			$user = wp_get_current_user();
		}
		return $user instanceof WP_User && in_array( 'sam_cashier', (array) $user->roles, true );
	}

	public function register_menu() {
		if ( ! current_user_can( 'sam_manage_tickets' ) ) {
			add_menu_page( 'Skener vstupenek', 'Skener vstupenek', 'sam_scan_tickets', 'sam-ticket-scanner', array( $this, 'render_scanner_page' ), 'dashicons-camera', 56 );
			return;
		}

		add_menu_page( 'Vstupenky', 'Vstupenky', 'sam_manage_tickets', 'sam-tickets', array( $this, 'render_list_page' ), 'dashicons-tickets-alt', 56 );
		add_submenu_page( 'sam-tickets', 'Přehled vstupenek', 'Přehled', 'sam_manage_tickets', 'sam-tickets', array( $this, 'render_list_page' ) );
		add_submenu_page( 'sam-tickets', 'Vytvořit vstupenku', 'Vytvořit ručně', 'sam_manage_tickets', 'sam-tickets-create', array( $this, 'render_create_page' ) );
		add_submenu_page( 'sam-tickets', 'Skener vstupenek', 'Skener', 'sam_scan_tickets', 'sam-ticket-scanner', array( $this, 'render_scanner_page' ) );
		add_submenu_page( 'sam-tickets', 'Nastavení vstupenek', 'Nastavení a reporty', 'sam_manage_tickets', 'sam-tickets-settings', array( $this, 'render_settings_page' ) );
	}

	/** Upozornění, když je nastavená platnost v minulosti (nové vstupenky by byly hned propadlé). */
	public function validity_notice() {
		if ( ! current_user_can( 'sam_manage_tickets' ) || ! SAM_Tickets_Validity::configured_date_is_past() ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p><strong>Vstupenky:</strong> nastavená platnost (%s) už uplynula, nově prodané vstupenky by byly hned propadlé. <a href="%s">Nastavte nové datum.</a></p></div>',
			esc_html( SAM_Tickets_Validity::calculate_valid_until()->format( 'j. n. Y' ) ),
			esc_url( admin_url( 'admin.php?page=sam-tickets-settings' ) )
		);
	}

	public function render_scanner_page() {
		if ( ! current_user_can( 'sam_scan_tickets' ) ) {
			wp_die( esc_html__( 'K této stránce nemáte oprávnění.', 'sam-tickets' ) );
		}
		wp_enqueue_script( 'wp-api-fetch' );
		// Záložní čtečka QR pro prohlížeče bez BarcodeDetector (Safari a Chrome na iPhonu/iPadu, Firefox).
		wp_enqueue_script( 'sam-jsqr', plugins_url( 'assets/js/vendor/jsQR.min.js', SAM_TICKETS_FILE ), array(), '1.4.0', true );
		?>
		<?php if ( $this->is_cashier_user() ) : ?>
			<style>
				#wpadminbar,#wpfooter,#adminmenumain{display:none!important}html.wp-toolbar{padding-top:0!important}#wpcontent,#wpfooter{margin-left:0!important}
			</style>
		<?php endif; ?>
		<div class="wrap sam-ticket-scanner" data-rest-root="<?php echo esc_url( rest_url( 'sam-tickets/v1/' ) ); ?>" data-rest-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>">
			<div style="display:flex;align-items:center;justify-content:space-between;gap:16px;max-width:770px">
				<h1>Skener vstupenek</h1>
				<?php if ( $this->is_cashier_user() ) : ?>
					<a class="button" href="<?php echo esc_url( wp_logout_url( wp_login_url() ) ); ?>">Odhlásit se</a>
				<?php endif; ?>
			</div>
			<p>Naskenujte QR kód ze vstupenky (nové i ze starého systému), nebo kód vložte ručně.</p>
			<div style="max-width:720px;background:#fff;padding:24px;border:1px solid #dcdcde;border-radius:12px">
				<div style="display:flex;gap:8px;flex-wrap:wrap"><input type="text" id="sam-scanner-token" class="regular-text" autocomplete="off" autocapitalize="characters" placeholder="QR kód, odkaz nebo kód vstupenky" style="flex:1;min-width:220px;min-height:44px"><button type="button" class="button button-primary" id="sam-scanner-lookup" style="min-height:44px">Ověřit</button><button type="button" class="button" id="sam-scanner-camera" style="min-height:44px">Zapnout kameru</button></div>
				<video id="sam-scanner-video" playsinline muted hidden style="width:100%;max-height:420px;object-fit:cover;margin-top:16px;border-radius:10px;background:#111"></video>
				<div id="sam-scanner-message" role="status" style="margin-top:16px"></div>
				<div id="sam-scanner-result" style="margin-top:16px"></div>
			</div>
		</div>
		<?php $this->render_scanner_script(); ?>
		<?php
	}

	public function render_list_page() {
		$this->guard();

		$per_page = 50;
		$page     = max( 1, absint( isset( $_GET['paged'] ) ? $_GET['paged'] : 1 ) );
		$search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$status   = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$rows     = $this->repository->get_admin_rows( $per_page, ( $page - 1 ) * $per_page, $search, $status );
		$total    = $this->repository->count_tickets( $search, $status );
		$pages    = max( 1, (int) ceil( $total / $per_page ) );
		$counts   = $this->repository->count_by_status();
		$labels   = array( '' => 'Vše', 'valid' => 'Platné', 'partially_redeemed' => 'Částečně využité', 'redeemed' => 'Využité', 'expired' => 'Prošlé', 'void' => 'Zneplatněné' );
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline">Vstupenky</h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=sam-tickets-create' ) ); ?>" class="page-title-action">Vytvořit ručně</a>
			<hr class="wp-header-end">
			<?php if ( isset( $_GET['created'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>Vstupenka #<?php echo esc_html( absint( $_GET['created'] ) ); ?> byla vytvořena<?php echo ! empty( $_GET['emailed'] ) ? ' a odeslána zákazníkovi' : ''; ?>.</p></div>
			<?php endif; ?>
			<ul class="subsubsub">
				<?php
				$links = array();
				foreach ( $labels as $key => $label ) {
					$count   = '' === $key ? array_sum( $counts ) : ( $counts[ $key ] ?? 0 );
					$url     = add_query_arg( array( 'page' => 'sam-tickets', 'status' => $key ), admin_url( 'admin.php' ) );
					$links[] = '<li><a href="' . esc_url( $url ) . '"' . ( $key === $status ? ' class="current"' : '' ) . '>' . esc_html( $label ) . ' <span class="count">(' . esc_html( number_format_i18n( $count ) ) . ')</span></a>';
				}
				echo implode( ' |</li>', $links ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				?>
			</ul>
			<form method="get" style="float:right;margin:8px 0">
				<input type="hidden" name="page" value="sam-tickets">
				<input type="hidden" name="status" value="<?php echo esc_attr( $status ); ?>">
				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Objednávka, e-mail nebo kód">
				<button class="button">Hledat</button>
			</form>
			<table class="widefat striped" style="margin-top:16px;clear:both">
				<thead><tr><th>ID</th><th>Objednávka</th><th>E-mail</th><th>Jazyk / měna</th><th>Stav množství</th><th>Platnost do</th><th>Zdroj</th><th>Stav</th></tr></thead>
				<tbody>
				<?php if ( ! $rows ) : ?>
					<tr><td colspan="8"><?php echo $search || $status ? 'Nic nenalezeno.' : 'Zatím nebyla vytvořena žádná vstupenka.'; ?></td></tr>
				<?php else : foreach ( $rows as $row ) :
					$order          = $row->order_id ? wc_get_order( $row->order_id ) : null;
					$order_edit_url = $order instanceof WC_Order ? $order->get_edit_order_url() : '';
					$remaining      = max( 0, (int) $row->purchased_total - (int) $row->redeemed_total - (int) $row->refunded_total - (int) $row->refund_pending_total );
					?>
					<tr>
						<td><strong>#<?php echo esc_html( $row->id ); ?></strong><br><code><?php echo esc_html( substr( $row->token, 0, 10 ) ); ?>…</code></td>
						<td><?php echo $order_edit_url ? '<a href="' . esc_url( $order_edit_url ) . '">#' . esc_html( $row->order_id ) . '</a>' : '—'; ?></td>
						<td><?php echo esc_html( $row->customer_email ); ?></td>
						<td><strong><?php echo esc_html( strtoupper( $row->language ) ); ?></strong> / <?php echo esc_html( $row->currency ); ?></td>
						<td><strong>Zbývá <?php echo esc_html( $remaining ); ?> / <?php echo esc_html( (int) $row->purchased_total ); ?></strong><br><span class="description">Využito <?php echo esc_html( (int) $row->redeemed_total ); ?> · refundováno <?php echo esc_html( (int) $row->refunded_total ); ?><?php if ( (int) $row->refund_pending_total > 0 ) : ?> · čeká <?php echo esc_html( (int) $row->refund_pending_total ); ?><?php endif; ?></span></td>
						<td><?php echo esc_html( get_date_from_gmt( $row->valid_until, 'j. n. Y' ) ); ?></td>
						<td><?php echo esc_html( $this->source_label( $row->source ) ); ?></td>
						<td><?php echo wp_kses_post( $this->status_badge( $row->valid_until < current_time( 'mysql', true ) && in_array( $row->status, array( 'valid', 'partially_redeemed' ), true ) ? 'expired' : $row->status ) ); ?></td>
					</tr>
				<?php endforeach; endif; ?>
				</tbody>
			</table>
			<?php if ( $pages > 1 ) : ?>
				<div class="tablenav"><div class="tablenav-pages"><?php echo wp_kses_post( paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $page, 'total' => $pages ) ) ); ?></div></div>
			<?php endif; ?>
		</div>
		<?php
	}

	/** Produkty, které jde vystavit ručně: publikované vstupenky v češtině (CZK). */
	private function manual_products() {
		$products = wc_get_products( array( 'status' => 'publish', 'limit' => -1, 'orderby' => 'menu_order', 'order' => 'ASC' ) );
		$default  = apply_filters( 'wpml_default_language', null );
		return array_values(
			array_filter(
				$products,
				static function( $product ) use ( $default ) {
					if ( ! SAM_Tickets_Service::is_ticket_product( $product->get_id() ) ) {
						return false;
					}
					if ( ! $default ) {
						return true;
					}
					$details = apply_filters( 'wpml_post_language_details', null, $product->get_id() );
					return ! is_array( $details ) || empty( $details['language_code'] ) || $details['language_code'] === $default;
				}
			)
		);
	}

	public function render_create_page() {
		$this->guard();
		$products = $this->manual_products();
		?>
		<div class="wrap">
			<h1>Vytvořit vstupenku ručně</h1>
			<p>Bez online platby: pro převod, fakturu, kompenzaci, dárek nebo jiný výjimečný případ. Vytvoří se interní objednávka WooCommerce (zaplacená, bez platební brány) a běžná vstupenka s QR kódem. Faktura ve Fakturoidu se k ní nevystavuje.</p>
			<?php if ( isset( $_GET['error'] ) ) : ?><div class="notice notice-error"><p><?php echo esc_html( wp_unslash( $_GET['error'] ) ); ?></p></div><?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="sam_create_manual_ticket">
				<?php wp_nonce_field( 'sam_create_manual_ticket' ); ?>
				<table class="form-table" role="presentation">
					<tr><th><label for="sam-ticket-email">E-mail zákazníka</label></th><td><input type="email" class="regular-text" id="sam-ticket-email" name="customer_email" required></td></tr>
					<tr><th><label for="sam-ticket-language">Jazyk vstupenky a e-mailu</label></th><td><select id="sam-ticket-language" name="language"><option value="cs">Čeština</option><option value="en">English</option></select></td></tr>
					<tr><th><label for="sam-ticket-source">Důvod / zdroj</label></th><td><select id="sam-ticket-source" name="source"><option value="bank_transfer">Bankovní převod</option><option value="invoice">Faktura</option><option value="compensation">Kompenzace</option><option value="gift">Dárek / spolupráce</option><option value="manual">Jiné ruční vystavení</option></select></td></tr>
					<tr><th>Odeslat zákazníkovi</th><td><label><input type="checkbox" name="send_email" value="1" checked> Poslat e-mail se vstupenkou (QR + PDF)</label></td></tr>
					<tr><th><label for="sam-ticket-note">Interní poznámka</label></th><td><textarea class="large-text" rows="3" id="sam-ticket-note" name="note"></textarea></td></tr>
				</table>
				<h2>Obsah vstupenky</h2>
				<p class="description">Cena se do interní objednávky propíše podle ceníku (pro přehled), zákazník nic neplatí. Platnost: do <?php echo esc_html( SAM_Tickets_Validity::calculate_valid_until()->format( 'j. n. Y' ) ); ?>.</p>
				<table class="widefat striped" style="max-width:900px;margin-top:12px">
					<thead><tr><th>Vstupenka</th><th style="width:130px">Počet</th></tr></thead>
					<tbody>
					<?php foreach ( $products as $product ) : ?>
						<tr><td><?php echo esc_html( $product->get_name() ); ?> <span class="description">— <?php echo wp_kses_post( $product->get_price_html() ); ?></span></td><td><input type="number" min="0" max="100" step="1" value="0" name="quantities[<?php echo esc_attr( $product->get_id() ); ?>]" style="width:90px"></td></tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php submit_button( 'Vytvořit vstupenku' ); ?>
			</form>
		</div>
		<?php
	}

	public function render_settings_page() {
		$this->guard();
		$settings = SAM_Tickets_Settings::all();
		$counts   = $this->repository->count_by_status();
		$active   = ( $counts['valid'] ?? 0 ) + ( $counts['partially_redeemed'] ?? 0 );
		$legacy   = $this->legacy->status();
		?>
		<div class="wrap">
			<h1>Nastavení vstupenek a reporty</h1>
			<?php if ( isset( $_GET['saved'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Nastavení uloženo<?php echo isset( $_GET['extended'] ) ? esc_html( sprintf( '; platnost změněna u %d vstupenek', absint( $_GET['extended'] ) ) ) : ''; ?>.</p></div><?php endif; ?>
			<?php if ( isset( $_GET['report'] ) ) : ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html( wp_unslash( $_GET['report'] ) ); ?></p></div><?php endif; ?>
			<?php if ( isset( $_GET['imported'] ) ) : ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html( wp_unslash( $_GET['imported'] ) ); ?></p></div><?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="sam_tickets_save_settings">
				<?php wp_nonce_field( 'sam_tickets_save_settings' ); ?>
				<h2>Platnost</h2>
				<table class="form-table" role="presentation">
					<tr><th><label for="sam-valid-until">Vstupenky platí do</label></th><td>
						<input type="date" id="sam-valid-until" name="valid_until" value="<?php echo esc_attr( $settings['valid_until'] ); ?>" required>
						<p class="description">Datum se uloží ke každé nově prodané vstupence. Po tomto dni se vstupenky automaticky zneplatní a na e-mail níže přijde report.</p>
						<label style="display:block;margin-top:8px"><input type="checkbox" name="apply_to_active" value="1"> Změnit platnost i u všech dosud platných vstupenek (teď <?php echo esc_html( number_format_i18n( $active ) ); ?>)</label>
					</td></tr>
				</table>
				<h2>Reporty</h2>
				<table class="form-table" role="presentation">
					<tr><th><label for="sam-report-email">E-mail pro reporty</label></th><td>
						<input type="text" class="regular-text" id="sam-report-email" name="report_email" value="<?php echo esc_attr( $settings['report_email'] ); ?>" required>
						<p class="description">Víc adres oddělte čárkou. Chodí sem: 1. den v měsíci přehled všech neuplatněných vstupenek (pro účetní) a po konci platnosti přehled propadlých vstupenek.</p>
					</td></tr>
				</table>
				<?php submit_button( 'Uložit nastavení' ); ?>
			</form>

			<h2>Odeslat report hned</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
				<input type="hidden" name="action" value="sam_tickets_send_report">
				<?php wp_nonce_field( 'sam_tickets_send_report' ); ?>
				<button class="button" name="type" value="unredeemed">Poslat přehled neuplatněných vstupenek</button>
				<span class="description">Odejde na <?php echo esc_html( implode( ', ', SAM_Tickets_Settings::report_recipients() ) ); ?>. Poslední měsíční report: <?php echo esc_html( get_option( 'sam_tickets_last_monthly_report', '—' ) ); ?>.</span>
			</form>

			<h2>Vstupenky ze starého systému</h2>
			<p>Převezme ze starého pluginu (Event Tickets with Ticket Scanner) dosud platné vstupenky, aby je šlo načíst v novém skeneru. Import jde spustit opakovaně, už převedené objednávky se přeskočí.</p>
			<p><strong>Stav:</strong> převedeno <?php echo esc_html( number_format_i18n( $legacy['tickets'] ) ); ?> objednávek (<?php echo esc_html( number_format_i18n( $legacy['codes'] ) ); ?> starých kódů)<?php echo $legacy['available'] ? '' : ' · tabulka starého pluginu v databázi není'; ?>.</p>
			<?php if ( $legacy['available'] ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="sam_tickets_import_legacy">
				<?php wp_nonce_field( 'sam_tickets_import_legacy' ); ?>
				<button class="button">Spustit import</button>
			</form>
			<?php endif; ?>
		</div>
		<?php
	}

	public function handle_save_settings() {
		$this->guard();
		check_admin_referer( 'sam_tickets_save_settings' );

		$date  = sanitize_text_field( wp_unslash( $_POST['valid_until'] ?? '' ) );
		$email = sanitize_text_field( wp_unslash( $_POST['report_email'] ?? '' ) );
		if ( ! SAM_Tickets_Settings::sanitize_date( $date ) ) {
			wp_safe_redirect( add_query_arg( 'report', 'Neplatné datum platnosti.', admin_url( 'admin.php?page=sam-tickets-settings' ) ) );
			exit;
		}
		SAM_Tickets_Settings::update( array( 'valid_until' => $date, 'report_email' => $email ) );

		$args = array( 'saved' => 1 );
		if ( ! empty( $_POST['apply_to_active'] ) ) {
			$args['extended'] = $this->repository->set_valid_until_for_active( SAM_Tickets_Validity::valid_until_gmt( $date ), get_current_user_id() );
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php?page=sam-tickets-settings' ) ) );
		exit;
	}

	public function handle_send_report() {
		$this->guard();
		check_admin_referer( 'sam_tickets_send_report' );
		$result  = SAM_Tickets_Reports::send_unredeemed_report( $this->repository, true );
		$message = is_wp_error( $result ) ? 'Report se nepodařilo odeslat: ' . $result->get_error_message() : sprintf( 'Report odeslán (%d vstupenek).', (int) $result );
		wp_safe_redirect( add_query_arg( 'report', $message, admin_url( 'admin.php?page=sam-tickets-settings' ) ) );
		exit;
	}

	public function handle_import_legacy() {
		$this->guard();
		check_admin_referer( 'sam_tickets_import_legacy' );
		$result  = $this->legacy->import();
		$message = is_wp_error( $result ) ? 'Import selhal: ' . $result->get_error_message() : sprintf( 'Import dokončen: nově převedeno %d objednávek (%d kódů), přeskočeno %d už převedených.', $result['tickets'], $result['codes'], $result['skipped'] );
		wp_safe_redirect( add_query_arg( 'imported', $message, admin_url( 'admin.php?page=sam-tickets-settings' ) ) );
		exit;
	}

	private function render_scanner_script() {
		?>
		<style>
		.sam-ticket-scanner video[hidden]{display:none!important}.sam-ticket-result-head{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;padding:18px;border-radius:10px;background:#f6f7f7}.sam-ticket-status{display:inline-block;padding:5px 10px;border-radius:999px;background:#137333;color:#fff;font-weight:700}.sam-ticket-status[data-status="void"],.sam-ticket-status[data-status="expired"]{background:#b32d2e}.sam-ticket-status[data-status="redeemed"]{background:#50575e}.sam-ticket-status[data-status="partially_redeemed"]{background:#8a5a00}.sam-ticket-scanner-item{padding:14px 0;border-bottom:1px solid #dcdcde}.sam-ticket-item-head{display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap}.sam-ticket-visitors{list-style:none;margin:10px 0 0;padding:0}.sam-ticket-visitor{display:flex;justify-content:space-between;gap:12px;align-items:center;padding:8px 12px;margin:0 0 6px;border-radius:8px;background:#f6f7f7;font-size:15px}.sam-ticket-visitor .button{min-height:40px;min-width:120px}.sam-ticket-visitor[data-state="redeemed"],.sam-ticket-visitor[data-state="refunded"],.sam-ticket-visitor[data-state="pending"]{opacity:.65}.sam-ticket-visitor-state{font-weight:700}.sam-ticket-history{margin-top:20px;padding-top:16px;border-top:1px solid #dcdcde}.sam-scanner-error{padding:12px;background:#fcf0f1;border-left:4px solid #d63638}.sam-scanner-success{padding:12px;background:#edfaef;border-left:4px solid #00a32a}@media(max-width:600px){.sam-ticket-result-head{display:block}.sam-ticket-visitor .button{min-width:0;flex:0 0 auto}}
		</style>
		<script>
		(function () {
			const root = document.querySelector('.sam-ticket-scanner');
			if (!root) return;
			const input = document.getElementById('sam-scanner-token');
			const lookupButton = document.getElementById('sam-scanner-lookup');
			const cameraButton = document.getElementById('sam-scanner-camera');
			const video = document.getElementById('sam-scanner-video');
			const message = document.getElementById('sam-scanner-message');
			const result = document.getElementById('sam-scanner-result');
			let stream = null;
			let scanning = false;
			let lastTicket = null;
			let redeemOperationId = '';
			let redeeming = false;

			function escapeHtml(value) { const div = document.createElement('div'); div.textContent = value == null ? '' : String(value); return div.innerHTML; }
			function token(value) { return String(value || '').trim(); } // formát (nový i starý kód) rozpozná server
			function operationId() {
				if (window.crypto && typeof window.crypto.randomUUID === 'function') return window.crypto.randomUUID();
				const bytes = new Uint8Array(16); window.crypto.getRandomValues(bytes);
				return Array.from(bytes, b => b.toString(16).padStart(2, '0')).join('');
			}
			function setMessage(text, type) { message.className = type ? 'sam-scanner-' + type : ''; message.textContent = text || ''; }
			async function api(path, options) {
				const response = await fetch(root.dataset.restRoot + path, Object.assign({headers:{'X-WP-Nonce':root.dataset.restNonce,'Content-Type':'application/json'},credentials:'same-origin'}, options || {}));
				const data = await response.json();
				if (!response.ok) throw new Error(data.message || 'Požadavek se nepodařilo dokončit.');
				return data;
			}
			function statusLabel(status) { return ({valid:'Platná',partially_redeemed:'Částečně využitá',redeemed:'Využitá',void:'Zneplatněná',expired:'Prošlá'})[status] || status; }
			function ticketsWord(count) { return count >= 2 && count <= 4 ? 'vstupenky' : 'vstupenek'; }
			function visitorRows(item, canRedeem) {
				// Jeden řádek = jeden návštěvník (u rodinné vstupenky jedna rodina). Pořadí: uplatnění, refundovaní, čekající na refundaci, zbývající.
				const unit = item.unitType === 'family' ? 'Rodina' : 'Návštěvník';
				const rows = [];
				const states = [];
				for (let i = 0; i < item.redeemed; i++) states.push('redeemed');
				for (let i = 0; i < item.refunded; i++) states.push('refunded');
				for (let i = 0; i < item.refundPending; i++) states.push('pending');
				for (let i = 0; i < item.remaining; i++) states.push('remaining');
				states.forEach(function (state, index) {
					const name = unit + ' ' + (index + 1) + ' z ' + states.length;
					let right;
					if (state === 'remaining') right = canRedeem ? '<button type="button" class="button button-primary" data-redeem-item="' + item.id + '" data-redeem-quantity="1">Uplatnit</button>' : '<span class="sam-ticket-visitor-state">Neuplatněno</span>';
					else right = '<span class="sam-ticket-visitor-state">' + ({redeemed:'✓ Uplatněno',refunded:'Refundováno',pending:'Čeká na refundaci'})[state] + '</span>';
					rows.push('<li class="sam-ticket-visitor" data-state="' + state + '"><span>' + name + '</span>' + right + '</li>');
				});
				return rows.length ? '<ul class="sam-ticket-visitors">' + rows.join('') + '</ul>' : '';
			}
			function render(ticket) {
				lastTicket = ticket;
				redeemOperationId = operationId();
				const canRedeem = ticket.canRedeem === true;
				const items = ticket.items.map(function (item) {
					const pending = item.refundPending > 0 ? ' · čeká na refundaci ' + item.refundPending : '';
					const all = canRedeem && item.remaining > 1 ? '<button type="button" class="button" data-redeem-item="' + item.id + '" data-redeem-quantity="' + item.remaining + '">Uplatnit všech ' + item.remaining + '</button>' : '';
					return '<div class="sam-ticket-scanner-item"><div class="sam-ticket-item-head"><div><strong>' + escapeHtml(item.label) + '</strong><br><span>Zakoupeno ' + item.purchased + ' · využito ' + item.redeemed + ' · refundováno ' + item.refunded + pending + ' · zbývá ' + item.remaining + '</span></div>' + all + '</div>' + visitorRows(item, canRedeem) + '</div>';
				}).join('');
				const history = ticket.history.length ? '<div class="sam-ticket-history"><h3>Historie vstupenky</h3>' + ticket.history.map(function (entry) {
					const action = entry.type === 'refunded' ? 'Refundováno' : 'Uplatněno';
					const reference = entry.refundId ? ' · refundace ' + escapeHtml(entry.refundId) : '';
					return '<p><strong>' + action + ': ' + entry.quantity + '× ' + escapeHtml(entry.label) + '</strong><br>' + escapeHtml(entry.date) + ' · ' + escapeHtml(entry.user) + reference + '</p>';
				}).join('') + '</div>' : '';
				result.innerHTML = '<div class="sam-ticket-result-head"><div><h2 style="margin:0 0 6px">Vstupenka #' + ticket.id + '</h2><span>' + (ticket.source === 'legacy' ? 'Starý systém · ' : '') + 'Objednávka #' + ticket.orderId + '</span><br><span>Platnost do ' + escapeHtml(ticket.validUntil) + '</span></div><span class="sam-ticket-status" data-status="' + escapeHtml(ticket.status) + '">' + escapeHtml(statusLabel(ticket.status)) + '</span></div>' + items + history;
				result.querySelectorAll('button[data-redeem-item]').forEach(function (button) {
					button.addEventListener('click', function () { redeem(Number(button.dataset.redeemItem), Number(button.dataset.redeemQuantity)); });
				});
			}
			async function lookup() {
				const value = token(input.value);
				if (!value) { setMessage('Zadejte nebo naskenujte kód vstupenky.', 'error'); result.innerHTML = ''; return; }
				setMessage('Ověřuji vstupenku…'); lookupButton.disabled = true;
				try { const ticket = await api('lookup?token=' + encodeURIComponent(value)); render(ticket); setMessage('Vstupenka byla načtena.', 'success'); }
				catch (error) { result.innerHTML = ''; setMessage(error.message, 'error'); }
				finally { lookupButton.disabled = false; }
			}
			function setRedeemButtonsDisabled(disabled) { result.querySelectorAll('button[data-redeem-item]').forEach(function (button) { button.disabled = disabled; }); }
			async function redeem(itemId, quantity) {
				if (!itemId || !quantity || redeeming) return;
				// Jednoho návštěvníka uplatníme hned, hromadné uplatnění si necháme potvrdit.
				if (quantity > 1 && !window.confirm('Opravdu uplatnit ' + quantity + ' ' + ticketsWord(quantity) + ' najednou?')) return;
				redeeming = true;
				setRedeemButtonsDisabled(true);
				setMessage('Ukládám uplatnění…');
				try {
					const ticket = await api('redeem',{method:'POST',body:JSON.stringify({token:lastTicket.token,items:[{id:itemId,quantity:quantity}],operationId:redeemOperationId})});
					render(ticket);
					const left = ticket.items.reduce(function (sum, item) { return sum + item.remaining; }, 0);
					setMessage((quantity > 1 ? 'Uplatněno ' + quantity + ' ' + ticketsWord(quantity) + '.' : 'Návštěvník uplatněn.') + (left > 0 ? ' Na vstupence zbývá ' + left + '.' : ' Vstupenka je celá využitá.'), 'success');
				}
				catch (error) { await lookup(); setMessage(error.message, 'error'); }
				finally { redeeming = false; setRedeemButtonsDisabled(false); }
			}
			function stopCamera() { scanning=false; if(stream) stream.getTracks().forEach(track=>track.stop()); stream=null; video.hidden=true; cameraButton.textContent='Zapnout kameru'; }
			// Nativní BarcodeDetector (Chrome na Androidu), jinak jsQR nad snímky z videa (iPhone, iPad, Firefox).
			async function createReader() {
				if ('BarcodeDetector' in window) {
					try {
						const formats = typeof BarcodeDetector.getSupportedFormats === 'function' ? await BarcodeDetector.getSupportedFormats() : ['qr_code'];
						if (formats.indexOf('qr_code') !== -1) {
							const detector = new BarcodeDetector({formats:['qr_code']});
							return async function () { const codes = await detector.detect(video); return codes.length ? codes[0].rawValue : ''; };
						}
					} catch (e) {}
				}
				if (typeof window.jsQR === 'function') {
					const canvas = document.createElement('canvas');
					const context = canvas.getContext('2d', {willReadFrequently:true});
					return async function () {
						if (!video.videoWidth || !video.videoHeight) return '';
						const scale = Math.min(1, 720 / Math.max(video.videoWidth, video.videoHeight));
						canvas.width = Math.round(video.videoWidth * scale);
						canvas.height = Math.round(video.videoHeight * scale);
						context.drawImage(video, 0, 0, canvas.width, canvas.height);
						const frame = context.getImageData(0, 0, canvas.width, canvas.height);
						const code = window.jsQR(frame.data, frame.width, frame.height, {inversionAttempts:'dontInvert'});
						return code && code.data ? code.data : '';
					};
				}
				return null;
			}
			async function startCamera() {
				if (stream) { stopCamera(); return; }
				if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) { setMessage('Tento prohlížeč nepodporuje kameru. Kód můžete vložit ručně.', 'error'); return; }
				const read = await createReader();
				if (!read) { setMessage('Tento prohlížeč nepodporuje přímé čtení QR. Kód můžete vložit ručně.', 'error'); return; }
				try {
					stream=await navigator.mediaDevices.getUserMedia({video:{facingMode:{ideal:'environment'}},audio:false}); video.srcObject=stream; video.hidden=false; await video.play(); scanning=true; cameraButton.textContent='Vypnout kameru';
					async function scan(){ if(!scanning)return; try{const value=await read();if(value){input.value=value;stopCamera();await lookup();return;}}catch(e){} window.setTimeout(scan,250);} scan();
				} catch (error) { stopCamera(); setMessage('Kameru se nepodařilo otevřít. Zkontrolujte její oprávnění.', 'error'); }
			}
			lookupButton.addEventListener('click', lookup);
			input.addEventListener('keydown', event=>{if(event.key==='Enter'){event.preventDefault();lookup();}});
			cameraButton.addEventListener('click', startCamera);
			window.addEventListener('beforeunload', stopCamera);
		}());
		</script>
		<?php
	}

	public function handle_manual_ticket() {
		$this->guard();
		check_admin_referer( 'sam_create_manual_ticket' );

		$email      = sanitize_email( isset( $_POST['customer_email'] ) ? wp_unslash( $_POST['customer_email'] ) : '' );
		$source     = sanitize_key( isset( $_POST['source'] ) ? $_POST['source'] : 'manual' );
		$language   = 'en' === sanitize_key( $_POST['language'] ?? 'cs' ) ? 'en' : 'cs';
		$send_email = ! empty( $_POST['send_email'] );
		$note       = sanitize_textarea_field( isset( $_POST['note'] ) ? wp_unslash( $_POST['note'] ) : '' );
		$quantities = isset( $_POST['quantities'] ) && is_array( $_POST['quantities'] ) ? wp_unslash( $_POST['quantities'] ) : array();
		$allowed_sources = array( 'bank_transfer', 'invoice', 'compensation', 'gift', 'manual' );

		if ( ! is_email( $email ) ) {
			$this->redirect_error( 'Vyplňte platný e-mail zákazníka.' );
		}
		if ( ! in_array( $source, $allowed_sources, true ) ) {
			$source = 'manual';
		}

		$allowed_ids = array_map(
			static function( $product ) {
				return $product->get_id();
			},
			$this->manual_products()
		);
		$selected = array();
		foreach ( $quantities as $product_id => $quantity ) {
			$product_id = absint( $product_id );
			$quantity   = min( 100, absint( $quantity ) );
			if ( ! $product_id || ! $quantity || ! in_array( $product_id, $allowed_ids, true ) ) {
				continue;
			}
			$product = wc_get_product( $product_id );
			if ( $product ) {
				$selected[] = array( 'product' => $product, 'quantity' => $quantity );
			}
		}
		if ( ! $selected ) {
			$this->redirect_error( 'Vyberte alespoň jednu vstupenku.' );
		}

		// Ručně vystavená objednávka nesmí poslat e-mail dřív, než existuje vstupenka;
		// e-mail se vstupenkou odešleme až po jejím vytvoření (nebo vůbec).
		$suppress = static function() {
			return false;
		};
		add_filter( 'woocommerce_email_enabled_customer_processing_order', $suppress, 999 );
		add_filter( 'woocommerce_email_enabled_new_order', $suppress, 999 );

		try {
			$order = wc_create_order( array( 'created_via' => 'sam-manual-ticket' ) );
			foreach ( $selected as $selection ) {
				$order->add_product( $selection['product'], $selection['quantity'] );
			}
			$order->set_billing_email( $email );
			$order->set_currency( 'CZK' );
			$order->update_meta_data( '_sam_ticket_source', $source );
			$order->update_meta_data( '_sam_language', $language );
			$order->update_meta_data( '_sam_currency', 'CZK' );
			$order->update_meta_data( '_sam_ticket_created_by', get_current_user_id() );
			$order->update_meta_data( '_sam_no_invoice', 'yes' ); // sam-fakturoid ruční vstupenky nefakturuje
			if ( $note ) {
				$order->update_meta_data( '_sam_manual_ticket_note', $note );
			}
			$order->calculate_totals();
			$order->set_payment_method( 'sam_manual' );
			$order->set_payment_method_title( 'Ruční vystavení vstupenky' );
			$order->set_date_paid( time() );
			$order->set_status( 'processing' );
			$order->save();
			$order->add_order_note( 'Objednávka vytvořena ručně v administraci vstupenek (' . $this->source_label( $source ) . ').' . ( $note ? ' Poznámka: ' . $note : '' ) );

			$result = $this->service->generate_for_order( $order->get_id(), true );
			if ( is_wp_error( $result ) && 'sam_ticket_exists' === $result->get_error_code() ) {
				$data   = $result->get_error_data();
				$result = isset( $data['ticket_id'] ) ? absint( $data['ticket_id'] ) : 0;
			}
			if ( is_wp_error( $result ) || ! $result ) {
				throw new RuntimeException( is_wp_error( $result ) ? $result->get_error_message() : 'Vstupenku se nepodařilo vytvořit.' );
			}
		} catch ( Throwable $error ) {
			remove_filter( 'woocommerce_email_enabled_customer_processing_order', $suppress, 999 );
			remove_filter( 'woocommerce_email_enabled_new_order', $suppress, 999 );
			$this->redirect_error( $error->getMessage() );
		}

		remove_filter( 'woocommerce_email_enabled_customer_processing_order', $suppress, 999 );
		remove_filter( 'woocommerce_email_enabled_new_order', $suppress, 999 );

		$emailed = 0;
		if ( $send_email ) {
			$mailer = WC()->mailer();
			$emails = $mailer ? $mailer->get_emails() : array();
			if ( isset( $emails['WC_Email_Customer_Processing_Order'] ) ) {
				$emails['WC_Email_Customer_Processing_Order']->trigger( $order->get_id(), $order );
				$order->add_order_note( 'E-mail se vstupenkou odeslán na ' . $email . '.' );
				$emailed = 1;
			}
		}

		wp_safe_redirect( add_query_arg( array( 'created' => absint( $result ), 'emailed' => $emailed ), admin_url( 'admin.php?page=sam-tickets' ) ) );
		exit;
	}

	private function guard() {
		if ( ! current_user_can( 'sam_manage_tickets' ) ) {
			wp_die( esc_html__( 'K této stránce nemáte oprávnění.', 'sam-tickets' ) );
		}
	}

	private function redirect_error( $message ) {
		wp_safe_redirect( add_query_arg( 'error', rawurlencode( $message ), admin_url( 'admin.php?page=sam-tickets-create' ) ) );
		exit;
	}

	private function source_label( $source ) {
		$labels = array( 'woocommerce' => 'Online platba', 'bank_transfer' => 'Bankovní převod', 'invoice' => 'Faktura', 'compensation' => 'Kompenzace', 'gift' => 'Dárek / spolupráce', 'manual' => 'Ruční vystavení', 'legacy' => 'Starý systém' );
		return isset( $labels[ $source ] ) ? $labels[ $source ] : $source;
	}

	private function status_badge( $status ) {
		$labels = array( 'valid' => 'Platná', 'partially_redeemed' => 'Částečně využitá', 'redeemed' => 'Využitá', 'void' => 'Zneplatněná', 'expired' => 'Prošlá' );
		$colors = array( 'valid' => '#137333', 'partially_redeemed' => '#8a5a00', 'redeemed' => '#50575e', 'void' => '#b32d2e', 'expired' => '#b32d2e' );
		$label  = isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
		$color  = isset( $colors[ $status ] ) ? $colors[ $status ] : '#50575e';
		return '<span style="display:inline-block;padding:3px 8px;border-radius:999px;background:' . esc_attr( $color ) . ';color:#fff;font-size:12px;font-weight:600">' . esc_html( $label ) . '</span>';
	}
}
