<?php
/**
 * Author:              Christopher Ross
 * Author URI:          https://thisismyurl.com/?source=thisismyurl-webp-support
 * Plugin Name:         WEBP Support by thisismyurl.com
 * Plugin URI:          https://thisismyurl.com/thisismyurl-webp-support/?source=thisismyurl-webp-support
 * Donate link:         https://thisismyurl.com/donate/?source=thisismyurl-webp-support
 * 
 * Description:         Safely enable WEBP uploads and convert them to WebP format.
 * Tags:                webp, uploads, media library
 * 
 * Version: 1.260102
 * Requires at least:   5.3
 * Requires PHP:        7.4
 * 
 * Update URI:          https://github.com/thisismyurl/thisismyurl-webp-support
 * GitHub Plugin URI:   https://github.com/thisismyurl/thisismyurl-webp-support
 * Primary Branch:      main
 * Text Domain:         thisismyurl-webp-support
 * 
 * License:             GPL2
 * License URI:         https://www.gnu.org/licenses/gpl-2.0.html
 * 
 * 
 * @package TIMU_WEBP_Support
 * 
 * 
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Version-aware Core Loader
 */
function timu_webp_support_load_core() {
    $core_path = plugin_dir_path( __FILE__ ) . 'core/class-timu-core.php';
    if ( ! class_exists( 'TIMU_Core_v1' ) ) {
        require_once $core_path;
    }
}
timu_webp_support_load_core();

class TIMU_WebP_Support extends TIMU_Core_v1 {

    public function __construct() {
        parent::__construct( 
            'thisismyurl-webp-support', 
            plugin_dir_url( __FILE__ ), 
            'timu_ws_settings_group', 
            '', 
            'tools.php' 
        );

        add_action( 'init', array( $this, 'setup_plugin' ) );
        add_filter( 'upload_mimes', array( $this, 'add_webp_mime_types' ) );
        add_filter( 'wp_handle_upload', array( $this, 'process_webp_upload' ) );
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );

        register_activation_hook( __FILE__, array( $this, 'activate_plugin_defaults' ) );
    }

    /**
     * Configure the settings blueprint for the Core generator.
     */
    public function setup_plugin() {
        // Check if the AVIF sibling plugin is available
        $avif_active = class_exists( 'TIMU_AVIF_Support' );

        // Build handling options dynamically
        $handling_options = array(
            'asis' => __( 'Upload as a .webp file', 'thisismyurl-webp-support' ),
        );

        if ( $avif_active ) {
            $handling_options['avif'] = __( 'Convert uploads to .avif files.', 'thisismyurl-webp-support' );
        }

        $blueprint = array(
            'config' => array(
                'title'  => __( 'WebP Configuration', 'thisismyurl-webp-support' ),
                'fields' => array(
                    'enabled' => array(
                        'type'      => 'switch',
                        'label'     => __( 'Enable WebP Support', 'thisismyurl-webp-support' ),
                        'desc'      => __( 'Allows .webp files to be uploaded to the Media Library.', 'thisismyurl-webp-support' ),
                        'is_parent' => true, 
                        'default'   => 1
                    ),
                    'handling_mode' => array(
                        'type'    => 'radio',
                        'label'   => __( 'WebP Handling Mode', 'thisismyurl-webp-support' ),
                        'parent'  => 'enabled', 
                        'options' => $handling_options,
                        'default' => 'asis',
                        'desc'    => $avif_active 
                            ? __( 'Choose how to handle image uploads.', 'thisismyurl-webp-support' )
                            : __( 'AVIF conversion requires the AVIF Support plugin.', 'thisismyurl-webp-support' )
                    ),
                    'quality' => array(
                        'type'    => 'number',
                        'label'   => __( 'Compression Quality', 'thisismyurl-webp-support' ),
                        'desc'    => __( 'Set image quality from 1-100 (Default: 80).', 'thisismyurl-webp-support' ),
                        'parent'  => 'enabled', 
                        'min'     => 1,
                        'max'     => 100,
                        'default' => 80
                    ),
                )
            )
        );

        $this->init_settings_generator( $blueprint );
    }

    /**
     * Set plugin defaults upon activation.
     */
    public function activate_plugin_defaults() {
        $option_name = $this->plugin_slug . '_options';
        if ( false === get_option( $option_name ) ) {
            update_option( $option_name, array( 
                'enabled'       => 1,
                'handling_mode' => 'asis',
                'quality'       => 80
            ) );
        }
    }

    /**
     * Process uploads based on selected handling mode.
     */
    public function process_webp_upload( $upload ) {
        if ( 1 != $this->get_plugin_option( 'enabled', 1 ) ) {
            return $upload;
        }

        $mode = $this->get_plugin_option( 'handling_mode', 'asis' );
        if ( 'asis' === $mode ) {
            return $upload;
        }

        // Conversion logic for 'convert' (WebP) or 'avif' would be implemented here.
        return $upload;
    }

    public function add_webp_mime_types( $mimes ) {
        if ( 1 == $this->get_plugin_option( 'enabled', 1 ) ) {
            $mimes['webp'] = 'image/webp';
        }
        return $mimes;
    }

    public function add_admin_menu() {
        add_management_page(
            __( 'WebP Support Settings', 'thisismyurl-webp-support' ),
            __( 'WebP Support', 'thisismyurl-webp-support' ),
            'manage_options',
            $this->plugin_slug,
            array( $this, 'render_settings_page' )
        );
    }
}
new TIMU_WebP_Support();