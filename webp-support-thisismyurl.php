<?php
/**
 * TIMU WebP Support Plugin
 *
 * This plugin facilitates the secure upload and processing of WebP images within the 
 * WordPress Media Library. It integrates with the TIMU Shared Core to provide consistent 
 * settings management and cross-plugin format conversion.
 *
 * @package    TIMU_WebP_Support
 * @author     Christopher Ross <https://thisismyurl.com/>
 * @version    1.260102
 * @license    GPL-2.0+
 */

/**
 * Security: Prevent direct file access.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Version-aware Core Loader
 *
 * Ensures the TIMU Core base class is available before initializing the plugin.
 * The check for class_exists prevents fatal errors in multi-plugin environments
 * where multiple versions of the core library might be present.
 */
function timu_webp_support_load_core() {
	$core_path = plugin_dir_path( __FILE__ ) . 'core/class-timu-core.php';
	if ( ! class_exists( 'TIMU_Core_v1' ) ) {
		require_once $core_path;
	}
}
timu_webp_support_load_core();

/**
 * Class TIMU_WebP_Support
 *
 * Extends the shared core to handle WebP-specific MIME types and image processing.
 * Adheres to WordPress standards for hook registration and object-oriented design.
 */
class TIMU_WebP_Support extends TIMU_Core_v1 {

	/**
	 * Constructor: Initializes the plugin structure and WordPress hooks.
	 *
	 * Passes configuration parameters to the parent core constructor to set up 
	 * standard admin behaviors and asset enqueuing.
	 */
	public function __construct() {
		parent::__construct(
			'thisismyurl-webp-support',      // Unique plugin slug.
			plugin_dir_url( __FILE__ ),       // Base URL for assets.
			'timu_ws_settings_group',         // Settings registration group.
			'',                               // Custom icon (optional).
			'tools.php'                       // Admin menu parent location.
		);

		/**
		 * Hook: Initialize the settings blueprint.
		 */
		add_action( 'init', array( $this, 'setup_plugin' ) );

		/**
		 * Filters: Handle MIME types and the upload lifecycle.
		 */
		add_filter( 'upload_mimes', array( $this, 'add_webp_mime_types' ) );
		add_filter( 'wp_handle_upload', array( $this, 'process_webp_upload' ) );

		/**
		 * Action: Register the dedicated management page.
		 */
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );

		/**
		 * Activation: Ensure default options exist in the database.
		 */
		register_activation_hook( __FILE__, array( $this, 'activate_plugin_defaults' ) );
	}

	/**
	 * Configuration Blueprint
	 *
	 * Defines the settings structure for the Core's automated UI generator.
	 * Implements dynamic awareness of sibling plugins (AVIF) to adjust UI options.
	 */
	public function setup_plugin() {
		/**
		 * Dependency Check: Verify if the AVIF sibling plugin is active.
		 */
		$avif_active = class_exists( 'TIMU_AVIF_Support' );

		/**
		 * Dynamically build radio options based on the plugin ecosystem.
		 */
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
					'enabled'       => array(
						'type'      => 'switch',
						'label'     => __( 'Enable WebP Support', 'thisismyurl-webp-support' ),
						'desc'      => __( 'Allows .webp files to be uploaded to the Media Library.', 'thisismyurl-webp-support' ),
						'is_parent' => true, // Triggers cascading visibility in shared-admin.js.
						'default'   => 1,
					),
					'handling_mode' => array(
						'type'    => 'radio',
						'label'   => __( 'WebP Handling Mode', 'thisismyurl-webp-support' ),
						'parent'  => 'enabled', // Subordinate to the main enable switch.
						'options' => $handling_options,
						'default' => 'asis',
						'desc'    => $avif_active
							? __( 'Choose how to handle image uploads.', 'thisismyurl-webp-support' )
							: __( 'AVIF conversion requires the AVIF Support plugin.', 'thisismyurl-webp-support' ),
					),
					'quality'       => array(
						'type'    => 'number',
						'label'   => __( 'Compression Quality', 'thisismyurl-webp-support' ),
						'desc'    => __( 'Set image quality from 1-100 (Default: 80).', 'thisismyurl-webp-support' ),
						'parent'  => 'enabled',
						'min'     => 1,
						'max'     => 100,
						'default' => 80,
					),
				),
			),
		);

		/**
		 * Pass the blueprint to the Core's generation engine.
		 */
		$this->init_settings_generator( $blueprint );
	}

	/**
	 * Default Option Initialization
	 *
	 * Uses the register_activation_hook to ensure a clean state upon install.
	 */
	public function activate_plugin_defaults() {
		$option_name = "{$this->plugin_slug}_options";
		if ( false === get_option( $option_name ) ) {
			update_option( $option_name, array(
				'enabled'       => 1,
				'handling_mode' => 'asis',
				'quality'       => 80,
			) );
		}
	}

	/**
	 * Admin Menu Registration
	 *
	 * Registers the settings page under the Tools menu (tools.php).
	 */
	public function add_admin_menu() {
		add_management_page(
			__( 'WebP Support Settings', 'thisismyurl-webp-support' ),
			__( 'WebP Support', 'thisismyurl-webp-support' ),
			'manage_options',
			$this->plugin_slug,
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * MIME Type Support
	 *
	 * Modifies the list of allowed MIME types to permit .webp uploads.
	 *
	 * @param array $mimes Existing MIME types.
	 * @return array Modified MIME types.
	 */
	public function add_webp_mime_types( $mimes ) {
		if ( 1 === (int) $this->get_plugin_option( 'enabled', 1 ) ) {
			$mimes['webp'] = 'image/webp';
		}
		return $mimes;
	}

	/**
	 * Image Processing Orchestrator
	 *
	 * Intercepts the upload process to either compress native WebP files or 
	 * convert them to AVIF using the shared core utility.
	 *
	 * @param array $upload The standard WordPress upload result array.
	 * @return array The processed upload result.
	 */
	public function process_webp_upload( $upload ) {
		/**
		 * Exit early if plugin is disabled or the file is not a WebP.
		 */
		if ( 1 !== (int) $this->get_plugin_option( 'enabled', 1 ) || 'image/webp' !== $upload['type'] ) {
			return $upload;
		}

		/**
		 * Determine the processing objective.
		 */
		$mode          = $this->get_plugin_option( 'handling_mode', 'asis' );
		$target_format = ( 'avif' === $mode ) ? 'avif' : 'webp';
		$quality       = (int) $this->get_plugin_option( 'quality', 80 );

		/**
		 * Leverage the Shared Core utility to handle heavy Imagick operations.
		 * This ensures centralized resource management.
		 */
		return $this->process_image_conversion( $upload, $target_format, $quality );
	}
}

/**
 * Initialize the plugin object.
 */
new TIMU_WebP_Support();