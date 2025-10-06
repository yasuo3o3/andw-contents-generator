(() => {
	if ( ! window.wp || ! window.andwHtmlImporter ) {
		return;
	}

	const settings = window.andwHtmlImporter;
	const {
		plugins: { registerPlugin },
		editPost: { PluginSidebar, PluginSidebarMoreMenuItem },
		element: { createElement, useState, useMemo },
		components: { PanelBody, TextareaControl, Button, ToggleControl, Notice, Spinner, RangeControl },
		data: { dispatch, select },
		blocks,
		blockEditor,
		i18n: { __ },
		apiFetch,
	} = window.wp;

	const BlockPreview = ( blockEditor && blockEditor.BlockPreview ) || ( window.wp.editor && window.wp.editor.BlockPreview );

	if ( settings.nonce && apiFetch && apiFetch.createNonceMiddleware ) {
		apiFetch.use( apiFetch.createNonceMiddleware( settings.nonce ) );
	}

	const strings = settings.strings || {};

	const Panel = () => {
		const [ html, setHtml ] = useState( '' );
		const [ loading, setLoading ] = useState( false );
		const [ error, setError ] = useState( '' );
		const [ previewBlocks, setPreviewBlocks ] = useState( [] );
		const [ columnDetection, setColumnDetection ] = useState( !! settings.defaultColumns );
		const [ threshold, setThreshold ] = useState( settings.defaultThreshold || 0.7 );

		const postId = select( 'core/editor' ).getCurrentPostId();

		const hasPreview = useMemo( () => Array.isArray( previewBlocks ) && previewBlocks.length > 0, [ previewBlocks ] );

		const requestConvert = ( persistMedia ) => {
			if ( ! html.trim() ) {
				setError( __( 'HTMLを入力してください。', 'andw-contents-generator' ) );
				return Promise.reject();
			}

			setLoading( true );
			setError( '' );

			return apiFetch( {
				url: settings.restUrl,
				method: 'POST',
				data: {
					html,
					post_id: postId || 0,
					column_detection: columnDetection,
					persist_media: persistMedia,
					score_threshold: threshold,
				},
			} )
				.catch( ( err ) => {
					const message = ( err && err.message ) ? err.message : strings.failure || __( 'HTMLの変換に失敗しました。', 'andw-contents-generator' );
					setError( message );
					const noticeStore = dispatch( 'core/notices' );

					if ( noticeStore && noticeStore.createNotice ) {
						noticeStore.createNotice( 'error', message, { type: 'snackbar' } );
					}

					throw err;
				} )
				.finally( () => {
					setLoading( false );
				} );
		};

		const handlePreview = () => {
			requestConvert( false ).then( ( response ) => {
				if ( response && response.blocks ) {
					let parsed = [];

					try {
						parsed = blocks.parse( response.blocks );
					} catch ( parseError ) {
						console.error( 'andW HTML preview parse error', parseError );
					}

					setPreviewBlocks( parsed );
				}
			} );
		};

		const applyBlocks = ( response, action ) => {
			if ( ! response || ! response.blocks ) {
				return;
			}

			let parsed = [];

			try {
				parsed = blocks.parse( response.blocks );
			} catch ( parseError ) {
				console.error( 'andW HTML apply parse error', parseError );
				setError( strings.failure || __( 'HTMLの変換に失敗しました。', 'andw-contents-generator' ) );
				return;
			}

			const editor = dispatch( 'core/block-editor' );

			if ( 'replace' === action ) {
				editor.resetBlocks( parsed );
			} else {
				editor.insertBlocks( parsed );
			}

			dispatch( 'core/editor' ).editPost( { status: 'draft' } );

			const noticeStore = dispatch( 'core/notices' );

			if ( noticeStore && noticeStore.createNotice ) {
				const message = 'replace' === action ? ( strings.successInsert || __( '変換結果を下書きに反映しました。', 'andw-contents-generator' ) ) : ( strings.successAppend || __( '変換結果を追記しました。', 'andw-contents-generator' ) );
				noticeStore.createNotice( 'success', message, { type: 'snackbar' } );
			}
		};

		const handleInsert = () => {
			if ( ! postId ) {
				setError( __( '投稿を保存してから実行してください。', 'andw-contents-generator' ) );
				return;
			}

			requestConvert( true ).then( ( response ) => applyBlocks( response, 'replace' ) );
		};

		const handleAppend = () => {
			if ( ! postId ) {
				setError( __( '投稿を保存してから実行してください。', 'andw-contents-generator' ) );
				return;
			}

			requestConvert( true ).then( ( response ) => applyBlocks( response, 'append' ) );
		};

		return createElement(
			PluginSidebar,
			{
				slug: 'andw-html-importer',
				title: strings.panelTitle || __( 'HTMLインポート', 'andw-contents-generator' ),
			},
			createElement(
				PanelBody,
				{ title: strings.panelTitle || __( 'HTMLインポート', 'andw-contents-generator' ), initialOpen: true },
				createElement( TextareaControl, {
					label: strings.pasteLabel || __( '静的HTMLを貼り付け', 'andw-contents-generator' ),
					value: html,
					onChange: setHtml,
					rows: 12,
				} ),
				createElement( ToggleControl, {
					label: strings.columnsToggle || __( '列化を自動検出する', 'andw-contents-generator' ),
					checked: columnDetection,
					onChange: () => setColumnDetection( ( value ) => ! value ),
				} ),
				createElement( RangeControl, {
					label: __( '列化スコア閾値', 'andw-contents-generator' ),
					value: threshold,
					onChange: setThreshold,
					min: 0,
					max: 1,
					step: 0.05,
				} ),
				error && createElement( Notice, { status: 'error', isDismissible: true, onRemove: () => setError( '' ) }, error ),
				createElement( 'div', { className: 'andw-html-buttons', style: { display: 'flex', gap: '6px', marginTop: '8px' } },
					createElement( Button, {
						variant: 'secondary',
						onClick: handlePreview,
						disabled: loading,
					}, loading ? createElement( Spinner, null ) : ( strings.preview || __( 'プレビュー', 'andw-contents-generator' ) ) ),
					createElement( Button, {
						variant: 'primary',
						onClick: handleInsert,
						disabled: loading,
					}, loading ? createElement( Spinner, null ) : ( strings.insertDraft || __( '下書きとして挿入', 'andw-contents-generator' ) ) ),
					createElement( Button, {
						variant: 'tertiary',
						onClick: handleAppend,
						disabled: loading,
					}, loading ? createElement( Spinner, null ) : ( strings.append || __( '現在の記事に追記', 'andw-contents-generator' ) ) )
				),
				hasPreview && BlockPreview && createElement( 'div', { style: { marginTop: '12px' } },
					createElement( 'h3', null, __( 'プレビュー', 'andw-contents-generator' ) ),
					createElement( BlockPreview, { blocks: previewBlocks, viewportWidth: 600 } )
				)
			)
		);
	};

	const MenuItem = () => createElement( PluginSidebarMoreMenuItem, { target: 'andw-html-importer' }, strings.panelTitle || __( 'HTMLインポート', 'andw-contents-generator' ) );

	const render = () => [ createElement( Panel, { key: 'panel' } ), createElement( MenuItem, { key: 'menu' } ) ];

	registerPlugin( 'andw-html-importer', { render } );
})();
