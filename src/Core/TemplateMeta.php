<?php

namespace RowSprout\Core;

use RowSprout\Core\Template\ConfigNormalizer;
use RowSprout\Core\Template\FieldTypeRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TemplateMeta {

	// "_json" is a historical leftover from when this was stored as a JSON
	// string — kept as-is rather than reworded, since it's just a name at
	// this point. It's stored as a plain PHP array now (see save()):
	// WordPress's own maybe_serialize() handles that natively, and it's
	// what lets WPML's <custom-fields-texts> (see RowSprout Pro's
	// wpml-config.xml) walk into individual group field values for
	// translation — something it can't do with a value that's really just
	// one opaque JSON-encoded string.
	public const META_KEY = '_rowsprout_page_template_json';

	/**
	 * @return array<string, array{label:string,description:string,default_code:string,type?:string,supports_options?:bool,default_options?:array<int, string>,can_be_overruled?:bool,required?:bool}>
	 */
	public static function getFieldTypeDefinitions(): array {
		return FieldTypeRegistry::getDefinitions();
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function getFieldTypeDefinition( string $type ): array {
		return FieldTypeRegistry::getDefinition( $type );
	}

	/**
	 * @param int   $postId
	 * @param array $config
	 */
	public static function save( int $postId, array $config ): void {
		// Every save — admin UI or MCP API alike — bumps this, regardless of
		// what the caller passed in. Lets a save path that renders a form
		// ahead of time (the classic/Elementor property & groups tables)
		// detect whether the config changed under it since that render, by
		// comparing a version stamped into the form against this current
		// value at save time (see SavePost::handleSave()'s own version
		// check). microtime(true) rather than a coarser unit: two saves
		// within the same second (a script looping over several API calls)
		// must not collide.
		$config['config_updated_at'] = (string) microtime( true );

		$normalized = ConfigNormalizer::normalizeConfig( $config );

		update_post_meta( $postId, self::META_KEY, $normalized );
	}

	/**
	 * True if this template's config has been saved again since
	 * $expectedVersion was read (see save()'s own docblock for what this
	 * value is and when it changes). A caller doing its own read-modify-
	 * write outside the classic/Elementor save flow (see RowSprout
	 * Pro's MCP tools) should capture config_updated_at at read time and
	 * check this immediately before its own save() call, to detect — not
	 * silently overwrite — a change that landed in between.
	 */
	public static function hasChangedSince( int $postId, string $expectedVersion ): bool {
		if ( $expectedVersion === '' ) {
			return false;
		}

		$current = (string) ( self::get( $postId )['config_updated_at'] ?? '' );

		return $current !== '' && $current !== $expectedVersion;
	}

	public static function getHref( int $postId ): string {
		$config = self::get( $postId );
		return isset( $config['rowsprout_page_href'] ) ? (string) $config['rowsprout_page_href'] : '';
	}

	/**
	 * @param int $postId
	 * @return array
	 */
	public static function get( int $postId ): array {
		$raw = get_post_meta( $postId, self::META_KEY, true );

		if ( is_array( $raw ) ) {
			return ConfigNormalizer::normalizeConfig( $raw );
		}

		if ( is_string( $raw ) && $raw !== '' ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				return ConfigNormalizer::normalizeConfig( $decoded );
			}
		}

		return [];
	}

}
