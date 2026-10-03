<?php

namespace RowSprout\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Single source of truth for postmeta keys used by both plugins, so they are
 * never hand-duplicated as literal strings. `TemplateMeta::META_KEY` and `WpmlPageLanguage::GROUP_MARKER_META`
 * already had their own single source of truth and aren't duplicated here.
 */
final class PostMetaKeys {

	// On a rowsprout_template post: which save action was last chosen
	// (registry: SavePost::getTemplateSaveActions()).
	public const SAVE_ACTION = '_rowsprout_page_save_action';

	// On a rowsprout_page post: the rowsprout_template it was generated from.
	public const SOURCE_TEMPLATE_ID = '_rowsprout_page_source_template_id';

	// On a rowsprout_page post: the group guid it was generated from (unique
	// only within SOURCE_TEMPLATE_ID, never on its own).
	public const SOURCE_GROUP_ID = '_rowsprout_page_source_group_id';

}
