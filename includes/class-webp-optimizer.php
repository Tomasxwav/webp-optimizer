<?php
/**
 * Core optimization logic — supports WebP, JPEG, PNG, GIF and AVIF.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WebP_Optimizer {

    const META_OPTIMIZED      = '_webp_optimizer_optimized';
    const META_ORIGINAL_SIZE  = '_webp_optimizer_original_size';
    const META_OPTIMIZED_SIZE = '_webp_optimizer_optimized_size';
    const META_DATE           = '_webp_optimizer_date';
    const META_ERROR          = '_webp_optimizer_error'; // skips image on future runs
    const META_CONVERTED      = '_webp_optimizer_converted'; // 'webp' once converted

    /** Hard file-size ceiling: no image on disk should exceed this after processing. */
    const MAX_FILE_BYTES = 614400; // 600 KB

    /** All MIME types this plugin can handle. */
    const SUPPORTED_MIMES = array(
        'image/webp',
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/avif',
    );

    /** Human-readable labels per MIME. */
    const MIME_LABELS = array(
        'image/webp' => 'WebP',
        'image/jpeg' => 'JPEG',
        'image/png'  => 'PNG',
        'image/gif'  => 'GIF',
        'image/avif' => 'AVIF',
    );

    // -------------------------------------------------------------------------
    // Queries
    // -------------------------------------------------------------------------

    /**
     * IDs of image attachments (of given MIME types) that have not been optimized.
     *
     * @param int      $limit
     * @param string[] $formats  MIME types to include.
     * @return int[]
     */
    public static function get_unoptimized_ids( $limit = 5, $formats = array() ) {
        global $wpdb;

        $formats = self::sanitize_formats( $formats );
        if ( empty( $formats ) ) {
            return array();
        }

        $mime_in = self::mime_in_clause( $formats );

        return $wpdb->get_col(
            $wpdb->prepare(
                "SELECT p.ID
                 FROM {$wpdb->posts} p
                 WHERE p.post_type   = 'attachment'
                   AND p.post_status = 'inherit'
                   AND p.post_mime_type IN {$mime_in}
                   AND NOT EXISTS (
                       SELECT 1 FROM {$wpdb->postmeta} pm
                       WHERE pm.post_id    = p.ID
                         AND pm.meta_key   = %s
                         AND pm.meta_value = '1'
                   )
                   AND NOT EXISTS (
                       SELECT 1 FROM {$wpdb->postmeta} pm2
                       WHERE pm2.post_id  = p.ID
                         AND pm2.meta_key = %s
                   )
                 LIMIT %d",
                self::META_OPTIMIZED,
                self::META_ERROR,
                $limit
            )
        );
    }

    /**
     * All image attachment IDs for given MIME types (paged, used in force mode).
     *
     * @param int      $limit
     * @param int      $offset
     * @param string[] $formats
     * @return array { ids: int[], total: int }
     */
    public static function get_all_image_ids( $limit = 5, $offset = 0, $formats = array() ) {
        global $wpdb;

        $formats = self::sanitize_formats( $formats );
        if ( empty( $formats ) ) {
            return array( 'ids' => array(), 'total' => 0 );
        }

        $mime_in = self::mime_in_clause( $formats );

        $total = (int) $wpdb->get_var(
            "SELECT COUNT(ID) FROM {$wpdb->posts}
             WHERE post_type   = 'attachment'
               AND post_status = 'inherit'
               AND post_mime_type IN {$mime_in}"
        );

        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts}
                 WHERE post_type   = 'attachment'
                   AND post_status = 'inherit'
                   AND post_mime_type IN {$mime_in}
                 LIMIT %d OFFSET %d",
                $limit,
                $offset
            )
        );

        return array(
            'ids'   => array_map( 'intval', $ids ),
            'total' => $total,
        );
    }

    /**
     * IDs of JPEG/PNG attachments not yet converted to WebP.
     *
     * @param int $limit
     * @return int[]
     */
    public static function get_unconverted_ids( $limit = 5, $formats = array() ) {
        global $wpdb;

        $allowed     = array( 'image/jpeg', 'image/png', 'image/avif' );
        $convertible = empty( $formats )
            ? $allowed
            : array_values( array_intersect( (array) $formats, $allowed ) );

        if ( empty( $convertible ) ) {
            return array();
        }

        $mime_in = self::mime_in_clause( $convertible );

        return $wpdb->get_col(
            $wpdb->prepare(
                "SELECT p.ID
                 FROM {$wpdb->posts} p
                 WHERE p.post_type   = 'attachment'
                   AND p.post_status = 'inherit'
                   AND p.post_mime_type IN {$mime_in}
                   AND NOT EXISTS (
                       SELECT 1 FROM {$wpdb->postmeta} pm
                       WHERE pm.post_id  = p.ID
                         AND pm.meta_key = %s
                   )
                   AND NOT EXISTS (
                       SELECT 1 FROM {$wpdb->postmeta} pm2
                       WHERE pm2.post_id  = p.ID
                         AND pm2.meta_key = %s
                   )
                 LIMIT %d",
                self::META_CONVERTED,
                self::META_ERROR,
                $limit
            )
        );
    }

    /**
     * Total count of JPEG/PNG attachments not yet converted to WebP.
     *
     * @return int
     */
    public static function get_unconverted_count( $formats = array() ) {
        global $wpdb;

        $allowed     = array( 'image/jpeg', 'image/png', 'image/avif' );
        $convertible = empty( $formats )
            ? $allowed
            : array_values( array_intersect( (array) $formats, $allowed ) );

        if ( empty( $convertible ) ) {
            return 0;
        }

        $mime_in = self::mime_in_clause( $convertible );

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(p.ID)
                 FROM {$wpdb->posts} p
                 WHERE p.post_type   = 'attachment'
                   AND p.post_status = 'inherit'
                   AND p.post_mime_type IN {$mime_in}
                   AND NOT EXISTS (
                       SELECT 1 FROM {$wpdb->postmeta} pm
                       WHERE pm.post_id  = p.ID
                         AND pm.meta_key = %s
                   )
                   AND NOT EXISTS (
                       SELECT 1 FROM {$wpdb->postmeta} pm2
                       WHERE pm2.post_id  = p.ID
                         AND pm2.meta_key = %s
                   )",
                self::META_CONVERTED,
                self::META_ERROR
            )
        );
    }

    /**
     * Global optimization statistics, optionally filtered by MIME types.
     *
     * @param string[] $formats  Leave empty for all supported types.
     * @return array
     */
    public static function get_stats( $formats = array() ) {
        global $wpdb;

        $formats = self::sanitize_formats( $formats );
        if ( empty( $formats ) ) {
            $formats = self::SUPPORTED_MIMES;
        }

        $mime_in = self::mime_in_clause( $formats );

        $total = (int) $wpdb->get_var(
            "SELECT COUNT(ID) FROM {$wpdb->posts}
             WHERE post_type   = 'attachment'
               AND post_status = 'inherit'
               AND post_mime_type IN {$mime_in}"
        );

        $optimized = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT p.ID)
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
                 WHERE p.post_type   = 'attachment'
                   AND p.post_status = 'inherit'
                   AND p.post_mime_type IN {$mime_in}
                   AND pm.meta_key   = %s
                   AND pm.meta_value = '1'",
                self::META_OPTIMIZED
            )
        );

        $errored = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT p.ID)
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
                 WHERE p.post_type   = 'attachment'
                   AND p.post_status = 'inherit'
                   AND p.post_mime_type IN {$mime_in}
                   AND pm.meta_key   = %s",
                self::META_ERROR
            )
        );

        // Count optimized images that belong to the selected formats
        $original_total = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT SUM(pm.meta_value)
                 FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE pm.meta_key = %s
                   AND p.post_mime_type IN {$mime_in}",
                self::META_ORIGINAL_SIZE
            )
        );

        $optimized_total = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT SUM(pm.meta_value)
                 FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE pm.meta_key = %s
                   AND p.post_mime_type IN {$mime_in}",
                self::META_OPTIMIZED_SIZE
            )
        );

        $savings = max( 0, $original_total - $optimized_total );

        return array(
            'total'          => $total,
            'optimized'      => $optimized,
            'errors'         => $errored,
            'pending'        => max( 0, $total - $optimized - $errored ),
            'original_size'  => $original_total,
            'optimized_size' => $optimized_total,
            'savings'        => $savings,
            'savings_pct'    => $original_total > 0
                ? round( ( $savings / $original_total ) * 100, 1 )
                : 0,
        );
    }

    /**
     * Per-format breakdown of total images in the library.
     *
     * @return array  keyed by MIME type: { total, optimized, label }
     */
    public static function get_format_counts() {
        global $wpdb;

        $counts = array();

        foreach ( self::SUPPORTED_MIMES as $mime ) {
            $total = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(ID) FROM {$wpdb->posts}
                     WHERE post_type = 'attachment' AND post_status = 'inherit'
                       AND post_mime_type = %s",
                    $mime
                )
            );

            $optimized = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(DISTINCT p.ID)
                     FROM {$wpdb->posts} p
                     INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
                     WHERE p.post_type   = 'attachment'
                       AND p.post_status = 'inherit'
                       AND p.post_mime_type = %s
                       AND pm.meta_key   = %s
                       AND pm.meta_value = '1'",
                    $mime,
                    self::META_OPTIMIZED
                )
            );

            $counts[ $mime ] = array(
                'label'     => self::MIME_LABELS[ $mime ],
                'total'     => $total,
                'optimized' => $optimized,
            );
        }

        return $counts;
    }

    // -------------------------------------------------------------------------
    // Optimization
    // -------------------------------------------------------------------------

    /**
     * Optimize a single image attachment.
     *
     * Safe flow:
     *   1. Compress to a TEMP file — the original is NEVER touched at this stage.
     *   2. Only if the temp file is smaller: create .bak (if requested) then replace original.
     *   3. Thumbnails follow the same temp → compare → replace pattern.
     *
     * @param int  $attachment_id
     * @param int  $quality  1–100 (PNG ignores this — always max lossless compression).
     * @param bool $backup   Save a .bak copy of the original before replacing.
     * @return array|WP_Error
     */
    public static function optimize_image( $attachment_id, $quality = 80, $backup = true ) {

        $file_path = get_attached_file( $attachment_id );

        if ( ! $file_path || ! file_exists( $file_path ) ) {
            return new WP_Error( 'file_not_found', 'Archivo no encontrado: ' . $file_path );
        }

        if ( ! is_readable( $file_path ) || ! is_writable( $file_path ) ) {
            return new WP_Error( 'permission_denied', 'Sin permisos de lectura/escritura: ' . $file_path );
        }

        @set_time_limit( 60 );

        $ext = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );

        if ( ! in_array( $ext, array( 'webp', 'jpg', 'jpeg', 'png', 'gif', 'avif' ), true ) ) {
            return new WP_Error( 'unsupported', 'Formato no soportado: .' . $ext );
        }

        clearstatcache();
        $original_size = filesize( $file_path );

        // ── Step 1: Compress to temp file (original untouched) ────────────────
        $tmp = self::compress_to_temp( $file_path, $ext, $quality );

        if ( is_wp_error( $tmp ) ) {
            return $tmp;
        }

        // ── Step 1b: Enforce 600 KB cap on lossy formats ──────────────────────
        if ( in_array( $ext, array( 'jpg', 'jpeg', 'webp', 'avif' ), true ) ) {
            $cap_q = $quality - 5;
            while ( $cap_q >= 50 ) {
                clearstatcache();
                if ( (int) @filesize( $tmp ) <= self::MAX_FILE_BYTES ) {
                    break;
                }
                $retry = self::compress_to_temp( $file_path, $ext, $cap_q );
                if ( ! is_wp_error( $retry ) ) {
                    clearstatcache();
                    if ( (int) @filesize( $retry ) < (int) @filesize( $tmp ) ) {
                        @unlink( $tmp );
                        $tmp = $retry;
                    } else {
                        @unlink( $retry );
                        break;
                    }
                }
                $cap_q -= 5;
            }
        }

        clearstatcache();
        $new_size = filesize( $tmp );

        if ( $new_size <= 0 || $new_size >= $original_size ) {
            // Compressed file is not smaller — discard temp, keep original as-is.
            @unlink( $tmp );
            $new_size = $original_size;
        } else {
            // ── Step 2: Create .bak BEFORE touching the original ──────────────
            $backup_path = $file_path . '.bak';
            if ( $backup && ! file_exists( $backup_path ) ) {
                if ( ! @copy( $file_path, $backup_path ) ) {
                    @unlink( $tmp );
                    return new WP_Error(
                        'backup_failed',
                        'No se pudo crear la copia de seguridad. Comprueba los permisos del directorio.'
                    );
                }
            }

            // ── Step 3: Atomically replace original with compressed temp ──────
            if ( ! self::atomic_replace( $tmp, $file_path ) ) {
                @unlink( $tmp );
                // Rollback backup if we couldn't replace
                if ( $backup && file_exists( $backup_path ) ) {
                    @unlink( $backup_path );
                }
                return new WP_Error( 'write_failed', 'No se pudo escribir el archivo comprimido.' );
            }
        }

        // ── Step 4: Compress thumbnails (same safe pattern) ───────────────────
        $metadata = wp_get_attachment_metadata( $attachment_id );
        if ( is_array( $metadata ) && ! empty( $metadata['sizes'] ) ) {
            $upload_dir = dirname( $file_path );
            foreach ( $metadata['sizes'] as $size_data ) {
                $thumb     = $upload_dir . '/' . $size_data['file'];
                $thumb_ext = strtolower( pathinfo( $thumb, PATHINFO_EXTENSION ) );

                if ( ! file_exists( $thumb ) ) { continue; }
                if ( ! in_array( $thumb_ext, array( 'webp', 'jpg', 'jpeg', 'png', 'gif', 'avif' ), true ) ) { continue; }

                $thumb_tmp = self::compress_to_temp( $thumb, $thumb_ext, $quality );
                if ( is_wp_error( $thumb_tmp ) ) { continue; } // non-critical; skip this thumbnail

                clearstatcache();
                $thumb_new_size = filesize( $thumb_tmp );

                if ( $thumb_new_size > 0 && $thumb_new_size < filesize( $thumb ) ) {
                    if ( $backup ) {
                        $thumb_bak = $thumb . '.bak';
                        if ( ! file_exists( $thumb_bak ) ) {
                            @copy( $thumb, $thumb_bak ); // best-effort; failure is non-critical
                        }
                    }
                    self::atomic_replace( $thumb_tmp, $thumb );
                } else {
                    @unlink( $thumb_tmp );
                }
            }
        }

        // ── Step 5: Update WordPress filesize metadata (WP 6.0+) ─────────────
        clearstatcache();
        if ( is_array( $metadata ) && isset( $metadata['filesize'] ) ) {
            $metadata['filesize'] = filesize( $file_path );
            wp_update_attachment_metadata( $attachment_id, $metadata );
        }

        // ── Step 6: Store plugin meta ─────────────────────────────────────────
        update_post_meta( $attachment_id, self::META_OPTIMIZED, '1' );

        // META_ORIGINAL_SIZE is written only ONCE (first optimization) so that
        // savings always reflect reduction from the real original upload size.
        $stored_original = get_post_meta( $attachment_id, self::META_ORIGINAL_SIZE, true );
        if ( '' === $stored_original || false === $stored_original ) {
            update_post_meta( $attachment_id, self::META_ORIGINAL_SIZE, $original_size );
        }

        update_post_meta( $attachment_id, self::META_OPTIMIZED_SIZE, $new_size );
        update_post_meta( $attachment_id, self::META_DATE,           current_time( 'mysql' ) );
        delete_post_meta( $attachment_id, self::META_ERROR ); // clear any previous error flag

        // Compute savings against the preserved true original.
        $true_original = (int) get_post_meta( $attachment_id, self::META_ORIGINAL_SIZE, true );
        if ( $true_original > 0 ) {
            $original_size = $true_original;
        }

        $savings     = max( 0, $original_size - $new_size );
        $savings_pct = $original_size > 0
            ? round( ( $savings / $original_size ) * 100, 2 )
            : 0;

        return array(
            'id'            => $attachment_id,
            'original_size' => $original_size,
            'new_size'      => $new_size,
            'savings'       => $savings,
            'savings_pct'   => $savings_pct,
        );
    }

    // -------------------------------------------------------------------------
    // Format-specific compressors
    // -------------------------------------------------------------------------

    /**
     * Compress $input and write the result to a unique temp file.
     * The original $input is NEVER modified.
     *
     * @param string $input    Path of the source image.
     * @param string $ext      Extension: webp|jpg|jpeg|png|gif|avif
     * @param int    $quality
     * @return string|WP_Error  Path to the temp file on success, WP_Error on failure.
     */
    private static function compress_to_temp( $input, $ext, $quality ) {
        $tmp = $input . '.opt_' . uniqid() . '.tmp';

        switch ( $ext ) {
            case 'webp':
                $result = self::compress_webp( $input, $tmp, $quality );
                break;
            case 'jpg':
            case 'jpeg':
                $result = self::compress_jpeg( $input, $tmp, $quality );
                break;
            case 'png':
                $result = self::compress_png( $input, $tmp );
                break;
            case 'gif':
                $result = self::compress_gif( $input, $tmp );
                break;
            case 'avif':
                $result = self::compress_avif( $input, $tmp, $quality );
                break;
            default:
                return new WP_Error( 'unsupported_ext', 'Extensión no soportada: ' . $ext );
        }

        if ( is_wp_error( $result ) ) {
            @unlink( $tmp );
            return $result;
        }

        if ( ! file_exists( $tmp ) ) {
            return new WP_Error( 'compress_no_output', 'El compresor no generó ningún archivo de salida.' );
        }

        return $tmp;
    }

    /**
     * Atomically replace $destination with $source.
     * Uses rename() (preferred, atomic on same filesystem) with a copy() fallback.
     * Cleans up $source on failure.
     *
     * @return bool
     */
    private static function atomic_replace( $source, $destination ) {
        if ( @rename( $source, $destination ) ) {
            return true;
        }
        // rename() fails across different filesystem mounts — fall back to copy+delete.
        if ( @copy( $source, $destination ) ) {
            @unlink( $source );
            return true;
        }
        return false;
    }

    /** Compress WebP → $output using GD or ImageMagick. */
    private static function compress_webp( $input, $output, $quality ) {
        if ( function_exists( 'imagecreatefromwebp' ) && function_exists( 'imagewebp' ) ) {
            $img = @imagecreatefromwebp( $input );
            if ( $img !== false ) {
                imagealphablending( $img, false );
                imagesavealpha( $img, true );
                $ok = imagewebp( $img, $output, $quality );
                imagedestroy( $img );
                if ( $ok ) { return true; }
            }
        }
        return self::imagick_compress( $input, $output, $quality, 'webp' );
    }

    /** Compress JPEG → $output using GD or ImageMagick. */
    private static function compress_jpeg( $input, $output, $quality ) {
        if ( function_exists( 'imagecreatefromjpeg' ) && function_exists( 'imagejpeg' ) ) {
            $img = @imagecreatefromjpeg( $input );
            if ( $img !== false ) {
                $ok = imagejpeg( $img, $output, $quality );
                imagedestroy( $img );
                if ( $ok ) { return true; }
            }
        }
        return self::imagick_compress( $input, $output, $quality, 'jpeg' );
    }

    /**
     * Compress PNG → $output using GD or ImageMagick.
     * PNG is lossless; applies maximum zlib compression.
     */
    private static function compress_png( $input, $output ) {
        if ( function_exists( 'imagecreatefrompng' ) && function_exists( 'imagepng' ) ) {
            $img = @imagecreatefrompng( $input );
            if ( $img !== false ) {
                imagealphablending( $img, false );
                imagesavealpha( $img, true );
                $ok = imagepng( $img, $output, 9, PNG_ALL_FILTERS );
                imagedestroy( $img );
                if ( $ok ) { return true; }
            }
        }
        return self::imagick_compress( $input, $output, 95, 'png' );
    }

    /**
     * Re-save static GIF → $output using GD or ImageMagick.
     * Animated GIFs are skipped to prevent frame loss.
     */
    private static function compress_gif( $input, $output ) {
        if ( self::is_animated_gif( $input ) ) {
            return new WP_Error( 'animated_gif', 'GIF animado omitido (se evita pérdida de fotogramas).' );
        }
        if ( function_exists( 'imagecreatefromgif' ) && function_exists( 'imagegif' ) ) {
            $img = @imagecreatefromgif( $input );
            if ( $img !== false ) {
                $ok = imagegif( $img, $output );
                imagedestroy( $img );
                if ( $ok ) { return true; }
            }
        }
        return self::imagick_compress( $input, $output, 85, 'gif' );
    }

    /** Compress AVIF → $output using GD (PHP 8.1+) or ImageMagick. */
    private static function compress_avif( $input, $output, $quality ) {
        if ( function_exists( 'imagecreatefromavif' ) && function_exists( 'imageavif' ) ) {
            $img = @imagecreatefromavif( $input );
            if ( $img !== false ) {
                imagealphablending( $img, false );
                imagesavealpha( $img, true );
                $ok = imageavif( $img, $output, $quality, 6 );
                imagedestroy( $img );
                if ( $ok ) { return true; }
            }
        }
        if ( class_exists( 'Imagick' ) ) {
            try {
                $imagick = new Imagick( $input );
                $imagick->setImageCompressionQuality( $quality );
                $imagick->setImageFormat( 'avif' );
                $imagick->writeImage( $output );
                $imagick->destroy();
                return true;
            } catch ( Exception $e ) {
                return new WP_Error( 'imagick_avif_error', $e->getMessage() );
            }
        }
        return new WP_Error(
            'no_avif_support',
            'AVIF requiere PHP 8.1+ con libavif o ImageMagick con soporte AVIF.'
        );
    }

    /**
     * Shared ImageMagick compressor — reads from $input, writes to $output.
     * The $input file is never modified.
     */
    private static function imagick_compress( $input, $output, $quality, $format ) {
        if ( ! class_exists( 'Imagick' ) ) {
            return new WP_Error(
                'no_image_library',
                'No se encontró GD ni ImageMagick. Activa una de estas extensiones PHP.'
            );
        }
        try {
            $imagick = new Imagick( $input );
            $imagick->setImageCompressionQuality( $quality );
            $imagick->setImageFormat( $format );
            $imagick->writeImage( $output );
            $imagick->destroy();
            return true;
        } catch ( Exception $e ) {
            return new WP_Error( 'imagick_error', $e->getMessage() );
        }
    }

    // -------------------------------------------------------------------------
    // WebP Conversion (JPEG / PNG → WebP)
    // -------------------------------------------------------------------------

    /**
     * Convert a JPEG or PNG attachment to WebP and update all WordPress metadata.
     * Automatically reduces quality until the result is under MAX_FILE_BYTES (600 KB).
     *
     * @param int  $attachment_id
     * @param int  $quality  Starting quality (40–95).
     * @param bool $backup   Keep a .bak copy of the original file.
     * @return array|WP_Error
     */
    public static function convert_to_webp( $attachment_id, $quality = 80, $backup = true ) {

        $file_path = get_attached_file( $attachment_id );

        if ( ! $file_path || ! file_exists( $file_path ) ) {
            return new WP_Error( 'file_not_found', 'Archivo no encontrado: ' . $file_path );
        }

        if ( ! is_readable( $file_path ) || ! is_writable( dirname( $file_path ) ) ) {
            return new WP_Error( 'permission_denied', 'Sin permisos de lectura/escritura.' );
        }

        @set_time_limit( 60 );

        $ext = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
        if ( ! in_array( $ext, array( 'jpg', 'jpeg', 'png', 'avif' ), true ) ) {
            return new WP_Error( 'not_convertible', 'Solo se convierten JPEG, PNG y AVIF a WebP.' );
        }

        clearstatcache();
        $original_size = filesize( $file_path );

        $webp_path = preg_replace( '/\.(jpe?g|png|avif)$/i', '.webp', $file_path );

        $result = self::convert_source_to_webp( $file_path, $webp_path, $quality );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        clearstatcache();
        $new_size = filesize( $webp_path );

        // Backup original before deleting it
        if ( $backup ) {
            $bak = $file_path . '.bak';
            if ( ! file_exists( $bak ) && ! @copy( $file_path, $bak ) ) {
                @unlink( $webp_path );
                return new WP_Error( 'backup_failed', 'No se pudo crear la copia de seguridad.' );
            }
        }

        // ── Update WordPress database ─────────────────────────────────────────
        // (el archivo original se borra DESPUÉS de que la BD esté completamente actualizada)

        // 1. _wp_attached_file (relative path from uploads root)
        $upload_info  = wp_upload_dir();
        $base         = trailingslashit( $upload_info['basedir'] );
        $new_relative = str_replace( '\\', '/', str_replace( $base, '', $webp_path ) );
        update_post_meta( $attachment_id, '_wp_attached_file', $new_relative );

        // 2. post_mime_type
        global $wpdb;
        $wpdb->update(
            $wpdb->posts,
            array( 'post_mime_type' => 'image/webp' ),
            array( 'ID' => $attachment_id ),
            array( '%s' ),
            array( '%d' )
        );

        // 3. Attachment metadata: main file path + all thumbnail files
        $metadata = wp_get_attachment_metadata( $attachment_id );
        if ( is_array( $metadata ) ) {
            if ( isset( $metadata['file'] ) ) {
                $metadata['file'] = preg_replace( '/\.(jpe?g|png|avif)$/i', '.webp', $metadata['file'] );
            }
            $metadata['filesize'] = $new_size;

            if ( ! empty( $metadata['sizes'] ) ) {
                $upload_dir_path = dirname( $file_path );
                foreach ( $metadata['sizes'] as $size_key => $size_data ) {
                    $thumb      = $upload_dir_path . '/' . $size_data['file'];
                    $thumb_webp = preg_replace( '/\.(jpe?g|png|avif)$/i', '.webp', $thumb );

                    if ( ! file_exists( $thumb ) ) {
                        continue;
                    }

                    $t_result = self::convert_source_to_webp( $thumb, $thumb_webp, $quality );
                    if ( ! is_wp_error( $t_result ) ) {
                        if ( $backup ) {
                            $t_bak = $thumb . '.bak';
                            if ( ! file_exists( $t_bak ) ) {
                                @copy( $thumb, $t_bak );
                            }
                        }
                        @unlink( $thumb );
                        $metadata['sizes'][ $size_key ]['file']      = preg_replace( '/\.(jpe?g|png|avif)$/i', '.webp', $size_data['file'] );
                        $metadata['sizes'][ $size_key ]['mime-type'] = 'image/webp';
                        clearstatcache();
                        if ( isset( $metadata['sizes'][ $size_key ]['filesize'] ) ) {
                            $metadata['sizes'][ $size_key ]['filesize'] = (int) @filesize( $thumb_webp );
                        }
                    }
                }
            }

            wp_update_attachment_metadata( $attachment_id, $metadata );
        }

        // Eliminar el original solo después de que la BD esté completamente actualizada
        @unlink( $file_path );

        // ── Plugin meta ───────────────────────────────────────────────────────
        update_post_meta( $attachment_id, self::META_OPTIMIZED,  '1' );
        update_post_meta( $attachment_id, self::META_CONVERTED,  'webp' );

        $stored_original = get_post_meta( $attachment_id, self::META_ORIGINAL_SIZE, true );
        if ( '' === $stored_original || false === $stored_original ) {
            update_post_meta( $attachment_id, self::META_ORIGINAL_SIZE, $original_size );
        }

        update_post_meta( $attachment_id, self::META_OPTIMIZED_SIZE, $new_size );
        update_post_meta( $attachment_id, self::META_DATE,            current_time( 'mysql' ) );
        delete_post_meta( $attachment_id, self::META_ERROR );

        $true_original = (int) get_post_meta( $attachment_id, self::META_ORIGINAL_SIZE, true );
        if ( $true_original > 0 ) {
            $original_size = $true_original;
        }

        $savings     = max( 0, $original_size - $new_size );
        $savings_pct = $original_size > 0 ? round( ( $savings / $original_size ) * 100, 2 ) : 0;

        return array(
            'id'            => $attachment_id,
            'original_size' => $original_size,
            'new_size'      => $new_size,
            'savings'       => $savings,
            'savings_pct'   => $savings_pct,
        );
    }

    /**
     * Write a WebP version of $input to $output.
     * Reduces quality by 5 each iteration until the result is under MAX_FILE_BYTES.
     */
    private static function convert_source_to_webp( $input, $output, $quality ) {
        $min_quality = 40;

        do {
            $tmp    = $output . '.conv_' . uniqid() . '.tmp';
            $result = self::image_to_webp( $input, $tmp, $quality );

            if ( is_wp_error( $result ) ) {
                @unlink( $tmp );
                return $result;
            }

            clearstatcache();
            $size = (int) @filesize( $tmp );

            if ( $size <= 0 ) {
                @unlink( $tmp );
                return new WP_Error( 'empty_output', 'El convertidor no generó ningún archivo.' );
            }

            if ( $size <= self::MAX_FILE_BYTES || $quality <= $min_quality ) {
                if ( ! self::atomic_replace( $tmp, $output ) ) {
                    @unlink( $tmp );
                    return new WP_Error( 'write_failed', 'No se pudo escribir el archivo WebP convertido.' );
                }
                return true;
            }

            @unlink( $tmp );
            $quality -= 5;

        } while ( $quality >= $min_quality );

        return new WP_Error( 'conversion_failed', 'No se pudo convertir la imagen.' );
    }

    /**
     * Encode $input (JPEG or PNG) as WebP and save to $output.
     * Uses GD when available, falls back to ImageMagick. Source is never modified.
     */
    private static function image_to_webp( $input, $output, $quality ) {
        $ext = strtolower( pathinfo( $input, PATHINFO_EXTENSION ) );
        $img = null;

        if ( $ext === 'png' && function_exists( 'imagecreatefrompng' ) ) {
            $img = @imagecreatefrompng( $input );
            if ( $img ) {
                imagealphablending( $img, false );
                imagesavealpha( $img, true );
            }
        } elseif ( in_array( $ext, array( 'jpg', 'jpeg' ), true ) && function_exists( 'imagecreatefromjpeg' ) ) {
            $img = @imagecreatefromjpeg( $input );
        } elseif ( $ext === 'avif' && function_exists( 'imagecreatefromavif' ) ) {
            $img = @imagecreatefromavif( $input );
            if ( $img ) {
                imagealphablending( $img, false );
                imagesavealpha( $img, true );
            }
        }

        if ( $img && function_exists( 'imagewebp' ) ) {
            $ok = imagewebp( $img, $output, $quality );
            imagedestroy( $img );
            if ( $ok ) {
                return true;
            }
        } elseif ( $img ) {
            imagedestroy( $img );
        }

        if ( class_exists( 'Imagick' ) ) {
            try {
                $imagick = new Imagick( $input );
                $imagick->setImageCompressionQuality( $quality );
                $imagick->setImageFormat( 'webp' );
                $imagick->writeImage( $output );
                $imagick->destroy();
                return true;
            } catch ( Exception $e ) {
                return new WP_Error( 'imagick_error', $e->getMessage() );
            }
        }

        return new WP_Error( 'no_webp_support', 'Se necesita GD con soporte WebP o ImageMagick para convertir.' );
    }

    // -------------------------------------------------------------------------
    // Restore / Reset
    // -------------------------------------------------------------------------

    /**
     * Restore an image (and its thumbnails) from .bak backups.
     * Updates WordPress filesize metadata. Clears all plugin meta.
     *
     * @param int $attachment_id
     * @return true|WP_Error
     */
    public static function restore_image( $attachment_id ) {
        $file_path     = get_attached_file( $attachment_id );
        $was_converted = ( get_post_meta( $attachment_id, self::META_CONVERTED, true ) === 'webp' );

        if ( ! $file_path ) {
            return new WP_Error( 'invalid_attachment', 'No se pudo obtener la ruta del adjunto.' );
        }

        if ( $was_converted ) {
            // Tras la conversión, $file_path apunta al .webp.
            // El backup se guardó en la ruta ORIGINAL + .bak (ej: image.jpg.bak).
            // Lo buscamos probando cada posible extensión original.
            $original_path = null;
            $backup_path   = null;

            foreach ( array( 'jpg', 'jpeg', 'png', 'avif' ) as $try_ext ) {
                $candidate     = preg_replace( '/\.webp$/i', '.' . $try_ext, $file_path );
                $candidate_bak = $candidate . '.bak';
                if ( file_exists( $candidate_bak ) ) {
                    $original_path = $candidate;
                    $backup_path   = $candidate_bak;
                    break;
                }
            }

            if ( ! $backup_path ) {
                return new WP_Error( 'no_backup', 'No hay copia de seguridad (.bak) para este archivo.' );
            }

            // Restaurar archivo principal desde el backup
            if ( ! @copy( $backup_path, $original_path ) ) {
                return new WP_Error( 'restore_failed', 'No se pudo restaurar el archivo original. Comprueba los permisos.' );
            }
            @unlink( $backup_path );
            @unlink( $file_path ); // Eliminar el WebP

            // Restaurar la base de datos de WordPress
            $orig_ext   = strtolower( pathinfo( $original_path, PATHINFO_EXTENSION ) );
            $mime_map   = array( 'png' => 'image/png', 'avif' => 'image/avif', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg' );
            $orig_mime  = $mime_map[ $orig_ext ] ?? 'image/jpeg';

            global $wpdb;
            $wpdb->update(
                $wpdb->posts,
                array( 'post_mime_type' => $orig_mime ),
                array( 'ID' => $attachment_id ),
                array( '%s' ),
                array( '%d' )
            );

            $upload_info   = wp_upload_dir();
            $base          = trailingslashit( $upload_info['basedir'] );
            $orig_relative = str_replace( '\\', '/', str_replace( $base, '', $original_path ) );
            update_post_meta( $attachment_id, '_wp_attached_file', $orig_relative );

            $metadata = wp_get_attachment_metadata( $attachment_id );
            if ( is_array( $metadata ) ) {
                if ( isset( $metadata['file'] ) ) {
                    $metadata['file'] = $orig_relative;
                }

                if ( ! empty( $metadata['sizes'] ) ) {
                    $upload_dir_path = dirname( $original_path );
                    foreach ( $metadata['sizes'] as $size_key => $size_data ) {
                        $webp_thumb = $upload_dir_path . '/' . $size_data['file'];
                        foreach ( array( 'jpg', 'jpeg', 'png', 'avif' ) as $te ) {
                            $orig_thumb     = preg_replace( '/\.webp$/i', '.' . $te, $webp_thumb );
                            $orig_thumb_bak = $orig_thumb . '.bak';
                            if ( file_exists( $orig_thumb_bak ) ) {
                                if ( @copy( $orig_thumb_bak, $orig_thumb ) ) {
                                    @unlink( $orig_thumb_bak );
                                    @unlink( $webp_thumb );
                                    $metadata['sizes'][ $size_key ]['file']      = preg_replace( '/\.webp$/i', '.' . $te, $size_data['file'] );
                                    $metadata['sizes'][ $size_key ]['mime-type'] = $orig_mime;
                                    clearstatcache();
                                    if ( isset( $metadata['sizes'][ $size_key ]['filesize'] ) ) {
                                        $metadata['sizes'][ $size_key ]['filesize'] = (int) @filesize( $orig_thumb );
                                    }
                                }
                                break;
                            }
                        }
                    }
                }

                clearstatcache();
                if ( isset( $metadata['filesize'] ) ) {
                    $metadata['filesize'] = (int) @filesize( $original_path );
                }
                wp_update_attachment_metadata( $attachment_id, $metadata );
            }

        } else {
            // Restauración estándar: la imagen solo fue comprimida, no convertida.
            $backup_path = $file_path . '.bak';

            if ( ! file_exists( $backup_path ) ) {
                return new WP_Error( 'no_backup', 'No hay copia de seguridad (.bak) para este archivo.' );
            }

            if ( ! @copy( $backup_path, $file_path ) ) {
                return new WP_Error( 'restore_failed', 'No se pudo restaurar el archivo original. Comprueba los permisos.' );
            }
            @unlink( $backup_path );

            $metadata = wp_get_attachment_metadata( $attachment_id );
            if ( is_array( $metadata ) && ! empty( $metadata['sizes'] ) ) {
                $upload_dir = dirname( $file_path );
                foreach ( $metadata['sizes'] as $size_data ) {
                    $thumb     = $upload_dir . '/' . $size_data['file'];
                    $thumb_bak = $thumb . '.bak';
                    if ( file_exists( $thumb_bak ) ) {
                        if ( @copy( $thumb_bak, $thumb ) ) {
                            @unlink( $thumb_bak );
                        }
                    }
                }
            }

            clearstatcache();
            if ( is_array( $metadata ) && isset( $metadata['filesize'] ) ) {
                $metadata['filesize'] = filesize( $file_path );
                wp_update_attachment_metadata( $attachment_id, $metadata );
            }
        }

        // Limpiar todo el meta del plugin — la imagen vuelve a su estado original
        foreach ( array(
            self::META_OPTIMIZED,
            self::META_ORIGINAL_SIZE,
            self::META_OPTIMIZED_SIZE,
            self::META_DATE,
            self::META_ERROR,
            self::META_CONVERTED,
        ) as $key ) {
            delete_post_meta( $attachment_id, $key );
        }

        return true;
    }

    /**
     * Delete all optimization post meta (does NOT touch actual files).
     */
    public static function reset_all() {
        global $wpdb;

        foreach ( array(
            self::META_OPTIMIZED,
            self::META_ORIGINAL_SIZE,
            self::META_OPTIMIZED_SIZE,
            self::META_DATE,
            self::META_ERROR,
            self::META_CONVERTED,
        ) as $key ) {
            $wpdb->delete( $wpdb->postmeta, array( 'meta_key' => $key ), array( '%s' ) );
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Format bytes into a human-readable string.
     */
    public static function format_bytes( $bytes ) {
        $bytes = (int) $bytes;
        if ( $bytes >= 1048576 ) { return round( $bytes / 1048576, 2 ) . ' MB'; }
        if ( $bytes >= 1024 )    { return round( $bytes / 1024,    2 ) . ' KB'; }
        return $bytes . ' B';
    }

    /**
     * Check which image processing libraries are available.
     *
     * @return array { gd: bool, gd_avif: bool, imagick: bool, imagick_avif: bool }
     */
    public static function check_libraries() {
        $gd      = function_exists( 'imagecreatefromwebp' );
        $gd_avif = function_exists( 'imagecreatefromavif' ) && function_exists( 'imageavif' );

        $imagick      = false;
        $imagick_avif = false;
        if ( class_exists( 'Imagick' ) ) {
            $imagick = true;
            try {
                $formats      = ( new Imagick() )->queryFormats( 'AVIF' );
                $imagick_avif = ! empty( $formats );
            } catch ( Exception $e ) {
                $imagick_avif = false;
            }
        }

        return compact( 'gd', 'gd_avif', 'imagick', 'imagick_avif' );
    }

    /**
     * Detect whether a GIF file contains multiple frames (animated).
     */
    private static function is_animated_gif( $file_path ) {
        $fh = @fopen( $file_path, 'rb' );
        if ( ! $fh ) {
            return false;
        }

        $count  = 0;
        $read   = 0;
        $max    = 1048576; // 1 MB máx para detectar animación
        $needle = "\x00\x21\xF9\x04";
        $carry  = ''; // últimos 3 bytes del chunk anterior para no perder coincidencias en el límite

        while ( ! feof( $fh ) && $read < $max ) {
            $chunk  = fread( $fh, 4096 );
            $search = $carry . $chunk;
            $count += substr_count( $search, $needle );
            if ( $count > 1 ) {
                break;
            }
            $carry  = substr( $chunk, -3 );
            $read  += strlen( $chunk );
        }

        fclose( $fh );
        return $count > 1;
    }

    /**
     * Validate and filter a list of MIME types against the supported set.
     *
     * @param string[] $formats
     * @return string[]
     */
    private static function sanitize_formats( $formats ) {
        if ( empty( $formats ) ) {
            return self::SUPPORTED_MIMES;
        }
        return array_values(
            array_intersect( (array) $formats, self::SUPPORTED_MIMES )
        );
    }

    /**
     * Build a safe SQL IN(...) clause from an array of MIME strings.
     * Values are escaped individually with esc_sql.
     *
     * @param string[] $formats  Already validated against SUPPORTED_MIMES.
     * @return string  e.g. ('image/webp','image/jpeg')
     */
    private static function mime_in_clause( array $formats ) {
        $escaped = array_map( function ( $m ) {
            return "'" . esc_sql( $m ) . "'";
        }, $formats );
        return '(' . implode( ',', $escaped ) . ')';
    }

    // -------------------------------------------------------------------------
    // MIME Type Repair
    // -------------------------------------------------------------------------

    /**
     * Count attachments where post_mime_type doesn't match the actual file extension.
     *
     * @return int
     */
    public static function get_mime_mismatch_count() {
        global $wpdb;
        return (int) $wpdb->get_var( self::mime_mismatch_sql( 'COUNT(DISTINCT p.ID)' ) );
    }

    /**
     * Fix post_mime_type for all attachments where it doesn't match the file extension.
     *
     * @return int  Number of records corrected.
     */
    public static function fix_mime_types() {
        global $wpdb;

        $rows = $wpdb->get_results(
            self::mime_mismatch_sql( 'p.ID, pm.meta_value AS file_path, p.post_mime_type AS stored_mime' )
        );

        if ( empty( $rows ) ) {
            return 0;
        }

        $ext_to_mime = array(
            'webp' => 'image/webp',
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'gif'  => 'image/gif',
            'avif' => 'image/avif',
        );

        $fixed = 0;
        foreach ( $rows as $row ) {
            $ext     = strtolower( pathinfo( $row->file_path, PATHINFO_EXTENSION ) );
            $correct = isset( $ext_to_mime[ $ext ] ) ? $ext_to_mime[ $ext ] : null;
            if ( ! $correct || $correct === $row->stored_mime ) {
                continue;
            }
            $wpdb->update(
                $wpdb->posts,
                array( 'post_mime_type' => $correct ),
                array( 'ID' => (int) $row->ID ),
                array( '%s' ),
                array( '%d' )
            );
            clean_post_cache( (int) $row->ID );
            $fixed++;
        }

        return $fixed;
    }

    /**
     * Build the base SQL to detect MIME type vs file-extension mismatches.
     * Only called internally with hardcoded $select strings — not user input.
     *
     * @param string $select  Column list (hardcoded by caller).
     * @return string
     */
    private static function mime_mismatch_sql( $select ) {
        global $wpdb;

        // Conditions: file ends with an extension whose expected MIME differs from stored MIME.
        $conditions = array(
            "(pm.meta_value LIKE '%.webp'  AND p.post_mime_type != 'image/webp')",
            "(pm.meta_value LIKE '%.jpg'   AND p.post_mime_type != 'image/jpeg')",
            "(pm.meta_value LIKE '%.jpeg'  AND p.post_mime_type != 'image/jpeg')",
            "(pm.meta_value LIKE '%.png'   AND p.post_mime_type != 'image/png')",
            "(pm.meta_value LIKE '%.gif'   AND p.post_mime_type != 'image/gif')",
            "(pm.meta_value LIKE '%.avif'  AND p.post_mime_type != 'image/avif')",
        );

        $where = implode( ' OR ', $conditions );

        return "SELECT {$select}
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
                WHERE p.post_type   = 'attachment'
                  AND p.post_status = 'inherit'
                  AND pm.meta_key   = '_wp_attached_file'
                  AND ( {$where} )";
    }

    // -------------------------------------------------------------------------
    // Lifecycle
    // -------------------------------------------------------------------------

    public static function activate() {}
    public static function deactivate() {}
}
