/* global jQuery, webpOptimizer */
(function ($) {
    'use strict';

    var BATCH_SIZE = 5;

    // ── State ────────────────────────────────────────────────────────────────
    var state = {
        running:        false,
        stop:           false,
        processed:      0,
        total:          0,
        offset:         0,
        phase:          'optimize', // 'optimize' | 'convert'
        convertAfter:   false,
        force:          false,
        quality:        parseInt( webpOptimizer.settings.quality, 10 ) || 80,
        backup:         !! webpOptimizer.settings.backup,
        formats:        webpOptimizer.settings.formats || webpOptimizer.allFormats,
        lastPending:    -1,
        stuckCount:     0,
    };

    // ── DOM refs ─────────────────────────────────────────────────────────────
    var $progressSection = $( '#webp-progress-section' );
    var $progressFill    = $( '#webp-progress-bar' );
    var $progressPct     = $( '#webp-progress-pct' );
    var $progressCount   = $( '#webp-progress-count' );
    var $progressTotal   = $( '#webp-progress-total' );
    var $progressStatus  = $( '#webp-progress-status' );
    var $statsGrid       = $( '#webp-stats' );
    var $logContent      = $( '#webp-log-content' );
    var $btnOptimize     = $( '#btn-optimize' );
    var $btnOptConvert   = $( '#btn-optimize-convert' );
    var $btnStop         = $( '#btn-stop' );
    var $btnFixMime      = $( '#btn-fix-mime' );
    var $mimeBadge       = $( '#webp-mime-count' );

    // ── Controls ─────────────────────────────────────────────────────────────

    $( '#webp-quality' ).on( 'input', function () {
        state.quality = parseInt( $( this ).val(), 10 );
        $( '#webp-quality-val' ).text( state.quality + '%' );
        autoSave();
    } );

    $( '#webp-backup' ).on( 'change', function () {
        state.backup = $( this ).is( ':checked' );
        autoSave();
    } );

    $( '.webp-fmt' ).on( 'change', function () {
        state.formats = getFormats();
        autoSave();
        refreshStats();
    } );

    $( '#webp-scope' ).on( 'change', function () {
        refreshStats();
    } );

    $( '#btn-clear-log' ).on( 'click', function () {
        $logContent.html( '<p class="webp-log-placeholder">Registro limpiado.</p>' );
    } );

    $btnFixMime.on( 'click', function () {
        if ( state.running ) { return; }

        $btnFixMime.prop( 'disabled', true );
        addLog( 'info', '─── Reparando MIME types incorrectos… ───' );

        $.post( webpOptimizer.ajaxUrl, {
            action: 'webp_optimizer_fix_mime_types',
            nonce:  webpOptimizer.nonce,
        }, function ( res ) {
            if ( res.success ) {
                var fixed     = parseInt( res.data.fixed, 10 ) || 0;
                var remaining = parseInt( res.data.mismatch_count, 10 ) || 0;
                if ( fixed > 0 ) {
                    addLog( 'success', fixed + ' MIME type(s) corregido(s) correctamente.' );
                } else {
                    addLog( 'info', 'No se encontraron desajustes de MIME type.' );
                }
                $mimeBadge.text( remaining );
                if ( remaining > 0 ) {
                    $mimeBadge.show();
                    $btnFixMime.prop( 'disabled', false );
                } else {
                    $mimeBadge.hide();
                    $btnFixMime.prop( 'disabled', true );
                }
            } else {
                addLog( 'error', 'Error al reparar MIME types: ' + ( res.data || '' ) );
                $btnFixMime.prop( 'disabled', false );
            }
        } ).fail( function () {
            addLog( 'error', 'Error de conexión al reparar MIME types.' );
            $btnFixMime.prop( 'disabled', false );
        } );
    } );

    $btnStop.on( 'click', function () {
        state.stop = true;
        $btnStop.prop( 'disabled', true ).text( webpOptimizer.i18n.stopping );
    } );

    // ── Action buttons ────────────────────────────────────────────────────────

    $btnOptimize.on( 'click', function () {
        if ( state.running ) { return; }
        state.convertAfter = false;
        state.force        = $( '#webp-scope' ).val() === 'all';
        startFlow();
    } );

    $btnOptConvert.on( 'click', function () {
        if ( state.running ) { return; }
        if ( ! confirm( webpOptimizer.i18n.confirmConvert ) ) { return; }
        state.convertAfter = true;
        state.force        = $( '#webp-scope' ).val() === 'all';
        startFlow();
    } );

    // ── Auto-save quality & backup ───────────────────────────────────────────

    function getFormats() {
        var formats = [];
        $( '.webp-fmt:checked' ).each( function () {
            formats.push( $( this ).val() );
        } );
        return formats.length ? formats : webpOptimizer.allFormats;
    }

    function autoSave() {
        $.post( webpOptimizer.ajaxUrl, {
            action:     'webp_optimizer_save_settings',
            nonce:      webpOptimizer.nonce,
            quality:    state.quality,
            batch_size: BATCH_SIZE,
            backup:     state.backup ? '1' : '0',
            formats:    getFormats(),
        } );
    }

    // =========================================================================
    // Main flow
    // =========================================================================

    function startFlow() {
        state.running        = true;
        state.stop           = false;
        state.processed      = 0;
        state.total          = 0;
        state.offset         = 0;
        state.phase          = 'optimize';
        state.formats        = getFormats();
        state.lastPending    = -1;
        state.stuckCount     = 0;

        $progressSection
            .removeClass( 'is-done' )
            .addClass( 'is-running' );
        $progressFill.css( 'width', '0%' );
        $progressPct.text( '0%' );

        $btnOptimize.add( $btnOptConvert ).add( $btnFixMime ).hide();
        $btnStop.show().prop( 'disabled', false )
            .html( '<span class="dashicons dashicons-no"></span> Detener' );
        $logContent.html( '' );

        setProgress( 0, 0, 'Iniciando…' );
        fetchNextBatch();
    }

    // ── Optimize phase ────────────────────────────────────────────────────────

    function fetchNextBatch() {
        if ( state.stop ) {
            finish( 'Detenido por el usuario.' );
            return;
        }

        if ( state.phase === 'convert' ) {
            fetchConvertBatch();
            return;
        }

        $.post( webpOptimizer.ajaxUrl, {
            action:     'webp_optimizer_get_batch',
            nonce:      webpOptimizer.nonce,
            force:      state.force ? '1' : '0',
            batch_size: BATCH_SIZE,
            offset:     state.offset,
            formats:    state.formats,
        }, function ( res ) {
            if ( ! res.success ) {
                addLog( 'error', 'Error al obtener lote: ' + ( res.data || '' ) );
                finish( 'Error durante la optimización.' );
                return;
            }

            var ids = res.data.ids;

            if ( ! ids || ids.length === 0 ) {
                transitionOrFinish( state.processed + ' imagen(es) optimizadas.' );
                return;
            }

            if ( state.total === 0 ) {
                state.total = parseInt( res.data.total, 10 ) || ids.length;
            }

            state.offset += ids.length;
            processBatch( ids );

        } ).fail( function () {
            addLog( 'error', 'Error de conexión al obtener lote.' );
            finish( 'Error de red.' );
        } );
    }

    function processBatch( ids ) {
        if ( state.stop ) {
            finish( 'Detenido por el usuario.' );
            return;
        }

        setProgress( state.processed, state.total,
            'Optimizando ' + ids.length + ' imagen(es)…' );

        $.post( webpOptimizer.ajaxUrl, {
            action:  'webp_optimizer_optimize_batch',
            nonce:   webpOptimizer.nonce,
            ids:     ids,
            quality: state.quality,
            backup:  state.backup ? '1' : '0',
            formats: state.formats,
        }, function ( res ) {
            if ( ! res.success ) {
                addLog( 'error', 'Error en lote: ' + ( res.data || '' ) );
                finish( 'Error durante la optimización.' );
                return;
            }

            $.each( res.data.results, function ( _, r ) {
                if ( r.success ) {
                    var pct = r.savings_pct > 0
                        ? ' <span class="webp-pct">&#8209;' + r.savings_pct + '%</span>'
                        : '';
                    var tag = r.format
                        ? ' <span class="webp-fmt-tag">' + escHtml( r.format ) + '</span>'
                        : '';
                    addLog( 'success',
                        tag + ' <strong>' + escHtml( r.file ) + '</strong>' +
                        ' &nbsp;' + r.original + ' &rarr; ' + r.new + pct
                    );
                } else {
                    addLog( 'error',
                        '<strong>' + escHtml( r.file ) + '</strong>: ' + escHtml( r.message )
                    );
                }
            } );

            state.processed += ids.length;

            var pendingLeft = parseInt( res.data.stats.pending, 10 ) || 0;
            if ( ! state.force ) {
                state.total = state.processed + pendingLeft;
            }

            updateStats( res.data.stats );
            setProgress( state.processed, state.total,
                'Optimizando… ' + state.processed + ' / ' + state.total );

            // Stuck-loop guard
            if ( pendingLeft > 0 && pendingLeft >= state.lastPending && state.lastPending !== -1 ) {
                state.stuckCount++;
            } else {
                state.stuckCount = 0;
            }
            state.lastPending = pendingLeft;

            if ( state.stuckCount >= 2 ) {
                transitionOrFinish( 'Algunas imágenes no pudieron optimizarse.' );
                return;
            }

            var hasMore = state.force
                ? ( state.offset < state.total )
                : ( pendingLeft > 0 );

            if ( hasMore && ! state.stop ) {
                setTimeout( fetchNextBatch, 200 );
            } else {
                transitionOrFinish( state.processed + ' imagen(es) optimizadas.' );
            }

        } ).fail( function () {
            addLog( 'error', 'Error de conexión al procesar lote.' );
            finish( 'Error de red.' );
        } );
    }

    function transitionOrFinish( optimizeMsg ) {
        if ( state.convertAfter ) {
            addLog( 'info', '─── ' + optimizeMsg + ' — Iniciando conversión a WebP… ───' );
            state.phase       = 'convert';
            state.processed   = 0;
            state.total       = 0;
            state.lastPending = -1;
            state.stuckCount  = 0;
            setProgress( 0, 0, 'Preparando conversión…' );
            setTimeout( fetchConvertBatch, 300 );
        } else {
            finish( '¡Completado! ' + optimizeMsg );
        }
    }

    // ── Conversion phase ──────────────────────────────────────────────────────

    function fetchConvertBatch() {
        if ( state.stop ) {
            finish( 'Detenido por el usuario.' );
            return;
        }

        $.post( webpOptimizer.ajaxUrl, {
            action:          'webp_optimizer_get_convert_batch',
            nonce:           webpOptimizer.nonce,
            batch_size:      BATCH_SIZE,
            formats: state.formats,
        }, function ( res ) {
            if ( ! res.success ) {
                addLog( 'error', 'Error al obtener lote de conversión: ' + ( res.data || '' ) );
                finish( 'Error durante la conversión.' );
                return;
            }

            var ids = res.data.ids;

            if ( ! ids || ids.length === 0 ) {
                finish( '¡Todo listo! ' + state.processed + ' imagen(es) convertidas a WebP.' );
                return;
            }

            if ( state.total === 0 ) {
                state.total = parseInt( res.data.total, 10 ) || ids.length;
            }

            convertBatch( ids );

        } ).fail( function () {
            addLog( 'error', 'Error de conexión al obtener lote de conversión.' );
            finish( 'Error de red.' );
        } );
    }

    function convertBatch( ids ) {
        if ( state.stop ) {
            finish( 'Detenido por el usuario.' );
            return;
        }

        setProgress( state.processed, state.total,
            'Convirtiendo ' + ids.length + ' imagen(es) a WebP…' );

        $.post( webpOptimizer.ajaxUrl, {
            action:          'webp_optimizer_convert_batch',
            nonce:           webpOptimizer.nonce,
            ids:             ids,
            quality:         state.quality,
            backup:          state.backup ? '1' : '0',
            formats: state.formats,
        }, function ( res ) {
            if ( ! res.success ) {
                addLog( 'error', 'Error en lote de conversión: ' + ( res.data || '' ) );
                finish( 'Error durante la conversión.' );
                return;
            }

            $.each( res.data.results, function ( _, r ) {
                if ( r.success ) {
                    var pct = r.savings_pct > 0
                        ? ' <span class="webp-pct">&#8209;' + r.savings_pct + '%</span>'
                        : '';
                    var tag = ' <span class="webp-fmt-tag">'
                        + escHtml( r.from_mime || '' ) + ' → WebP</span>';
                    addLog( 'success',
                        tag + ' <strong>' + escHtml( r.file ) + '</strong>' +
                        ' &nbsp;' + r.original + ' → ' + r.new + pct
                    );
                } else {
                    addLog( 'error',
                        '<strong>' + escHtml( r.file ) + '</strong>: ' + escHtml( r.message )
                    );
                }
            } );

            state.processed += ids.length;

            var pendingConv = parseInt( res.data.stats.pending_convert, 10 ) || 0;
            state.total = state.processed + pendingConv;

            updateStats( res.data.stats );
            $( '#stat-pending-convert' ).text( pendingConv );
            setProgress( state.processed, state.total,
                'Convirtiendo… ' + state.processed + ' / ' + state.total );

            if ( pendingConv > 0 && ! state.stop ) {
                setTimeout( fetchConvertBatch, 200 );
            } else {
                finish( '¡Todo listo! ' + state.processed + ' imagen(es) convertidas a WebP.' );
            }

        } ).fail( function () {
            addLog( 'error', 'Error de conexión al convertir lote.' );
            finish( 'Error de red.' );
        } );
    }

    // =========================================================================
    // UI helpers
    // =========================================================================

    function finish( message ) {
        state.running = false;
        setProgress( state.processed, state.total, message );
        $progressSection
            .removeClass( 'is-running' )
            .addClass( 'is-done' );
        addLog( 'info', '─── ' + message + ' ───' );
        $btnOptimize.add( $btnOptConvert ).show();
        $btnFixMime.show();
        $btnStop.hide();
    }

    function setProgress( current, total, status ) {
        var pct = ( total > 0 )
            ? Math.min( 100, Math.round( ( current / total ) * 100 ) )
            : 0;

        $progressFill.css( 'width', pct + '%' );
        $progressPct.text( pct + '%' );
        $progressCount.text( current );
        $progressTotal.text( total );
        $progressStatus.text( status );
    }

    function updateStats( stats ) {
        $( '#stat-total' ).text( stats.total );
        $( '#stat-optimized' ).text( stats.optimized );
        $( '#stat-pending' ).text( stats.pending );
        $( '#stat-savings' ).text( stats.savings );
        if ( stats.pending_convert !== undefined ) {
            $( '#stat-pending-convert' ).text( stats.pending_convert );
        }
    }

    var _refreshTimer = null;
    function refreshStats() {
        if ( state.running ) { return; }
        clearTimeout( _refreshTimer );
        _refreshTimer = setTimeout( function () {
            $statsGrid.addClass( 'is-loading' );
            $.post( webpOptimizer.ajaxUrl, {
                action:  'webp_optimizer_get_stats',
                nonce:   webpOptimizer.nonce,
                formats: getFormats(),
            }, function ( res ) {
                $statsGrid.removeClass( 'is-loading' );
                if ( res.success ) {
                    updateStats( res.data );
                }
            } ).fail( function () {
                $statsGrid.removeClass( 'is-loading' );
            } );
        }, 300 );
    }

    function addLog( type, html ) {
        $logContent.find( '.webp-log-placeholder' ).remove();
        var now  = new Date();
        var time = now.toLocaleTimeString( [], { hour: '2-digit', minute: '2-digit', second: '2-digit' } );
        var $item = $(
            '<div class="webp-log-entry webp-log-' + type + '">' +
                '<span class="webp-log-time">' + time + '</span> ' + html +
            '</div>'
        );
        $logContent.append( $item );
        $logContent.scrollTop( $logContent[ 0 ].scrollHeight );
    }

    function escHtml( str ) {
        return $( '<div>' ).text( String( str ) ).html();
    }

}( jQuery ));
