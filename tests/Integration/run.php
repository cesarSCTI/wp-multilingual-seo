<?php
/** Integration assertions executed inside a real WordPress + Elementor site. */
if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }
function mls_it_assert( $condition, $message ) {
    if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); }
    echo "PASS: {$message}\n";
}
update_option( 'mls_settings', array_merge( mls_get_settings(), array(
    'source_lang' => 'es', 'target_langs' => array( 'en' ),
    'post_types' => array( 'page' ), 'debug_mode' => 0,
) ) );
MLS_Language_Registry::flush_cache();
$old_fixtures = get_posts( array( 'post_type' => 'page', 'post_status' => 'any', 'posts_per_page' => -1, 's' => 'Inicio integral' ) );
foreach ( $old_fixtures as $old_fixture ) {
    if ( 'Inicio integral' === $old_fixture->post_title ) {
        MLS_DB::delete_translations_for_post( $old_fixture->ID );
        wp_delete_post( $old_fixture->ID, true );
    }
}
$page_id = wp_insert_post( array(
    'post_title' => 'Inicio integral', 'post_name' => 'inicio-integral',
    'post_type' => 'page', 'post_status' => 'publish',
) );
mls_it_assert( ! is_wp_error( $page_id ) && $page_id > 0, 'page fixture created' );
$source_data = array( array(
    'id' => 'section1', 'elType' => 'container', 'settings' => array(),
    'elements' => array(
        array( 'id' => 'heading1', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => array( 'title' => 'Titulo fuente' ), 'elements' => array() ),
        array( 'id' => 'text1', 'elType' => 'widget', 'widgetType' => 'html', 'settings' => array( 'html' => '<p>Parrafo fuente completo.</p>' ), 'elements' => array() ),
        array( 'id' => 'button1', 'elType' => 'widget', 'widgetType' => 'button', 'settings' => array( 'text' => 'Boton fuente' ), 'elements' => array() ),
    ),
) );
update_post_meta( $page_id, '_elementor_edit_mode', 'builder' );
update_post_meta( $page_id, '_elementor_template_type', 'wp-page' );
update_post_meta( $page_id, '_elementor_version', defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '3.0.0' );
update_post_meta( $page_id, '_elementor_data', wp_slash( wp_json_encode( $source_data ) ) );
$source_units = MLS_Elementor_Adapter::extract_units( wp_json_encode( $source_data ) );
$translations = array(
    'heading1.title' => 'Complete translated heading',
    'text1.html' => '<p>Complete translated paragraph.</p>',
    'button1.text' => 'Translated button',
);
$translated_units = array();
foreach ( $source_units as $unit ) {
    if ( isset( $translations[ $unit['unit_id'] ] ) ) { $unit['text'] = $translations[ $unit['unit_id'] ]; }
    $translated_units[] = $unit;
}
mls_it_assert( 3 === count( $translated_units ), 'heading, paragraph and button extracted' );
MLS_DB::save_translation( array(
    'post_id' => $page_id, 'language' => 'en', 'post_title' => 'Integrated home',
    'post_slug' => 'integrated-home', 'translated_path' => 'integrated-home',
    'status' => MLS_DB::STATUS_PUBLISHED, 'builder' => 'elementor',
    'builder_data' => MLS_Elementor_Adapter::apply_translations( wp_json_encode( $source_data ), $translated_units ),
    'translation_units' => wp_json_encode( $translated_units ),
    'source_hash' => MLS_DB::compute_source_hash( $page_id ),
) );
$_SERVER['REQUEST_URI'] = '/en/integrated-home/';
MLS_Language_Context::reset();
mls_it_assert( MLS_Language_Context::set_translation_context( 'en', $page_id ), 'translation context accepted for /en/' );
$direct = MLS_Elementor_Adapter::apply_translations_to_data( $source_data, $translated_units );
mls_it_assert( 3 === count( $direct[0]['elements'] ), 'direct adapter completed' );
$renderer = new MLS_Elementor_Renderer();
mls_debug_log( 'integration renderer probe' );
mls_it_assert( true, 'debug logger returns without locale recursion' );
$rendered = $renderer->filter_render_tree( $source_data, $page_id );
$json = wp_json_encode( $rendered );
mls_it_assert( false !== strpos( $json, 'Complete translated heading' ), 'heading translated in live Elementor tree' );
mls_it_assert( false !== strpos( $json, 'Complete translated paragraph' ), 'paragraph translated in live Elementor tree' );
mls_it_assert( false !== strpos( $json, 'Translated button' ), 'button translated in live Elementor tree' );
mls_it_assert( 3 === count( $rendered[0]['elements'] ), 'all Elementor widgets preserved' );
$cache = new MLS_Elementor_Cache();
mls_it_assert( 'disable' === $cache->disable_document_cache_for_translation( 24 ), 'document cache disabled on translated URL' );
$en_id = $cache->add_language_discriminator( 'element' );
MLS_Language_Context::mark_source_request();
$_SERVER['REQUEST_URI'] = '/inicio-integral/';
mls_it_assert( 24 === $cache->disable_document_cache_for_translation( 24 ), 'document cache preserved on source URL' );
$es_id = $cache->add_language_discriminator( 'element' );
mls_it_assert( $en_id !== $es_id, 'Elementor cache is isolated by language' );
update_option( 'home', 'http://localhost:8099/site' );
$_SERVER['REQUEST_URI'] = '/site/en/integrated-home/?x=1';
mls_it_assert( 'en/integrated-home' === MLS_Language_Context::get_request_relative_path(), 'subdirectory URL normalized' );
$_SERVER['REQUEST_URI'] = '/site-other/en/integrated-home/';
mls_it_assert( ! MLS_Language_Context::request_matches_language_prefix( 'en' ), 'neighboring path cannot activate translation context' );
update_option( 'home', 'http://localhost:8099' );
flush_rewrite_rules();
echo "INTEGRATION_OK page_id={$page_id}\n";



