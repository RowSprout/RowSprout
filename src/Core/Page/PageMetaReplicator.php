<?php

namespace RowSprout\Core\Page;

use RowSprout\Core\Groups\GroupRepository;
use RowSprout\Core\PostMetaKeys;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PageMetaReplicator {

	/**
	 * @param array<string, mixed> $group
	 * @param int|string $codeId
	 * @param int|string $parentCodeId
	 * @param array<int, int|string> $aliasCodeIds
	 */
	public static function copyAndReplaceMeta( int $sourcePostId, int $targetPostId, array $group, $codeId, $parentCodeId, array $aliasCodeIds = [] ): void {
		$postMeta = get_post_meta( $sourcePostId );

		self::reconcilePrefixedMetaKeys( $sourcePostId, $targetPostId, $group, array_keys( $postMeta ) );

		$excludedKeys = [
			'_edit_lock',
			'_edit_last',
			'_rowsprout_page_*',
			PostMetaKeys::SOURCE_GROUP_ID,
		];

		$excludedKeys = apply_filters(
			'rowsprout_excluded_meta_keys',
			$excludedKeys,
			$sourcePostId,
			$targetPostId,
			$group
		);

		foreach ( $postMeta as $key => $values ) {
			if ( GroupRepository::keyMatches( (string) $key, (array) $excludedKeys ) ) {
				continue;
			}

			foreach ( $values as $value ) {
				$value = maybe_unserialize( $value );

				if ( is_string( $value ) ) {
					$value = self::replaceTokensInString( $value, $group, $codeId, $parentCodeId, $sourcePostId, $aliasCodeIds );
				} elseif ( is_array( $value ) ) {
					array_walk_recursive(
						$value,
						static function ( &$item ) use ( $group, $codeId, $parentCodeId, $sourcePostId, $aliasCodeIds ) {
							if ( is_string( $item ) ) {
								$item = PageBuilder::applyGroupPlaceholderTokensToMarkup( $item, $group, $codeId, $parentCodeId, $sourcePostId, $aliasCodeIds );
							}
						}
					);
				}

				if ( metadata_exists( 'post', $targetPostId, (string) $key ) ) {
					update_post_meta( $targetPostId, (string) $key, wp_slash( $value ) );
				} else {
					add_post_meta( $targetPostId, (string) $key, wp_slash( $value ) );
				}
			}
		}
	}

	/**
	 * Meta stored as a JSON string — above all Elementor's _elementor_data —
	 * must get its tokens replaced inside the DECODED strings. A plain
	 * str_replace() on the JSON text pastes the raw value in unescaped, so a
	 * group value with a double quote, a backslash or a line break (allowed by
	 * sanitize_text_field()/sanitize_textarea_field(), i.e. most field types)
	 * produces invalid JSON and Elementor can no longer load the page.
	 *
	 * The JSON is only re-encoded when a token was actually replaced, so meta
	 * without tokens keeps its exact original bytes. Anything that is not a
	 * JSON object/array falls back to the plain replacement.
	 *
	 * @param array<string, mixed> $group
	 * @param int|string $codeId
	 * @param int|string $parentCodeId
	 * @param array<int, int|string> $aliasCodeIds
	 */
	private static function replaceTokensInString( string $value, array $group, $codeId, $parentCodeId, int $sourcePostId, array $aliasCodeIds ): string {
		$trimmed = ltrim( $value );
		$first   = $trimmed !== '' ? $trimmed[0] : '';

		if ( ( $first === '{' || $first === '[' ) && strpos( $value, '@code_' ) !== false ) {
			$decoded = json_decode( $value, true );

			if ( is_array( $decoded ) && json_last_error() === JSON_ERROR_NONE ) {
				$changed = false;
				array_walk_recursive(
					$decoded,
					static function ( &$item ) use ( $group, $codeId, $parentCodeId, $sourcePostId, $aliasCodeIds, &$changed ) {
						if ( ! is_string( $item ) || strpos( $item, '@code_' ) === false ) {
							return;
						}
						$replaced = PageBuilder::applyGroupPlaceholderTokensToMarkup( $item, $group, $codeId, $parentCodeId, $sourcePostId, $aliasCodeIds );
						if ( $replaced !== $item ) {
							$item    = $replaced;
							$changed = true;
						}
					}
				);

				if ( ! $changed ) {
					return $value;
				}

				$encoded = wp_json_encode( $decoded );

				return is_string( $encoded ) ? $encoded : $value;
			}
		}

		return PageBuilder::applyGroupPlaceholderTokensToMarkup( $value, $group, $codeId, $parentCodeId, $sourcePostId, $aliasCodeIds );
	}

	/**
	 * Cleans up "orphaned" per-instance meta keys the loop above can never
	 * catch on its own: it only ever ADDS/UPDATES whatever key names
	 * currently exist on the SOURCE, so a key that used to exist on the
	 * template but was removed (e.g. a RankMath Schema block deleted from
	 * the template's SEO panel — RankMath gives every schema block its own
	 * rank_math_schema_<Type> meta key, confirmed live: removing "Product"
	 * and adding "Service" on the template left the already-generated
	 * page with BOTH rank_math_schema_Product and rank_math_schema_Service,
	 * since nothing ever told it to drop the first one) is silently never
	 * touched again on an already-generated page, staying there forever.
	 *
	 * A fixed key list (rowsprout_excluded_meta_keys/
	 * rowsprout_page_delete_post_meta_keys) can't express this: the exact
	 * key name depends on data the admin picks (the schema type), not
	 * something a plugin integration can enumerate in advance. Instead,
	 * rowsprout_reconciled_meta_key_prefixes lets an integration
	 * register a KEY PREFIX it owns (e.g. 'rank_math_schema_') — every
	 * target meta key starting with that prefix which ISN'T also one of
	 * the source's OWN current meta keys gets deleted here, before the
	 * main copy loop above runs; a prefixed key that's still legitimately
	 * on the source is left alone and gets freshly copied/updated by that
	 * same loop right after, same as any other key.
	 *
	 * @param array<string, mixed> $group
	 * @param array<int, string>   $sourceKeys
	 */
	private static function reconcilePrefixedMetaKeys( int $sourcePostId, int $targetPostId, array $group, array $sourceKeys ): void {
		$prefixes = (array) apply_filters(
			'rowsprout_reconciled_meta_key_prefixes',
			[],
			$sourcePostId,
			$targetPostId,
			$group
		);
		$prefixes = array_values( array_unique( array_filter( $prefixes, 'is_string' ) ) );

		if ( empty( $prefixes ) ) {
			return;
		}

		$targetKeys = array_keys( get_post_meta( $targetPostId ) );

		foreach ( $targetKeys as $targetKey ) {
			$targetKey = (string) $targetKey;

			foreach ( $prefixes as $prefix ) {
				if ( strpos( $targetKey, $prefix ) !== 0 ) {
					continue;
				}
				if ( ! in_array( $targetKey, $sourceKeys, true ) ) {
					delete_post_meta( $targetPostId, $targetKey );
				}
				break;
			}
		}
	}
}
