# Benzy Custom Fields

Lightweight custom fields for WordPress. Build field groups in wp-admin and attach them to pages, posts or any custom post type.

## Field types

Text, Text Area, WYSIWYG Editor, Number, Email, URL, Select, Checkbox, Radio Buttons, True / False, Date, Color Picker, Image, Gallery, File, Repeater (sub fields of any type, nesting allowed).

## Install

1. Upload the `benzy-custom-fields` folder to `wp-content/plugins/` (or upload the zip via **Plugins → Add New → Upload Plugin**).
2. Activate **Benzy Custom Fields**.

## Create fields

1. Go to **Custom Fields → Add New**.
2. Give the group a title, then click **+ Add Field** for each field (label, name, type, instructions…).
   For a **Repeater**, add its sub fields inside it.
3. Under **Location & Settings**, tick the post types (Page, Post, your CPTs) and optionally limit to one page template.
4. Publish. The fields now appear on matching edit screens (classic and block editor).

Values are stored as normal post meta using the field **name** as the meta key.

## Use in your theme

```php
// Text / textarea / number / select…
echo esc_html( bcf_get_field( 'hero_title' ) );

// Echo with sensible HTML per type (WYSIWYG HTML, image tag, file link…)
bcf_the_field( 'intro' );

// Image: array with ID, url, width, height, alt, title, caption, sizes
$img = bcf_get_field( 'hero_image' );
if ( $img ) {
	echo wp_get_attachment_image( $img['ID'], 'large' );
}

// Gallery: array of image arrays
foreach ( bcf_get_field( 'photos' ) as $photo ) {
	echo '<img src="' . esc_url( $photo['sizes']['medium'] ?? $photo['url'] ) . '" alt="' . esc_attr( $photo['alt'] ) . '">';
}

// File: array with ID, url, filename, title, mime_type, filesize
$file = bcf_get_field( 'brochure' );

// Repeater, option 1: plain array of rows
foreach ( bcf_get_field( 'slides' ) as $slide ) {
	echo esc_html( $slide['caption'] );
}

// Repeater, option 2: loop helpers (nested repeaters work too)
if ( bcf_have_rows( 'slides' ) ) {
	while ( bcf_have_rows( 'slides' ) ) {
		bcf_the_row();
		bcf_the_sub_field( 'caption' );
		$image = bcf_get_sub_field( 'image' );
	}
}

// Other post / raw value
bcf_get_field( 'hero_title', 42 );
bcf_get_field( 'hero_image', null, false ); // attachment ID

// All fields for the current post
$all = bcf_get_fields();
```

Shortcode (in content or widgets):

```
[bcf field="hero_title"]
[bcf field="hero_image" size="medium"]
[bcf field="hero_title" post_id="42"]
```

## Notes

- Only administrators (`manage_options`) can create or edit field groups.
- WYSIWYG content is filtered with `wp_kses_post` for users without the `unfiltered_html` capability.
- Field names must be unique per post across all groups that apply to it.
- If a group is limited to a page template, save the page after changing its template and reload to see the group.
