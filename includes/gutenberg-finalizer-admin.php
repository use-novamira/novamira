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
    add_action('admin_menu', __NAMESPACE__ . '\\register_gutenberg_finalizer_menu');
    add_action('admin_enqueue_scripts', __NAMESPACE__ . '\\enqueue_gutenberg_finalizer_assets');
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
        capability: 'edit_posts',
        menu_slug: gutenberg_finalizer_page_slug(),
        callback: __NAMESPACE__ . '\\render_gutenberg_finalizer_page',
    );
}

function enqueue_gutenberg_finalizer_assets(string $hook_suffix): void
{
    if (!is_gutenberg_finalizer_request()) {
        return;
    }

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

    unset($hook_suffix);
}

function is_gutenberg_finalizer_request(): bool
{
    return ($_GET['page'] ?? '') === gutenberg_finalizer_page_slug();
}

function render_gutenberg_finalizer_page(): void
{
    if (!current_user_can('edit_posts')) {
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
            const root = document.getElementById( 'novamira-gb-finalizer' );
            if ( ! root || ! window.wp || ! wp.apiFetch ) {
                return;
            }

            const apiFetch = wp.apiFetch;
            apiFetch.use( apiFetch.createNonceMiddleware( config.nonce ) );

            const progress = document.getElementById( 'novamira-gb-progress' );
            const notice = document.getElementById( 'novamira-gb-notice' );
            const editorFrame = document.getElementById( 'novamira-gb-editor-frame' );
            const editorLoadTimeoutMs = Number( config.editorLoadTimeoutMs || 30000 );
            const blockRegistrationTimeoutMs = Number( config.blockRegistrationTimeoutMs || 30000 );
            let leaseOwner = '';
            let isRunning = false;
            let dashboardPollRunning = false;
            let editorFrameUrl = '';
            let editorFrameLoadPromise = Promise.resolve();

            const path = ( suffix ) => `/novamira/v1${ suffix }`;

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

            const navigateEditorFrame = ( editorUrl ) => {
                if ( ! editorFrame ) {
                    throw new Error( 'The hidden editor iframe is not available on this admin page.' );
                }

                const nextUrl = sameOriginEditorUrl( editorUrl );
                if ( editorFrameUrl === nextUrl ) {
                    return editorFrameLoadPromise;
                }

                editorFrameUrl = nextUrl;
                editorFrameLoadPromise = new Promise( ( resolve, reject ) => {
                    let settled = false;
                    const cleanup = () => {
                        editorFrame.removeEventListener( 'load', onLoad );
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

                    editorFrame.addEventListener( 'load', onLoad );
                    editorFrame.src = nextUrl;
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

            const editorBlocksApi = () => {
                const frameWindow = iframeWindow();
                if ( ! frameWindow || ! frameWindow.wp || ! frameWindow.wp.blocks ) {
                    return null;
                }

                const blocksApi = frameWindow.wp.blocks;
                const required = [ 'createBlock', 'serialize', 'parse', 'validateBlock', 'getBlockType' ];
                const hasRequiredMethods = required.every( ( method ) => typeof blocksApi[ method ] === 'function' );

                return hasRequiredMethods ? blocksApi : null;
            };

            const waitForEditorBlocksApi = async () => {
                const startedAt = Date.now();
                while ( Date.now() - startedAt < editorLoadTimeoutMs ) {
                    const blocksApi = editorBlocksApi();
                    if ( blocksApi ) {
                        return blocksApi;
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

            const loadEditorBlocksApi = async ( editorUrl, blocks ) => {
                await navigateEditorFrame( editorUrl );
                const blocksApi = await waitForEditorBlocksApi();
                await waitForBlockRegistrations( blocksApi, collectBlockRefs( blocks ) );
                return blocksApi;
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

            const serializeJob = async ( job ) => {
                const blocks = job.blocks || [];
                const blocksApi = await loadEditorBlocksApi( job.editor_url || '', blocks );
                const created = blocks.map( ( spec ) => toBlock( blocksApi, spec ) );
                const content = blocksApi.serialize( created );
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

            const failCurrentItem = async ( itemId, errors, message ) => apiFetch( {
                path: path( `/gutenberg/items/${ itemId }/fail` ),
                method: 'POST',
                data: {
                    lease_owner: leaseOwner,
                    errors,
                    message,
                },
            } );

            const heartbeat = async () => apiFetch( {
                path: path( '/gutenberg/finalizer-runtime/heartbeat' ),
                method: 'POST',
            } );

            const finalNotice = ( batch ) => {
                if ( batch && batch.status === 'finalized' ) {
                    clearNotice();
                    setProgress( 'Nothing to do. The queue is ready.' );
                    return;
                }

                setProgress( 'Something needs attention. Return to the agent.' );
                setNotice( 'error', 'Something needs attention. Return to the agent.' );
            };

            const processBatch = async ( batchId ) => {
                const activeBatchId = Number( batchId || 0 );
                if ( ! activeBatchId ) {
                    return false;
                }
                if ( isRunning ) {
                    return false;
                }

                isRunning = true;
                try {
                    clearNotice();
                    setProgress( 'Working on queued Gutenberg changes...' );
                    const claim = await apiFetch( {
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
                                await failCurrentItem( item.item_id, result.errors, 'JS validation failed; canonical content was not written.' );
                                setProgress( 'Something needs attention. Return to the agent.' );
                                setNotice( 'error', 'Something needs attention. Return to the agent.' );
                                break;
                            }

                            const completed = await apiFetch( {
                                path: path( `/gutenberg/items/${ item.item_id }/complete` ),
                                method: 'POST',
                                data: {
                                    lease_owner: leaseOwner,
                                    content: result.content,
                                    validations: result.validations,
                                },
                            } );
                            processed += 1;
                            if ( completed.done ) {
                                finalNotice( completed.batch );
                                break;
                            }
                        } catch ( error ) {
                            const isMissingRegistration = error && error.code === 'missing_block_registration';
                            const errorItems = isMissingRegistration && Array.isArray( error.missingBlockRefs )
                                ? error.missingBlockRefs.map( ( ref ) => ( {
                                    block_name: ref.name || '',
                                    path: ref.path || '',
                                    category: 'registration',
                                    code: 'missing_block_registration',
                                    message: `Block "${ ref.name || '(missing name)' }" was not registered in the editor iframe.`,
                                } ) )
                                : [ {
                                    block_name: '',
                                    path: '',
                                    category: 'serialization',
                                    code: 'js_exception',
                                    message: error.message || String( error ),
                                } ];
                            await failCurrentItem(
                                item.item_id,
                                errorItems,
                                isMissingRegistration
                                    ? 'One or more Gutenberg blocks were not registered in the editor iframe; canonical content was not written.'
                                    : 'The browser block serializer threw an exception.'
                            );
                            setProgress( 'Something needs attention. Return to the agent.' );
                            setNotice( 'error', 'Something needs attention. Return to the agent.' );
                            break;
                        }
                    }
                } catch ( error ) {
                    setNotice( 'error', 'The queue stopped. Return to the agent.' );
                    setProgress( 'Something needs attention. Return to the agent.' );
                    return false;
                } finally {
                    isRunning = false;
                }

                return true;
            };

            const refreshDashboardBatches = async () => {
                const response = await apiFetch( {
                    path: path( '/gutenberg/batches?status=ready,failed' ),
                    method: 'GET',
                } );

                return Array.isArray( response.batches ) ? response.batches : [];
            };

            const processDashboardQueue = async () => {
                if ( dashboardPollRunning || isRunning ) {
                    return;
                }

                dashboardPollRunning = true;
                try {
                    await heartbeat();
                    const batches = await refreshDashboardBatches();
                    const batch = batches.find( ( item ) => [ 'ready', 'failed' ].includes( item.status ) );
                    if ( ! batch ) {
                        clearNotice();
                        setProgress( 'Nothing to do. The queue is ready.' );
                        return;
                    }

                    clearNotice();
                    setProgress( 'Working on queued Gutenberg changes...' );
                    await processBatch( batch.batch_id );
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
