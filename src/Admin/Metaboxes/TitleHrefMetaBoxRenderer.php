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
		$isChild     = HrefPatternValidator::isChildTemplate( $postId );
		$description = sprintf(
			/* translators: 1: example href with only the href token, 2: example href with extra text around a token. */
			__( 'The URL of each generated page. Use placeholder tokens so every page gets its own URL, for example %1$s or %2$s.', 'rowsprout' ),
			'<code>/' . esc_html( $hrefToken ) . '</code>',
			'<code>/offer-' . esc_html( $hrefToken ) . '</code>'
		);
		$childDescription = sprintf(
			/* translators: 1: example child href, 2: the resulting URL of a generated child page. */
			__( 'The last part of the URL of each generated page. Every child page is placed under its parent page, so a fixed value such as %1$s already gives each page its own URL (e.g. %2$s).', 'rowsprout' ),
			'<code>/contact</code>',
			'<code>/amsterdam/contact</code>'
		);
		$warning = sprintf(
			/* translators: %s: the href placeholder token, e.g. @code_href_123@. */
			__( 'This href does not contain the group\'s href token (%s), so the generated pages do not get a URL of their own: WordPress numbers them instead (e.g. offer, offer-2, offer-3), and with an empty href no pages are generated at all.', 'rowsprout' ),
			'<code>' . esc_html( $hrefToken ) . '</code>'
		);
		// An empty field is not flagged here (a new template starts empty);
		// the save notice covers an empty href once there are groups. A child
		// template needs no token at all (see HrefPatternValidator).
		$showWarning = ! $isChild && $hrefValue !== '' && ! HrefPatternValidator::referencesGroupHref( $hrefValue, $postId );

		echo '<label for="rowsprout_page_href">' . esc_html__( 'Href', 'rowsprout' ) . '</label><br />';
		echo '<input type="text" id="rowsprout_page_href" name="rowsprout_page_href" value="' . esc_attr( $hrefValue ) . '" placeholder="' . esc_attr( $isChild ? '/contact' : '/' . $hrefToken ) . '" style="width:100%;" data-href-token-codes="' . esc_attr( (string) wp_json_encode( HrefPatternValidator::getHrefTokenCodes( $postId ) ) ) . '" data-href-is-child="' . ( $isChild ? '1' : '0' ) . '" data-href-placeholder="' . esc_attr( '/' . $hrefToken ) . '" />';
		echo '<p class="description" data-href-description="parent"' . ( $isChild ? ' hidden' : '' ) . '>' . wp_kses( $description, [ 'code' => [] ] ) . '</p>';
		echo '<p class="description" data-href-description="child"' . ( $isChild ? '' : ' hidden' ) . '>' . wp_kses( $childDescription, [ 'code' => [] ] ) . '</p>';
		echo '<div class="notice notice-warning inline" data-href-token-warning' . ( $showWarning ? '' : ' hidden' ) . '><p>' . wp_kses( $warning, [ 'code' => [] ] ) . '</p></div>';

		self::renderScript();
	}

	/**
	 * Live re-check while typing, mirroring HrefPatternValidator::referencesGroupHref().
	 * Delegated on document because the Elementor modal injects this markup
	 * outside the normal metabox lifecycle. Child state starts from the
	 * server (Elementor's canvas has no #parent_id select) and follows the
	 * classic screen's Parent dropdown when that is changed.
	 */
	private static function renderScript(): void {
		wp_register_script( 'rowsprout-href-token-warning', false, [ 'jquery' ], ROWSPROUT_VERSION, true );
		wp_enqueue_script( 'rowsprout-href-token-warning' );
		// Codes are sanitize_key() output ([a-z0-9_-]), so they need no regex escaping.
		wp_add_inline_script( 'rowsprout-href-token-warning', <<<'JS'
jQuery(function($) {
	function refresh($input) {
		var isChild = String($input.attr('data-href-is-child')) === '1';
		var codes = $input.data('hrefTokenCodes') || ['href', 'slug'];
		var pattern = new RegExp('@code_(?:' + codes.join('|') + ')_\\d+@');
		var value = String($input.val()).trim();
		var $field = $input.parent();
		$input.attr('placeholder', isChild ? '/contact' : $input.attr('data-href-placeholder'));
		$field.find('[data-href-description="parent"]').prop('hidden', isChild);
		$field.find('[data-href-description="child"]').prop('hidden', !isChild);
		$input.nextAll('[data-href-token-warning]').first().prop('hidden', isChild || value === '' || pattern.test(value));
	}
	$(document).on('input change', '#rowsprout_page_href', function() {
		refresh($(this));
	});
	// Deferred: groups-metabox.js may put the select back when the admin
	// cancels its "replace the groups" confirm, without firing change.
	$(document).on('change', '#parent_id', function() {
		var $select = $(this);
		setTimeout(function() {
			var $input = $('#rowsprout_page_href');
			if ($input.length) {
				$input.attr('data-href-is-child', (parseInt($select.val() || '0', 10) || 0) > 0 ? '1' : '0');
				refresh($input);
			}
		}, 0);
	});
});
JS
		);
	}
}
