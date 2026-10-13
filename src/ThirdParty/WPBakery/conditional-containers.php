<?php
/**
 * WPBakery's container classes for "If / then" (ConditionalElement): global
 * classes, as WPBakery looks them up by "php_class_name", loaded only once
 * WPBakeryShortCodesContainer exists. The output itself is the shortcodes'
 * (ConditionalElement::renderIf() / renderArea()).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, PEAR.NamingConventions.ValidClassName.Invalid -- WPBakery's naming.

/**
 * "If / then".
 */
class RowSprout_WPBakery_Conditional_If extends WPBakeryShortCodesContainer {
}

/**
 * Its "Then".
 */
class RowSprout_WPBakery_Conditional_Then extends WPBakeryShortCodesContainer {
}

/**
 * Its "Else".
 */
class RowSprout_WPBakery_Conditional_Else extends WPBakeryShortCodesContainer {
}

/**
 * Marker for ConditionalElement::declareContainers().
 */
class RowSprout_WPBakery_Conditional_Container {
}
