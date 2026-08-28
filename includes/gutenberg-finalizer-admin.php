<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\GutenbergFinalizer;

if (!defined('ABSPATH')) {
    exit();
}

function boot_gutenberg_finalizer_admin(): void
{
    add_action('admin_menu', __NAMESPACE__ . '\\register_gutenberg_finalizer_menu', priority: 60);
    add_action('admin_enqueue_scripts', __NAMESPACE__ . '\\enqueue_gutenberg_finalizer_assets');
    add_filter(
        'wp_client_side_media_processing_enabled',
        __NAMESPACE__ . '\\disable_gutenberg_finalizer_editor_client_side_media_processing',
    );
}

/**
 * The hidden finalizer editor never uploads or transcodes media, so it does not need client-side
 * media processing. When core enables that feature on a block editor screen it also sends a
 * document isolation header, and that header would place the hidden editor in its own agent
 * cluster, separate from the Queue page that embeds it, even though both documents share an origin.
 * The Queue could then no longer read the editor runtime directly. This only removes the core
 * trigger: other isolation causes (sandbox/CSP headers, host redirects) are still covered by the
 * postMessage serializer bridge.
 *
 * @param mixed $enabled The incoming filter value.
 * @return mixed False for the hidden finalizer editor request, the incoming value otherwise.
 */
function disable_gutenberg_finalizer_editor_client_side_media_processing(mixed $enabled): mixed
{
    if (is_gutenberg_finalizer_editor_request()) {
        return false;
    }

    return $enabled;
}

function gutenberg_finalizer_page_slug(): string
{
    return 'novamira-gutenberg-finalize';
}

function register_gutenberg_finalizer_menu(): void
{
    if (!defined('NOVAMIRA_VERSION')) {
        return;
    }

    add_submenu_page(
        parent_slug: 'novamira-connect',
        page_title: __('Block Editor Queue', domain: 'novamira'),
        menu_title: __('Block Editor Queue', domain: 'novamira'),
        capability: novamira_manage_capability(),
        menu_slug: gutenberg_finalizer_page_slug(),
        callback: __NAMESPACE__ . '\\render_gutenberg_finalizer_page',
    );
}

function enqueue_gutenberg_finalizer_assets(string $hook_suffix): void
{
    if (is_gutenberg_finalizer_editor_request()) {
        enqueue_gutenberg_finalizer_editor_bridge();

        return;
    }

    if (!is_gutenberg_finalizer_request()) {
        return;
    }

    enqueue_gutenberg_finalizer_runtime_assets();

    unset($hook_suffix);
}

function enqueue_gutenberg_finalizer_editor_bridge(): void
{
    wp_register_script(
        handle: 'novamira-gutenberg-finalizer-editor-bridge',
        src: false,
        deps: ['wp-blocks'],
        ver: NOVAMIRA_VERSION,
        args: true,
    );
    wp_add_inline_script(handle: 'novamira-gutenberg-finalizer-editor-bridge', data: <<<'JS'
        ( function () {
            const parentOrigin = window.location.origin;
            const sleep = ( milliseconds ) => new Promise( ( resolve ) => {
                window.setTimeout( resolve, milliseconds );
            } );
            const postToParent = ( data ) => window.parent.postMessage( data, parentOrigin );

            const collectBlockRefs = ( blocks, prefix = '' ) => {
                const refs = [];
                ( Array.isArray( blocks ) ? blocks : [] ).forEach( ( block, index ) => {
                    if ( ! block || typeof block !== 'object' ) {
                        return;
                    }
                    const pathText = prefix === '' ? String( index ) : `${ prefix }.${ index }`;
                    if ( typeof block.name === 'string' && block.name !== '' ) {
                        refs.push( { name: block.name, path: pathText } );
                    }
                    refs.push( ...collectBlockRefs( block.innerBlocks || [], pathText ) );
                } );
                return refs;
            };

            const missingRegistrationError = ( missingRefs ) => {
                const names = Array.from( new Set( missingRefs.map( ( ref ) => ref.name ) ) );
                const error = new Error(
                    `The editor iframe did not register required block types: ${ names.join( ', ' ) }.`
                );
                error.code = 'missing_block_registration';
                error.missingBlockRefs = missingRefs;
                return error;
            };

            const waitForBlockRegistrations = async ( blocksApi, refs ) => {
                const deadline = Date.now() + 30000;
                let missingRefs = refs.filter( ( ref ) => ! blocksApi.getBlockType( ref.name ) );
                while ( missingRefs.length && Date.now() < deadline ) {
                    await sleep( 100 );
                    missingRefs = refs.filter( ( ref ) => ! blocksApi.getBlockType( ref.name ) );
                }
                if ( missingRefs.length ) {
                    throw missingRegistrationError( missingRefs );
                }
            };

            const toBlock = ( blocksApi, spec ) => blocksApi.createBlock(
                spec.name,
                spec.attributes || {},
                ( spec.innerBlocks || [] ).map( ( innerSpec ) => toBlock( blocksApi, innerSpec ) )
            );

            const issueMessage = ( issue ) => {
                if ( ! issue ) {
                    return 'Block validation failed.';
                }
                if ( typeof issue === 'string' ) {
                    return issue;
                }
                if ( issue.message ) {
                    return issue.message;
                }
                if ( Array.isArray( issue.args ) ) {
                    return issue.args.map( String ).join( ' ' );
                }
                return 'Block validation failed.';
            };

            const blockName = ( block ) => block.name || block.blockName || '';

            const validateBlocks = ( blocksApi, blocks, prefix = '' ) => {
                const validations = [];
                blocks.forEach( ( block, index ) => {
                    const pathText = prefix === '' ? String( index ) : `${ prefix }.${ index }`;
                    let result;
                    try {
                        result = blocksApi.validateBlock( block );
                    } catch ( error ) {
                        result = [ false, [ { message: error.message || String( error ) } ] ];
                    }
                    const isValid = Array.isArray( result ) ? result[ 0 ] === true : result === true;
                    const issues = Array.isArray( result ) ? ( result[ 1 ] || [] ) : [];
                    validations.push( {
                        name: blockName( block ),
                        path: pathText,
                        isValid,
                        issues: issues.map( ( issue ) => ( {
                            message: issueMessage( issue ).replace( /\s+/g, ' ' ).trim().slice( 0, 300 ),
                        } ) ),
                    } );
                    if ( Array.isArray( block.innerBlocks ) && block.innerBlocks.length ) {
                        validations.push( ...validateBlocks( blocksApi, block.innerBlocks, pathText ) );
                    }
                } );
                return validations;
            };

            const mountAndSettleBlocks = async ( created ) => {
                const wpApi = window.wp;
                if ( ! wpApi || ! wpApi.data || ! wpApi.blocks ) {
                    return null;
                }

                const blockEditorDispatch = wpApi.data.dispatch( 'core/block-editor' );
                const blockEditorSelect = wpApi.data.select( 'core/block-editor' );
                if (
                    ! blockEditorDispatch
                    || ! blockEditorSelect
                    || typeof blockEditorDispatch.resetBlocks !== 'function'
                    || typeof blockEditorSelect.getBlocks !== 'function'
                ) {
                    return null;
                }

                const editorSelect = wpApi.data.select( 'core/editor' );
                const editorDispatch = wpApi.data.dispatch( 'core/editor' );
                const hydrationDeadline = Date.now() + 30000;
                while ( Date.now() < hydrationDeadline ) {
                    const ready = editorSelect && typeof editorSelect.__unstableIsEditorReady === 'function'
                        ? editorSelect.__unstableIsEditorReady()
                        : blockEditorSelect.getBlocks().length > 0;
                    if ( ready ) {
                        break;
                    }
                    await sleep( 200 );
                }

                try {
                    editorDispatch.lockPostAutosaving( 'novamira-gb-finalizer' );
                    editorDispatch.lockPostSaving( 'novamira-gb-finalizer' );
                } catch ( lockError ) {
                    // Older editors may lack the locks. The Queue still owns persistence.
                }

                if ( editorDispatch && typeof editorDispatch.resetEditorBlocks === 'function' ) {
                    editorDispatch.resetEditorBlocks( created );
                } else {
                    blockEditorDispatch.resetBlocks( created );
                }

                const sameTopLevelNames = ( currentBlocks ) => Array.isArray( currentBlocks )
                    && currentBlocks.length === created.length
                    && currentBlocks.every( ( block, index ) => block && block.name === created[ index ].name );

                const applyDeadline = Date.now() + 5000;
                let applied = false;
                while ( Date.now() < applyDeadline ) {
                    if ( sameTopLevelNames( blockEditorSelect.getBlocks() ) ) {
                        applied = true;
                        break;
                    }
                    await sleep( 100 );
                }
                if ( ! applied ) {
                    return null;
                }

                const settleDeadline = Date.now() + 5000;
                let previousSnapshot = '';
                while ( Date.now() < settleDeadline ) {
                    await sleep( 250 );
                    const snapshot = wpApi.blocks.serialize( blockEditorSelect.getBlocks() );
                    if ( snapshot !== '' && snapshot === previousSnapshot ) {
                        break;
                    }
                    previousSnapshot = snapshot;
                }

                const settled = blockEditorSelect.getBlocks();
                return sameTopLevelNames( settled ) ? settled : null;
            };

            const serializeBlocks = async ( blockSpecs ) => {
                const blocksApi = window.wp && window.wp.blocks;
                if ( ! blocksApi ) {
                    throw new Error( 'The editor iframe block API is unavailable.' );
                }

                await waitForBlockRegistrations( blocksApi, collectBlockRefs( blockSpecs ) );
                const created = blockSpecs.map( ( spec ) => toBlock( blocksApi, spec ) );
                const settled = await mountAndSettleBlocks( created );
                const content = blocksApi.serialize( settled || created );
                const parsed = blocksApi.parse( content );
                const validations = validateBlocks( blocksApi, parsed );
                const errors = [];
                validations.forEach( ( validation ) => {
                    if ( validation.isValid ) {
                        return;
                    }
                    const issues = validation.issues.length
                        ? validation.issues
                        : [ { message: 'Block validation failed.' } ];
                    issues.forEach( ( issue ) => errors.push( {
                        block_name: validation.name || '',
                        path: validation.path || '',
                        category: 'validation',
                        code: 'block_validation_failed',
                        message: issue.message || 'Block validation failed.',
                    } ) );
                } );

                return { content, validations, errors };
            };

            window.addEventListener( 'message', async ( event ) => {
                if (
                    event.source !== window.parent
                    || event.origin !== parentOrigin
                    || ! event.data
                    || event.data.type !== 'novamira-gb-serialize-request'
                ) {
                    return;
                }

                const requestId = String( event.data.requestId || '' );
                if ( requestId === '' ) {
                    return;
                }

                try {
                    const result = await serializeBlocks( Array.isArray( event.data.blocks ) ? event.data.blocks : [] );
                    postToParent( {
                        type: 'novamira-gb-serialize-response',
                        requestId,
                        ok: true,
                        result,
                    } );
                } catch ( error ) {
                    postToParent( {
                        type: 'novamira-gb-serialize-response',
                        requestId,
                        ok: false,
                        error: {
                            code: ( error && error.code ) || 'iframe_bridge_exception',
                            message: error && error.message ? error.message : String( error ),
                            missingBlockRefs: error && Array.isArray( error.missingBlockRefs )
                                ? error.missingBlockRefs
                                : [],
                        },
                    } );
                }
            } );

            postToParent( {
                type: 'novamira-gb-editor-diagnostic',
                href: window.location.href,
                origin: window.location.origin,
                documentDomain: document.domain || '',
                crossOriginIsolated: window.crossOriginIsolated === true,
                originAgentCluster: window.originAgentCluster === true,
                ancestorOrigin: window.location.ancestorOrigins && window.location.ancestorOrigins.length
                    ? window.location.ancestorOrigins[ 0 ]
                    : '',
                hasBlocksApi: !! ( window.wp && window.wp.blocks ),
                hasSerializerBridge: true,
            } );
        } )();
        JS);
    wp_enqueue_script(handle: 'novamira-gutenberg-finalizer-editor-bridge');
}

function enqueue_gutenberg_finalizer_runtime_assets(): void
{
    wp_register_script(
        handle: 'novamira-gutenberg-finalizer',
        src: false,
        deps: ['wp-api-fetch', 'wp-blocks', 'wp-block-library', 'wp-format-library'],
        ver: NOVAMIRA_VERSION,
        args: true,
    );

    $config = [
        'nonce' => wp_create_nonce('wp_rest'),
    ];
    $encoded_config = wp_json_encode($config);
    if (is_string($encoded_config)) {
        wp_add_inline_script(
            handle: 'novamira-gutenberg-finalizer',
            data: 'window.novamiraGutenbergFinalizer = ' . $encoded_config . ';',
            position: 'before',
        );
    }
    wp_add_inline_script(handle: 'novamira-gutenberg-finalizer', data: gutenberg_finalizer_script());
    wp_enqueue_script(handle: 'novamira-gutenberg-finalizer');
}

function is_gutenberg_finalizer_request(): bool
{
    return ($_GET['page'] ?? '') === gutenberg_finalizer_page_slug();
}

function is_gutenberg_finalizer_editor_request(): bool
{
    return ($_GET['novamira_gb_finalizer'] ?? '') === '1';
}

function render_gutenberg_finalizer_page(): void
{
    if (!novamira_current_user_can_manage()) {
        return;
    }

    if (function_exists('novamira_render_admin_header')) {
        novamira_render_admin_header();
    }

    ?>
    <div class="wrap novamira-gb-finalizer" id="novamira-gb-finalizer">
        <h1 class="wp-heading-inline"><?php esc_html_e('Block Editor Queue', domain: 'novamira'); ?></h1>
        <hr class="wp-header-end">
        <?php render_gutenberg_finalizer_styles(); ?>

        <?php render_gutenberg_finalizer_page_content(); ?>
    </div>
    <?php
}

function render_gutenberg_finalizer_page_content(): void
{
    render_gutenberg_finalizer_dashboard();
}

function render_gutenberg_finalizer_dashboard(): void
{ ?>
    <div id="novamira-gb-notice" class="notice" hidden><p></p></div>

    <section class="summary-panel" aria-live="polite">
        <p><?php esc_html_e(
            'This background utility page is used by Novamira to safely validate and serialize Gutenberg blocks. During Gutenberg editing sessions, this page serves as a technical bridge, utilizing the native WordPress editor engine to serialize block structures securely.',
            domain: 'novamira',
        ); ?></p>
        <p><strong><?php esc_html_e(
            'Please keep this tab open in the background while an active session is running. You can safely ignore this page, but closing it before the session completes will pause the updates.',
            domain: 'novamira',
        ); ?></strong></p>
        <p id="novamira-gb-progress" class="progress-line"><?php esc_html_e(
            'Checking for queued Gutenberg changes...',
            domain: 'novamira',
        ); ?></p>
    </section>
    <div class="novamira-gb-editor-frame-wrap" aria-hidden="true">
        <iframe
            id="novamira-gb-editor-frame"
            class="novamira-gb-editor-frame"
            title="<?php esc_attr_e('Novamira hidden block editor', domain: 'novamira'); ?>"
            tabindex="-1"
            src="about:blank"
        ></iframe>
    </div>
    <?php }

function render_gutenberg_finalizer_styles(): void
{ ?>
    <style>
        .novamira-gb-finalizer .summary-panel {
            background: linear-gradient(135deg, #ffffff 0%, #f9f9fb 100%);
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 24px 28px;
            margin: 20px 0;
            max-width: 800px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
        }
        .novamira-gb-finalizer .summary-panel p {
            font-size: 14px;
            line-height: 1.6;
            color: #4a5568;
            margin: 0 0 12px 0;
        }
        .novamira-gb-finalizer .summary-panel p strong {
            color: #2d3748;
        }
        .novamira-gb-finalizer .progress-line {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 20px 0 0 0;
            padding-top: 16px;
            border-top: 1px solid #edf2f7;
            font-weight: 600;
            color: #4f46e5;
        }
        .novamira-gb-finalizer .progress-line::before {
            content: "";
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background-color: #4f46e5;
            box-shadow: 0 0 0 0 rgba(79, 70, 229, 0.7);
            animation: nvp-pulse 1.6s infinite cubic-bezier(0.66, 0, 0, 1);
        }
        @keyframes nvp-pulse {
            to {
                box-shadow: 0 0 0 8px rgba(79, 70, 229, 0);
            }
        }
        .novamira-gb-finalizer .novamira-gb-editor-frame-wrap {
            position: absolute;
            top: 0;
            left: -10000px;
            width: 1280px;
            height: 900px;
            overflow: hidden;
            opacity: 0;
            pointer-events: none;
        }
        .novamira-gb-finalizer .novamira-gb-editor-frame {
            display: block;
            width: 1280px;
            height: 900px;
            border: 0;
        }
    </style>
    <?php }

function gutenberg_finalizer_script(): string
{
    return <<<'JS'
        ( function () {
            const config = window.novamiraGutenbergFinalizer || {};
            const rootId = config.rootId || 'novamira-gb-finalizer';
            const root = document.getElementById( rootId );
            if ( ! root || ! window.wp || ! wp.apiFetch ) {
                return;
            }

            const apiFetch = wp.apiFetch;
            apiFetch.use( apiFetch.createNonceMiddleware( config.nonce ) );

            const progress = document.getElementById( 'novamira-gb-progress' );
            const notice = document.getElementById( 'novamira-gb-notice' );
            let editorFrame = document.getElementById( 'novamira-gb-editor-frame' );
            const editorLoadTimeoutMs = Number( config.editorLoadTimeoutMs || 30000 );
            const blockRegistrationTimeoutMs = Number( config.blockRegistrationTimeoutMs || 30000 );
            let leaseOwner = '';
            let isRunning = false;
            let dashboardPollRunning = false;
            let editorFrameUrl = '';
            let editorFrameLoadPromise = Promise.resolve();
            let frameAccessError = null;
            let fallbackWarning = '';
            let editorFrameDiagnostic = null;

            const path = ( suffix ) => `/novamira/v1${ suffix }`;

            window.addEventListener( 'message', ( event ) => {
                if (
                    ! editorFrame
                    || event.source !== editorFrame.contentWindow
                    || event.origin !== window.location.origin
                    || ! event.data
                    || event.data.type !== 'novamira-gb-editor-diagnostic'
                ) {
                    return;
                }

                editorFrameDiagnostic = event.data;
            } );

            const setNotice = ( type, message ) => {
                if ( ! notice ) {
                    return;
                }
                notice.className = `notice notice-${ type }`;
                notice.hidden = false;
                const p = notice.querySelector( 'p' );
                if ( p ) {
                    p.textContent = message;
                }
            };

            const clearNotice = () => {
                if ( notice ) {
                    notice.hidden = true;
                }
            };

            const setProgress = ( message ) => {
                if ( progress ) {
                    progress.textContent = message;
                }
            };

            const issueMessage = ( issue ) => {
                if ( ! issue ) {
                    return 'Block validation failed.';
                }
                if ( typeof issue === 'string' ) {
                    return issue;
                }
                if ( issue.message ) {
                    return issue.message;
                }
                if ( Array.isArray( issue.args ) ) {
                    return issue.args.map( String ).join( ' ' );
                }
                try {
                    return JSON.stringify( issue );
                } catch ( error ) {
                    return 'Block validation failed.';
                }
            };

            const compactIssue = ( validation, issue ) => ( {
                block_name: validation.name || '',
                path: validation.path || '',
                category: 'validation',
                code: 'block_validation_failed',
                message: issueMessage( issue ).replace( /\s+/g, ' ' ).trim().slice( 0, 300 ),
            } );

            const sleep = ( milliseconds ) => new Promise( ( resolve ) => {
                window.setTimeout( resolve, milliseconds );
            } );

            const sameOriginEditorUrl = ( editorUrl ) => {
                if ( ! editorUrl ) {
                    throw new Error( 'The queued Gutenberg item did not include an editor URL.' );
                }

                const url = new URL( editorUrl, window.location.href );
                if ( url.origin !== window.location.origin ) {
                    throw new Error( 'The editor iframe URL is not same-origin.' );
                }

                return url.href;
            };

            const ensureEditorFrame = () => {
                if ( editorFrame ) {
                    return editorFrame;
                }

                const wrap = document.createElement( 'div' );
                wrap.className = 'novamira-gb-editor-frame-wrap';
                wrap.setAttribute( 'aria-hidden', 'true' );
                wrap.style.position = 'absolute';
                wrap.style.top = '0';
                wrap.style.left = '-10000px';
                wrap.style.width = '1280px';
                wrap.style.height = '900px';
                wrap.style.overflow = 'hidden';
                wrap.style.opacity = '0';
                wrap.style.pointerEvents = 'none';

                const frame = document.createElement( 'iframe' );
                frame.id = 'novamira-gb-editor-frame';
                frame.className = 'novamira-gb-editor-frame';
                frame.title = 'Novamira hidden block editor';
                frame.tabIndex = -1;
                frame.src = 'about:blank';
                frame.style.display = 'block';
                frame.style.width = '1280px';
                frame.style.height = '900px';
                frame.style.border = '0';

                wrap.appendChild( frame );
                root.appendChild( wrap );
                editorFrame = frame;

                return frame;
            };

            const navigateEditorFrame = ( editorUrl ) => {
                const frame = ensureEditorFrame();

                const nextUrl = sameOriginEditorUrl( editorUrl );
                if ( editorFrameUrl === nextUrl ) {
                    return editorFrameLoadPromise;
                }

                editorFrameUrl = nextUrl;
                editorFrameDiagnostic = null;
                editorFrameLoadPromise = new Promise( ( resolve, reject ) => {
                    let settled = false;
                    const cleanup = () => {
                        frame.removeEventListener( 'load', onLoad );
                        window.clearTimeout( timeoutId );
                    };
                    const onLoad = () => {
                        if ( settled ) {
                            return;
                        }
                        settled = true;
                        cleanup();
                        resolve();
                    };
                    const timeoutId = window.setTimeout( () => {
                        if ( settled ) {
                            return;
                        }
                        settled = true;
                        cleanup();
                        reject( new Error( 'The hidden editor iframe did not finish loading.' ) );
                    }, editorLoadTimeoutMs );

                    frame.addEventListener( 'load', onLoad );
                    frame.src = nextUrl;
                } );
                editorFrameLoadPromise.catch( () => {
                    if ( editorFrameUrl === nextUrl ) {
                        editorFrameUrl = '';
                    }
                } );

                return editorFrameLoadPromise;
            };

            const iframeWindow = () => {
                if ( ! editorFrame || ! editorFrame.contentWindow ) {
                    return null;
                }

                try {
                    return editorFrame.contentWindow;
                } catch ( error ) {
                    return null;
                }
            };

            const requiredBlocksMethods = [ 'createBlock', 'serialize', 'parse', 'validateBlock', 'getBlockType' ];

            const usableBlocksApi = ( blocksApi ) => {
                if ( ! blocksApi ) {
                    return null;
                }

                const hasRequiredMethods = requiredBlocksMethods.every(
                    ( method ) => typeof blocksApi[ method ] === 'function'
                );

                return hasRequiredMethods ? blocksApi : null;
            };

            // Every read below crosses a frame boundary, so any property access can throw a
            // SecurityError when the frame ends up in another origin. Treat that as "not ready"
            // and record it, so the caller can stop polling and explain what happened.
            const editorBlocksApi = () => {
                try {
                    const frameWindow = iframeWindow();
                    if ( ! frameWindow ) {
                        return null;
                    }

                    const wpApi = frameWindow.wp;

                    return wpApi ? usableBlocksApi( wpApi.blocks ) : null;
                } catch ( error ) {
                    frameAccessError = error;
                    return null;
                }
            };

            const crossOriginFrameError = ( error ) => {
                const detail = error && error.message ? ` (${ error.message })` : '';
                const frameError = new Error(
                    'The hidden editor iframe browsing context is isolated from the Queue page, so its block editor '
                    + 'runtime cannot be read directly. This can happen even when both documents report the same origin, '
                    + `for example when the browser applies cross-origin isolation or origin-keying policy.${ detail }`
                );
                frameError.code = 'editor_frame_cross_origin';
                return frameError;
            };

            const waitForEditorBlocksApi = async () => {
                const startedAt = Date.now();
                while ( Date.now() - startedAt < editorLoadTimeoutMs ) {
                    frameAccessError = null;
                    const blocksApi = editorBlocksApi();
                    if ( blocksApi ) {
                        return blocksApi;
                    }
                    // The frame already fired "load", so a blocked read will not resolve by waiting.
                    if ( frameAccessError ) {
                        throw crossOriginFrameError( frameAccessError );
                    }
                    await sleep( 100 );
                }

                throw new Error( 'The WordPress block editor JavaScript runtime is not available in the hidden iframe.' );
            };

            const collectBlockRefs = ( blocks, prefix = '' ) => {
                const refs = [];
                ( Array.isArray( blocks ) ? blocks : [] ).forEach( ( block, index ) => {
                    if ( ! block || typeof block !== 'object' ) {
                        return;
                    }

                    const pathText = prefix === '' ? String( index ) : `${ prefix }.${ index }`;
                    if ( typeof block.name === 'string' && block.name !== '' ) {
                        refs.push( { name: block.name, path: pathText } );
                    }
                    refs.push( ...collectBlockRefs( block.innerBlocks || [], pathText ) );
                } );
                return refs;
            };

            const uniqueBlockNames = ( refs ) => Array.from( new Set( refs.map( ( ref ) => ref.name ) ) );

            const missingRegistrationError = ( missingRefs ) => {
                const names = uniqueBlockNames( missingRefs );
                const error = new Error( `The editor iframe did not register required block types: ${ names.join( ', ' ) }.` );
                error.code = 'missing_block_registration';
                error.missingBlockRefs = missingRefs;
                return error;
            };

            let coreBlocksRegistered = false;

            // Fallback runtime: this admin page loads wp-blocks and wp-block-library itself, so core
            // blocks can be serialized here when the hidden iframe is unreachable.
            const localBlocksApi = () => {
                const wpApi = window.wp;
                if ( ! wpApi ) {
                    return null;
                }

                if ( ! coreBlocksRegistered ) {
                    coreBlocksRegistered = true;
                    if ( wpApi.blockLibrary && typeof wpApi.blockLibrary.registerCoreBlocks === 'function' ) {
                        wpApi.blockLibrary.registerCoreBlocks();
                    }
                }

                return usableBlocksApi( wpApi.blocks );
            };

            const waitForBlockRegistrations = async ( blocksApi, refs ) => {
                const startedAt = Date.now();
                let missingRefs = refs.filter( ( ref ) => ! blocksApi.getBlockType( ref.name ) );
                while ( missingRefs.length && Date.now() - startedAt < blockRegistrationTimeoutMs ) {
                    await sleep( 100 );
                    missingRefs = refs.filter( ( ref ) => ! blocksApi.getBlockType( ref.name ) );
                }

                if ( missingRefs.length ) {
                    throw missingRegistrationError( missingRefs );
                }
            };

            const editorFrameDiagnosticSummary = () => {
                if ( ! editorFrameDiagnostic ) {
                    return 'Editor diagnostic unavailable.';
                }

                return 'Editor diagnostic: '
                    + `href=${ String( editorFrameDiagnostic.href || '' ) }; `
                    + `origin=${ String( editorFrameDiagnostic.origin || '' ) }; `
                    + `document.domain=${ String( editorFrameDiagnostic.documentDomain || '' ) }; `
                    + `crossOriginIsolated=${ editorFrameDiagnostic.crossOriginIsolated === true ? 'true' : 'false' }; `
                    + `originAgentCluster=${ editorFrameDiagnostic.originAgentCluster === true ? 'true' : 'false' }; `
                    + `ancestorOrigin=${ String( editorFrameDiagnostic.ancestorOrigin || '' ) }; `
                    + `parentDocument.domain=${ String( document.domain || '' ) }; `
                    + `parentCrossOriginIsolated=${ window.crossOriginIsolated === true ? 'true' : 'false' }; `
                    + `parentOriginAgentCluster=${ window.originAgentCluster === true ? 'true' : 'false' }; `
                    + `wp.blocks=${ editorFrameDiagnostic.hasBlocksApi === true ? 'available' : 'missing' }; `
                    + `serializerBridge=${ editorFrameDiagnostic.hasSerializerBridge === true ? 'available' : 'missing' }.`;
            };

            const waitForEditorSerializerBridge = async () => {
                const startedAt = Date.now();
                while ( Date.now() - startedAt < editorLoadTimeoutMs ) {
                    if ( editorFrameDiagnostic && editorFrameDiagnostic.hasSerializerBridge === true ) {
                        return;
                    }
                    await sleep( 100 );
                }

                throw new Error( 'The hidden editor iframe serializer bridge did not become available.' );
            };

            const serializeThroughEditorFrame = async ( blocks ) => {
                await waitForEditorSerializerBridge();

                const frameWindow = editorFrame && editorFrame.contentWindow;
                if ( ! frameWindow ) {
                    throw new Error( 'The hidden editor iframe window is unavailable.' );
                }

                const requestId = `${ Date.now() }-${ Math.random().toString( 36 ).slice( 2 ) }`;
                return new Promise( ( resolve, reject ) => {
                    let settled = false;
                    const cleanup = () => {
                        window.removeEventListener( 'message', onMessage );
                        window.clearTimeout( timeoutId );
                    };
                    const onMessage = ( event ) => {
                        if (
                            event.source !== frameWindow
                            || event.origin !== window.location.origin
                            || ! event.data
                            || event.data.type !== 'novamira-gb-serialize-response'
                            || event.data.requestId !== requestId
                        ) {
                            return;
                        }

                        settled = true;
                        cleanup();
                        if ( event.data.ok === true && event.data.result ) {
                            resolve( event.data.result );
                            return;
                        }

                        const responseError = event.data.error || {};
                        const error = new Error(
                            responseError.message || 'The hidden editor iframe serializer bridge failed.'
                        );
                        error.code = responseError.code || 'iframe_bridge_exception';
                        // The iframe was reachable and answered; this is a serializer error, not an
                        // unreachable frame.
                        error.bridgeResponded = true;
                        error.missingBlockRefs = Array.isArray( responseError.missingBlockRefs )
                            ? responseError.missingBlockRefs
                            : [];
                        reject( error );
                    };
                    const timeoutId = window.setTimeout( () => {
                        if ( settled ) {
                            return;
                        }
                        settled = true;
                        cleanup();
                        reject( new Error( 'The hidden editor iframe serializer bridge timed out.' ) );
                    }, editorLoadTimeoutMs + Number( config.mountSettleTimeoutMs || 5000 ) );

                    window.addEventListener( 'message', onMessage );
                    frameWindow.postMessage( {
                        type: 'novamira-gb-serialize-request',
                        requestId,
                        blocks,
                    }, window.location.origin );
                } );
            };

            const toBlock = ( blocksApi, spec ) => blocksApi.createBlock(
                spec.name,
                spec.attributes || {},
                ( spec.innerBlocks || [] ).map( ( innerSpec ) => toBlock( blocksApi, innerSpec ) )
            );

            const blockName = ( block ) => block.name || block.blockName || '';

            const validateBlocks = ( blocksApi, blocks, prefix = '' ) => {
                const validations = [];
                blocks.forEach( ( block, index ) => {
                    const pathText = prefix === '' ? String( index ) : `${ prefix }.${ index }`;
                    let result;
                    try {
                        result = blocksApi.validateBlock( block );
                    } catch ( error ) {
                        result = [ false, [ { message: error.message || String( error ) } ] ];
                    }
                    const isValid = Array.isArray( result ) ? result[ 0 ] === true : result === true;
                    const issues = Array.isArray( result ) ? ( result[ 1 ] || [] ) : [];
                    validations.push( {
                        name: blockName( block ),
                        path: pathText,
                        isValid,
                        issues,
                    } );
                    if ( Array.isArray( block.innerBlocks ) && block.innerBlocks.length ) {
                        validations.push( ...validateBlocks( blocksApi, block.innerBlocks, pathText ) );
                    }
                } );
                return validations;
            };

            // Mount the created blocks in the hidden editor's block-editor store and let their
            // edit() components run their mount effects before serializing. Plugin blocks such as
            // Kadence assign editor-managed attributes (uniqueID, kbVersion, ...) from those
            // effects; serializing bare createBlock() output silently drops them, which breaks
            // front-end rendering even though block validation passes. Returns the settled block
            // tree, or null when the store is unavailable (fallback runtime) so the caller keeps
            // the previous createBlock()-only behavior.
            const mountAndSettleBlocks = async ( created ) => {
                try {
                    const frameWindow = iframeWindow();
                    if ( ! frameWindow || ! frameWindow.wp || ! frameWindow.wp.data || ! frameWindow.wp.blocks ) {
                        return null;
                    }

                    const frameData = frameWindow.wp.data;
                    const blockEditorDispatch = frameData.dispatch( 'core/block-editor' );
                    const blockEditorSelect = frameData.select( 'core/block-editor' );
                    if (
                        ! blockEditorDispatch
                        || ! blockEditorSelect
                        || typeof blockEditorDispatch.resetBlocks !== 'function'
                        || typeof blockEditorSelect.getBlocks !== 'function'
                    ) {
                        return null;
                    }

                    // In the post editor the core/block-editor store is CONTROLLED: it is synced
                    // from the core/editor post entity, so a direct resetBlocks() is overwritten
                    // by editor hydration and the harvest would silently return the post's OLD
                    // content. Wait for hydration, then reset through core/editor instead.
                    const editorSelect = frameData.select( 'core/editor' );
                    const editorDispatch = frameData.dispatch( 'core/editor' );

                    const hydrationDeadline = Date.now() + Number( config.editorLoadTimeoutMs || 30000 );
                    while ( Date.now() < hydrationDeadline ) {
                        const ready = editorSelect && typeof editorSelect.__unstableIsEditorReady === 'function'
                            ? editorSelect.__unstableIsEditorReady()
                            : blockEditorSelect.getBlocks().length > 0;
                        if ( ready ) {
                            break;
                        }
                        await sleep( 200 );
                    }

                    // The hidden editor must never persist this experiment on its own.
                    try {
                        editorDispatch.lockPostAutosaving( 'novamira-gb-finalizer' );
                        editorDispatch.lockPostSaving( 'novamira-gb-finalizer' );
                    } catch ( lockError ) {
                        // Older editors may lack the locks; mounting is still safe because the
                        // finalizer persists content through its own REST call, not the editor.
                    }

                    if ( editorDispatch && typeof editorDispatch.resetEditorBlocks === 'function' ) {
                        editorDispatch.resetEditorBlocks( created );
                    } else {
                        blockEditorDispatch.resetBlocks( created );
                    }

                    // Confirm the store now holds OUR tree (same top-level names, in order)
                    // before trusting anything it returns.
                    const sameTopLevelNames = ( currentBlocks ) => Array.isArray( currentBlocks )
                        && currentBlocks.length === created.length
                        && currentBlocks.every( ( block, index ) => block && block.name === created[ index ].name );

                    const applyDeadline = Date.now() + 5000;
                    let applied = false;
                    while ( Date.now() < applyDeadline ) {
                        if ( sameTopLevelNames( blockEditorSelect.getBlocks() ) ) {
                            applied = true;
                            break;
                        }
                        await sleep( 100 );
                    }
                    if ( ! applied ) {
                        return null;
                    }

                    // Mount effects run over the next frames; treat two identical consecutive
                    // serializations as "settled" and give up after a bounded wait.
                    const settleDeadline = Date.now() + Number( config.mountSettleTimeoutMs || 5000 );
                    let previousSnapshot = '';
                    while ( Date.now() < settleDeadline ) {
                        await sleep( 250 );
                        const snapshot = frameWindow.wp.blocks.serialize( blockEditorSelect.getBlocks() );
                        if ( snapshot !== '' && snapshot === previousSnapshot ) {
                            break;
                        }
                        previousSnapshot = snapshot;
                    }

                    const settled = blockEditorSelect.getBlocks();
                    return sameTopLevelNames( settled ) ? settled : null;
                } catch ( error ) {
                    return null;
                }
            };

            const serializeWithBlocksApi = async ( blocksApi, blocks ) => {
                const created = blocks.map( ( spec ) => toBlock( blocksApi, spec ) );
                const settled = await mountAndSettleBlocks( created );
                const content = blocksApi.serialize( settled || created );
                const parsed = blocksApi.parse( content );
                const validations = validateBlocks( blocksApi, parsed );
                const errors = [];
                validations.forEach( ( validation ) => {
                    if ( validation.isValid ) {
                        return;
                    }
                    const issues = validation.issues.length ? validation.issues : [ { message: 'Block validation failed.' } ];
                    issues.forEach( ( issue ) => errors.push( compactIssue( validation, issue ) ) );
                } );
                return { content, validations, errors };
            };

            const errorMessage = ( error, fallback ) => error && error.message ? error.message : fallback;

            const errorCode = ( error, fallback ) => error && error.code ? String( error.code ) : fallback;

            const consolePrefix = '[Novamira Block Editor Queue]';

            const warn = ( message ) => {
                if ( window.console && typeof window.console.warn === 'function' ) {
                    window.console.warn( `${ consolePrefix } ${ message }` );
                }
            };

            const logError = ( message, error ) => {
                if ( window.console && typeof window.console.error === 'function' ) {
                    window.console.error( `${ consolePrefix } ${ message }`, error );
                }
            };

            const bridgeResponded = ( error ) => !! error && error.bridgeResponded === true;

            // The direct iframe read never reached the editor runtime and the serializer bridge never
            // answered (unavailable, timed out, or the frame never navigated), so nothing came back
            // from the hidden editor at all. This is an environment problem (isolation headers,
            // sandboxing, redirects), not a block-spec problem, so it is reported as one runtime
            // failure instead of per-block registration rows. unserializedRefs lists the block types
            // that could not be serialized as a result (the ones the page-local runtime lacks, or every
            // required type when that runtime is unavailable); localRuntimeNote explains the latter.
            const frameInaccessibleError = ( serializationRuntimeReason, unserializedRefs, localRuntimeNote = '' ) => {
                const names = uniqueBlockNames( unserializedRefs );
                const error = new Error(
                    'The hidden block editor iframe could not be used, so canonical content was not written. '
                    + 'This is an environment problem on the finalizing browser or site, not a block-spec problem. '
                    + ( names.length
                        ? `Block types that could not be serialized: ${ names.join( ', ' ) }. `
                        : '' )
                    + ( localRuntimeNote !== '' ? `${ localRuntimeNote } ` : '' )
                    + `Direct access: ${ serializationRuntimeReason }`
                );
                error.code = 'editor_frame_inaccessible';
                error.category = 'runtime';
                error.missingBlockRefs = unserializedRefs;
                return error;
            };

            const serializeJob = async ( job ) => {
                const blocks = job.blocks || [];
                const refs = collectBlockRefs( blocks );
                let frameError = null;
                let bridgeError = null;
                // True once the direct read returned a usable block API: any later tier-1 error is a
                // serializer error from a reachable frame, not a frame-access problem.
                let frameReached = false;

                try {
                    await navigateEditorFrame( job.editor_url || '' );
                    const blocksApi = await waitForEditorBlocksApi();
                    frameReached = true;
                    await waitForBlockRegistrations( blocksApi, refs );
                    const result = await serializeWithBlocksApi( blocksApi, blocks );
                    return {
                        ...result,
                        serializationRuntime: 'iframe',
                        serializationRuntimeReason: '',
                    };
                } catch ( error ) {
                    frameError = error;
                }

                warn(
                    `Direct access to the hidden editor iframe failed [${ errorCode( frameError, 'js_exception' ) }]: `
                    + `${ errorMessage( frameError, 'Direct iframe access was unavailable.' ) } `
                    + 'Trying the same-origin postMessage serializer bridge.'
                );

                try {
                    const result = await serializeThroughEditorFrame( blocks );
                    return {
                        content: typeof result.content === 'string' ? result.content : '',
                        validations: Array.isArray( result.validations ) ? result.validations : [],
                        errors: Array.isArray( result.errors ) ? result.errors : [],
                        serializationRuntime: 'iframe',
                        serializationRuntimeReason: errorMessage(
                            frameError,
                            'Direct iframe access was unavailable.'
                        ) + ' Recovered through the same-origin postMessage serializer bridge. '
                            + editorFrameDiagnosticSummary(),
                    };
                } catch ( error ) {
                    bridgeError = error;
                }

                const frameReason = errorMessage( frameError, 'Direct iframe access was unavailable.' );
                const bridgeReason = errorMessage( bridgeError, 'The iframe serializer bridge was unavailable.' );
                const serializationRuntimeReason = `${ frameReason } Bridge: ${ bridgeReason } `
                    + editorFrameDiagnosticSummary();

                warn(
                    `The hidden editor serializer bridge failed [${ errorCode( bridgeError, 'js_exception' ) }]: `
                    + `${ bridgeReason } Trying the local block runtime of this page (core blocks only). `
                    + editorFrameDiagnosticSummary()
                );

                // The hidden editor is only "inaccessible" when nothing came back from it: the direct
                // read never reached its runtime and the bridge never answered. If the bridge did
                // answer, or the direct read got hold of the runtime, the error it produced is a
                // genuine serializer error and must be preserved as-is.
                const editorFrameUnusable = ! frameReached && ! bridgeResponded( bridgeError );
                const editorReportedError = bridgeResponded( bridgeError ) ? bridgeError : ( frameReached ? frameError : null );

                const fallbackApi = localBlocksApi();
                if ( ! fallbackApi ) {
                    warn( 'The local block runtime of this page is unavailable. No serialization runtime is left.' );
                    const error = editorFrameUnusable
                        ? frameInaccessibleError(
                            serializationRuntimeReason,
                            refs,
                            'The block runtime of this page is unavailable as well.'
                        )
                        : ( editorReportedError || new Error( 'No block serialization runtime is available.' ) );
                    error.serializationRuntime = 'fallback';
                    error.serializationRuntimeReason = serializationRuntimeReason;
                    throw error;
                }

                const missingRefs = refs.filter( ( ref ) => ! fallbackApi.getBlockType( ref.name ) );
                if ( missingRefs.length ) {
                    const missingNames = uniqueBlockNames( missingRefs ).join( ', ' );
                    let registrationError;
                    if ( editorFrameUnusable ) {
                        // The missing types on this page are a symptom of the unusable iframe, not
                        // of the block spec.
                        registrationError = frameInaccessibleError( serializationRuntimeReason, missingRefs );
                        warn(
                            `The local block runtime of this page lacks block types the hidden editor could not be asked about: ${ missingNames }. `
                            + 'Attributing the failure to the inaccessible editor iframe.'
                        );
                    } else {
                        registrationError = editorReportedError || missingRegistrationError( missingRefs );
                        warn(
                            `The local block runtime of this page cannot serialize block types (${ missingNames }); `
                            + `keeping the hidden editor's own error [${ errorCode( registrationError, 'js_exception' ) }]: `
                            + errorMessage( registrationError, 'Block serialization failed.' )
                        );
                    }
                    registrationError.serializationRuntime = 'fallback';
                    registrationError.serializationRuntimeReason = serializationRuntimeReason;
                    throw registrationError;
                }

                fallbackWarning = 'The hidden block editor iframe and its serializer bridge could not be used, so blocks '
                    + 'were serialized with the block runtime of this page. Only blocks registered on this page are '
                    + `supported. Reason: ${ serializationRuntimeReason }`;
                warn( `Serializing with the local block runtime of this page. Reason: ${ serializationRuntimeReason }` );

                const result = await serializeWithBlocksApi( fallbackApi, blocks );
                return {
                    ...result,
                    serializationRuntime: 'fallback',
                    serializationRuntimeReason,
                };
            };

            const failCurrentItem = async (
                itemId,
                errors,
                message,
                serializationRuntime = '',
                serializationRuntimeReason = ''
            ) => apiFetch( {
                path: path( `/gutenberg/items/${ itemId }/fail` ),
                method: 'POST',
                data: {
                    lease_owner: leaseOwner,
                    errors,
                    message,
                    serialization_runtime: serializationRuntime,
                    serialization_runtime_reason: serializationRuntimeReason,
                },
            } );

            const heartbeat = async () => apiFetch( {
                path: path( '/gutenberg/finalizer-runtime/heartbeat' ),
                method: 'POST',
            } );

            const batchErrorMessage = ( batch, fallback ) => {
                const lastError = batch && typeof batch.last_error === 'string' ? batch.last_error.trim() : '';
                return lastError !== '' ? lastError : fallback;
            };

            // The notice element only receives textContent, so the batch error is rendered as
            // plain text and never interpreted as markup.
            const reportBatchFailure = ( batch, fallbackMessage, error = null ) => {
                const message = batchErrorMessage( batch, fallbackMessage );
                setProgress( 'Something needs attention. Return to the agent.' );
                setNotice( 'error', message );
                logError( `Batch failed: ${ message }`, error );
            };

            const finalNotice = ( batch ) => {
                if ( batch && batch.status === 'finalized' ) {
                    if ( fallbackWarning ) {
                        setNotice( 'warning', fallbackWarning );
                    } else {
                        clearNotice();
                    }
                    setProgress( 'Nothing to do. The queue is ready.' );
                    return;
                }

                reportBatchFailure( batch, 'Something needs attention. Return to the agent.' );
            };

            const processBatch = async ( batchId, initialClaim = null ) => {
                const activeBatchId = Number( batchId || 0 );
                if ( ! activeBatchId ) {
                    return false;
                }
                if ( isRunning ) {
                    return false;
                }

                isRunning = true;
                fallbackWarning = '';
                try {
                    clearNotice();
                    setProgress( 'Working on queued Gutenberg changes...' );
                    const claim = initialClaim || await apiFetch( {
                        path: path( `/gutenberg/batches/${ activeBatchId }/claim` ),
                        method: 'POST',
                    } );
                    leaseOwner = claim.lease_owner;

                    let processed = 0;
                    const total = claim.batch && claim.batch.item_count ? claim.batch.item_count : 0;
                    while ( true ) {
                        const next = await apiFetch( {
                            path: path( `/gutenberg/batches/${ activeBatchId }/items/claim-next` ),
                            method: 'POST',
                            data: { lease_owner: leaseOwner },
                        } );
                        if ( next.done ) {
                            finalNotice( next.batch );
                            break;
                        }

                        const item = next.item;
                        setProgress(
                            total > 1
                                ? `Working on queued Gutenberg changes (${ processed + 1 } of ${ total })...`
                                : 'Working on queued Gutenberg changes...'
                        );
                        const job = await apiFetch( {
                            path: path( `/gutenberg/items/${ item.item_id }/spec?lease_owner=${ encodeURIComponent( leaseOwner ) }` ),
                            method: 'GET',
                        } );

                        try {
                            const result = await serializeJob( job );
                            if ( result.errors.length ) {
                                const validationMessage = 'JS validation failed; canonical content was not written.';
                                const failed = await failCurrentItem(
                                    item.item_id,
                                    result.errors,
                                    validationMessage,
                                    result.serializationRuntime,
                                    result.serializationRuntimeReason
                                );
                                reportBatchFailure( failed && failed.batch, validationMessage, result.errors );
                                break;
                            }

                            const completed = await apiFetch( {
                                path: path( `/gutenberg/items/${ item.item_id }/complete` ),
                                method: 'POST',
                                data: {
                                    lease_owner: leaseOwner,
                                    content: result.content,
                                    validations: result.validations,
                                    serialization_runtime: result.serializationRuntime,
                                    serialization_runtime_reason: result.serializationRuntimeReason,
                                },
                            } );
                            processed += 1;
                            if ( completed.done ) {
                                finalNotice( completed.batch );
                                break;
                            }
                        } catch ( error ) {
                            const isFrameInaccessible = error && error.code === 'editor_frame_inaccessible';
                            const isMissingRegistration = error && error.code === 'missing_block_registration';
                            let errorItems;
                            let failureMessage;
                            if ( isFrameInaccessible ) {
                                // One runtime row: the block names are a symptom, not N registration failures.
                                errorItems = [ {
                                    block_name: '',
                                    path: '',
                                    category: 'runtime',
                                    code: 'editor_frame_inaccessible',
                                    message: error.message,
                                } ];
                                failureMessage = error.message;
                            } else if ( isMissingRegistration && Array.isArray( error.missingBlockRefs ) ) {
                                errorItems = error.missingBlockRefs.map( ( ref ) => ( {
                                    block_name: ref.name || '',
                                    path: ref.path || '',
                                    category: 'registration',
                                    code: 'missing_block_registration',
                                    message: `Block "${ ref.name || '(missing name)' }" was not registered in the block editor runtime.`,
                                } ) );
                                failureMessage = 'One or more Gutenberg blocks were not registered in the block editor runtime; canonical content was not written.';
                            } else {
                                errorItems = [ {
                                    block_name: '',
                                    path: '',
                                    category: ( error && error.category ) || 'serialization',
                                    code: ( error && error.code ) || 'js_exception',
                                    message: error && error.message ? error.message : String( error ),
                                } ];
                                failureMessage = 'The browser block serializer threw an exception.';
                            }
                            const failed = await failCurrentItem(
                                item.item_id,
                                errorItems,
                                failureMessage,
                                error && error.serializationRuntime ? error.serializationRuntime : '',
                                error && error.serializationRuntimeReason ? error.serializationRuntimeReason : ''
                            );
                            reportBatchFailure( failed && failed.batch, failureMessage, error );
                            break;
                        }
                    }
                } catch ( error ) {
                    setNotice( 'error', 'The queue stopped. Return to the agent.' );
                    setProgress( 'Something needs attention. Return to the agent.' );
                    logError( 'The queue stopped.', error );
                    return false;
                } finally {
                    isRunning = false;
                }

                return true;
            };

            const processDashboardQueue = async () => {
                if ( dashboardPollRunning || isRunning ) {
                    return;
                }

                dashboardPollRunning = true;
                try {
                    await heartbeat();
                    const next = await apiFetch( {
                        path: path( '/gutenberg/batches/claim-next' ),
                        method: 'POST',
                    } );
                    if ( next.done || ! next.claim || ! next.claim.batch ) {
                        clearNotice();
                        setProgress( 'Nothing to do. The queue is ready.' );
                        return;
                    }

                    clearNotice();
                    setProgress( 'Working on queued Gutenberg changes...' );
                    await processBatch( next.claim.batch.batch_id, next.claim );
                } catch ( error ) {
                    setNotice( 'error', 'Queue disconnected. Reload this page.' );
                    setProgress( 'Queue disconnected. Reload this page.' );
                } finally {
                    dashboardPollRunning = false;
                }
            };

            heartbeat().catch( () => {} );
            window.setInterval( () => {
                heartbeat().catch( () => {
                    setProgress( 'Queue disconnected. Reload this page.' );
                } );
            }, 15000 );

            window.setTimeout( processDashboardQueue, 250 );
            window.setInterval( processDashboardQueue, 5000 );
        }() );
        JS;
}
