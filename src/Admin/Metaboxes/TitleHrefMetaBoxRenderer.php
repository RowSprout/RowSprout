<?php

namespace RowSprout\Admin\Metaboxes;

use RowSprout\Core\Helpers;
use RowSprout\Core\Template\HrefPatternValidator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TitleHrefMetaBoxRenderer {

	public static function render( \WP_Post $post ): void {
		$postId      = (int) $post->ID;
		$hrefValue   = Helpers::getTemplateHref( $postId );
		$hrefToken   = '@code_href_' . $postId . '@';
		$description = sprintf(
			/* translators: 1: example href with only the href token, 2: example href with extra text around a token. */
			__( 'The URL of each generated page. Use placeholder tokens so every page gets its own URL, for example %1$s or %2$s.', 'rowsprout' ),
			'<code>/' . esc_html( $hrefToken ) . '</code>',
			'<code>/offer-' . esc_html( $hrefToken ) . '</code>'
		);
		$warning = sprintf(
			/* translators: %s: the href placeholder token, e.g. @code_href_123@. */
			__( 'This href does not contain the group\'s href token (%s), so the generated pages do not get a URL of their own: WordPress numbers them instead (e.g. offer, offer-2, offer-3), and with an empty href no pages are generated at all.', 'rowsprout' ),
			'<code>' . esc_html( $hrefToken ) . '</code>'
		);
		// An empty field is not flagged here (a new template starts empty);
		// the save notice covers an empty href once there are groups.
		$showWarning = $hrefValue !== '' && ! HrefPatternValidator::referencesGroupHref( $hrefValue, $postId );

		echo '<label for="rowsprout_page_href">' . esc_html__( 'Href', 'rowsprout' ) . '</label><br />';
		echo '<input type="text" id="rowsprout_page_href" name="rowsprout_page_href" value="' . esc_attr( $hrefValue ) . '" placeholder="/' . esc_attr( $hrefToken ) . '" style="width:100%;" data-href-token-codes="' . esc_attr( (string) wp_json_encode( HrefPatternValidator::getHrefTokenCodes( $postId ) ) ) . '" />';
		echo '<p class="description">' . wp_kses( $description, [ 'code' => [] ] ) . '</p>';
		echo '<div class="notice notice-warning inline" data-href-token-warning' . ( $showWarning ? '' : ' hidden' ) . '><p>' . wp_kses( $warning, [ 'code' => [] ] ) . '</p></div>';

		self::renderScript();
	}

	/**
	 * Live re-check while typing, mirroring HrefPatternValidator::referencesGroupHref().
	 * Delegated on document because the Elementor modal injects this markup
	 * outside the normal metabox lifecycle.
	 */
	private static function renderScript(): void {
		wp_register_script( 'rowsprout-href-token-warning', false, [ 'jquery' ], ROWSPROUT_VERSION, true );
		wp_enqueue_script( 'rowsprout-href-token-warning' );
		// Codes are sanitize_key() output ([a-z0-9_-]), so they need no regex escaping.
		wp_add_inline_script( 'rowsprout-href-token-warning', <<<'JS'
jQuery(function($) {
	$(document).on('input change', '#rowsprout_page_href', function() {
		var $input = $(this);
		var codes = $input.data('hrefTokenCodes') || ['href', 'slug'];
		var pattern = new RegExp('@code_(?:' + codes.join('|') + ')_\\d+@');
		var value = String($input.val()).trim();
		$input.nextAll('[data-href-token-warning]').first().prop('hidden', value === '' || pattern.test(value));
	});
});
JS
		);
	}
}
