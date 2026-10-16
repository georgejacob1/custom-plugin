# Functional Custom Fields

Lightweight custom fields for WordPress. Build field groups in wp-admin and attach them to pages, posts or any custom post type.

## Field types

Text, Text Area, WYSIWYG Editor, Number, Email, URL, Select, Checkbox, Radio Buttons, True / False, Date, Color Picker, Image, Gallery, File, Repeater (sub fields of any type, nesting allowed).

Layout: **Tab** groups the fields that follow it (up to the next tab) under a tab. Tabs can be placed on top or on the left; turn on **New Tab Group** on a tab to start a separate row of tabs. Tabs store no value. Inside repeaters, tabs work in the Block and Row layouts.

## Install

1. Upload the `functional-custom-fields` folder to `wp-content/plugins/` (or upload the zip via **Plugins → Add New → Upload Plugin**).
2. Activate **Functional Custom Fields**.

## Create fields

1. Go to **Custom Fields → Add New**.
2. Enter the group title in the top bar, then click **Add Field** for each field (label, name, type, instructions…).
   For a **Repeater**, add its sub fields inside it.
3. In **Settings → Location Rules**, choose where the group appears, e.g. `Post Type` `is equal to` `Airline Travel`. Use **and** to add a condition to a rule group and **Add rule group** for an alternative ("or"). Rules: Post Type, Post Template, Post Status, Post Format, Post Category, Post Taxonomy, Post, Page Template, Page Type, Page Parent, Page, Options Page. **Presentation** sets the box position and order; **Group Settings** has the Active switch and a description.
4. Click **Save Changes**. Active groups appear on matching edit screens (classic and block editor); inactive ones are saved as drafts.

Values are stored as normal post meta using the field **name** as the meta key.

## Options pages

Site-wide fields (e.g. a "Common Content" page for footer text, phone numbers, social links):

1. Go to **Custom Fields → Options Pages → Add New**, enter the page title (e.g. `Common Content`) and optionally the menu title, menu slug, parent page (top-level, Settings, another options page…), menu position, icon and capability (default `edit_posts`). Publish it and the page appears in the admin menu.
2. Create a field group with the location rule `Options Page` `is equal to` `Common Content`.
3. Fill in the fields on the options page and click **Update**.

Values are stored in `wp_options` as `options_{field name}` (the same keys ACF uses). Read them with `'option'` as the post ID:

```php
echo esc_html( fcf_get_field( 'footer_text', 'option' ) );
fcf_the_field( 'footer_logo', 'option' );
if ( fcf_have_rows( 'social_links', 'option' ) ) { /* ... */ }
```

Shortcode: `[fcf field="footer_text" post_id="option"]`. Pages can also be registered in code:

```php
add_action( 'init', function () {
	fcf_add_options_page( array( 'page_title' => 'Common Content', 'menu_slug' => 'common-content' ) );
	fcf_add_options_sub_page( array( 'page_title' => 'Footer', 'parent_slug' => 'common-content' ) );
} );
```

## Import / export

**Custom Fields → Tools**:

- **Export**: tick field groups and options pages, then **Export As JSON** (download a file to import on another site) or **Generate PHP** (code for your theme that registers them with `fcf_add_options_page()` and `fcf_add_local_field_group()`).
- **Import**: choose a `.json` file and click **Import JSON**. Files exported by this plugin **or by ACF** work: ACF field settings are converted (date picker → Date, color picker → Color, group → one-row repeater…); unsupported ACF types (relationship, post object, flexible content…) are skipped and listed. Items whose key already exists are updated, not duplicated.

Field values are not included in exports.

## Field settings

Open a field to edit it. Settings are split into tabs:

- **General**: field type (or **Browse Fields**), label, name, choices, default value. Image, gallery and file fields also have **Return Format** (Array / URL / ID) and **Library** (all media, or only files uploaded to the post).
- **Validation**: required, character limit (text / text area), min / max values or rows. Image, gallery and file fields can limit width, height, file size and allowed file types.
- **Presentation**: instructions, placeholder, prepend / append text, image preview size, repeater button label, and wrapper attributes (width %, class, id).
- **Conditional Logic**: show the field only when other fields on the same level match rules ("and" within a group, "or" between groups). Fields hidden by logic are not saved, so they keep their previous value.

## Use in your theme

```php
// Text / textarea / number / select…
echo esc_html( fcf_get_field( 'hero_title' ) );

// Echo with sensible HTML per type (WYSIWYG HTML, image tag, file link…)
fcf_the_field( 'intro' );

// Image: array with ID, url, width, height, alt, title, caption, sizes
$img = fcf_get_field( 'hero_image' );
if ( $img ) {
	echo wp_get_attachment_image( $img['ID'], 'large' );
}

// Gallery: array of image arrays
foreach ( fcf_get_field( 'photos' ) as $photo ) {
	echo '<img src="' . esc_url( $photo['sizes']['medium'] ?? $photo['url'] ) . '" alt="' . esc_attr( $photo['alt'] ) . '">';
}

// File: array with ID, url, filename, title, mime_type, filesize
$file = fcf_get_field( 'brochure' );

// Repeater, option 1: plain array of rows
foreach ( fcf_get_field( 'slides' ) as $slide ) {
	echo esc_html( $slide['caption'] );
}

// Repeater, option 2: loop helpers (nested repeaters work too)
if ( fcf_have_rows( 'slides' ) ) {
	while ( fcf_have_rows( 'slides' ) ) {
		fcf_the_row();
		fcf_the_sub_field( 'caption' );
		$image = fcf_get_sub_field( 'image' );
	}
}

// Other post / raw value
fcf_get_field( 'hero_title', 42 );
fcf_get_field( 'hero_image', null, false ); // attachment ID

// All fields for the current post
$all = fcf_get_fields();
```

Shortcode (in content or widgets):

```
[fcf field="hero_title"]
[fcf field="hero_image" size="medium"]
[fcf field="hero_title" post_id="42"]
```

## Notes

- Only administrators (`manage_options`) can create or edit field groups.
- WYSIWYG content is filtered with `wp_kses_post` for users without the `unfiltered_html` capability.
- Field names must be unique per post across all groups that apply to it.
- Template, status, format, category, taxonomy, page type and page parent rules are checked when the edit screen loads. After changing one of those on a post, save and reload to see the matching groups.
