<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Ledger_Admin {

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu', array( $this, 'register_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'admin_post_ledger_create_link', array( $this, 'handle_create_link' ) );
        add_action( 'admin_post_ledger_delete_link', array( $this, 'handle_delete_link' ) );
        add_action( 'admin_post_ledger_export_csv', array( 'Ledger_CSV', 'export_all' ) );
        add_action( 'admin_post_ledger_import_csv', array( $this, 'handle_import_csv' ) );
        add_action( 'admin_post_ledger_run_check_now', array( $this, 'handle_run_check_now' ) );
        add_action( 'admin_post_ledger_activate_license', array( $this, 'handle_activate_license' ) );
        add_action( 'admin_post_ledger_save_settings', array( $this, 'handle_save_settings' ) );
    }

    public function enqueue_assets( $hook ) {
        if ( false === strpos( $hook, 'ledger-links' ) ) {
            return;
        }
        wp_enqueue_style( 'ledger-links-admin', LEDGER_LINKS_URL . 'assets/css/admin.css', array(), LEDGER_LINKS_VERSION );
    }

    public function register_menu() {
        add_menu_page(
            'Ledger Links', 'Ledger Links', 'manage_options', 'ledger-links',
            array( $this, 'render_links_page' ), 'dashicons-admin-links', 58
        );
        add_submenu_page( 'ledger-links', 'All Links', 'All Links', 'manage_options', 'ledger-links', array( $this, 'render_links_page' ) );
        add_submenu_page( 'ledger-links', 'Analytics', 'Analytics', 'manage_options', 'ledger-links-analytics', array( $this, 'render_analytics_page' ) );
        add_submenu_page( 'ledger-links', 'Import / Export', 'Import / Export', 'manage_options', 'ledger-links-import', array( $this, 'render_import_page' ) );
        add_submenu_page( 'ledger-links', 'Settings', 'Settings', 'manage_options', 'ledger-links-settings', array( $this, 'render_settings_page' ) );
    }

    // ---- Handlers ----

    public function handle_create_link() {
        check_admin_referer( 'ledger_create_link' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Not allowed.' );
        }

        $slug = sanitize_title( wp_unslash( $_POST['slug'] ?? '' ) );
        $target = esc_url_raw( wp_unslash( $_POST['target_url'] ?? '' ) );

        if ( empty( $slug ) || empty( $target ) || ! filter_var( $target, FILTER_VALIDATE_URL ) ) {
            wp_safe_redirect( add_query_arg( 'ledger_error', 'invalid_input', wp_get_referer() ) );
            exit;
        }

        if ( Ledger_Links_CPT::get_by_slug( $slug ) ) {
            wp_safe_redirect( add_query_arg( 'ledger_error', 'slug_taken', wp_get_referer() ) );
            exit;
        }

        // Device targeting stays free — it was a named missing-feature complaint in research, not a Pro gate.
        $mobile = ! empty( $_POST['mobile_target_url'] ) ? esc_url_raw( wp_unslash( $_POST['mobile_target_url'] ) ) : '';

        Ledger_Links_CPT::insert( array(
            'slug'              => $slug,
            'target_url'        => $target,
            'mobile_target_url' => $mobile,
            'title'             => sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) ),
            'category'          => sanitize_text_field( wp_unslash( $_POST['category'] ?? '' ) ),
            'redirect_type'     => (int) ( $_POST['redirect_type'] ?? 301 ),
        ) );

        wp_safe_redirect( admin_url( 'admin.php?page=ledger-links&ledger_created=1' ) );
        exit;
    }

    public function handle_delete_link() {
        check_admin_referer( 'ledger_delete_link' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Not allowed.' );
        }
        Ledger_Links_CPT::delete( absint( $_GET['id'] ?? 0 ) );
        wp_safe_redirect( admin_url( 'admin.php?page=ledger-links&ledger_deleted=1' ) );
        exit;
    }

    public function handle_import_csv() {
        check_admin_referer( 'ledger_import_csv' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Not allowed.' );
        }

        if ( empty( $_FILES['csv_file']['tmp_name'] ) || UPLOAD_ERR_OK !== $_FILES['csv_file']['error'] ) {
            wp_safe_redirect( add_query_arg( 'ledger_error', 'upload_failed', wp_get_referer() ) );
            exit;
        }

        $filename = sanitize_file_name( $_FILES['csv_file']['name'] );
        if ( 'csv' !== strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) ) ) {
            wp_safe_redirect( add_query_arg( 'ledger_error', 'not_csv', wp_get_referer() ) );
            exit;
        }

        $result = Ledger_CSV::import_from_file( $_FILES['csv_file']['tmp_name'] );
        set_transient( 'ledger_last_import_result', $result, 60 );

        wp_safe_redirect( admin_url( 'admin.php?page=ledger-links-import&ledger_imported=1' ) );
        exit;
    }

    public function handle_run_check_now() {
        check_admin_referer( 'ledger_run_check_now' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Not allowed.' );
        }
        $broken = Ledger_Link_Checker::instance()->check_all_links();
        set_transient( 'ledger_last_check_result', $broken, 60 );
        wp_safe_redirect( admin_url( 'admin.php?page=ledger-links&ledger_checked=1' ) );
        exit;
    }

    public function handle_activate_license() {
        check_admin_referer( 'ledger_activate_license' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Not allowed.' );
        }
        $result = Ledger_License::activate( wp_unslash( $_POST['license_key'] ?? '' ) );
        set_transient( 'ledger_license_result', $result, 60 );
        wp_safe_redirect( admin_url( 'admin.php?page=ledger-links-settings' ) );
        exit;
    }

    public function handle_save_settings() {
        check_admin_referer( 'ledger_save_settings' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Not allowed.' );
        }
        $settings = get_option( 'ledger_links_settings', array() );
        $settings['base_slug']            = sanitize_title( wp_unslash( $_POST['base_slug'] ?? 'go' ) );
        $settings['exclude_bots']         = ! empty( $_POST['exclude_bots'] );
        $settings['exclude_admin_clicks'] = ! empty( $_POST['exclude_admin_clicks'] );
        update_option( 'ledger_links_settings', $settings );
        flush_rewrite_rules();
        wp_safe_redirect( admin_url( 'admin.php?page=ledger-links-settings&ledger_saved=1' ) );
        exit;
    }

    // ---- Views ----

    public function render_links_page() {
        require LEDGER_LINKS_DIR . 'admin/views/links.php';
    }

    public function render_analytics_page() {
        require LEDGER_LINKS_DIR . 'admin/views/analytics.php';
    }

    public function render_import_page() {
        require LEDGER_LINKS_DIR . 'admin/views/import.php';
    }

    public function render_settings_page() {
        require LEDGER_LINKS_DIR . 'admin/views/settings.php';
    }
}
