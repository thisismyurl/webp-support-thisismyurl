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
 * Version:             1.260101
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
 * Ensures the highest version of TIMU_Core is loaded.
 */
function timu_webp_support_load_core() {
    $core_path = plugin_dir_path( __FILE__ ) . 'core/class-timu-core.php';
    if ( ! class_exists( 'TIMU_Core_v1' ) ) {
        require_once $core_path;
    }
}
timu_webp_support_load_core();

class TIMU_WEBP_Support extends TIMU_Core_v1 {

    /**
     * Constructor: Initializes Core and WebP specific hooks.
     */
    public function __construct() {
        parent::__construct( 
            'thisismyurl-webp-support', 
            plugin_dir_url( __FILE__ ), 
            'timu_ws_settings_group', 
            '', 
            'thisismyurl-webp-support' 
        );

        // AJAX Handlers
        add_action( 'wp_ajax_timu_wsbulk_optimize', array( $this, 'ajax_bulk_optimize' ) );
        add_action( 'wp_ajax_timu_wsrestore_single', array( $this, 'ajax_restore_single' ) );
        
        // Menu integration
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
    }

    /**
     * Overrides the core action links to point to Tools instead of Settings.
     */
    public function add_plugin_action_links( $links ) {
        $is_valid = $this->is_licensed();
        // Point specifically to Tools page [cite: 10]
        $settings_url = admin_url( 'tools.php?page=' . $this->plugin_slug );
        $action_links[] = '<a href="' . esc_url( $settings_url ) . '">' . __( 'Settings', $this->text_domain ) . '</a>';

        if ( $is_valid ) {
            $action_links[] = '<a href="https://thisismyurl.com/support/" target="_blank" style="font-weight: bold; color: #46b450;">' . __( 'Support', $this->text_domain ) . '</a>';
        } else {
            $donate_url = 'https://thisismyurl.com/donate/?source=' . urlencode( $this->plugin_slug );
            $action_links[] = '<a href="' . esc_url( $donate_url ) . '" target="_blank" style="font-weight: bold;">' . __( 'Donate', $this->text_domain ) . '</a>';
        }
        return array_merge( $action_links, $links );
    }

    /**
     * Register the Media submenu page.
     */
    public function add_admin_menu() {
        if ( ! class_exists( 'TIMU_IC' ) ) {
            add_management_page(
                __( 'WebP Support', 'thisismyurl-webp-support' ),
                __( 'WebP Support', 'thisismyurl-webp-support' ),
                'manage_options',
                $this->plugin_slug,
                array( $this, 'render_ui' )
            );
        }
    }

    /**
     * Renders the Dashboard utilizing the TIMU Card system.
     */
    public function render_ui() {
        if ( ! current_user_can( 'manage_options' ) ) return;

        $lists       = $this->get_media_lists();
        $pending_ids = array_map( function( $p ) { return $p->ID; }, $lists['pending'] );
        $restorable  = array();

        foreach ( $lists['media'] as $m ) {
            $orig = get_post_meta( $m->ID, '_webp_original_path', true );
            if ( $orig && 'external' !== $orig ) {
                $restorable[] = $m->ID;
            }
        }

        ob_start();
        if ( ! empty( $restorable ) ) : ?>
            <div class="timu-card">
                <div class="timu-card-header"><?php esc_html_e( 'Bulk Actions', 'thisismyurl-webp-support' ); ?></div>
                <div class="timu-card-body">
                    <button id="btn-restore-all" class="button button-secondary" style="width:100%; text-align:center;" data-ids="<?php echo esc_attr( wp_json_encode( $restorable ) ); ?>">
                        <?php esc_html_e( 'Restore All Originals', 'thisismyurl-webp-support' ); ?>
                    </button>
                </div>
            </div>
        <?php endif;
        $sidebar_extra = ob_get_clean();
        ?>

        <div class="wrap timu-admin-wrap">
            <?php $this->render_core_header(); ?>
            
            <div id="poststuff">
                <div id="post-body" class="metabox-holder columns-2">
                    <div id="post-body-content">
                        
                        <div class="timu-card">
                            <div class="timu-card-header"><?php esc_html_e( 'Optimization Dashboard', 'thisismyurl-webp-support' ); ?></div>
                            <div class="timu-card-body">
                                <div class="fwo-controls" style="display: flex; gap: 10px; align-items: center;">
                                    <button id="btn-start" class="button button-primary button-large" <?php disabled( empty( $pending_ids ) ); ?>>
                                        <?php printf( esc_html__( 'Optimize All %d Images', 'thisismyurl-webp-support' ), count( $pending_ids ) ); ?>
                                    </button>
                                    <button id="btn-cancel" class="button button-secondary button-large" style="display:none; color: #d63638;">
                                        <?php esc_html_e( 'Cancel Batch', 'thisismyurl-webp-support' ); ?>
                                    </button>
                                </div>
                                
                                <div id="fwo-progress-container" style="display:none; margin-top:20px; background:#f0f0f1; height:30px; position:relative; border-radius:4px; overflow:hidden; border:1px solid #c3c4c7;">
                                    <div id="fwo-progress-bar" style="background:#2271b1; height:100%; width:0%; transition:width 0.2s;"></div>
                                    <div id="fwo-progress-text" style="position:absolute; width:100%; text-align:center; top:0; line-height:30px; font-weight:bold; color:#fff; mix-blend-mode:difference;">0%</div>
                                </div>
                            </div>
                        </div>

                        <div class="timu-card">
                            <div class="timu-card-header"><?php esc_html_e( 'Managed Media', 'thisismyurl-webp-support' ); ?> (<span id="m-cnt"><?php echo count( $lists['media'] ); ?></span>)</div>
                            <div class="timu-card-body">
                                <table class="widefat striped" id="fwo-media-table" style="border:none; box-shadow:none;">
                                    <thead>
                                        <tr>
                                            <th><?php esc_html_e( 'Preview', 'thisismyurl-webp-support' ); ?></th>
                                            <th><?php esc_html_e( 'File Name', 'thisismyurl-webp-support' ); ?></th>
                                            <th><?php esc_html_e( 'Action', 'thisismyurl-webp-support' ); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ( $lists['media'] as $post ) : 
                                            $orig = get_post_meta( $post->ID, '_webp_original_path', true );
                                            $status = isset( $post->timu_wsstatus ) ? $post->timu_wsstatus : '';
                                        ?>
                                            <tr id="fwo-media-row-<?php echo esc_attr( $post->ID ); ?>">
                                                <td><?php echo wp_get_attachment_image( $post->ID, array( 50, 50 ) ); ?></td>
                                                <td><?php echo esc_html( basename( get_attached_file( $post->ID ) ) ); ?></td>
                                                <td>
                                                    <?php if ( 'missing' === $status ) : ?>
                                                        <span style="color:#d63638;"><?php esc_html_e( 'File Missing', 'thisismyurl-webp-support' ); ?></span>
                                                    <?php elseif ( $orig && 'external' !== $orig ) : ?>
                                                        <button class="restore-btn button button-small" data-id="<?php echo esc_attr( $post->ID ); ?>">
                                                            <?php esc_html_e( 'Restore', 'thisismyurl-webp-support' ); ?>
                                                        </button>
                                                    <?php else : ?>
                                                        <span class="description"><?php esc_html_e( 'Optimized', 'thisismyurl-webp-support' ); ?></span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <?php $this->render_registration_field(); ?>
                    </div>
                    <?php $this->render_core_sidebar( $sidebar_extra ); ?>
                </div>
            </div>
            <?php $this->render_core_footer(); ?>
        </div>

        <script type="text/javascript">
        jQuery(document).ready(function($) {
            const pendingIds = <?php echo wp_json_encode( $pending_ids ); ?>;
            const nonce = '<?php echo esc_js( wp_create_nonce( "timu_wswebp_nonce" ) ); ?>';
            let completed = 0;
            let isCancelled = false;

            $(document).on('click', '.restore-btn', function() {
                const $btn = $(this);
                $btn.prop('disabled', true).text('...');
                $.post(ajaxurl, { action: 'timu_wsrestore_single', attachment_id: $btn.data('id'), nonce: nonce })
                    .done(() => location.reload());
            });

            $('#btn-restore-all').click(function() {
                const ids = $(this).data('ids');
                if(!confirm('<?php echo esc_js( __( "Restore all images? This cannot be undone.", "thisismyurl-webp-support" ) ); ?>')) return;
                $(this).prop('disabled', true).text('<?php echo esc_js( __( "Restoring...", "thisismyurl-webp-support" ) ); ?>');
                
                const processRestore = () => {
                    if(!ids.length) return location.reload();
                    $.post(ajaxurl, { action: 'timu_wsrestore_single', attachment_id: ids.shift(), nonce: nonce }).always(processRestore);
                };
                processRestore();
            });

            $('#btn-start').click(function() {
                const $btn = $(this);
                const total = pendingIds.length;
                $btn.prop('disabled', true).text('<?php echo esc_js( __( "Processing...", "thisismyurl-webp-support" ) ); ?>');
                $('#btn-cancel').show();
                $('#fwo-progress-container').fadeIn();

                const processNext = () => {
                    if (isCancelled || !pendingIds.length) return;
                    const id = pendingIds.shift();
                    $.post(ajaxurl, { action: 'timu_wsbulk_optimize', attachment_id: id, nonce: nonce })
                        .done(function(res) {
                            if (res.success) {
                                completed++;
                                const pct = Math.round((completed / total) * 100);
                                $('#fwo-progress-bar').css('width', pct + '%');
                                $('#fwo-progress-text').text(pct + '%');
                                $('#p-cnt').text(total - completed);
                            }
                            processNext();
                        });
                };
                processNext();
            });

            $('#btn-cancel').click(() => { isCancelled = true; location.reload(); });
        });
        </script>
        <?php
    }



    public function get_media_lists() {
        $query = new WP_Query(
            array(
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                'posts_per_page' => -1,
                'no_found_rows'  => true,
                'post_mime_type' => array( 'image/jpeg', 'image/png', 'image/gif', 'image/bmp', 'image/webp' ),
            )
        );

        $pending = array(); $media = array();
        if ( $query->posts ) {
            foreach ( $query->posts as $post ) {
                $file = get_attached_file( $post->ID );
                $orig_path = get_post_meta( $post->ID, '_webp_original_path', true );
                if ( 'image/webp' === get_post_mime_type( $post->ID ) && ! $orig_path ) {
                    update_post_meta( $post->ID, '_webp_original_path', 'external' );
                    $orig_path = 'external';
                }
                if ( ! file_exists( $file ) ) { $post->timu_wsstatus = 'missing'; $media[] = $post; continue; }
                if ( $orig_path || 'image/webp' === get_post_mime_type( $post->ID ) ) { $media[] = $post; } 
                else { $pending[] = $post; }
            }
        }
        return array( 'pending' => $pending, 'media' => $media );
    }

    public function convert_to_webp( $id, $quality = 80 ) {
        $fs = $this->init_fs();
        $full_path = get_attached_file( $id );
        if ( ! $full_path || ! $fs->exists( $full_path ) ) return new WP_Error( 'missing', 'File missing.' );

        $info = getimagesize( $full_path );
        $original_size = filesize( $full_path );
        $new_path = preg_replace( '/\.(jpg|jpeg|png|gif|bmp)$/i', '.webp', $full_path );

        switch ( $info['mime'] ) {
            case 'image/jpeg': $image = imagecreatefromjpeg( $full_path ); break;
            case 'image/png': 
                $image = imagecreatefrompng( $full_path );
                if ( $image ) { imagepalettetotruecolor( $image ); imagealphablending( $image, true ); imagesavealpha( $image, true ); }
                break;
            case 'image/gif': $image = imagecreatefromgif( $full_path ); break;
            case 'image/bmp': $image = imagecreatefrombmp( $full_path ); break;
            default: return new WP_Error( 'mime', 'Unsupported format.' );
        }

        if ( ! $image ) return new WP_Error( 'gd', 'GD conversion failed.' );
        imagewebp( $image, $new_path, $quality );
        imagedestroy( $image );

        $upload_dir = wp_upload_dir();
        $rel_path   = get_post_meta( $id, '_wp_attached_file', true );
        $backup_dir = $upload_dir['basedir'] . '/webp-backups/' . dirname( $rel_path );

        if ( wp_mkdir_p( $backup_dir ) ) {
            $backup_path = $backup_dir . '/' . basename( $full_path );
            if ( $fs->move( $full_path, $backup_path, true ) ) {
                update_post_meta( $id, '_webp_original_path', $backup_path );
                update_post_meta( $id, '_webp_savings', ( $original_size - filesize( $new_path ) ) );
                update_post_meta( $id, '_wp_attached_file', preg_replace( '/\.(jpg|jpeg|png|gif|bmp)$/i', '.webp', $rel_path ) );
                wp_update_post( array( 'ID' => $id, 'post_mime_type' => 'image/webp' ) );
                return true;
            }
        }
        return new WP_Error( 'move', 'Failed to archive original.' );
    }

    public function restore_image( $id ) {
        $fs = $this->init_fs();
        $backup_path = get_post_meta( $id, '_webp_original_path', true );
        if ( ! $backup_path || 'external' === $backup_path || ! $fs->exists( $backup_path ) ) return false;

        $current_webp = get_attached_file( $id );
        $extension = pathinfo( $backup_path, PATHINFO_EXTENSION );
        $restored_path = preg_replace( '/\.webp$/i', '.' . $extension, $current_webp );

        if ( $fs->move( $backup_path, $restored_path, true ) ) {
            if ( $fs->exists( $current_webp ) ) $fs->delete( $current_webp );
            $new_rel = preg_replace( '/\.webp$/i', '.' . $extension, get_post_meta( $id, '_wp_attached_file', true ) );
            update_post_meta( $id, '_wp_attached_file', $new_rel );
            $mimes = array( 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'bmp' => 'image/bmp' );
            wp_update_post( array( 'ID' => $id, 'post_mime_type' => $mimes[strtolower($extension)] ?? 'image/jpeg' ) );
            delete_post_meta( $id, '_webp_original_path' );
            delete_post_meta( $id, '_webp_savings' );
            return true;
        }
        return false;
    }

    public function ajax_bulk_optimize() {
        check_ajax_referer( 'timu_wswebp_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error();
        $id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;
        $result = $this->convert_to_webp( $id );
        if ( true === $result ) wp_send_json_success();
        wp_send_json_error();
    }

    public function ajax_restore_single() {
        check_ajax_referer( 'timu_wswebp_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error();
        $id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;
        if ( $this->restore_image( $id ) ) wp_send_json_success();
        wp_send_json_error();
    }
}

new TIMU_WEBP_Support();