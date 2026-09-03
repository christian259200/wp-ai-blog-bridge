<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Markdown to Gutenberg block markup.
 *
 * The converter is deliberately line based: the AI pipeline emits predictable
 * markdown, and a block-aware converter keeps posts editable in the block
 * editor instead of landing as one opaque HTML lump.
 */
class ABB_Markdown {

	/** @var string[] Heading anchors collected during the last conversion. */
	private static $headings = array();

	/**
	 * @param string $markdown
	 * @return string Gutenberg block markup.
	 */
	public static function to_blocks( $markdown ) {
		self::$headings = array();

		$markdown = str_replace( array( "\r\n", "\r" ), "\n", (string) $markdown );
		$lines    = explode( "\n", $markdown );
		$blocks   = array();
		$i        = 0;
		$count    = count( $lines );

		while ( $i < $count ) {
			$line    = $lines[ $i ];
			$trimmed = trim( $line );

			// Blank line.
			if ( '' === $trimmed ) {
				$i++;
				continue;
			}

			// Already-serialised Gutenberg markup: pass through untouched.
			if ( 0 === strpos( $trimmed, '<!-- wp:' ) ) {
				$buffer = array();
				while ( $i < $count && '' !== trim( $lines[ $i ] ) ) {
					$buffer[] = $lines[ $i ];
					$i++;
				}
				$blocks[] = implode( "\n", $buffer );
				continue;
			}

			// Fenced code block.
			if ( preg_match( '/^```+\s*([a-zA-Z0-9_+-]*)\s*$/', $trimmed, $m ) ) {
				$i++;
				$code = array();
				while ( $i < $count && ! preg_match( '/^```+\s*$/', trim( $lines[ $i ] ) ) ) {
					$code[] = $lines[ $i ];
					$i++;
				}
				$i++; // closing fence
				$blocks[] = self::code_block( implode( "\n", $code ) );
				continue;
			}

			// Raw HTML block (inline SVG charts, embeds, tables built by the pipeline).
			if ( 0 === strpos( $trimmed, '<' ) && ! preg_match( '/^<\/?(strong|em|a|code|span|b|i)\b/i', $trimmed ) ) {
				$buffer = array();
				while ( $i < $count && '' !== trim( $lines[ $i ] ) ) {
					$buffer[] = $lines[ $i ];
					$i++;
				}
				$blocks[] = self::html_block( implode( "\n", $buffer ) );
				continue;
			}

			// Heading.
			if ( preg_match( '/^(#{1,6})\s+(.*)$/', $trimmed, $m ) ) {
				$blocks[] = self::heading_block( strlen( $m[1] ), trim( $m[2] ) );
				$i++;
				continue;
			}

			// Horizontal rule.
			if ( preg_match( '/^(\*\s*){3,}$|^(-\s*){3,}$|^(_\s*){3,}$/', $trimmed ) ) {
				$blocks[] = "<!-- wp:separator -->\n<hr class=\"wp-block-separator has-alpha-channel-opacity\"/>\n<!-- /wp:separator -->";
				$i++;
				continue;
			}

			// A YouTube URL alone on its line becomes a responsive embed.
			if ( preg_match( '#^https?://(?:www\.)?(?:youtube\.com/watch\?v=|youtu\.be/)([A-Za-z0-9_-]{11})#', $trimmed, $m ) ) {
				$blocks[] = self::video_block( $m[1] );
				$i++;
				continue;
			}

			// Standalone image.
			if ( preg_match( '/^!\[([^\]]*)\]\(([^)\s]+)(?:\s+"([^"]*)")?\)$/', $trimmed, $m ) ) {
				$blocks[] = self::image_block( $m[2], $m[1], $m[3] ?? '' );
				$i++;
				continue;
			}

			// Table.
			if ( 0 === strpos( $trimmed, '|' ) && isset( $lines[ $i + 1 ] ) && preg_match( '/^\|[\s:|-]+\|$/', trim( $lines[ $i + 1 ] ) ) ) {
				$rows = array();
				while ( $i < $count && 0 === strpos( trim( $lines[ $i ] ), '|' ) ) {
					$rows[] = trim( $lines[ $i ] );
					$i++;
				}
				$blocks[] = self::table_block( $rows );
				continue;
			}

			// Blockquote.
			if ( 0 === strpos( $trimmed, '>' ) ) {
				$quote = array();
				while ( $i < $count && 0 === strpos( trim( $lines[ $i ] ), '>' ) ) {
					$quote[] = preg_replace( '/^>\s?/', '', trim( $lines[ $i ] ) );
					$i++;
				}
				$blocks[] = self::quote_block( $quote );
				continue;
			}

			// Lists.
			if ( preg_match( '/^([-*+]|\d+\.)\s+/', $trimmed ) ) {
				$ordered = (bool) preg_match( '/^\d+\.\s+/', $trimmed );
				$items   = array();
				while ( $i < $count ) {
					$current = $lines[ $i ];
					$ct      = trim( $current );
					if ( '' === $ct ) {
						break;
					}
					if ( preg_match( '/^([-*+]|\d+\.)\s+(.*)$/', $ct, $m ) ) {
						$items[] = $m[2];
						$i++;
						continue;
					}
					// Continuation line of the previous item.
					if ( $items && preg_match( '/^\s{2,}\S/', $current ) ) {
						$items[ count( $items ) - 1 ] .= ' ' . $ct;
						$i++;
						continue;
					}
					break;
				}
				$blocks[] = self::list_block( $items, $ordered );
				continue;
			}

			// Paragraph: consume until a blank line or a line that starts a new block.
			$para = array();
			while ( $i < $count ) {
				$ct = trim( $lines[ $i ] );
				if ( '' === $ct || preg_match( '/^(#{1,6}\s|>|```|\||[-*+]\s|\d+\.\s|<)/', $ct ) ) {
					break;
				}
				$para[] = $ct;
				$i++;
			}
			if ( $para ) {
				$blocks[] = self::paragraph_block( implode( ' ', $para ) );
			} else {
				$i++;
			}
		}

		return implode( "\n\n", array_filter( $blocks ) );
	}

	/**
	 * Headings collected by the last to_blocks() run: [ ['level'=>2,'text'=>'','anchor'=>''], ... ]
	 */
	public static function last_headings() {
		return self::$headings;
	}

	/* --------------------------------------------------------------------- */
	/* Block builders                                                        */
	/* --------------------------------------------------------------------- */

	private static function heading_block( $level, $text ) {
		$level  = max( 2, min( 6, (int) $level ) ); // H1 belongs to the post title.
		$anchor = sanitize_title( wp_strip_all_tags( $text ) );
		$inner  = self::inline( $text );

		self::$headings[] = array(
			'level'  => $level,
			'text'   => wp_strip_all_tags( $text ),
			'anchor' => $anchor,
		);

		return sprintf(
			"<!-- wp:heading {\"level\":%d,\"anchor\":\"%s\"} -->\n<h%d class=\"wp-block-heading\" id=\"%s\">%s</h%d>\n<!-- /wp:heading -->",
			$level,
			esc_attr( $anchor ),
			$level,
			esc_attr( $anchor ),
			$inner,
			$level
		);
	}

	private static function paragraph_block( $text ) {
		return "<!-- wp:paragraph -->\n<p>" . self::inline( $text ) . "</p>\n<!-- /wp:paragraph -->";
	}

	private static function list_block( array $items, $ordered ) {
		$tag  = $ordered ? 'ol' : 'ul';
		$attr = $ordered ? ' {"ordered":true}' : '';
		$out  = "<!-- wp:list{$attr} -->\n<{$tag} class=\"wp-block-list\">";
		foreach ( $items as $item ) {
			$out .= "\n<!-- wp:list-item -->\n<li>" . self::inline( $item ) . "</li>\n<!-- /wp:list-item -->";
		}
		$out .= "\n</{$tag}>\n<!-- /wp:list -->";
		return $out;
	}

	private static function quote_block( array $lines ) {
		$out = "<!-- wp:quote -->\n<blockquote class=\"wp-block-quote\">";
		foreach ( array_filter( $lines, 'strlen' ) as $line ) {
			$out .= "<!-- wp:paragraph -->\n<p>" . self::inline( $line ) . "</p>\n<!-- /wp:paragraph -->";
		}
		$out .= "</blockquote>\n<!-- /wp:quote -->";
		return $out;
	}

	private static function code_block( $code ) {
		return "<!-- wp:code -->\n<pre class=\"wp-block-code\"><code>" . esc_html( $code ) . "</code></pre>\n<!-- /wp:code -->";
	}

	private static function html_block( $html ) {
		return "<!-- wp:html -->\n" . $html . "\n<!-- /wp:html -->";
	}

	private static function image_block( $url, $alt, $caption ) {
		$figure = '<figure class="wp-block-image size-large"><img src="' . esc_url( $url ) . '" alt="' . esc_attr( $alt ) . '"/>';
		if ( '' !== $caption ) {
			$figure .= '<figcaption class="wp-element-caption">' . self::inline( $caption ) . '</figcaption>';
		}
		$figure .= '</figure>';

		return "<!-- wp:image {\"sizeSlug\":\"large\"} -->\n" . $figure . "\n<!-- /wp:image -->";
	}

	/**
	 * Responsive YouTube embed.
	 *
	 * Uses youtube-nocookie so no tracking cookie is set until the visitor
	 * actually plays, and loads the iframe lazily so an embed near the foot of
	 * a long article costs nothing on first paint. Wrapped in wp:html because
	 * core's embed block would fetch oEmbed on every save.
	 */
	private static function video_block( $id ) {
		$html  = '<div class="abb-video">';
		$html .= '<iframe src="https://www.youtube-nocookie.com/embed/' . esc_attr( $id ) . '"';
		$html .= ' title="YouTube video" loading="lazy" allowfullscreen';
		$html .= ' allow="accelerometer; clipboard-write; encrypted-media; gyroscope; picture-in-picture"';
		$html .= ' referrerpolicy="strict-origin-when-cross-origin" frameborder="0"></iframe>';
		$html .= '</div>';

		return "<!-- wp:html -->
" . $html . "
<!-- /wp:html -->";
	}

	private static function table_block( array $rows ) {
		$cells = array();
		foreach ( $rows as $index => $row ) {
			if ( preg_match( '/^\|[\s:|-]+\|$/', $row ) ) {
				continue; // alignment row
			}
			$row     = trim( $row, '|' );
			$cells[] = array_map( 'trim', explode( '|', $row ) );
		}
		if ( ! $cells ) {
			return '';
		}

		$header = array_shift( $cells );
		$html   = '<figure class="wp-block-table"><table><thead><tr>';
		foreach ( $header as $cell ) {
			$html .= '<th>' . self::inline( $cell ) . '</th>';
		}
		$html .= '</tr></thead><tbody>';
		foreach ( $cells as $row ) {
			$html .= '<tr>';
			foreach ( $row as $cell ) {
				$html .= '<td>' . self::inline( $cell ) . '</td>';
			}
			$html .= '</tr>';
		}
		$html .= '</tbody></table></figure>';

		return "<!-- wp:table -->\n" . $html . "\n<!-- /wp:table -->";
	}

	/**
	 * Inline markdown: images, links, bold, italic, code, strikethrough.
	 */
	private static function inline( $text ) {
		$text = (string) $text;

		// Protect inline code spans before any other replacement runs.
		$codes = array();
		$text  = preg_replace_callback(
			'/`([^`]+)`/',
			static function ( $m ) use ( &$codes ) {
				$key           = '%%ABBCODE' . count( $codes ) . '%%';
				$codes[ $key ] = '<code>' . esc_html( $m[1] ) . '</code>';
				return $key;
			},
			$text
		);

		$text = preg_replace_callback(
			'/!\[([^\]]*)\]\(([^)\s]+)(?:\s+"([^"]*)")?\)/',
			static function ( $m ) {
				return '<img src="' . esc_url( $m[2] ) . '" alt="' . esc_attr( $m[1] ) . '"/>';
			},
			$text
		);

		$text = preg_replace_callback(
			'/\[([^\]]+)\]\(([^)\s]+)(?:\s+"([^"]*)")?\)/',
			static function ( $m ) {
				$rel      = '';
				$home     = wp_parse_url( home_url(), PHP_URL_HOST );
				$host     = wp_parse_url( $m[2], PHP_URL_HOST );
				$external = $host && $host !== $home;
				if ( $external ) {
					$rel = ' target="_blank" rel="noopener noreferrer"';
				}
				$title = ! empty( $m[3] ) ? ' title="' . esc_attr( $m[3] ) . '"' : '';
				return '<a href="' . esc_url( $m[2] ) . '"' . $title . $rel . '>' . $m[1] . '</a>';
			},
			$text
		);

		$text = preg_replace( '/\*\*\*(.+?)\*\*\*/s', '<strong><em>$1</em></strong>', $text );
		$text = preg_replace( '/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $text );
		$text = preg_replace( '/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])/s', '<em>$1</em>', $text );
		$text = preg_replace( '/~~(.+?)~~/s', '<s>$1</s>', $text );

		return strtr( $text, $codes );
	}
}
