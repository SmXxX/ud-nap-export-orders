<?php
/**
 * Admin UI: top-level menu, export pages with inline settings, global
 * settings page, asset enqueueing.
 *
 * @package UD_NAP_Orders_Exporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UD_NAP_Exporter_Admin {

	const PAGE_EXPORT_XML = 'ud-nap-export-xml';
	const PAGE_EXPORT_CSV = 'ud-nap-export-csv';
	const PAGE_SETTINGS   = 'ud-nap-settings';

	/** @var UD_NAP_Exporter_Settings */
	private $settings;

	public function __construct( UD_NAP_Exporter_Settings $settings ) {
		$this->settings = $settings;
	}

	public function hooks() {
		add_action( 'admin_menu', array( $this, 'register_menus' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'plugin_action_links_' . UD_NAP_EXPORTER_BASENAME, array( $this, 'plugin_action_links' ) );
	}

	/**
	 * Build the menu. Disabled export types are not registered, so they
	 * never appear in the sidebar. The first enabled export becomes the
	 * landing page for the top-level menu.
	 */
	public function register_menus() {
		$cap = 'manage_woocommerce';

		// Pick the landing page = first enabled item.
		if ( $this->settings->is_xml_enabled() ) {
			$first_slug  = self::PAGE_EXPORT_XML;
			$first_title = __( 'НАП XML Експорт', 'ud-nap-orders-exporter' );
			$first_cb    = array( $this, 'render_export_xml_page' );
		} elseif ( $this->settings->is_csv_enabled() ) {
			$first_slug  = self::PAGE_EXPORT_CSV;
			$first_title = __( 'Експорт на таблица', 'ud-nap-orders-exporter' );
			$first_cb    = array( $this, 'render_export_csv_page' );
		} else {
			$first_slug  = self::PAGE_SETTINGS;
			$first_title = __( 'Настройки', 'ud-nap-orders-exporter' );
			$first_cb    = array( $this, 'render_settings_page' );
		}

		add_menu_page(
			__( 'UD Експорти', 'ud-nap-orders-exporter' ),
			__( 'UD Експорти', 'ud-nap-orders-exporter' ),
			$cap,
			$first_slug,
			$first_cb,
			'dashicons-media-spreadsheet',
			58
		);

		// First submenu entry must reuse the parent slug so the menu label
		// matches the landing page (standard WP pattern).
		add_submenu_page( $first_slug, $first_title, $first_title, $cap, $first_slug, $first_cb );

		// Add the other export type if it's enabled and isn't already the landing.
		if ( $this->settings->is_xml_enabled() && self::PAGE_EXPORT_XML !== $first_slug ) {
			add_submenu_page(
				$first_slug,
				__( 'НАП XML Експорт', 'ud-nap-orders-exporter' ),
				__( 'НАП XML Експорт', 'ud-nap-orders-exporter' ),
				$cap,
				self::PAGE_EXPORT_XML,
				array( $this, 'render_export_xml_page' )
			);
		}
		if ( $this->settings->is_csv_enabled() && self::PAGE_EXPORT_CSV !== $first_slug ) {
			add_submenu_page(
				$first_slug,
				__( 'Експорт на таблица', 'ud-nap-orders-exporter' ),
				__( 'Експорт на таблица', 'ud-nap-orders-exporter' ),
				$cap,
				self::PAGE_EXPORT_CSV,
				array( $this, 'render_export_csv_page' )
			);
		}

		// Settings — always last (unless it's already the landing).
		if ( self::PAGE_SETTINGS !== $first_slug ) {
			add_submenu_page(
				$first_slug,
				__( 'Настройки', 'ud-nap-orders-exporter' ),
				__( 'Настройки', 'ud-nap-orders-exporter' ),
				$cap,
				self::PAGE_SETTINGS,
				array( $this, 'render_settings_page' )
			);
		}
	}

	public function plugin_action_links( $links ) {
		$links[] = '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE_SETTINGS ) ) . '">' . esc_html__( 'Настройки', 'ud-nap-orders-exporter' ) . '</a>';
		return $links;
	}

	public function enqueue_assets( $hook ) {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( ! in_array( $page, array( self::PAGE_EXPORT_XML, self::PAGE_EXPORT_CSV, self::PAGE_SETTINGS ), true ) ) {
			return;
		}

		wp_enqueue_style(
			'ud-nap-exporter-admin',
			UD_NAP_EXPORTER_URL . 'assets/css/admin.css',
			array(),
			UD_NAP_EXPORTER_VERSION
		);

		wp_enqueue_script(
			'ud-nap-exporter-admin',
			UD_NAP_EXPORTER_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			UD_NAP_EXPORTER_VERSION,
			true
		);

		wp_localize_script(
			'ud-nap-exporter-admin',
			'udNapExporter',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'ud_nap_exporter' ),
				'i18n'    => array(
					'starting'   => __( 'Стартиране на експорт…', 'ud-nap-orders-exporter' ),
					'processing' => __( 'Обработени %1$s от %2$s поръчки…', 'ud-nap-orders-exporter' ),
					'done'       => __( 'Експортът е завършен.', 'ud-nap-orders-exporter' ),
					'download'   => __( 'Изтегли файла', 'ud-nap-orders-exporter' ),
					'failed'     => __( 'Експортът се провали: %s', 'ud-nap-orders-exporter' ),
					'saving'     => __( 'Запазване…', 'ud-nap-orders-exporter' ),
					'saved'      => __( '✓ Запазено', 'ud-nap-orders-exporter' ),
					'saveFailed' => __( 'Грешка при запазване', 'ud-nap-orders-exporter' ),
				),
			)
		);
	}

	// ---- Pages --------------------------------------------------------------

	public function render_export_xml_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$s   = $this->settings->get_all();
		$opt = UD_NAP_Exporter_Settings::OPTION_KEY;
		?>
		<div class="wrap ud-nap-wrap">
			<h1><?php esc_html_e( 'НАП XML Експорт', 'ud-nap-orders-exporter' ); ?></h1>
			<p><?php esc_html_e( 'Генериране на SAF-T XML файл с поръчки от WooCommerce за подаване към НАП.', 'ud-nap-orders-exporter' ); ?></p>

			<?php if ( empty( $s['shop_unique_id'] ) || empty( $s['company_eik'] ) ) : ?>
				<div class="notice notice-warning inline">
					<p><?php esc_html_e( 'Моля, попълнете данните за фирмата и Уникалния идентификатор на магазина по-долу преди да стартирате експорт.', 'ud-nap-orders-exporter' ); ?></p>
				</div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Стартиране на експорт', 'ud-nap-orders-exporter' ); ?></h2>
			<?php
			$this->render_export_form_block(
				array(
					'action_start' => 'ud_nap_start',
					'action_step'  => 'ud_nap_step',
					'button_label' => __( 'Генерирай XML', 'ud-nap-orders-exporter' ),
				)
			);
			?>

			<hr />

			<h2><?php esc_html_e( 'Настройки за XML експорт', 'ud-nap-orders-exporter' ); ?></h2>
			<form method="post" action="options.php">
				<?php settings_fields( 'ud_nap_exporter_settings_group' ); ?>
				<input type="hidden" name="<?php echo esc_attr( $opt ); ?>[_section]" value="xml" />

				<h3><?php esc_html_e( 'Данни за фирмата', 'ud-nap-orders-exporter' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Използват се в header-а на audit XML файла и в касовия бон.', 'ud-nap-orders-exporter' ); ?></p>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label><?php esc_html_e( 'Уникален идентификатор на магазина (e_shop_n)', 'ud-nap-orders-exporter' ); ?></label></th>
							<td><input type="text" class="regular-text" name="<?php echo esc_attr( $opt ); ?>[shop_unique_id]" value="<?php echo esc_attr( $s['shop_unique_id'] ); ?>" placeholder="RF0000000" />
								<p class="description"><?php esc_html_e( 'Регистрационен номер, издаден от НАП за Вашия онлайн магазин (RF…).', 'ud-nap-orders-exporter' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label><?php esc_html_e( 'Вид електронен магазин (e_shop_type)', 'ud-nap-orders-exporter' ); ?></label></th>
							<td>
								<select name="<?php echo esc_attr( $opt ); ?>[e_shop_type]">
									<?php foreach ( UD_NAP_Exporter_Settings::e_shop_types() as $code => $label ) : ?>
										<option value="<?php echo esc_attr( $code ); ?>" <?php selected( (string) $s['e_shop_type'], (string) $code ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label><?php esc_html_e( 'Домейн', 'ud-nap-orders-exporter' ); ?></label></th>
							<td><input type="url" class="regular-text" name="<?php echo esc_attr( $opt ); ?>[domain_name]" value="<?php echo esc_attr( $s['domain_name'] ); ?>" placeholder="https://example.com/" />
								<p class="description"><?php esc_html_e( 'Пълен URL на магазина (със схема). Ако е празно, се използва адресът на сайта.', 'ud-nap-orders-exporter' ); ?></p>
							</td>
						</tr>
						<?php
						$fields = array(
							'company_name'    => __( 'Име на фирмата', 'ud-nap-orders-exporter' ),
							'company_eik'     => __( 'ЕИК / Булстат', 'ud-nap-orders-exporter' ),
							'company_vat'     => __( 'ДДС номер', 'ud-nap-orders-exporter' ),
							'company_address' => __( 'Адрес', 'ud-nap-orders-exporter' ),
							'company_city'    => __( 'Град', 'ud-nap-orders-exporter' ),
							'company_country' => __( 'Код на държава', 'ud-nap-orders-exporter' ),
							'company_email'   => __( 'Имейл', 'ud-nap-orders-exporter' ),
						);
						foreach ( $fields as $key => $label ) :
							?>
							<tr>
								<th scope="row"><label><?php echo esc_html( $label ); ?></label></th>
								<td><input type="<?php echo 'company_email' === $key ? 'email' : 'text'; ?>" class="regular-text" name="<?php echo esc_attr( $opt ); ?>[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $s[ $key ] ); ?>" /></td>
							</tr>
						<?php endforeach; ?>
						<tr>
							<th scope="row"><label><?php esc_html_e( 'Начало на номерацията на документи (doc_n)', 'ud-nap-orders-exporter' ); ?></label></th>
							<td><input type="number" min="1" step="1" class="regular-text" name="<?php echo esc_attr( $opt ); ?>[doc_n_start]" value="<?php echo esc_attr( $s['doc_n_start'] ); ?>" />
								<p class="description"><?php esc_html_e( 'Броячът започва от тази стойност при първия издаден документ. След това се увеличава автоматично и никога не се използва повторно.', 'ud-nap-orders-exporter' ); ?></p>
							</td>
						</tr>
					</tbody>
				</table>

				<h3><?php esc_html_e( 'Съпоставяне на методите на плащане с кодове по НАП', 'ud-nap-orders-exporter' ); ?></h3>
				<p class="description"><?php esc_html_e( 'За всеки активен или използван метод на плащане изберете кода, който да се записва в <paym>. Неизбраните методи се отчитат с код 8 — Други.', 'ud-nap-orders-exporter' ); ?></p>
				<table class="form-table ud-nap-paym-map" role="presentation">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Метод на плащане', 'ud-nap-orders-exporter' ); ?></th>
							<th><?php esc_html_e( 'НАП код (paym)', 'ud-nap-orders-exporter' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						$gateways_all = array();
						if ( function_exists( 'WC' ) && WC()->payment_gateways() ) {
							foreach ( WC()->payment_gateways()->payment_gateways() as $gid => $gw ) {
								$label             = method_exists( $gw, 'get_method_title' ) ? $gw->get_method_title() : $gid;
								$gateways_all[ $gid ] = $label ?: $gid;
							}
						}
						foreach ( $this->settings->get_known_payment_gateway_ids() as $gid ) {
							if ( ! isset( $gateways_all[ $gid ] ) ) {
								$gateways_all[ $gid ] = $gid;
							}
						}
						ksort( $gateways_all );
						$map = (array) $s['payment_map'];
						if ( empty( $gateways_all ) ) :
							?>
							<tr><td colspan="2"><em><?php esc_html_e( 'Няма открити методи на плащане.', 'ud-nap-orders-exporter' ); ?></em></td></tr>
							<?php
						else :
							foreach ( $gateways_all as $gid => $gw_label ) :
								$current = isset( $map[ $gid ] ) ? (string) $map[ $gid ] : '';
								?>
								<tr>
									<th scope="row">
										<label><?php echo esc_html( $gw_label ); ?></label>
										<br /><code style="font-weight:normal;"><?php echo esc_html( $gid ); ?></code>
									</th>
									<td>
										<select name="<?php echo esc_attr( $opt ); ?>[payment_map][<?php echo esc_attr( $gid ); ?>]">
											<option value=""><?php esc_html_e( '— Не е зададено (→ 8 Други) —', 'ud-nap-orders-exporter' ); ?></option>
											<?php foreach ( UD_NAP_Exporter_Settings::paym_codes() as $code => $code_label ) : ?>
												<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $current, (string) $code ); ?>><?php echo esc_html( $code_label ); ?></option>
											<?php endforeach; ?>
										</select>
									</td>
								</tr>
							<?php
							endforeach;
						endif;
						?>
					</tbody>
				</table>

				<h3><?php esc_html_e( 'Свързване на полета', 'ud-nap-orders-exporter' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Свържете meta ключовете на поръчките в WooCommerce с XML таговете, които ги изискват.', 'ud-nap-orders-exporter' ); ?></p>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label><?php esc_html_e( 'Meta ключ за ID на транзакция', 'ud-nap-orders-exporter' ); ?></label></th>
							<td><input type="text" class="regular-text" name="<?php echo esc_attr( $opt ); ?>[meta_transaction_id]" value="<?php echo esc_attr( $s['meta_transaction_id'] ); ?>" />
								<p class="description"><?php esc_html_e( 'По подразбиране е _transaction_id. Променете го, ако Вашият платежен метод съхранява транзакцията другаде.', 'ud-nap-orders-exporter' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label><?php esc_html_e( 'Meta ключ за платежен оператор', 'ud-nap-orders-exporter' ); ?></label></th>
							<td><input type="text" class="regular-text" name="<?php echo esc_attr( $opt ); ?>[meta_payment_provider]" value="<?php echo esc_attr( $s['meta_payment_provider'] ); ?>" />
								<p class="description"><?php esc_html_e( 'Незадължително. Ако е празно, се използва името на платежния метод от WooCommerce.', 'ud-nap-orders-exporter' ); ?></p>
							</td>
						</tr>
					</tbody>
				</table>

				<?php submit_button( __( 'Запази настройките за XML', 'ud-nap-orders-exporter' ) ); ?>
			</form>
		</div>
		<?php
	}

	public function render_export_csv_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$s            = $this->settings->get_all();
		$opt          = UD_NAP_Exporter_Settings::OPTION_KEY;
		$std_cols     = UD_NAP_Exporter_Settings::standard_csv_columns();
		$discovered   = $this->settings->discover_order_meta_keys();
		$gateways     = $this->get_available_payment_gateways();
		$cod_selected = (array) ( isset( $s['csv_cod_methods'] ) ? $s['csv_cod_methods'] : array() );
		?>
		<div class="wrap ud-nap-wrap">
			<h1><?php esc_html_e( 'Експорт на таблица', 'ud-nap-orders-exporter' ); ?></h1>
			<p><?php esc_html_e( 'Експортиране на поръчки от WooCommerce като Excel файл (.xlsx). Отваря се директно в Excel и Numbers. От секцията по-долу можете да изберете кои колони да бъдат включени.', 'ud-nap-orders-exporter' ); ?></p>

			<h2><?php esc_html_e( 'Стартиране на експорт', 'ud-nap-orders-exporter' ); ?></h2>
			<?php
			$this->render_export_form_block(
				array(
					'action_start'     => 'ud_nap_csv_start',
					'action_step'      => 'ud_nap_csv_step',
					'button_label'     => __( 'Генерирай таблица', 'ud-nap-orders-exporter' ),
					'show_report_type' => true,
				)
			);
			?>

			<hr />

			<h2><?php esc_html_e( 'Настройки за експорт на таблица', 'ud-nap-orders-exporter' ); ?></h2>
			<form method="post" action="options.php">
				<?php settings_fields( 'ud_nap_exporter_settings_group' ); ?>
				<input type="hidden" name="<?php echo esc_attr( $opt ); ?>[_section]" value="csv" />

				<h3><?php esc_html_e( 'Колони', 'ud-nap-orders-exporter' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Изберете кои стандартни полета на поръчките да се включат в Excel файла. Редът на колоните е фиксиран.', 'ud-nap-orders-exporter' ); ?></p>
				<p>
					<button type="button" class="button" id="ud-nap-cols-all"><?php esc_html_e( 'Избери всички', 'ud-nap-orders-exporter' ); ?></button>
					<button type="button" class="button" id="ud-nap-cols-none"><?php esc_html_e( 'Изчисти избора', 'ud-nap-orders-exporter' ); ?></button>
					<span class="ud-nap-columns-saved" aria-live="polite"></span>
				</p>
				<div class="ud-nap-columns-grid">
					<?php foreach ( $std_cols as $col_key => $col_label ) :
						$checked = in_array( $col_key, (array) $s['csv_columns'], true );
						?>
						<label class="ud-nap-column">
							<input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[csv_columns][]" value="<?php echo esc_attr( $col_key ); ?>" <?php checked( $checked ); ?> />
							<span class="ud-nap-column-label"><?php echo esc_html( $col_label ); ?></span>
						</label>
					<?php endforeach; ?>
				</div>

				<h3><?php esc_html_e( 'Допълнителни meta ключове', 'ud-nap-orders-exporter' ); ?></h3>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label><?php esc_html_e( 'Допълнителни meta ключове', 'ud-nap-orders-exporter' ); ?></label></th>
							<td>
								<textarea name="<?php echo esc_attr( $opt ); ?>[csv_extra_meta_keys]" rows="6" cols="50" class="large-text code" placeholder="_billing_vat_number&#10;_my_custom_meta"><?php echo esc_textarea( $s['csv_extra_meta_keys'] ); ?></textarea>
								<p class="description">
									<?php esc_html_e( 'Един meta ключ на ред (или разделени със запетая). Добавят се след стандартните колони.', 'ud-nap-orders-exporter' ); ?>
								</p>
								<?php if ( ! empty( $discovered ) ) : ?>
									<details>
										<summary><?php esc_html_e( 'Намерени meta ключове от съществуващи поръчки (натиснете за разгъване)', 'ud-nap-orders-exporter' ); ?></summary>
										<p class="description"><?php esc_html_e( 'Копирайте някой от тях в полето по-горе:', 'ud-nap-orders-exporter' ); ?></p>
										<ul class="ud-nap-discovered">
											<?php foreach ( $discovered as $mk ) : ?>
												<li><code><?php echo esc_html( $mk ); ?></code></li>
											<?php endforeach; ?>
										</ul>
									</details>
								<?php endif; ?>
							</td>
						</tr>
					</tbody>
				</table>

				<h3><?php esc_html_e( 'Методи на плащане за „Наложен платеж“', 'ud-nap-orders-exporter' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Изберете кои методи на плащане да се отчитат като „Наложен платеж“ в обобщението в края на Excel файла. Всички останали методи се отчитат като „Платени с карта“.', 'ud-nap-orders-exporter' ); ?></p>
				<?php if ( ! empty( $gateways ) ) : ?>
					<div class="ud-nap-payment-methods">
						<?php foreach ( $gateways as $gateway_id => $gateway_label ) :
							$cod_checked = in_array( (string) $gateway_id, array_map( 'strval', $cod_selected ), true );
							?>
							<label class="ud-nap-payment-method-label">
								<input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[csv_cod_methods][]" value="<?php echo esc_attr( $gateway_id ); ?>" <?php checked( $cod_checked ); ?> />
								<?php echo esc_html( $gateway_label ); ?>
								<span class="ud-nap-payment-method-id"><?php echo esc_html( $gateway_id ); ?></span>
							</label>
						<?php endforeach; ?>
					</div>
				<?php else : ?>
					<p><em><?php esc_html_e( 'Не са открити методи на плащане.', 'ud-nap-orders-exporter' ); ?></em></p>
				<?php endif; ?>

				<?php submit_button( __( 'Запази настройките за таблицата', 'ud-nap-orders-exporter' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Build a list of WooCommerce payment gateways available for filtering.
	 * Includes enabled gateways plus any gateway IDs that already appear on
	 * existing orders (so historical methods that have since been disabled
	 * can still be filtered out / in).
	 *
	 * @return array<string,string>  gateway_id => admin label
	 */
	private function get_available_payment_gateways() {
		$out = array();

		if ( function_exists( 'WC' ) && WC()->payment_gateways() ) {
			$gateways = WC()->payment_gateways()->payment_gateways();
			foreach ( $gateways as $id => $gw ) {
				if ( isset( $gw->enabled ) && 'yes' !== $gw->enabled ) {
					continue;
				}
				$label    = method_exists( $gw, 'get_method_title' ) ? $gw->get_method_title() : '';
				if ( '' === $label && method_exists( $gw, 'get_title' ) ) {
					$label = $gw->get_title();
				}
				$out[ $id ] = '' !== $label ? $label : $id;
			}
		}

		// Pick up any historical gateway IDs that are no longer enabled but
		// still exist on past orders, so the admin can filter them as well.
		global $wpdb;
		$historical = $wpdb->get_col(
			"SELECT DISTINCT pm.meta_value
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE pm.meta_key = '_payment_method'
			   AND p.post_type = 'shop_order'
			 ORDER BY pm.meta_value ASC
			 LIMIT 50"
		);
		if ( is_array( $historical ) ) {
			foreach ( $historical as $gid ) {
				$gid = (string) $gid;
				if ( '' === $gid || isset( $out[ $gid ] ) ) {
					continue;
				}
				$out[ $gid ] = $gid; // Fall back to the slug as label.
			}
		}

		ksort( $out );
		return $out;
	}

	/**
	 * Renders the AJAX export form (date range + refunds + optional payment
	 * filter + run button + progress + result). Used by both export pages.
	 */
	private function render_export_form_block( array $args ) {
		$s                = $this->settings->get_all();
		$payment_methods  = isset( $args['payment_methods'] ) && is_array( $args['payment_methods'] ) ? $args['payment_methods'] : array();
		$show_report_type = ! empty( $args['show_report_type'] );
		?>
		<form
			class="ud-nap-export-form"
			data-action-start="<?php echo esc_attr( $args['action_start'] ); ?>"
			data-action-step="<?php echo esc_attr( $args['action_step'] ); ?>"
			onsubmit="return false;"
		>
			<table class="form-table" role="presentation">
				<tbody>
					<?php if ( $show_report_type ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Тип отчет', 'ud-nap-orders-exporter' ); ?></th>
							<td>
								<label style="display:block;margin-bottom:4px;">
									<input type="radio" class="ud-nap-report-type" name="ud_nap_report_type" value="all" checked />
									<?php esc_html_e( 'Всички поръчки (с обобщение по карта и наложен платеж)', 'ud-nap-orders-exporter' ); ?>
								</label>
								<label style="display:block;margin-bottom:4px;">
									<input type="radio" class="ud-nap-report-type" name="ud_nap_report_type" value="card" />
									<?php esc_html_e( 'Само платени с карта', 'ud-nap-orders-exporter' ); ?>
								</label>
								<label style="display:block;">
									<input type="radio" class="ud-nap-report-type" name="ud_nap_report_type" value="cod" />
									<?php esc_html_e( 'Само наложен платеж', 'ud-nap-orders-exporter' ); ?>
								</label>
								<p class="description"><?php esc_html_e( 'Кои методи се броят за „Наложен платеж“ се настройва в секция „Настройки за експорт на таблица → Методи на плащане за „Наложен платеж““ по-долу.', 'ud-nap-orders-exporter' ); ?></p>
							</td>
						</tr>
					<?php endif; ?>
					<tr>
						<th scope="row"><label><?php esc_html_e( 'Начална дата', 'ud-nap-orders-exporter' ); ?></label></th>
						<td><input type="date" class="ud-nap-date-from" required /></td>
					</tr>
					<tr>
						<th scope="row"><label><?php esc_html_e( 'Крайна дата', 'ud-nap-orders-exporter' ); ?></label></th>
						<td><input type="date" class="ud-nap-date-to" required /></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Включи възстановявания', 'ud-nap-orders-exporter' ); ?></th>
						<td>
							<label>
								<input type="checkbox" class="ud-nap-include-refunds" value="1" <?php checked( 'yes', $s['include_refunds'] ); ?> />
								<?php esc_html_e( 'Включи възстановените поръчки в експорта', 'ud-nap-orders-exporter' ); ?>
							</label>
						</td>
					</tr>
					<?php if ( ! empty( $payment_methods ) ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Методи на плащане', 'ud-nap-orders-exporter' ); ?></th>
							<td>
								<p>
									<button type="button" class="button button-small ud-nap-pm-all"><?php esc_html_e( 'Избери всички', 'ud-nap-orders-exporter' ); ?></button>
									<button type="button" class="button button-small ud-nap-pm-none"><?php esc_html_e( 'Изчисти избора', 'ud-nap-orders-exporter' ); ?></button>
								</p>
								<div class="ud-nap-payment-methods">
									<?php foreach ( $payment_methods as $gateway_id => $gateway_label ) : ?>
										<label class="ud-nap-payment-method-label">
											<input type="checkbox" class="ud-nap-payment-method" value="<?php echo esc_attr( $gateway_id ); ?>" checked />
											<?php echo esc_html( $gateway_label ); ?>
											<span class="ud-nap-payment-method-id"><?php echo esc_html( $gateway_id ); ?></span>
										</label>
									<?php endforeach; ?>
								</div>
								<p class="description"><?php esc_html_e( 'Само поръчки с избраните методи на плащане ще бъдат експортирани. Ако не изберете нито един метод, ще бъдат включени всички.', 'ud-nap-orders-exporter' ); ?></p>
							</td>
						</tr>
					<?php endif; ?>
				</tbody>
			</table>

			<p>
				<button type="button" class="button button-primary ud-nap-export-start">
					<?php echo esc_html( $args['button_label'] ); ?>
				</button>
			</p>

			<div class="ud-nap-progress" style="display:none;">
				<p class="ud-nap-status"></p>
				<progress value="0" max="100" style="width:100%;max-width:500px;"></progress>
			</div>

			<div class="ud-nap-result" style="display:none;"></div>
		</form>
		<?php
	}

	/**
	 * Global settings page: only document-type toggles + shared export
	 * behaviour. Type-specific settings live on each export page.
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$s            = $this->settings->get_all();
		$statuses_all = wc_get_order_statuses();
		$opt          = UD_NAP_Exporter_Settings::OPTION_KEY;
		?>
		<div class="wrap ud-nap-wrap">
			<h1><?php esc_html_e( 'UD Експорти — Настройки', 'ud-nap-orders-exporter' ); ?></h1>

			<form method="post" action="options.php">
				<?php settings_fields( 'ud_nap_exporter_settings_group' ); ?>
				<input type="hidden" name="<?php echo esc_attr( $opt ); ?>[_section]" value="global" />

				<h2><?php esc_html_e( 'Видове документи', 'ud-nap-orders-exporter' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Изберете кои формати за експорт да са активни. Изключените формати се премахват от менюто.', 'ud-nap-orders-exporter' ); ?></p>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><?php esc_html_e( 'НАП XML (SAF-T)', 'ud-nap-orders-exporter' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[enable_xml_export]" value="yes" <?php checked( 'yes', $s['enable_xml_export'] ); ?> />
									<?php esc_html_e( 'Активирай НАП XML експорт', 'ud-nap-orders-exporter' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Excel таблица (.xlsx)', 'ud-nap-orders-exporter' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[enable_csv_export]" value="yes" <?php checked( 'yes', $s['enable_csv_export'] ); ?> />
									<?php esc_html_e( 'Активирай експорт на таблица', 'ud-nap-orders-exporter' ); ?>
								</label>
							</td>
						</tr>
					</tbody>
				</table>

				<h2><?php esc_html_e( 'Поведение на експорта', 'ud-nap-orders-exporter' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Тези настройки важат и за XML, и за експорта на таблица.', 'ud-nap-orders-exporter' ); ?></p>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><?php esc_html_e( 'Статуси на поръчките', 'ud-nap-orders-exporter' ); ?></th>
							<td>
								<?php foreach ( $statuses_all as $key => $label ) :
									$slug    = str_replace( 'wc-', '', $key );
									$checked = in_array( $slug, $s['order_statuses'], true );
									?>
									<label style="display:inline-block;margin-right:12px;">
										<input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[order_statuses][]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( $checked ); ?> />
										<?php echo esc_html( $label ); ?>
									</label>
								<?php endforeach; ?>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Включвай възстановявания по подразбиране', 'ud-nap-orders-exporter' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[include_refunds]" value="yes" <?php checked( 'yes', $s['include_refunds'] ); ?> />
									<?php esc_html_e( 'Да', 'ud-nap-orders-exporter' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><label><?php esc_html_e( 'Размер на партидата', 'ud-nap-orders-exporter' ); ?></label></th>
							<td><input type="number" min="10" max="500" step="10" name="<?php echo esc_attr( $opt ); ?>[batch_size]" value="<?php echo esc_attr( $s['batch_size'] ); ?>" />
								<p class="description"><?php esc_html_e( 'Брой поръчки, обработвани при всяка AJAX заявка. Намалете стойността, ако експортът прекъсва.', 'ud-nap-orders-exporter' ); ?></p>
							</td>
						</tr>
					</tbody>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
