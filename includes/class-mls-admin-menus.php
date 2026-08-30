<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pantalla de administración para traducir las etiquetas de los menús de
 * navegación: texto visible, atributo `title` (tooltip) y descripción de cada
 * ítem, por idioma. Incluye un botón para rellenar lo que falte con Google.
 */
class MLS_Admin_Menus {

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_page' ), 20 );
		add_action( 'admin_post_mls_save_menu_translations', array( $this, 'handle_save' ) );
	}

	public function add_page() {
		add_submenu_page(
			'mls-settings',
			__( 'Menús', 'mls' ),
			__( 'Menús', 'mls' ),
			'manage_options',
			'mls-menus',
			array( $this, 'render' )
		);
	}

	/**
	 * @return array<int,string> menu_id => name
	 */
	private function menus() {
		$menus = wp_get_nav_menus();
		$out   = array();
		foreach ( (array) $menus as $menu ) {
			$out[ (int) $menu->term_id ] = $menu->name;
		}
		return $out;
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$targets = array_keys( MLS_Language_Registry::targets() );
		if ( empty( $targets ) ) {
			echo '<div class="wrap mls-admin"><h1>' . esc_html__( 'Menús', 'mls' ) . '</h1><p>' . esc_html__( 'Configura al menos un idioma destino.', 'mls' ) . '</p></div>';
			return;
		}

		$menus = $this->menus();
		if ( empty( $menus ) ) {
			echo '<div class="wrap mls-admin"><h1>' . esc_html__( 'Menús', 'mls' ) . '</h1><p>' . esc_html__( 'No hay menús de navegación creados todavía (Apariencia → Menús).', 'mls' ) . '</p></div>';
			return;
		}

		$menu_ids   = array_keys( $menus );
		$active_menu = isset( $_GET['menu'] ) && in_array( (int) $_GET['menu'], $menu_ids, true ) ? (int) $_GET['menu'] : $menu_ids[0];
		$active_lang = isset( $_GET['lang'] ) && in_array( sanitize_key( $_GET['lang'] ), $targets, true ) ? sanitize_key( $_GET['lang'] ) : $targets[0];

		$items    = wp_get_nav_menu_items( $active_menu );
		$items    = is_array( $items ) ? $items : array();
		$settings = mls_get_settings();
		$has_api  = ! empty( $settings['api_key'] );
		?>
		<div class="wrap mls-admin">
			<h1><?php esc_html_e( 'Traducción de menús', 'mls' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Traduce el texto visible de cada opción del menú. Solo se aplica en las URLs /idioma/; el menú original no se toca.', 'mls' ); ?></p>

			<form method="get" style="margin:12px 0;">
				<input type="hidden" name="page" value="mls-menus" />
				<label><?php esc_html_e( 'Menú', 'mls' ); ?>
					<select name="menu" onchange="this.form.submit()">
						<?php foreach ( $menus as $id => $name ) : ?>
							<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $active_menu, $id ); ?>><?php echo esc_html( $name ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label style="margin-left:12px;"><?php esc_html_e( 'Idioma', 'mls' ); ?>
					<select name="lang" onchange="this.form.submit()">
						<?php foreach ( $targets as $lg ) : ?>
							<option value="<?php echo esc_attr( $lg ); ?>" <?php selected( $active_lang, $lg ); ?>><?php echo esc_html( strtoupper( $lg ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
			</form>

			<?php if ( isset( $_GET['mls_saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Traducciones del menú guardadas.', 'mls' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['mls_autofilled'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>
					<?php
					/* translators: %d: número de ítems traducidos */
					printf( esc_html__( 'Se tradujeron %d opciones con Google.', 'mls' ), (int) $_GET['mls_autofilled'] );
					?>
				</p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['mls_autofill_error'] ) ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'No se pudo traducir con Google. Revisa la API key y la cuota.', 'mls' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'mls_save_menu_' . $active_menu . '_' . $active_lang ); ?>
				<input type="hidden" name="action" value="mls_save_menu_translations" />
				<input type="hidden" name="menu" value="<?php echo esc_attr( $active_menu ); ?>" />
				<input type="hidden" name="lang" value="<?php echo esc_attr( $active_lang ); ?>" />

				<table class="widefat striped">
					<thead><tr>
						<th style="width:26%"><?php esc_html_e( 'Opción original', 'mls' ); ?></th>
						<th><?php esc_html_e( 'Texto traducido', 'mls' ); ?></th>
						<th style="width:22%"><?php esc_html_e( 'Título / tooltip', 'mls' ); ?></th>
						<th style="width:22%"><?php esc_html_e( 'Descripción', 'mls' ); ?></th>
						<th style="width:9%"><?php esc_html_e( 'Estado', 'mls' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $items as $item ) :
						$row     = MLS_Menus::get_row( (int) $item->ID, $active_lang );
						$strings = MLS_Menus::item_strings( $item );
						?>
						<tr>
							<td>
								<strong><?php echo esc_html( $strings['title'] ); ?></strong>
								<br /><small class="description"><?php echo esc_html( $this->item_type_label( $item ) ); ?></small>
							</td>
							<td>
								<input type="text" name="items[<?php echo esc_attr( $item->ID ); ?>][title]" class="regular-text" style="width:100%"
									value="<?php echo esc_attr( $row ? $row->title : '' ); ?>"
									placeholder="<?php echo esc_attr( $strings['title'] ); ?>" />
							</td>
							<td>
								<input type="text" name="items[<?php echo esc_attr( $item->ID ); ?>][attr_title]" style="width:100%"
									value="<?php echo esc_attr( $row ? $row->attr_title : '' ); ?>"
									placeholder="<?php echo esc_attr( $strings['attr_title'] ); ?>"
									<?php echo '' === $strings['attr_title'] ? 'disabled' : ''; ?> />
							</td>
							<td>
								<textarea name="items[<?php echo esc_attr( $item->ID ); ?>][description]" rows="2" style="width:100%"
									placeholder="<?php echo esc_attr( $strings['description'] ); ?>"
									<?php echo '' === $strings['description'] ? 'disabled' : ''; ?>><?php echo esc_textarea( $row ? $row->description : '' ); ?></textarea>
							</td>
							<td><?php echo esc_html( $row ? MLS_DB::status_label( $row->status ) : MLS_DB::status_label( MLS_DB::STATUS_PENDING ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					<?php if ( empty( $items ) ) : ?>
						<tr><td colspan="5"><?php esc_html_e( 'Este menú no tiene opciones.', 'mls' ); ?></td></tr>
					<?php endif; ?>
					</tbody>
				</table>

				<?php if ( ! empty( $items ) ) : ?>
					<p style="margin-top:16px;">
						<button type="submit" name="mls_menu_op" value="save" class="button button-primary"><?php esc_html_e( 'Guardar traducciones', 'mls' ); ?></button>
						<?php if ( $has_api ) : ?>
							<button type="submit" name="mls_menu_op" value="autofill" class="button" style="margin-left:8px;"><?php esc_html_e( 'Rellenar vacíos con Google', 'mls' ); ?></button>
						<?php endif; ?>
					</p>
					<p class="description"><?php esc_html_e( 'Deja un campo vacío para que se muestre el texto original en ese idioma. Los campos guardados a mano no se sobrescriben al usar Google.', 'mls' ); ?></p>
				<?php endif; ?>
			</form>
		</div>
		<?php
	}

	/**
	 * @param object $item
	 * @return string
	 */
	private function item_type_label( $item ) {
		switch ( $item->type ) {
			case 'custom':
				return __( 'Enlace personalizado', 'mls' );
			case 'taxonomy':
				return __( 'Término', 'mls' ) . ' · ' . $item->object;
			case 'post_type':
			case 'post_type_archive':
				return __( 'Contenido', 'mls' ) . ' · ' . $item->object;
			default:
				return (string) $item->type;
		}
	}

	public function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sin permisos.', 'mls' ) );
		}

		$menu_id = isset( $_POST['menu'] ) ? absint( $_POST['menu'] ) : 0;
		$lang    = isset( $_POST['lang'] ) ? sanitize_key( $_POST['lang'] ) : '';
		$op      = isset( $_POST['mls_menu_op'] ) && 'autofill' === $_POST['mls_menu_op'] ? 'autofill' : 'save';

		check_admin_referer( 'mls_save_menu_' . $menu_id . '_' . $lang );

		if ( ! $menu_id || ! $lang ) {
			wp_die( esc_html__( 'Datos incompletos.', 'mls' ) );
		}

		$redirect_args = array( 'page' => 'mls-menus', 'menu' => $menu_id, 'lang' => $lang );

		if ( 'autofill' === $op ) {
			$settings = mls_get_settings();
			if ( empty( $settings['api_key'] ) ) {
				$redirect_args['mls_autofill_error'] = 1;
			} else {
				$done = MLS_Menus::translate_menu( $menu_id, $lang, false );
				$redirect_args['mls_autofilled'] = $done;
			}
			wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
			exit;
		}

		$items       = wp_get_nav_menu_items( $menu_id );
		$items       = is_array( $items ) ? $items : array();
		$by_id       = array();
		foreach ( $items as $item ) {
			$by_id[ (int) $item->ID ] = $item;
		}

		$submitted = isset( $_POST['items'] ) && is_array( $_POST['items'] ) ? wp_unslash( $_POST['items'] ) : array();

		foreach ( $submitted as $item_id => $fields ) {
			$item_id = (int) $item_id;
			if ( ! isset( $by_id[ $item_id ] ) ) {
				continue;
			}
			$title = isset( $fields['title'] ) ? $fields['title'] : '';
			$attr  = isset( $fields['attr_title'] ) ? $fields['attr_title'] : '';
			$desc  = isset( $fields['description'] ) ? $fields['description'] : '';

			// Una fila enteramente vacía y sin traducción previa no se guarda:
			// así "Rellenar vacíos con Google" sigue viéndola como pendiente.
			$has_value = '' !== trim( $title ) || '' !== trim( $attr ) || '' !== trim( $desc );
			if ( ! $has_value && ! MLS_Menus::get_row( $item_id, $lang ) ) {
				continue;
			}

			MLS_Menus::save( array(
				'menu_item_id' => $item_id,
				'language'     => $lang,
				'title'        => $title,
				'attr_title'   => $attr,
				'description'  => $desc,
				'status'       => MLS_DB::STATUS_MANUAL,
				'source_hash'  => MLS_Menus::source_hash( $by_id[ $item_id ] ),
			) );
		}

		$redirect_args['mls_saved'] = 1;
		wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
