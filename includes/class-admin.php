<?php
/**
 * Admin UI for WebP Optimizer.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WebP_Optimizer_Admin {

    public function __construct() {
        add_action( 'admin_menu',            array( $this, 'add_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

        add_action( 'wp_ajax_webp_optimizer_get_batch',         array( $this, 'ajax_get_batch' ) );
        add_action( 'wp_ajax_webp_optimizer_optimize_batch',    array( $this, 'ajax_optimize_batch' ) );
        add_action( 'wp_ajax_webp_optimizer_get_stats',         array( $this, 'ajax_get_stats' ) );
        add_action( 'wp_ajax_webp_optimizer_save_settings',     array( $this, 'ajax_save_settings' ) );
        add_action( 'wp_ajax_webp_optimizer_get_convert_batch', array( $this, 'ajax_get_convert_batch' ) );
        add_action( 'wp_ajax_webp_optimizer_convert_batch',     array( $this, 'ajax_convert_batch' ) );
        add_action( 'wp_ajax_webp_optimizer_fix_mime_types',    array( $this, 'ajax_fix_mime_types' ) );
    }

    // -------------------------------------------------------------------------
    // Menu & Assets
    // -------------------------------------------------------------------------

    public function add_menu() {
        add_media_page(
            'Image Optimizer',
            'Image Optimizer',
            'manage_options',
            'webp-optimizer',
            array( $this, 'render_page' )
        );
    }

    public function enqueue_assets( $hook ) {
        if ( 'media_page_webp-optimizer' !== $hook ) {
            return;
        }

        wp_enqueue_style(
            'webp-optimizer',
            WEBP_OPTIMIZER_URL . 'assets/css/admin.css',
            array(),
            WEBP_OPTIMIZER_VERSION
        );

        wp_enqueue_script(
            'webp-optimizer',
            WEBP_OPTIMIZER_URL . 'assets/js/admin.js',
            array( 'jquery' ),
            WEBP_OPTIMIZER_VERSION,
            true
        );

        $settings        = $this->get_settings();
        $convertible     = array_values( array_intersect( $settings['formats'], array( 'image/jpeg', 'image/png', 'image/avif' ) ) );
        $pending_convert = WebP_Optimizer::get_unconverted_count( $convertible );
        $mismatch_count  = WebP_Optimizer::get_mime_mismatch_count();

        wp_localize_script( 'webp-optimizer', 'webpOptimizer', array(
            'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
            'nonce'          => wp_create_nonce( 'webp_optimizer_nonce' ),
            'settings'       => $settings,
            'allFormats'     => WebP_Optimizer::SUPPORTED_MIMES,
            'pendingConvert' => $pending_convert,
            'mismatchCount'  => $mismatch_count,
            'i18n'           => array(
                'stopping'       => 'Deteniendo…',
                'confirmConvert' => "¿Convertir las imágenes seleccionadas a WebP?\n\nEsta operación modifica los archivos originales (se crea .bak si tienes activado el backup).",
            ),
        ) );
    }

    // -------------------------------------------------------------------------
    // Admin page
    // -------------------------------------------------------------------------

    public function render_page() {
        $settings        = $this->get_settings();
        $stats           = WebP_Optimizer::get_stats();
        $convertible     = array_values( array_intersect( $settings['formats'], array( 'image/jpeg', 'image/png', 'image/avif' ) ) );
        $pending_convert = WebP_Optimizer::get_unconverted_count( $convertible );
        $mismatch_count  = WebP_Optimizer::get_mime_mismatch_count();
        $libs            = WebP_Optimizer::check_libraries();
        $has_lib         = $libs['gd'] || $libs['imagick'];
        ?>
        <div class="wrap webp-optimizer-wrap">

            <h1><span class="dashicons dashicons-images-alt2"></span> Image Optimizer</h1>

            <?php if ( ! $has_lib ) : ?>
                <div class="notice notice-error">
                    <p>
                        <strong>Sin librerías de imagen:</strong>
                        Este plugin necesita <strong>GD</strong> o <strong>ImageMagick</strong>.
                        Contacta con tu proveedor de hosting para activarlas.
                    </p>
                </div>
            <?php endif; ?>

            <!-- ── Stats ── -->
            <div class="webp-stats-grid" id="webp-stats">
                <div class="webp-stat-card">
                    <span class="webp-stat-number" id="stat-total"><?php echo esc_html( $stats['total'] ); ?></span>
                    <span class="webp-stat-label">Total imágenes</span>
                </div>
                <div class="webp-stat-card webp-stat-green">
                    <span class="webp-stat-number" id="stat-optimized"><?php echo esc_html( $stats['optimized'] ); ?></span>
                    <span class="webp-stat-label">Optimizadas</span>
                </div>
                <div class="webp-stat-card webp-stat-orange">
                    <span class="webp-stat-number" id="stat-pending"><?php echo esc_html( $stats['pending'] ); ?></span>
                    <span class="webp-stat-label">Pendientes</span>
                </div>
                <div class="webp-stat-card webp-stat-blue">
                    <span class="webp-stat-number" id="stat-savings">
                        <?php echo esc_html( WebP_Optimizer::format_bytes( $stats['savings'] ) ); ?>
                    </span>
                    <span class="webp-stat-label">Espacio ahorrado</span>
                </div>
                <div class="webp-stat-card webp-stat-purple">
                    <span class="webp-stat-number" id="stat-pending-convert">
                        <?php echo esc_html( $pending_convert ); ?>
                    </span>
                    <span class="webp-stat-label">Sin convertir a WebP</span>
                </div>
            </div>

            <!-- ── Main panel ── -->
            <div class="webp-panel">

                <!-- Progress -->
                <div class="webp-progress-section" id="webp-progress-section">
                    <div class="webp-progress-meta">
                        <span class="webp-progress-status-text" id="webp-progress-status">Listo para optimizar</span>
                        <span class="webp-progress-fraction" id="webp-progress-fraction">
                            <span id="webp-progress-count">0</span> / <span id="webp-progress-total">0</span>
                        </span>
                    </div>
                    <div class="webp-progress-row">
                        <div class="webp-progress-track">
                            <div class="webp-progress-fill" id="webp-progress-bar"></div>
                        </div>
                        <span class="webp-progress-pct" id="webp-progress-pct">0%</span>
                    </div>
                </div>

                <!-- Controls -->
                <div class="webp-controls-row">
                    <div class="webp-control-group">
                        <label for="webp-scope">Imágenes</label>
                        <select id="webp-scope">
                            <option value="pending">Pendientes</option>
                            <option value="all">Todas</option>
                        </select>
                    </div>
                    <div class="webp-control-group">
                        <label for="webp-quality">Calidad</label>
                        <div class="webp-quality-wrap">
                            <input type="range" id="webp-quality" name="quality"
                                   min="50" max="95" step="5"
                                   value="<?php echo esc_attr( $settings['quality'] ); ?>">
                            <span id="webp-quality-val" class="webp-quality-value">
                                <?php echo esc_html( $settings['quality'] ); ?>%
                            </span>
                        </div>
                    </div>
                    <div class="webp-control-group">
                        <label class="webp-checkbox-label">
                            <input type="checkbox" id="webp-backup" value="1"
                                   <?php checked( $settings['backup'], true ); ?>>
                            Backup <code>.bak</code>
                        </label>
                    </div>
                    <div class="webp-control-group">
                        <label>Formatos</label>
                        <div class="webp-convert-formats">
                            <label class="webp-checkbox-label">
                                <input type="checkbox" class="webp-fmt" value="image/webp"
                                       <?php checked( in_array( 'image/webp', $settings['formats'], true ) ); ?>>
                                WebP
                            </label>
                            <label class="webp-checkbox-label">
                                <input type="checkbox" class="webp-fmt" value="image/jpeg"
                                       <?php checked( in_array( 'image/jpeg', $settings['formats'], true ) ); ?>>
                                JPEG
                            </label>
                            <label class="webp-checkbox-label">
                                <input type="checkbox" class="webp-fmt" value="image/png"
                                       <?php checked( in_array( 'image/png', $settings['formats'], true ) ); ?>>
                                PNG
                            </label>
                            <label class="webp-checkbox-label">
                                <input type="checkbox" class="webp-fmt" value="image/gif"
                                       <?php checked( in_array( 'image/gif', $settings['formats'], true ) ); ?>>
                                GIF
                            </label>
                            <label class="webp-checkbox-label">
                                <input type="checkbox" class="webp-fmt" value="image/avif"
                                       <?php checked( in_array( 'image/avif', $settings['formats'], true ) ); ?>>
                                AVIF
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Actions -->
                <div class="webp-actions">
                    <button id="btn-optimize" class="button button-primary button-large"
                            <?php disabled( ! $has_lib ); ?>>
                        <span class="dashicons dashicons-update"></span>
                        Optimizar
                    </button>
                    <button id="btn-optimize-convert" class="button button-large"
                            <?php disabled( ! $has_lib ); ?>>
                        <span class="dashicons dashicons-update-alt"></span>
                        Optimizar y convertir a WebP
                    </button>
                    <button id="btn-stop" class="button button-large" style="display:none;">
                        <span class="dashicons dashicons-no"></span>
                        Detener
                    </button>
                    <button id="btn-fix-mime" class="button button-large"
                            <?php disabled( $mismatch_count === 0 ); ?>>
                        <span class="dashicons dashicons-hammer"></span>
                        Reparar MIME types
                        <span id="webp-mime-count" class="webp-mime-badge"
                              style="<?php echo $mismatch_count > 0 ? '' : 'display:none;'; ?>">
                            <?php echo esc_html( $mismatch_count ); ?>
                        </span>
                    </button>
                </div>

                <!-- Log -->
                <div class="webp-log">
                    <div class="webp-log-header">
                        <span>Registro de operaciones</span>
                        <button id="btn-clear-log" class="button button-small">Limpiar</button>
                    </div>
                    <div id="webp-log-content" class="webp-log-content">
                        <p class="webp-log-placeholder">
                            El registro aparecerá aquí cuando empiece la optimización.
                        </p>
                    </div>
                </div>

            </div><!-- .webp-panel -->

        </div><!-- .webp-optimizer-wrap -->
        <?php
    }

    // -------------------------------------------------------------------------
    // AJAX handlers
    // -------------------------------------------------------------------------

    public function ajax_get_batch() {
        $this->verify_request();

        $force      = ! empty( $_POST['force'] ) && '1' === $_POST['force'];
        $batch_size = isset( $_POST['batch_size'] ) ? absint( $_POST['batch_size'] ) : 5;
        $offset     = isset( $_POST['offset'] )     ? absint( $_POST['offset'] )     : 0;
        $formats    = $this->extract_formats_from_post();

        if ( $force ) {
            $data  = WebP_Optimizer::get_all_image_ids( $batch_size, $offset, $formats );
            $ids   = $data['ids'];
            $total = $data['total'];
        } else {
            $ids   = WebP_Optimizer::get_unoptimized_ids( $batch_size, $formats );
            $stats = WebP_Optimizer::get_stats( $formats );
            $total = $stats['pending'];
        }

        wp_send_json_success( array( 'ids' => $ids, 'total' => (int) $total ) );
    }

    public function ajax_optimize_batch() {
        $this->verify_request();

        $ids     = isset( $_POST['ids'] ) ? array_map( 'absint', (array) $_POST['ids'] ) : array();
        $settings = $this->get_settings();
        $quality  = isset( $_POST['quality'] ) ? min( 95, max( 50, absint( $_POST['quality'] ) ) ) : $settings['quality'];
        $backup   = isset( $_POST['backup'] )  ? ( '1' === $_POST['backup'] )                      : $settings['backup'];
        $formats  = $this->extract_formats_from_post();

        $results = array();

        foreach ( $ids as $id ) {
            $mime = get_post_mime_type( $id );
            if ( ! empty( $formats ) && ! in_array( $mime, $formats, true ) ) {
                continue;
            }

            $res = WebP_Optimizer::optimize_image( $id, $quality, $backup );

            if ( is_wp_error( $res ) ) {
                update_post_meta( $id, WebP_Optimizer::META_ERROR, $res->get_error_message() );
                $results[] = array(
                    'id'      => $id,
                    'success' => false,
                    'file'    => basename( (string) get_attached_file( $id ) ),
                    'message' => $res->get_error_message(),
                );
            } else {
                $results[] = array(
                    'id'          => $id,
                    'success'     => true,
                    'file'        => basename( (string) get_attached_file( $id ) ),
                    'format'      => WebP_Optimizer::MIME_LABELS[ $mime ] ?? strtoupper( pathinfo( get_attached_file( $id ), PATHINFO_EXTENSION ) ),
                    'original'    => WebP_Optimizer::format_bytes( $res['original_size'] ),
                    'new'         => WebP_Optimizer::format_bytes( $res['new_size'] ),
                    'savings'     => WebP_Optimizer::format_bytes( $res['savings'] ),
                    'savings_pct' => $res['savings_pct'],
                );
            }
        }

        $stats  = WebP_Optimizer::get_stats( $formats );
        $errors = array_filter( $results, function ( $r ) { return ! $r['success']; } );

        wp_send_json_success( array(
            'results' => $results,
            'stats'   => array(
                'total'     => $stats['total'],
                'optimized' => $stats['optimized'],
                'pending'   => $stats['pending'],
                'savings'   => WebP_Optimizer::format_bytes( $stats['savings'] ),
                'errors'    => count( $errors ),
            ),
        ) );
    }

    public function ajax_get_stats() {
        $this->verify_request();

        $formats         = $this->extract_formats_from_post();
        $stats           = WebP_Optimizer::get_stats( $formats );
        $convertible     = array_values( array_intersect( $formats, array( 'image/jpeg', 'image/png', 'image/avif' ) ) );
        $pending_convert = WebP_Optimizer::get_unconverted_count( $convertible );

        wp_send_json_success( array(
            'total'           => $stats['total'],
            'optimized'       => $stats['optimized'],
            'pending'         => $stats['pending'],
            'savings'         => WebP_Optimizer::format_bytes( $stats['savings'] ),
            'pending_convert' => $pending_convert,
        ) );
    }

    public function ajax_save_settings() {
        $this->verify_request();

        $quality    = isset( $_POST['quality'] )    ? min( 95, max( 50, absint( $_POST['quality'] ) ) )    : 80;
        $batch_size = isset( $_POST['batch_size'] ) ? min( 20, max( 1,  absint( $_POST['batch_size'] ) ) ) : 5;
        $backup     = isset( $_POST['backup'] )     && '1' === $_POST['backup'];
        $formats    = $this->extract_formats_from_post();

        update_option( 'webp_optimizer_settings', compact( 'quality', 'batch_size', 'backup', 'formats' ) );

        wp_send_json_success( 'OK' );
    }

    public function ajax_get_convert_batch() {
        $this->verify_request();

        $batch_size = isset( $_POST['batch_size'] ) ? absint( $_POST['batch_size'] ) : 5;
        $formats    = $this->extract_convert_formats_from_post();
        $ids        = WebP_Optimizer::get_unconverted_ids( $batch_size, $formats );
        $total      = WebP_Optimizer::get_unconverted_count( $formats );

        wp_send_json_success( array( 'ids' => $ids, 'total' => (int) $total ) );
    }

    public function ajax_convert_batch() {
        $this->verify_request();

        $ids      = isset( $_POST['ids'] ) ? array_map( 'absint', (array) $_POST['ids'] ) : array();
        $settings = $this->get_settings();
        $quality  = isset( $_POST['quality'] ) ? min( 95, max( 40, absint( $_POST['quality'] ) ) ) : $settings['quality'];
        $backup   = isset( $_POST['backup'] )  ? ( '1' === $_POST['backup'] )                      : $settings['backup'];

        $results = array();

        foreach ( $ids as $id ) {
            $mime = get_post_mime_type( $id );
            if ( ! in_array( $mime, array( 'image/jpeg', 'image/png', 'image/avif' ), true ) ) {
                // Mark as error so this ID is never returned again by get_unconverted_ids().
                update_post_meta( $id, WebP_Optimizer::META_ERROR, 'Formato no convertible: ' . $mime );
                $results[] = array(
                    'id'      => $id,
                    'success' => false,
                    'file'    => basename( (string) get_attached_file( $id ) ),
                    'message' => 'Formato no convertible: ' . $mime,
                );
                continue;
            }

            $from_label = WebP_Optimizer::MIME_LABELS[ $mime ] ?? strtoupper( pathinfo( (string) get_attached_file( $id ), PATHINFO_EXTENSION ) );
            $res        = WebP_Optimizer::convert_to_webp( $id, $quality, $backup );

            if ( is_wp_error( $res ) ) {
                update_post_meta( $id, WebP_Optimizer::META_ERROR, $res->get_error_message() );
                $results[] = array(
                    'id'      => $id,
                    'success' => false,
                    'file'    => basename( (string) get_attached_file( $id ) ),
                    'message' => $res->get_error_message(),
                );
            } else {
                $results[] = array(
                    'id'          => $id,
                    'success'     => true,
                    'file'        => basename( (string) get_attached_file( $id ) ),
                    'from_mime'   => $from_label,
                    'original'    => WebP_Optimizer::format_bytes( $res['original_size'] ),
                    'new'         => WebP_Optimizer::format_bytes( $res['new_size'] ),
                    'savings'     => WebP_Optimizer::format_bytes( $res['savings'] ),
                    'savings_pct' => $res['savings_pct'],
                );
            }
        }

        $settings_arr  = $this->get_settings();
        $stats         = WebP_Optimizer::get_stats( $settings_arr['formats'] );
        $formats       = $this->extract_convert_formats_from_post();
        $pending_conv  = WebP_Optimizer::get_unconverted_count( $formats );
        $errors        = array_filter( $results, function ( $r ) { return ! $r['success']; } );

        wp_send_json_success( array(
            'results' => $results,
            'stats'   => array(
                'total'           => $stats['total'],
                'optimized'       => $stats['optimized'],
                'pending'         => $stats['pending'],
                'savings'         => WebP_Optimizer::format_bytes( $stats['savings'] ),
                'errors'          => count( $errors ),
                'pending_convert' => $pending_conv,
            ),
        ) );
    }

    public function ajax_fix_mime_types() {
        $this->verify_request();

        $fixed          = WebP_Optimizer::fix_mime_types();
        $mismatch_count = WebP_Optimizer::get_mime_mismatch_count();

        wp_send_json_success( array(
            'fixed'         => $fixed,
            'mismatch_count' => $mismatch_count,
        ) );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function verify_request() {
        check_ajax_referer( 'webp_optimizer_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permisos insuficientes.' );
        }
    }

    private function extract_formats_from_post() {
        if ( empty( $_POST['formats'] ) ) {
            return WebP_Optimizer::SUPPORTED_MIMES;
        }
        return array_values(
            array_intersect(
                (array) $_POST['formats'],
                WebP_Optimizer::SUPPORTED_MIMES
            )
        );
    }

    private function extract_convert_formats_from_post() {
        // Derives convertible formats from the general `formats` selection.
        // JPEG, PNG and AVIF can be converted to WebP; WebP and GIF cannot.
        $convertible = array( 'image/jpeg', 'image/png', 'image/avif' );
        if ( empty( $_POST['formats'] ) ) {
            return $convertible;
        }
        return array_values( array_intersect( (array) $_POST['formats'], $convertible ) );
    }

    private function get_settings() {
        return wp_parse_args(
            get_option( 'webp_optimizer_settings', array() ),
            array(
                'quality'    => 80,
                'backup'     => true,
                'batch_size' => 5,
                'formats'    => WebP_Optimizer::SUPPORTED_MIMES,
            )
        );
    }
}
