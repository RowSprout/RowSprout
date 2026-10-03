<?php

namespace RowSprout\ThirdParty\Elementor;

use RowSprout\Core\PostTypes;
use RowSprout\ThirdParty\Elementor\Widgets\TextareaWidget;
use RowSprout\ThirdParty\Elementor\Widgets\GridWidget;
use RowSprout\ThirdParty\Elementor\Widgets\HeadingWidget;
use RowSprout\ThirdParty\Elementor\Widgets\ButtonWidget;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ported from an old version of this plugin's elementor/dynamic-page-widgets.php.
 * No class_exists('\Elementor\Widget_Base') guard needed: both hooks below
 * simply never fire on a site without Elementor, so the widget classes
 * (which extend \Elementor\Widget_Base) are never autoloaded/instantiated
 * there either.
 *
 * The Thumbnail and Sibling Links widgets and the RowSprout Page Field / Page
 * Link dynamic tags are Pro features and live in rowsprout-pro
 * (RowSproutPro\ThirdParty\Elementor), which also registers the dynamic-tag
 * group and enables dynamic tags on the Text Editor widget. The
 * 'rowsprout_page' widget category and PageFieldResolver stay here because Pro
 * uses them too.
 */
final class ElementorIntegration {

	public static function register(): void {
		add_action( 'elementor/elements/categories_registered', [ self::class, 'registerCategory' ] );
		add_action( 'elementor/widgets/register', [ self::class, 'registerWidgets' ] );
		TextEditorTokenButton::register();
		add_filter( 'rowsprout_excluded_meta_keys', [ self::class, 'extendExcludedMetaKeys' ], 10, 4 );
		add_filter( 'rowsprout_page_delete_post_meta_keys', [ self::class, 'extendDeletedMetaKeys' ], 10, 2 );
		add_filter( 'rest_request_before_callbacks', [ self::class, 'pauseContentFilterForTemplateRest' ], 10, 3 );
		add_filter( 'rest_request_after_callbacks', [ self::class, 'resumeContentFilterAfterTemplateRest' ], 10, 3 );
	}

	/** @var bool */
	private static $contentFilterPaused = false;

	/**
	 * The REST posts controller renders `content.rendered` through
	 * the_content, where Elementor's Frontend::apply_builder_in_content()
	 * renders an Elementor-built template — and, with no stylesheets
	 * registered in a REST request, prints its post CSS inline
	 * (<style id="elementor-post-…">, Core\Files\CSS\Base::enqueue()). That
	 * stray output lands before the JSON, and the block editor then fails
	 * every save of such a template with "The response is not a valid JSON
	 * response". The editor never uses the rendered HTML, so Elementor's
	 * filter is paused for rowsprout_template REST routes only.
	 *
	 * @param mixed            $response
	 * @param array<mixed>     $handler
	 * @param \WP_REST_Request $request
	 * @return mixed
	 */
	public static function pauseContentFilterForTemplateRest( $response, $handler, $request ) {
		unset( $handler );

		if ( ! self::isTemplateRoute( $request ) || ! class_exists( '\Elementor\Plugin' ) || empty( \Elementor\Plugin::$instance->frontend ) ) {
			return $response;
		}

		\Elementor\Plugin::$instance->frontend->remove_content_filter();
		self::$contentFilterPaused = true;

		return $response;
	}

	/**
	 * @param mixed            $response
	 * @param array<mixed>     $handler
	 * @param \WP_REST_Request $request
	 * @return mixed
	 */
	public static function resumeContentFilterAfterTemplateRest( $response, $handler, $request ) {
		unset( $handler, $request );

		if ( self::$contentFilterPaused ) {
			\Elementor\Plugin::$instance->frontend->add_content_filter();
			self::$contentFilterPaused = false;
		}

		return $response;
	}

	/**
	 * @param mixed $request
	 */
	private static function isTemplateRoute( $request ): bool {
		return $request instanceof \WP_REST_Request
			&& preg_match( '#^/wp/v2/' . preg_quote( PostTypes::TEMPLATE, '#' ) . '(/|$)#', (string) $request->get_route() ) === 1;
	}

	/**
	 * Elementor rebuilds these on demand from _elementor_data — copying the
	 * template's own cached CSS/element cache onto every generated page would
	 * leave them referencing the template's post ID (wrong CSS file/cache
	 * key) until Elementor happens to regenerate them.
	 *
	 * @param array<int, string> $excludedKeys
	 * @return array<int, string>
	 */
	public static function extendExcludedMetaKeys( array $excludedKeys, int $sourcePostId, int $targetPostId, array $group ): array {
		unset( $sourcePostId, $targetPostId, $group );

		$excludedKeys[] = '_elementor_css';
		$excludedKeys[] = '_elementor_element_cache';

		return array_values( array_unique( array_filter( $excludedKeys, 'is_string' ) ) );
	}

	/**
	 * Belt-and-suspenders alongside extendExcludedMetaKeys() above: also
	 * strip any stale copy that slipped through (e.g. a page generated before
	 * this filter existed), forcing Elementor to render fresh CSS/cache for
	 * the generated page instead of reusing the template's.
	 *
	 * @param array<int, string> $metaKeys
	 * @return array<int, string>
	 */
	public static function extendDeletedMetaKeys( array $metaKeys, int $postId ): array {
		unset( $postId );

		$metaKeys[] = '_elementor_css';
		$metaKeys[] = '_elementor_element_cache';

		return array_values( array_unique( array_filter( $metaKeys, 'is_string' ) ) );
	}

	/**
	 * 'rowsprout_page' holds the widgets that show the current page's own group
	 * data (only offered on templates/generated pages); 'rowsprout_pages' holds
	 * GridWidget, which lists generated pages and is available on any post
	 * type. Categories only group the widget panel — Elementor does not store
	 * them in content, so moving a widget between them is safe.
	 *
	 * @param mixed $elementsManager \Elementor\Elements_Manager
	 */
	public static function registerCategory( $elementsManager ): void {
		$elementsManager->add_category(
			'rowsprout_page',
			[
				'title' => __( 'RowSprout', 'rowsprout' ),
				'icon'  => 'fa fa-plug',
			]
		);
		$elementsManager->add_category(
			'rowsprout_pages',
			[
				'title' => __( 'RowSprout pages', 'rowsprout' ),
				'icon'  => 'fa fa-plug',
			]
		);
	}

	/**
	 * @param mixed $widgetsManager \Elementor\Widgets_Manager
	 */
	public static function registerWidgets( $widgetsManager ): void {
		$widgetsManager->register( new TextareaWidget() );
		$widgetsManager->register( new GridWidget() );
		$widgetsManager->register( new HeadingWidget() );
		$widgetsManager->register( new ButtonWidget() );
	}
}
