# Gemini Translator

A WordPress plugin to translate your WooCommerce store using Google Gemini AI.

## Features

- 🔍 Scan WooCommerce products (titles, descriptions, short descriptions)
- 🤖 Auto-translate using Google Gemini AI
- ✏️ Edit translations manually in admin
- 🔎 Filter and search translations
- 📊 Dashboard with translation stats

## Requirements

- WordPress 5.0+
- WooCommerce 3.0+
- PHP 7.4+
- Google Gemini API key

## Installation

1. Download or clone this repository to `wp-content/plugins/gemini-translator/`
2. Activate the plugin in WordPress admin
3. Go to **Translator → Settings**
4. Add your Gemini API key (get one at [Google AI Studio](https://aistudio.google.com/app/apikey))
5. Select your target language
6. Go to **Translator → Dashboard** and click "Scan Products"
7. Click "Translate All" to translate your content

## Usage

### Scanning Content
Click "Scan Products" to detect all translatable strings from your WooCommerce products.

### Translating
- **Translate Batch (20)**: Translates 20 strings at a time
- **Translate All**: Translates all pending strings (may take a few minutes)

### Editing
Go to **Translator → Translations** to view, search, filter, and edit your translations.

## Roadmap

- [ ] Elementor pages support
- [ ] WCFM Marketplace support
- [ ] Frontend language switcher
- [ ] Import/export translations

## License

GPL v2 or later