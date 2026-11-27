<?php
/**
 * Page writer for SCF/ACF blocks — run through novamira/execute-php.
 *
 * Turns plain values into the flattened block data SCF stores
 * (`name: value` + `_name: field_key`, repeaters as `name: count` + `name_0_sub`),
 * writes the page through the serialize_blocks() fast lane, and checks it.
 * Use it only for pages made ENTIRELY of the site plugin's own blocks
 * (references/novamira.md, "fast lane").
 *
 * How to use: paste this file as the execute-php code, after setting $spec,
 * $post_id and $markers below (or json_decode them from a base64 string).
 * Field values are plain: text as strings, images as attachment IDs, links as
 * ['url' => ..., 'title' => ..., 'target' => ''], repeaters as a list of rows,
 * group fields as an associative array.
 */

// ---- Input -----------------------------------------------------------------
$spec = [
	// One entry per block, in page order.
	[ 'name' => 'acme/hero', 'anchor' => 'top', 'values' => [
		'heading' => 'Plan with confidence',
		'image'   => 42,
		'items'   => [ [ 'label' => 'First point' ], [ 'label' => 'Second point' ] ],
	] ],
];
$post_id = 0;              // existing page to overwrite (a revision is saved first)
$markers = [ 'Plan with confidence' ]; // distinctive strings that must show on the page
$align   = 'full';         // block align attribute; '' for none
// ---------------------------------------------------------------------------

if ( ! function_exists( 'acf_get_fields' ) ) {
	return 'SCF/ACF is not active';
}

// Flatten plain values into SCF's stored block format, using the real field keys.
$flatten = function ( array $fields, array $values, string $prefix, array &$data ) use ( &$flatten ) {
	foreach ( $fields as $field ) {
		$name = $field['name'];
		if ( '' === $name || ! array_key_exists( $name, $values ) ) {
			continue; // tabs, messages and fields not given stay unset
		}
		$key   = $prefix . $name;
		$value = $values[ $name ];
		$data[ '_' . $key ] = $field['key'];
		if ( 'repeater' === $field['type'] ) {
			$rows         = array_values( (array) $value );
			$data[ $key ] = count( $rows );
			foreach ( $rows as $i => $row ) {
				$flatten( $field['sub_fields'], (array) $row, $key . '_' . $i . '_', $data );
			}
		} elseif ( 'group' === $field['type'] ) {
			$data[ $key ] = '';
			$flatten( $field['sub_fields'], (array) $value, $key . '_', $data );
		} else {
			$data[ $key ] = $value;
		}
	}
};

$blocks  = [];
$missing = [];
foreach ( $spec as $item ) {
	$groups = acf_get_field_groups( [ 'block' => $item['name'] ] ); // matched by the group's location rule
	$fields = [];
	foreach ( $groups as $group ) {
		$fields = array_merge( $fields, acf_get_fields( $group ) ?: [] );
	}
	if ( ! $fields ) {
		$missing[] = $item['name'];
		continue;
	}
	$data = [];
	$flatten( $fields, $item['values'], '', $data );
	$unknown = array_diff( array_keys( $item['values'] ), array_column( $fields, 'name' ) );
	if ( $unknown ) {
		$missing[] = $item['name'] . ': no field named ' . implode( ', ', $unknown );
	}
	$attrs = [ 'name' => $item['name'], 'data' => $data, 'mode' => 'preview' ];
	if ( $align ) {
		$attrs['align'] = $align;
	}
	if ( ! empty( $item['anchor'] ) ) {
		$attrs['anchor'] = $item['anchor'];
	}
	$blocks[] = [ 'blockName' => $item['name'], 'attrs' => $attrs, 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => [] ];
}
if ( $missing ) {
	return [ 'stopped, nothing written' => $missing ]; // a typo would silently drop content
}

$content = serialize_blocks( $blocks );
wp_save_post_revision( $post_id ); // one-click undo for the owner
$result = wp_update_post( wp_slash( [ 'ID' => $post_id, 'post_content' => $content ] ), true ); // wp_slash: keeps backslashes and quotes intact
if ( is_wp_error( $result ) ) {
	return $result->get_error_message();
}

$stored = get_post_field( 'post_content', $post_id );
$html   = apply_filters( 'the_content', $stored );
return [
	'stored_matches'  => md5( $stored ) === md5( $content ),
	'blocks'          => array_values( array_filter( array_column( parse_blocks( $stored ), 'blockName' ) ) ),
	'markers_missing' => array_values( array_filter( $markers, fn( $m ) => ! str_contains( $html, $m ) && ! str_contains( $html, esc_html( $m ) ) ) ),
	'bytes'           => strlen( $content ),
];
