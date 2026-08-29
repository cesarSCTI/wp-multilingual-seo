<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pantalla de administración para revisar y corregir a mano las
 * traducciones de términos (nombre y descripción).
 */
class MLS_Admin_Terms {

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_page' ), 20 );
		add_action( 'admin_post_mls_save_term_translation', array( $this, 'handle_save' ) );
	}

	public function add_page() {
		add_submenu_page(
			'mls-settings',
			__( 'Términos', 'mls' ),
			__( 'Términos', 'mls' ),
			'manage_options',
			'mls-terms',
			array( $this, 'render' )
		);
	}

	private function taxonomies() {
		$settings = mls_get_settings();
		$chosen   = isset( $settings['taxonomies'] ) ? (array) $settings['taxonomies'] : array( 'category', 'post_tag' );
		return array_values( array_filter( $chosen, 'taxonomy_exists' ) );
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$targets = array_keys( MLS_Language_Registry::targets() );
		if ( empty( $targets ) ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Términos', 'mls' ) . '</h1><p>' . esc_html__( 'Configura al menos un idioma destino.', 'mls' ) . '</p></div>';
			return;
		}

		$taxonomies = $this->taxonomies();
		$active_tax = isset( $_GET['taxonomy'] ) && in_array( sanitize_key( $_GET['taxonomy'] ), $taxonomies, true )
			? sanitize_key( $_GET['taxonomy'] )
			: ( $taxonomies ? $taxonomies[0] : 'category' );
		$active_lang = isset( $_GET['lang'] ) && in_array( sanitize_key( $_GET['lang'] ), $targets, true )
			? sanitize_key( $_GET['lang'] )
			: $targets[0];

		$terms = get_terms( array(
			'taxonomy'   => $active_tax,
			'hide_empty' => false,
			'number'     => 200,
		) );
		if ( is_wp_error( $terms ) ) {
			$terms = array();
		}
		?>
		<div class="wrap mls-admin">
			<h1><?php esc_html_e( 'Traducción de términos', 'mls' ); ?></h1>

			<form method="get" style="margin:12px 0;">
				<input type="hidden" name="page" value="mls-terms" />
				<label><?php esc_html_e( 'Taxonomía', 'mls' ); ?>
					<select name="taxonomy" onchange="this.form.submit()">
						<?php foreach ( $taxonomies as $tx ) : $obj = get_taxonomy( $tx ); ?>
							<option value="<?php echo esc_attr( $tx ); ?>" <?php selected( $active_tax, $tx ); ?>><?php echo esc_html( $obj ? $obj->labels->name : $tx ); ?></option>
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
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Traducción del término guardada.', 'mls' ); ?></p></div>
			<?php endif; ?>

			<table class="widefat striped">
				<thead><tr>
					<th style="width:22%"><?php esc_html_e( 'Original', 'mls' ); ?></th>
					<th><?php esc_html_e( 'Nombre traducido', 'mls' ); ?></th>
					<th><?php esc_html_e( 'Descripción traducida', 'mls' ); ?></th>
					<th style="width:10%"><?php esc_html_e( 'Estado', 'mls' ); ?></th>
					<th style="width:8%"></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $terms as $term ) :
					$row = MLS_Terms::get_row( $term->term_id, $active_lang );
					?>
					<tr>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php wp_nonce_field( 'mls_save_term_' . $term->term_id . '_' . $active_lang ); ?>
							<input type="hidden" name="action" value="mls_save_term_translation" />
							<input type="hidden" name="term_id" value="<?php echo esc_attr( $term->term_id ); ?>" />
							<input type="hidden" name="taxonomy" value="<?php echo esc_attr( $active_tax ); ?>" />
							<input type="hidden" name="lang" value="<?php echo esc_attr( $active_lang ); ?>" />
							<td>
								<strong><?php echo esc_html( $term->name ); ?></strong>
								<?php if ( $term->description ) : ?><br /><small><?php echo esc_html( wp_trim_words( $term->description, 20 ) ); ?></small><?php endif; ?>
							</td>
							<td><input type="text" name="name" class="regular-text" style="width:100%" value="<?php echo esc_attr( $row ? $row->name : '' ); ?>" /></td>
							<td><textarea name="description" rows="2" style="width:100%"><?php echo esc_textarea( $row ? $row->description : '' ); ?></textarea></td>
							<td><?php echo esc_html( $row ? MLS_DB::status_label( $row->status ) : MLS_DB::status_label( MLS_DB::STATUS_PENDING ) ); ?></td>
							<td><button type="submit" class="button button-primary"><?php esc_html_e( 'Guardar', 'mls' ); ?></button></td>
						</form>
					</tr>
				<?php endforeach; ?>
				<?php if ( empty( $terms ) ) : ?>
					<tr><td colspan="5"><?php esc_html_e( 'No hay términos en esta taxonomía.', 'mls' ); ?></td></tr>
				<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	public function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sin permisos.', 'mls' ) );
		}
		$term_id  = isset( $_POST['term_id'] ) ? absint( $_POST['term_id'] ) : 0;
		$lang     = isset( $_POST['lang'] ) ? sanitize_key( $_POST['lang'] ) : '';
		$taxonomy = isset( $_POST['taxonomy'] ) ? sanitize_key( $_POST['taxonomy'] ) : '';

		check_admin_referer( 'mls_save_term_' . $term_id . '_' . $lang );

		if ( ! $term_id || ! $lang || ! $taxonomy ) {
			wp_die( esc_html__( 'Datos incompletos.', 'mls' ) );
		}

		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$desc = isset( $_POST['description'] ) ? wp_kses_post( wp_unslash( $_POST['description'] ) ) : '';

		MLS_Terms::save( array(
			'term_id'     => $term_id,
			'taxonomy'    => $taxonomy,
			'language'    => $lang,
			'name'        => $name,
			'description' => $desc,
			'slug'        => sanitize_title( $name ),
			'status'      => MLS_DB::STATUS_MANUAL,
		) );

		wp_safe_redirect( add_query_arg(
			array( 'page' => 'mls-terms', 'taxonomy' => $taxonomy, 'lang' => $lang, 'mls_saved' => 1 ),
			admin_url( 'admin.php' )
		) );
		exit;
	}
}
