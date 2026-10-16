# Functional Forms

A drag & drop form builder for WordPress, in the style of Fluent Forms. Build forms in wp-admin, embed them with a block or a shortcode, store submissions as entries and get email notifications.

## Field types

Single Line Text, Paragraph Text, Email, Phone, Number, Website / URL, Date, Dropdown, Radio Buttons, Checkboxes, File Upload (drag & drop, multiple files), Consent / Terms, Hidden Field, HTML / Content.

Each field has a label, name, placeholder, help text, required flag, default value and full or half width. Choice fields have a choices list, where `value : Label` stores a different value. Number fields have min/max. File fields have allowed types, max size and max number of files.

## Install

1. Upload the `functional-forms` folder to `wp-content/plugins/`, or upload `functional-forms.zip` via **Plugins → Add New → Upload Plugin**.
2. Activate **Functional Forms**.

## Build a form

1. Go to **Forms → Add New**. New forms start with Name, Email, Subject and Message fields.
2. Click a field type in the left palette to add it. Click a field to edit its settings. Drag the ✥ handle to reorder fields.
3. Under **Form Settings**, set:
   - the submit text, success message or redirect URL
   - whether entries are saved
   - the admin email notification, with an option to attach uploads
   - the auto-reply to the person who submitted
4. Publish.

## Embed

- **Block editor:** add the **Functional Form** block and pick a form. A live preview is shown in the editor.
- **Shortcode:** `[functional_form id="123"]`. Copy it from the **Embed** box or the Forms list.

Forms submit over AJAX and show inline errors. They also work without JavaScript, using a normal POST and redirect.

## Entries

**Forms → Entries** lists all submissions. You can filter by form and open an entry to see every value, linked files, the page it was sent from, and the IP and browser.
**Export CSV** is available from the entries filter bar and from each form's row actions.

## Email smart tags

Use these in subjects and messages: `{all_fields}`, `{field:name}`, `{form_title}`, `{site_name}`, `{page_url}`, `{entry_id}`. In the **Send to** box, `{admin_email}` is the site admin email.
The admin notification sets Reply-To to the first Email field.

## Uploads & security

- Files are only stored after the whole form passes validation. They go to `wp-content/uploads/functional-forms/YYYY/MM/`, get a random filename prefix, and the folders block directory listing.
- A file must match the field's allowed extensions **and** WordPress's allowed mime types. Its real content type is checked with `wp_check_filetype_and_ext`.
- Every submission is checked with a nonce and a honeypot. Values are sanitized per field type, and choice values are checked against the configured choices.
- Permanently deleting an entry also deletes its files.

## Developer hooks

```php
// Change or add data before it is saved/emailed.
add_filter( 'ffm_submission_data', function ( $data, $form_id ) { return $data; }, 10, 2 );

// Run after a successful submission (e.g. send to a CRM).
add_action( 'ffm_submission', function ( $form_id, $data, $entry_id ) {}, 10, 3 );

// Don't store the submitter's IP address.
add_filter( 'ffm_store_ip', '__return_false' );
```

## Notes

- Only administrators (`manage_options`) can manage forms and view entries.
- Uninstalling keeps forms, entries and uploads. To remove everything, add `define( 'FFM_DELETE_DATA', true );` to `wp-config.php` before you delete the plugin.
- If the site uses full-page caching, the cached nonce can expire after about 12–24 hours. Exclude form pages from the cache or keep the cache lifetime shorter than that.
