# Language Files

This directory contains translation files for WordPress Auto-Tag Pro.

## Available Translations

Currently available:
- **wp-auto-tag-pro.pot** - Template file for translators

## How to Translate

1. **For Translators:**
   - Copy `wp-auto-tag-pro.pot` to `wp-auto-tag-pro-{locale}.po` (e.g., `wp-auto-tag-pro-es_ES.po` for Spanish)
   - Use a translation editor like Poedit to translate the strings
   - Generate the `.mo` file from your `.po` file
   - Submit your translation back to the plugin developers

2. **For Developers:**
   - Use `wp i18n make-pot` command to regenerate the .pot file when adding new translatable strings
   - Example: `wp i18n make-pot . languages/wp-auto-tag-pro.pot --domain=wp-auto-tag-pro`

## Text Domain

The plugin uses the text domain: `wp-auto-tag-pro`

## Translation Functions Used

The plugin uses standard WordPress translation functions:
- `__()` - Returns translated string
- `_e()` - Echoes translated string
- `_x()` - Returns translated string with context
- `_n()` - Returns singular/plural translated string
- `esc_html__()` - Returns escaped translated string
- `esc_html_e()` - Echoes escaped translated string
- `esc_attr__()` - Returns escaped translated string for attributes
- `esc_attr_e()` - Echoes escaped translated string for attributes

## Testing Translations

1. Place your `.po` and `.mo` files in this directory
2. Set your WordPress language in wp-config.php or admin settings
3. The plugin will automatically load the appropriate translation

## Contributing Translations

We welcome translation contributions! Please:
1. Use the latest .pot file as your base
2. Test your translations thoroughly
3. Submit translations through our official channels
4. Include both .po and .mo files in your submission

## Language File Naming Convention

- Template: `wp-auto-tag-pro.pot`
- Translations: `wp-auto-tag-pro-{locale}.po` and `wp-auto-tag-pro-{locale}.mo`
- Examples:
  - Spanish (Spain): `wp-auto-tag-pro-es_ES.po`, `wp-auto-tag-pro-es_ES.mo`
  - French (France): `wp-auto-tag-pro-fr_FR.po`, `wp-auto-tag-pro-fr_FR.mo`
  - German: `wp-auto-tag-pro-de_DE.po`, `wp-auto-tag-pro-de_DE.mo`