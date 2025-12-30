<?php
/**
 * Author:      Christopher Ross
 * Author URI:  https://thisismyurl.com/
 * Plugin Name: WEBP Support by thisismyurl.com
 * Plugin URI:  https://thisismyurl.com/thisismyurl-webp-support/
 * Donate link: https://thisismyurl.com/donate/
 * Description: Non-destructive WebP conversion with backups, live categorization, and one-click restoration.
 * Version:     1.251229
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Update URI: https://github.com/thisismyurl/thisismyurl-webp-support
 * GitHub Plugin URI: https://github.com/thisismyurl/thisismyurl-webp-support
 * Primary Branch: main
 * 
 * Text Domain: thisismyurl-svg-support
 * License:     GPL2
 * 
 * 
 * * * @package TIMU_WEBP_Support 
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TIMU_WEBP_Support {

    /**
     * Initialize the plugin hooks.
     */
    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'add_admin_menu' ) );
        add_action( 'wp_ajax_timu_wsbulk_optimize', array( __CLASS__, 'ajax_bulk_optimize' ) );
        add_action( 'wp_ajax_timu_wsrestore_single', array( __CLASS__, 'ajax_restore_single' ) );
        add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( __CLASS__, 'add_plugin_action_links' ) );
}

    /**
     * Register the Media submenu page.
     */
    public static function add_admin_menu() {
        add_management_page(
            __( 'WebP Support', 'thisismyurl-webp-support' ),
            __( 'WebP Support', 'thisismyurl-webp-support' ),
            'manage_options',
            'thisismyurl-webp-support',
            array( __CLASS__, 'render_admin_page' )
        );
    }

    public static function add_plugin_action_links( $links ) {
         $custom_links = array(
                '<a href="' . admin_url( 'admin.php?page=webp-optimizer' ) . '">' . esc_html__( 'Settings', 'thisismyurl-webp-support' ) . '</a>',
                '<a href="https://thisismyurl.com/donate/" target="_blank" style="color: #2271b1; font-weight: bold;">' . esc_html__( 'Donate', 'thisismyurl-webp-support' ) . '</a>',
            );
        return array_merge( $custom_links, $links );
    }

    /**
     * Core Engine: Initialize WordPress Filesystem.
     */
    private static function init_fs() {
        global $wp_filesystem;
        if ( empty( $wp_filesystem ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }
        return $wp_filesystem;
    }

    /**
     * Get lists of pending and managed media items.
     */
    public static function get_media_lists() {
        $query = new WP_Query(
            array(
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                'posts_per_page' => -1,
                'no_found_rows'  => true,
                'post_mime_type' => array( 'image/jpeg', 'image/png', 'image/gif', 'image/bmp', 'image/webp' ),
            )
        );

        $pending = array();
        $media   = array();

        if ( $query->posts ) {
            foreach ( $query->posts as $post ) {
                $file      = get_attached_file( $post->ID );
                $mime      = get_post_mime_type( $post->ID );
                $orig_path = get_post_meta( $post->ID, '_webp_original_path', true );
                $is_webp   = ( 'image/webp' === $mime );

                if ( $is_webp && ! $orig_path ) {
                    update_post_meta( $post->ID, '_webp_original_path', 'external' );
                    $orig_path = 'external';
                }

                if ( ! file_exists( $file ) ) {
                    $post->timu_wsstatus = 'missing';
                    $media[]             = $post;
                    continue;
                }

                if ( $orig_path || $is_webp ) {
                    $media[] = $post;
                } else {
                    $pending[] = $post;
                }
            }
        }
        return array(
            'pending' => $pending,
            'media'   => $media,
        );
    }

    /**
     * Convert an image to WebP and back up the original.
     */
    public static function convert_to_webp( $id, $quality = 80 ) {
        $fs         = self::init_fs();
        $full_path = get_attached_file( $id );

        if ( ! $full_path || ! $fs->exists( $full_path ) ) {
            return new WP_Error( 'missing', __( 'File does not exist.', 'thisismyurl-webp-support' ) );
        }

        $info = getimagesize( $full_path );
        if ( ! $info ) {
            return new WP_Error( 'info', __( 'Invalid image data.', 'thisismyurl-webp-support' ) );
        }

        $original_size = filesize( $full_path );
        $new_path      = preg_replace( '/\.(jpg|jpeg|png|gif|bmp)$/i', '.webp', $full_path );

        switch ( $info['mime'] ) {
            case 'image/jpeg':
                $image = imagecreatefromjpeg( $full_path );
                break;
            case 'image/png':
                $image = imagecreatefrompng( $full_path );
                if ( $image ) {
                    imagepalettetotruecolor( $image );
                    imagealphablending( $image, true );
                    imagesavealpha( $image, true );
                }
                break;
            case 'image/gif':
                $image = imagecreatefromgif( $full_path );
                break;
            case 'image/bmp':
                $image = imagecreatefrombmp( $full_path );
                break;
            default:
                return new WP_Error( 'mime', __( 'Unsupported format.', 'thisismyurl-webp-support' ) );
        }

        if ( ! $image ) {
            return new WP_Error( 'gd', __( 'GD Library failed to process image.', 'thisismyurl-webp-support' ) );
        }

        imagewebp( $image, $new_path, $quality );
        imagedestroy( $image );

        $upload_dir  = wp_upload_dir();
        $rel_path    = get_post_meta( $id, '_wp_attached_file', true );
        $backup_dir  = $upload_dir['basedir'] . '/webp-backups/' . dirname( $rel_path );

        if ( wp_mkdir_p( $backup_dir ) ) {
            $backup_path = $backup_dir . '/' . basename( $full_path );
            if ( $fs->move( $full_path, $backup_path, true ) ) {
                update_post_meta( $id, '_webp_original_path', $backup_path );
                update_post_meta( $id, '_webp_savings', ( $original_size - filesize( $new_path ) ) );
                $new_rel_path = preg_replace( '/\.(jpg|jpeg|png|gif|bmp)$/i', '.webp', $rel_path );
                update_post_meta( $id, '_wp_attached_file', $new_rel_path );
                wp_update_post( array( 'ID' => $id, 'post_mime_type' => 'image/webp' ) );
                return true;
            }
        }
        return new WP_Error( 'move', __( 'Failed to archive original file.', 'thisismyurl-webp-support' ) );
    }

    /**
     * Restore original image from backup.
     */
    public static function restore_image( $id ) {
        $fs           = self::init_fs();
        $backup_path = get_post_meta( $id, '_webp_original_path', true );

        if ( ! $backup_path || 'external' === $backup_path || ! $fs->exists( $backup_path ) ) {
            return false;
        }

        $current_webp = get_attached_file( $id );
        $extension    = pathinfo( $backup_path, PATHINFO_EXTENSION );
        $restored_path = preg_replace( '/\.webp$/i', '.' . $extension, $current_webp );

        if ( $fs->move( $backup_path, $restored_path, true ) ) {
            if ( $fs->exists( $current_webp ) ) {
                $fs->delete( $current_webp );
            }
            $rel_path = get_post_meta( $id, '_wp_attached_file', true );
            $new_rel  = preg_replace( '/\.webp$/i', '.' . $extension, $rel_path );
            update_post_meta( $id, '_wp_attached_file', $new_rel );
            $mimes = array( 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'bmp' => 'image/bmp' );
            $mime  = isset( $mimes[ strtolower( $extension ) ] ) ? $mimes[ strtolower( $extension ) ] : 'image/jpeg';
            wp_update_post( array( 'ID' => $id, 'post_mime_type' => $mime ) );
            delete_post_meta( $id, '_webp_original_path' );
            delete_post_meta( $id, '_webp_savings' );
            return true;
        }
        return false;
    }

    /**
     * AJAX Handlers
     */
    public static function ajax_bulk_optimize() {
        check_ajax_referer( 'timu_wswebp_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Unauthorized' ); }
        $id     = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;
        $result = self::convert_to_webp( $id );
        if ( true === $result ) {
            wp_send_json_success( array(
                'filename' => basename( get_attached_file( $id ) ),
                'thumb'    => wp_get_attachment_image( $id, array( 50, 50 ) ),
            ) );
        }
        wp_send_json_error( is_wp_error( $result ) ? $result->get_error_message() : 'Unknown error' );
    }

    public static function ajax_restore_single() {
        check_ajax_referer( 'timu_wswebp_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Unauthorized' ); }
        $id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;
        if ( self::restore_image( $id ) ) { wp_send_json_success(); }
        wp_send_json_error();
    }

    /**
     * Render the Admin Dashboard.
     * Updated to match the SVG Support styling and two-column layout.
     */
    public static function render_admin_page() {
        $lists       = self::get_media_lists();
        $pending_ids = array_map( function( $p ) { return $p->ID; }, $lists['pending'] );
        $restorable  = array();

        foreach ( $lists['media'] as $m ) {
            $orig = get_post_meta( $m->ID, '_webp_original_path', true );
            if ( $orig && 'external' !== $orig ) {
                $restorable[] = $m->ID;
            }
        }
        ?>
        <div class="wrap">
            <h1>
                <?php esc_html_e( 'WebP Support', 'thisismyurl-webp-support' ); ?>
                <span style="font-size: 0.5em; font-weight: normal; vertical-align: middle; margin-left: 10px; color: #646970;">
                    <?php printf( 
                        esc_html__( 'by %s', 'thisismyurl-webp-support' ), 
                        '<a href="https://thisismyurl.com/" target="_blank" style="text-decoration: none; color: inherit;">thisismyurl.com</a>' 
                    ); ?>
                </span>
            </h1>
            <p><?php esc_html_e( 'Non-destructive WebP conversion with backups and one-click restoration.', 'thisismyurl-webp-support' ); ?></p>

            <div id="poststuff">
                <div id="post-body" class="metabox-holder columns-2">
                    
                    <div id="post-body-content">
                        
                        <div class="postbox">
                            <h2 class="hndle"><span><?php esc_html_e( 'Optimization Dashboard', 'thisismyurl-webp-support' ); ?></span></h2>
                            <div class="inside">
                                <div class="welcome-panel-content" style="padding: 10px 0; min-height: 100px;">
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
                        </div>

                        <div class="postbox">
                            <h2 class="hndle"><span><?php esc_html_e( 'Pending Optimizations', 'thisismyurl-webp-support' ); ?> (<span id="p-cnt"><?php echo count( $pending_ids ); ?></span>)</span></h2>
                            <div class="inside">
                                <table class="widefat striped" id="fwo-pending-table" style="border:none; box-shadow:none;">
                                    <thead>
                                        <tr>
                                            <th><?php esc_html_e( 'Preview', 'thisismyurl-webp-support' ); ?></th>
                                            <th><?php esc_html_e( 'ID', 'thisismyurl-webp-support' ); ?></th>
                                            <th><?php esc_html_e( 'File Name', 'thisismyurl-webp-support' ); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if ( $lists['pending'] ) : foreach ( $lists['pending'] as $post ) : ?>
                                            <tr id="fwo-row-<?php echo esc_attr( $post->ID ); ?>">
                                                <td><?php echo wp_get_attachment_image( $post->ID, array( 50, 50 ) ); ?></td>
                                                <td>#<?php echo esc_html( $post->ID ); ?></td>
                                                <td><?php echo esc_html( basename( get_attached_file( $post->ID ) ) ); ?></td>
                                            </tr>
                                        <?php endforeach; else : ?>
                                            <tr class="no-images"><td colspan="3"><?php esc_html_e( 'All images optimized!', 'thisismyurl-webp-support' ); ?></td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="postbox">
                            <h2 class="hndle"><span><?php esc_html_e( 'Managed Media', 'thisismyurl-webp-support' ); ?> (<span id="m-cnt"><?php echo count( $lists['media'] ); ?></span>)</span></h2>
                            <div class="inside">
                                <table class="widefat striped" id="fwo-media-table" style="border:none; box-shadow:none;">
                                    <thead>
                                        <tr>
                                            <th><?php esc_html_e( 'Preview', 'thisismyurl-webp-support' ); ?></th>
                                            <th><?php esc_html_e( 'ID', 'thisismyurl-webp-support' ); ?></th>
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
                                                <td>#<?php echo esc_html( $post->ID ); ?></td>
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
                    </div>

                    <div id="postbox-container-1" class="postbox-container">
                        
                        <div class="postbox">
                            <h2 class="hndle"><span><?php esc_html_e( 'Documentation', 'thisismyurl-webp-support' ); ?></span></h2>
                            <div class="inside">
                                <p><?php esc_html_e( 'This plugin converts legacy image formats (JPEG, PNG, BMP) into WebP using the GD Library. Originals are moved to a secure backup directory.', 'thisismyurl-webp-support' ); ?></p>
                                <hr />
                                <?php if ( ! empty( $restorable ) ) : ?>
                                    <p><strong><?php esc_html_e( 'Bulk Actions', 'thisismyurl-webp-support' ); ?></strong></p>
                                    <button id="btn-restore-all" class="button button-secondary" style="width:100%; text-align:center;" data-ids="<?php echo esc_attr( wp_json_encode( $restorable ) ); ?>">
                                        <?php esc_html_e( 'Restore All Originals', 'thisismyurl-webp-support' ); ?>
                                    </button>
                                    <hr />
                                <?php endif; ?>
                                <p><?php printf( 
                                    esc_html__( 'Provided free by %s.', 'thisismyurl-webp-support' ), 
                                    '<a href="https://thisismyurl.com/" target="_blank">thisismyurl.com</a>' 
                                ); ?></p>
                                <p><a href="https://thisismyurl.com/donate/" class="button button-secondary" target="_blank" style="width:100%; text-align:center;"><?php esc_html_e( 'Donate to Development', 'thisismyurl-webp-support' ); ?></a></p>
                            </div>
                        </div>

                    </div> </div> </div> </div> <script type="text/javascript">
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
                                $('#fwo-row-' + id).remove();
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
}
// 1. Initialize the core plugin logic
TIMU_WEBP_Support::init();



add_action( 'plugins_loaded', function() {
    $updater_path = plugin_dir_path( __FILE__ ) . 'updater.php';
    
    if ( file_exists( $updater_path ) ) {
        require_once $updater_path;
    }

    // Double-check the class name matches what is defined in your updater.php
    if ( class_exists( 'FWO_GitHub_Updater' ) ) {
        new FWO_GitHub_Updater( array(
            'slug'               => 'thisismyurl-webp-support',
            'proper_folder_name' => 'thisismyurl-webp-support',
            'api_url'            => 'https://api.github.com/repos/thisismyurl/thisismyurl-webp-support/releases/latest',
            'github_url'         => 'https://github.com/thisismyurl/thisismyurl-webp-support',
            'plugin_file'        => __FILE__,
        ) );
    }
});