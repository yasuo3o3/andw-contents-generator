<?php
defined( 'ABSPATH' ) || exit;

/**
 * HTML importer that converts markup to Gutenberg blocks.
 */
class Andw_Contents_Generator_HTML_Importer {
	/**
	 * Settings manager.
	 *
	 * @var Andw_Contents_Generator_Settings
	 */
	private $settings;

	/**
	 * Logger instance.
	 *
	 * @var Andw_Contents_Generator_Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param Andw_Contents_Generator_Settings $settings Settings manager.
	 * @param Andw_Contents_Generator_Logger   $logger   Logger instance.
	 */
	public function __construct( Andw_Contents_Generator_Settings $settings, Andw_Contents_Generator_Logger $logger ) {
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	/**
	 * Convert HTML to Gutenberg block markup.
	 *
	 * @param string $html  Raw HTML string.
	 * @param array  $args  Conversion options.
	 *
	 * @return array|WP_Error
	 */
	public function convert( $html, $args = array() ) {
		if ( empty( $html ) ) {
			return new WP_Error( 'andw_html_empty', __( 'HTMLを入力してください。', 'andw-contents-generator' ) );
		}

		$options       = $this->prepare_options( $args );
		$dom           = new DOMDocument();
		$libxml_state  = libxml_use_internal_errors( true );
		$encoded_html  = '<?xml encoding="utf-8" ?>' . $html;

		if ( ! $dom->loadHTML( $encoded_html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD ) ) {
			libxml_clear_errors();
			libxml_use_internal_errors( $libxml_state );
			return new WP_Error( 'andw_html_parse_error', __( 'HTMLを解析できませんでした。', 'andw-contents-generator' ) );
		}

		libxml_clear_errors();
		libxml_use_internal_errors( $libxml_state );

		$this->sanitize_dom( $dom, $options );
		$body = $dom->getElementsByTagName( 'body' )->item( 0 );

		if ( ! $body ) {
			$body = $dom;
		}

		$blocks = $this->walk_nodes( $body->childNodes, $options );
		$blocks = $this->apply_column_detection( $blocks, $options );
		$markup = $this->blocks_to_markup( $blocks, $options );

		return array(
			'blocks'            => $markup,
			'column_detection'  => (bool) $options['column_detection'],
			'strip_attributes'  => (bool) $options['strip_attributes'],
			'score_threshold'   => (float) $options['score_threshold'],
		);
	}

	/**
	 * Prepare options merging defaults and user input.
	 *
	 * @param array $args Raw args.
	 *
	 * @return array
	 */
	private function prepare_options( $args ) {
		$html_settings = $this->settings->get_html_settings();

		$options = wp_parse_args(
			$args,
			array(
				'column_detection' => (bool) $html_settings['column_detection'],
				'score_threshold'  => (float) $html_settings['score_threshold'],
				'strip_attributes' => (bool) $html_settings['strip_attributes'],
				'allowlist'        => (array) $html_settings['allowlist_domains'],
				'post_id'          => 0,
				'persist_media'    => false,
			)
		);

		$options['column_detection'] = (bool) $options['column_detection'];
		$options['strip_attributes'] = (bool) $options['strip_attributes'];
		$options['persist_media']    = (bool) $options['persist_media'];
		$options['score_threshold']  = min( max( (float) $options['score_threshold'], 0.0 ), 1.0 );
		$options['allowlist']        = array_map( 'strtolower', array_filter( array_map( 'sanitize_text_field', $options['allowlist'] ) ) );
		$options['post_id']          = absint( $options['post_id'] );

		return $options;
	}

	/**
	 * Sanitize DOM by removing disallowed nodes/attributes.
	 *
	 * @param DOMDocument $dom     DOM instance.
	 * @param array       $options Options.
	 */
	private function sanitize_dom( DOMDocument $dom, $options ) {
		$xpath = new DOMXPath( $dom );

		$disallowed_tags = array( 'script', 'style', 'meta', 'link', 'object', 'embed' );

		foreach ( $disallowed_tags as $tag ) {
			foreach ( iterator_to_array( $xpath->query( '//' . $tag ) ) as $node ) {
				$node->parentNode->removeChild( $node );
			}
		}

		foreach ( iterator_to_array( $xpath->query( '//@*' ) ) as $attr ) {
			/** @var DOMAttr $attr */
			$name = strtolower( $attr->nodeName );

			if ( 0 === strpos( $name, 'on' ) ) {
				$attr->ownerElement->removeAttributeNode( $attr );
				continue;
			}

			if ( ! $options['strip_attributes'] ) {
				continue;
			}

			$preserve = array( 'href', 'src', 'alt', 'title', 'colspan', 'rowspan' );

			if ( ! in_array( $name, $preserve, true ) ) {
				$attr->ownerElement->removeAttributeNode( $attr );
			}
		}

		foreach ( iterator_to_array( $xpath->query( '//iframe' ) ) as $iframe ) {
			/** @var DOMElement $iframe */
			$src = $iframe->getAttribute( 'src' );

			if ( empty( $src ) || ! $this->is_iframe_allowed( $src, $options['allowlist'] ) ) {
				$iframe->parentNode->removeChild( $iframe );
			}
		}
	}

	/**
	 * Determine iframe allowlist status.
	 *
	 * @param string $src       Source URL.
	 * @param array  $allowlist Domain allowlist.
	 *
	 * @return bool
	 */
	private function is_iframe_allowed( $src, $allowlist ) {
		$host = wp_parse_url( $src, PHP_URL_HOST );

		if ( ! $host ) {
			return false;
		}

		$host = strtolower( $host );

		foreach ( $allowlist as $allowed ) {
			$pattern = '/(^|\.)' . preg_quote( $allowed, '/' ) . '$/';

			if ( preg_match( $pattern, $host ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Walk nodes recursively and compile block candidates.
	 *
	 * @param DOMNodeList $nodes   Nodes.
	 * @param array       $options Options.
	 *
	 * @return array
	 */
	private function walk_nodes( DOMNodeList $nodes, $options ) {
		$blocks = array();

		foreach ( $nodes as $node ) {
			if ( XML_TEXT_NODE === $node->nodeType ) {
				$text = trim( preg_replace( '/\s+/u', ' ', $node->nodeValue ) );

				if ( '' !== $text ) {
					$blocks[] = array(
						'type'    => 'paragraph',
						'content' => $text,
						'length'  => strlen( $text ),
					);
				}

				continue;
			}

			if ( XML_ELEMENT_NODE !== $node->nodeType ) {
				continue;
			}

			$tag     = strtolower( $node->nodeName );
			$handled = $this->convert_element_node( $node, $tag, $options );

			if ( empty( $handled ) ) {
				continue;
			}

			if ( isset( $handled[0] ) && is_array( $handled[0] ) && ! isset( $handled['type'] ) ) {
				$blocks = array_merge( $blocks, $handled );
			} else {
				$blocks[] = $handled;
			}
		}

		return $blocks;
	}

	/**
	 * Convert element node into block structure.
	 *
	 * @param DOMElement $node    Node.
	 * @param string     $tag     Tag name.
	 * @param array      $options Options.
	 *
	 * @return array
	 */
	private function convert_element_node( DOMElement $node, $tag, $options ) {
		switch ( $tag ) {
			case 'h1':
			case 'h2':
			case 'h3':
			case 'h4':
			case 'h5':
			case 'h6':
				$level = in_array( $tag, array( 'h1', 'h2' ), true ) ? 2 : 3;
				$text  = trim( $node->textContent );

				if ( '' === $text ) {
					return array();
				}

				return array(
					'type'    => 'heading',
					'level'   => $level,
					'content' => $text,
					'length'  => strlen( $text ),
				);

			case 'p':
			case 'span':
				$text = trim( $node->textContent );

				if ( '' === $text ) {
					return array();
				}

				return array(
					'type'    => 'paragraph',
					'content' => $text,
					'length'  => strlen( $text ),
				);

			case 'ul':
			case 'ol':
				$items = array();

				foreach ( $node->childNodes as $child ) {
					if ( 'li' !== strtolower( $child->nodeName ) ) {
						continue;
					}

					$text = trim( $child->textContent );

					if ( '' !== $text ) {
						$items[] = $text;
					}
				}

				if ( empty( $items ) ) {
					return array();
				}

				return array(
					'type'    => 'list',
					'ordered' => 'ol' === $tag,
					'items'   => $items,
					'length'  => array_sum( array_map( 'strlen', $items ) ),
				);

			case 'blockquote':
				$text = trim( $node->textContent );

				if ( '' === $text ) {
					return array();
				}

				return array(
					'type'    => 'quote',
					'content' => $text,
					'length'  => strlen( $text ),
				);

			case 'img':
				$src = $node->getAttribute( 'src' );

				if ( empty( $src ) ) {
					return array();
				}

				return array(
					'type'  => 'image',
					'src'   => $src,
					'alt'   => $node->getAttribute( 'alt' ),
					'length'=> 1,
				);

			case 'table':
				return $this->convert_table( $node );

			case 'figure':
				return $this->convert_figure( $node, $options );

			default:
				$children = $this->walk_nodes( $node->childNodes, $options );

				if ( empty( $children ) ) {
					return array();
				}

				return array(
					'type'       => 'container',
					'tag'        => $tag,
					'blocks'     => $children,
					'length'     => $this->sum_length( $children ),
					'layoutHint' => $this->extract_layout_hint( $node ),
				);
		}
	}

	/**
	 * Convert table element to structure.
	 *
	 * @param DOMElement $node Table node.
	 *
	 * @return array
	 */
	private function convert_table( DOMElement $node ) {
		$rows = array();

		foreach ( $node->getElementsByTagName( 'tr' ) as $row ) {
			$cells = array();

			foreach ( $row->childNodes as $cell ) {
				$cell_tag = strtolower( $cell->nodeName );

				if ( ! in_array( $cell_tag, array( 'td', 'th' ), true ) ) {
					continue;
				}

				$cells[] = trim( preg_replace( '/\s+/u', ' ', $cell->textContent ) );
			}

			if ( ! empty( $cells ) ) {
				$rows[] = $cells;
			}
		}

		if ( empty( $rows ) ) {
			return array();
		}

		return array(
			'type'   => 'table',
			'rows'   => $rows,
			'length' => array_sum( array_map( 'count', $rows ) ),
		);
	}

	/**
	 * Convert figure node (image + caption).
	 *
	 * @param DOMElement $node    Figure node.
	 * @param array      $options Options.
	 *
	 * @return array
	 */
	private function convert_figure( DOMElement $node, $options ) {
		$image   = null;
		$caption = '';

		foreach ( $node->childNodes as $child ) {
			if ( 'img' === strtolower( $child->nodeName ) ) {
				$image = $this->convert_element_node( $child, 'img', $options );
			} elseif ( 'figcaption' === strtolower( $child->nodeName ) ) {
				$caption = trim( $child->textContent );
			}
		}

		if ( empty( $image ) ) {
			return array();
		}

		$image['caption'] = $caption;

		return $image;
	}

	/**
	 * Sum lengths of nested blocks.
	 *
	 * @param array $blocks Blocks.
	 *
	 * @return int
	 */
	private function sum_length( $blocks ) {
		$length = 0;

		foreach ( $blocks as $block ) {
			$length += isset( $block['length'] ) ? (int) $block['length'] : 0;
		}

		return $length;
	}

	/**
	 * Derive layout hints from element attributes.
	 *
	 * @param DOMElement $node Node.
	 *
	 * @return array
	 */
	private function extract_layout_hint( DOMElement $node ) {
		$hint = array(
			'layout' => false,
			'ratio'  => false,
		);

		$attributes = array();

		if ( $node->hasAttribute( 'class' ) ) {
			$attributes[] = $node->getAttribute( 'class' );
		}

		if ( $node->hasAttribute( 'id' ) ) {
			$attributes[] = $node->getAttribute( 'id' );
		}

		$attr_text = strtolower( implode( ' ', $attributes ) );

		if ( preg_match( '/(grid|row|flex|col|column|span)/', $attr_text ) ) {
			$hint['layout'] = true;
		}

		if ( preg_match( '/(1\/2|1\/3|2\/3|50|33|66|6\/12|4\/12|8\/12)/', $attr_text ) ) {
			$hint['ratio'] = true;
		}

		return $hint;
	}

	/**
	 * Apply column detection to block list.
	 *
	 * @param array $blocks  Block structures.
	 * @param array $options Options.
	 *
	 * @return array
	 */
	private function apply_column_detection( $blocks, $options ) {
		if ( empty( $blocks ) ) {
			return array();
		}

		if ( ! $options['column_detection'] ) {
			return $this->flatten_containers( $blocks );
		}

		$result = array();
		$count  = count( $blocks );
		$i      = 0;

		while ( $i < $count ) {
			$current = $blocks[ $i ];

			if ( isset( $current['type'] ) && 'container' === $current['type'] ) {
				$group = array( $current );
				$j     = $i + 1;

				while ( $j < $count ) {
					$next = $blocks[ $j ];

					if ( ! isset( $next['type'] ) || 'container' !== $next['type'] ) {
						break;
					}

					if ( $next['tag'] !== $current['tag'] ) {
						break;
					}

					$group[] = $next;

					if ( count( $group ) >= 3 ) {
						break;
					}

					$j++;
				}

				if ( count( $group ) >= 2 ) {
					$score = $this->calculate_group_score( $group );

					if ( $score >= $options['score_threshold'] ) {
						$columns = array();

						foreach ( $group as $container ) {
							$columns[] = $container['blocks'];
						}

						$result[] = array(
							'type'    => 'columns',
							'columns' => $columns,
						);

						$i += count( $group );
						continue;
					}
				}

				$result = array_merge( $result, $current['blocks'] );
				$i++;
				continue;
			}

			$result[] = $current;
			$i++;
		}

		return $this->flatten_containers( $result );
	}

	/**
	 * Flatten container blocks back into the main list.
	 *
	 * @param array $blocks Blocks.
	 *
	 * @return array
	 */
	private function flatten_containers( $blocks ) {
		$flattened = array();

		foreach ( $blocks as $block ) {
			if ( isset( $block['type'] ) && 'container' === $block['type'] ) {
				$flattened = array_merge( $flattened, $block['blocks'] );
			} else {
				$flattened[] = $block;
			}
		}

		return $flattened;
	}

	/**
	 * Calculate similarity score for potential column group.
	 *
	 * @param array $group Container blocks.
	 *
	 * @return float
	 */
	private function calculate_group_score( $group ) {
		$lengths = array();
		$bonus   = 0.0;

		foreach ( $group as $item ) {
			$lengths[] = max( 1, (int) $item['length'] );

			if ( ! empty( $item['layoutHint']['layout'] ) ) {
				$bonus += 0.15;
			}

			if ( ! empty( $item['layoutHint']['ratio'] ) ) {
				$bonus += 0.15;
			}
		}

		$min = min( $lengths );
		$max = max( $lengths );

		if ( 0 === $max ) {
			return 0.0;
		}

		$similarity = $min / $max;

		return min( 1.0, $similarity + $bonus );
	}

	/**
	 * Convert block structures to Gutenberg markup.
	 *
	 * @param array $blocks  Block structures.
	 * @param array $options Options including post_id/persist_media.
	 *
	 * @return string
	 */
	private function blocks_to_markup( $blocks, $options ) {
		$markup        = array();
		$post_id       = (int) $options['post_id'];
		$persist_media = ! empty( $options['persist_media'] );

		foreach ( $blocks as $block ) {
			if ( empty( $block['type'] ) ) {
				continue;
			}

			switch ( $block['type'] ) {
				case 'heading':
					$markup[] = sprintf(
						"<!-- wp:heading {\"level\":%1\$d} -->\n<h%1\$d>%2\$s</h%1\$d>\n<!-- /wp:heading -->",
						$block['level'],
						esc_html( wp_strip_all_tags( $block['content'] ) )
					);
					break;

				case 'paragraph':
					$markup[] = sprintf(
						"<!-- wp:paragraph -->\n<p>%s</p>\n<!-- /wp:paragraph -->",
						esc_html( wp_strip_all_tags( $block['content'] ) )
					);
					break;

				case 'list':
					$list_items = '';

					foreach ( $block['items'] as $item ) {
						$list_items .= '<li>' . esc_html( wp_strip_all_tags( $item ) ) . '</li>';
					}

					$tag = $block['ordered'] ? 'ol' : 'ul';
					$markup[] = sprintf(
						"<!-- wp:list {\"ordered\":%s} -->\n<%s>%s</%s>\n<!-- /wp:list -->",
						$block['ordered'] ? 'true' : 'false',
						$tag,
						$list_items,
						$tag
					);
					break;

				case 'quote':
					$markup[] = sprintf(
						"<!-- wp:quote -->\n<blockquote class=\"wp-block-quote\"><p>%s</p></blockquote>\n<!-- /wp:quote -->",
						esc_html( wp_strip_all_tags( $block['content'] ) )
					);
					break;

				case 'table':
					$rows_html = '';

					foreach ( $block['rows'] as $row ) {
						$cells_html = '';

						foreach ( $row as $cell ) {
							$cells_html .= '<td>' . esc_html( wp_strip_all_tags( $cell ) ) . '</td>';
						}

						$rows_html .= '<tr>' . $cells_html . '</tr>';
					}

					$markup[] = sprintf(
						"<!-- wp:table -->\n<figure class=\"wp-block-table\"><table><tbody>%s</tbody></table></figure>\n<!-- /wp:table -->",
						$rows_html
					);
					break;

				case 'image':
					$markup[] = $this->prepare_image_block( $block, $post_id, $persist_media );
					break;

				case 'columns':
					$markup[] = $this->columns_to_markup( $block, $options );
					break;
			}
		}

		$markup = array_filter( $markup );

		return implode( "\n\n", $markup );
	}

	/**
	 * Convert columns structure to markup.
	 *
	 * @param array $block   Columns block.
	 * @param array $options Options.
	 *
	 * @return string
	 */
	private function columns_to_markup( $block, $options ) {
		$columns_markup = array();

		foreach ( $block['columns'] as $column_blocks ) {
			$inner_markup = $this->blocks_to_markup( $column_blocks, $options );
			$columns_markup[] = sprintf( "<!-- wp:column -->\n<div class=\"wp-block-column\">%s</div>\n<!-- /wp:column -->", $inner_markup );
		}

		return sprintf(
			"<!-- wp:columns -->\n<div class=\"wp-block-columns\">%s</div>\n<!-- /wp:columns -->",
			implode( '', $columns_markup )
		);
	}

	/**
	 * Prepare image block markup.
	 *
	 * @param array $block         Image block data.
	 * @param int   $post_id       Post ID.
	 * @param bool  $persist_media Whether to sideload.
	 *
	 * @return string|null
	 */
	private function prepare_image_block( $block, $post_id, $persist_media ) {
		$src = esc_url_raw( $block['src'] );

		if ( empty( $src ) ) {
			return null;
		}

		if ( ! $persist_media ) {
			$attributes = array(
				'url' => $src,
			);

			$alt_text = '';

			if ( ! empty( $block['alt'] ) ) {
				$alt_text = sanitize_text_field( $block['alt'] );
				$attributes['alt'] = $alt_text;
			}

			$attr_json  = wp_json_encode( $attributes );
			$image_html = sprintf( '<img src="%s" alt="%s" />', esc_url( $src ), esc_attr( $alt_text ) );

			return sprintf( "<!-- wp:image %s -->\n<figure class=\"wp-block-image\">%s</figure>\n<!-- /wp:image -->", $attr_json, $image_html );
		}

		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$attachment_id = media_sideload_image( $src, $post_id, $block['alt'], 'id' );

		if ( is_wp_error( $attachment_id ) ) {
			$this->logger->log( 'HTML importer image sideload failed', array( 'error' => $attachment_id->get_error_message(), 'src' => $src ) );
			return null;
		}

		if ( ! empty( $block['alt'] ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $block['alt'] ) );
		}

		$image_html = wp_get_attachment_image( $attachment_id, 'large' );

		if ( ! $image_html ) {
			return null;
		}

		if ( ! empty( $block['caption'] ) ) {
			$image_html .= sprintf( '<figcaption>%s</figcaption>', esc_html( $block['caption'] ) );
		}

		return sprintf(
			"<!-- wp:image {\"id\":%d,\"sizeSlug\":\"large\",\"linkDestination\":\"none\"} -->\n%s\n<!-- /wp:image -->",
			$attachment_id,
			$image_html
		);
	}
}
