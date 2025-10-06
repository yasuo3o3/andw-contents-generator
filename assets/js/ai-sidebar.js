(() => {
	if ( ! window.wp || ! window.andwAiSidebar ) {
		return;
	}

	const settings = window.andwAiSidebar;
	const {
		plugins: { registerPlugin },
		editPost: { PluginSidebar, PluginSidebarMoreMenuItem },
		element: { createElement, useState },
		components: { PanelBody, Button, TextControl, Spinner, Notice },
		data: { dispatch, select },
		blocks,
		i18n: { __ },
		apiFetch,
	} = window.wp;

	if ( settings.nonce && apiFetch && apiFetch.createNonceMiddleware ) {
		apiFetch.use( apiFetch.createNonceMiddleware( settings.nonce ) );
	}

	const strings = settings.strings || {};

	const Panel = () => {
		const [ keywords, setKeywords ] = useState( '' );
		const [ loading, setLoading ] = useState( false );
		const [ error, setError ] = useState( '' );

		const runGeneration = ( mode ) => {
			if ( ! settings.isReady ) {
				setError( strings.needConfig || __( '設定が不足しています。', 'andw-contents-generator' ) );
				return;
			}

			const trimmed = keywords.trim();

			if ( ! trimmed ) {
				setError( __( 'キーワードを入力してください。', 'andw-contents-generator' ) );
				return;
			}

			setLoading( true );
			setError( '' );

			const postId = select( 'core/editor' ).getCurrentPostId();

			apiFetch( {
				url: settings.restUrl,
				method: 'POST',
				data: {
					keywords: trimmed,
					mode,
					post_id: postId,
				},
			} )
				.then( ( response ) => {
					if ( response.blocks ) {
						let parsed = [];

						try {
							parsed = blocks.parse( response.blocks );
						} catch ( parseError ) {
							console.error( 'andW AI parse error', parseError );
						}

						if ( parsed && parsed.length ) {
							dispatch( 'core/block-editor' ).insertBlocks( parsed );
						}
					}

					const update = { status: 'draft' };

					if ( response.summary ) {
						update.excerpt = response.summary;
					}

					dispatch( 'core/editor' ).editPost( update );

					const noticeStore = dispatch( 'core/notices' );

					if ( noticeStore && noticeStore.createNotice ) {
						noticeStore.createNotice( 'success', strings.success || __( 'AI生成が完了しました。', 'andw-contents-generator' ), { type: 'snackbar' } );
					}

					setKeywords( '' );
				} )
				.catch( ( err ) => {
					const message = ( err && err.message ) ? err.message : strings.failure || __( 'AI生成に失敗しました。', 'andw-contents-generator' );
					setError( message );
					const noticeStore = dispatch( 'core/notices' );

					if ( noticeStore && noticeStore.createNotice ) {
						noticeStore.createNotice( 'error', message, { type: 'snackbar' } );
					}
				} )
				.finally( () => {
					setLoading( false );
				} );
		};

		return createElement(
			PluginSidebar,
			{
				slug: 'andw-ai-sidebar',
				title: strings.panelTitle || __( 'AI生成', 'andw-contents-generator' ),
			},
			createElement(
				PanelBody,
				{ title: strings.panelTitle || __( 'AI生成', 'andw-contents-generator' ), initialOpen: true },
				! settings.isReady && createElement( Notice, { status: 'warning', isDismissible: false }, strings.needConfig || __( 'AI設定を完了してください。', 'andw-contents-generator' ) ),
				createElement( TextControl, {
					label: strings.keywordsLabel || __( 'キーワード', 'andw-contents-generator' ),
					value: keywords,
					onChange: setKeywords,
					placeholder: strings.placeholder || '',
				} ),
				error && createElement( Notice, { status: 'error', isDismissible: true, onRemove: () => setError( '' ) }, error ),
				createElement( 'div', { className: 'andw-ai-buttons' },
					createElement( Button, {
						isPrimary: true,
						onClick: () => runGeneration( 'heading' ),
						disabled: loading,
					}, loading ? createElement( Spinner, null ) : ( strings.generateHeading || __( '見出し生成', 'andw-contents-generator' ) ) ),
					createElement( Button, {
						isSecondary: true,
						onClick: () => runGeneration( 'body' ),
						disabled: loading,
					}, loading ? createElement( Spinner, null ) : ( strings.generateBody || __( '本文生成', 'andw-contents-generator' ) ) ),
					createElement( Button, {
						isTertiary: true,
						onClick: () => runGeneration( 'summary' ),
						disabled: loading,
					}, loading ? createElement( Spinner, null ) : ( strings.generateSummary || __( '要約生成', 'andw-contents-generator' ) ) )
				)
			)
		);
	};

	const MenuItem = () => createElement( PluginSidebarMoreMenuItem, { target: 'andw-ai-sidebar' }, strings.panelTitle || __( 'AI生成', 'andw-contents-generator' ) );

	const render = () => [ createElement( Panel, { key: 'panel' } ), createElement( MenuItem, { key: 'menu' } ) ];

	registerPlugin( 'andw-ai-sidebar', { render } );
})();
