<?php

namespace RowSprout\Core\Conditions;

use RowSprout\Core\BlockBindings\PropertyBindingSource;
use RowSprout\Core\Page\PageBuilder;
use RowSprout\ThirdParty\Elementor\PageFieldResolver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The markup of conditional content ("If / then"), the same for every
 * editor. Settings (an editor stores them under these names): "rules" (see
 * RuleSet), and per output, "" for Then and "else_" for Else: source
 * ("property", "text", "template", Else also "none"), property (a code),
 * text (own text with @code_…@ tokens), template (an editor's own template
 * id), html_tag (Else: "" = Then's); list_display for a list property.
 *
 * An output is text in its HTML tag with the classes rowsprout-conditional
 * and rowsprout-conditional--then / --else (an editor's text styles target
 * them), or a template in div.rowsprout-conditional-template (its own
 * styling). When a rule depends on today both outputs go into the page in
 * div.rowsprout-conditional-cases[data-rs-conditional] and
 * assets/js/conditional-content.js shows the right one with today's date
 * in the site's time zone, so a page cache cannot keep a stale outcome.
 */
final class ConditionalOutput {

	public const SCRIPT_HANDLE = 'rowsprout-conditional';

	public const TAGS = [ 'div', 'p', 'span', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ];

	/** How an item list is shown. */
	public const LIST_DISPLAYS = [ 'lines', 'bullets', 'numbers', 'commas' ];

	public static function register(): void {
		add_action( 'init', [ self::class, 'registerScript' ] );
	}

	public static function registerScript(): void {
		$suffix       = defined( 'WP_DEBUG' ) && WP_DEBUG ? '' : '.min';
		$relativePath = 'assets/js/conditional-content' . $suffix . '.js';
		$file         = ROWSPROUT_PATH . $relativePath;
		wp_register_script( self::SCRIPT_HANDLE, plugins_url( $relativePath, ROWSPROUT_FILE ), [], file_exists( $file ) ? (string) filemtime( $file ) : ROWSPROUT_VERSION, true );
	}

	/**
	 * The HTML for a group ($context: PageFieldResolver::resolveGroupContext(),
	 * null without a group) and the decision behind it. $renderers lets an
	 * editor format own text ("text": callable(string): string; default
	 * wpautop and shortcodes) and show its templates ("template":
	 * callable(int): string; default nothing).
	 *
	 * @param array<string, mixed>      $settings
	 * @param array<string, mixed>|null $context
	 * @param array<string, callable>   $renderers
	 * @return array{html: string, decision: array<string, mixed>}
	 */
	public static function render( array $settings, ?array $context, array $renderers = [] ): array {
		$decision = RuleSet::decide( (array) ( $settings['rules'] ?? [] ), $context['group'] ?? null );

		if ( $decision['client'] === null ) {
			$prefix = $decision['matches'] ? '' : 'else_';
			$inner  = self::content( $settings, $prefix, $context, $renderers );
			$html   = $inner === '' ? '' : self::wrap( $settings, $prefix, $inner, '' );
		} else {
			// Both outputs; the script shows the right one.
			wp_enqueue_script( self::SCRIPT_HANDLE );
			$html = '<div class="rowsprout-conditional-cases" data-rs-conditional="' . esc_attr( (string) wp_json_encode( $decision['client'] ) ) . '">';
			foreach ( [ 'then' => '', 'else' => 'else_' ] as $name => $prefix ) {
				$shown = $decision['matches'] === ( $name === 'then' );
				$html .= self::wrap( $settings, $prefix, self::content( $settings, $prefix, $context, $renderers ), ' data-rs-case="' . esc_attr( $name ) . '"' . ( $shown ? '' : ' hidden' ) );
			}
			$html .= '</div>';
		}

		return [ 'html' => $html, 'decision' => $decision ];
	}

	/**
	 * The HTML inside "Then" ($prefix '') or "Else" ('else_').
	 *
	 * @param array<string, mixed>      $settings
	 * @param array<string, mixed>|null $context
	 * @param array<string, callable>   $renderers
	 */
	public static function content( array $settings, string $prefix, ?array $context, array $renderers = [] ): string {
		$source = (string) ( $settings[ $prefix . 'source' ] ?? ( $prefix === '' ? 'property' : 'none' ) );

		if ( $source === 'text' ) {
			$text = (string) ( $settings[ $prefix . 'text' ] ?? '' );
			if ( $context !== null && strpos( $text, '@code_' ) !== false ) {
				// Generated pages had their tokens replaced already; this is an editor's preview.
				$templateId = (int) $context['template_id'];
				$text       = PageBuilder::applyGroupPlaceholderTokensToMarkup( $text, (array) $context['group'], $templateId, (int) wp_get_post_parent_id( $templateId ), $templateId );
			}
			if ( trim( $text ) === '' ) {
				return '';
			}

			return isset( $renderers['text'] ) ? (string) call_user_func( $renderers['text'], $text ) : do_shortcode( wpautop( $text ) );
		}
		if ( $source === 'template' ) {
			return isset( $renderers['template'] ) ? (string) call_user_func( $renderers['template'], (int) ( $settings[ $prefix . 'template' ] ?? 0 ) ) : '';
		}
		$property = (string) ( $settings[ $prefix . 'property' ] ?? '' );
		if ( $source !== 'property' || $property === '' || $context === null ) {
			return '';
		}
		$field = PageFieldResolver::getFieldByCode( (array) $context['group'], $property );
		if ( $field === null ) {
			return '';
		}
		if ( ConditionEvaluator::kindOf( $field['type'] ) === ConditionEvaluator::KIND_LIST ) {
			$display = in_array( $settings['list_display'] ?? 'lines', self::LIST_DISPLAYS, true ) ? (string) $settings['list_display'] : 'lines';
			return self::listHtml( $field['value'], $display );
		}

		return (string) PropertyBindingSource::format( $field['value'], $field['type'], 'content' );
	}

	/**
	 * One output in its element: text in its HTML tag with the text classes
	 * (a single paragraph unwrapped into it; blocks fall back to a div), a
	 * template in its own div.
	 *
	 * @param array<string, mixed> $settings
	 */
	public static function wrap( array $settings, string $prefix, string $html, string $attributes ): string {
		if ( ( $settings[ $prefix . 'source' ] ?? '' ) === 'template' ) {
			return '<div class="rowsprout-conditional-template"' . $attributes . '>' . $html . '</div>';
		}
		$tag = (string) ( $settings[ $prefix . 'html_tag' ] ?? '' );
		if ( $prefix !== '' && $tag === '' ) {
			$tag = (string) ( $settings['html_tag'] ?? '' );
		}
		$tag = in_array( $tag, self::TAGS, true ) ? $tag : 'div';
		if ( $tag !== 'div' && preg_match( '#^\s*<p>((?:(?!</?p[\s>]).)*)</p>\s*$#is', $html, $single ) === 1 ) {
			// One paragraph (as a text editor makes it): its text goes in the chosen tag.
			$html = $single[1];
		}
		if ( preg_match( '/<(ul|ol|p|div|h[1-6])[\s>]/i', $html ) === 1 && $tag !== 'div' ) {
			// Blocks cannot go inside a p, span or heading.
			$tag = 'div';
		}
		$tag = tag_escape( $tag );

		return '<' . $tag . ' class="rowsprout-conditional rowsprout-conditional--' . ( $prefix === '' ? 'then' : 'else' ) . '"' . $attributes . '>' . $html . '</' . $tag . '>';
	}

	/**
	 * An item list (one item per line) as lines, a bulleted or numbered list,
	 * or separated by commas.
	 */
	public static function listHtml( string $value, string $display ): string {
		$items = array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', $value ) ?: [] ), 'strlen' ) );
		if ( $items === [] ) {
			return '';
		}
		$items = array_map( 'esc_html', $items );
		switch ( $display ) {
			case 'bullets':
				return '<ul><li>' . implode( '</li><li>', $items ) . '</li></ul>';
			case 'numbers':
				return '<ol><li>' . implode( '</li><li>', $items ) . '</li></ol>';
			case 'commas':
				return implode( esc_html_x( ', ', 'list separator', 'rowsprout' ), $items );
			default:
				return implode( '<br>', $items );
		}
	}
}
