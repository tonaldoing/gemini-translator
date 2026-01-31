<?php
/**
 * Plugin Name: Gemini Translator
 * Plugin URI: https://github.com/tonaldoing/gemini-translator
 * Description: Translate your WooCommerce store using Google Gemini AI
 * Version: 0.1.0
 * Author: Tomás Vilas for Amrak Solutions
 * Author URI: https://github.com/tonaldoing
 * License: GPL v2 or later
 * Text Domain: gemini-translator
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('GEMINI_TRANSLATOR_VERSION', '0.1.0');
define('GEMINI_TRANSLATOR_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('GEMINI_TRANSLATOR_PLUGIN_URL', plugin_dir_url(__FILE__));

// Create database table on activation
function gt_activate() {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'gt_translations';
    $charset_collate = $wpdb->get_charset_collate();
    
    $sql = "CREATE TABLE $table_name (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        original_string text NOT NULL,
        string_hash varchar(64) NOT NULL,
        translated_string text,
        language_code varchar(10) NOT NULL,
        context varchar(100),
        source_type varchar(50),
        source_id bigint(20),
        status varchar(20) DEFAULT 'pending',
        date_created datetime DEFAULT CURRENT_TIMESTAMP,
        date_modified datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY string_hash (string_hash),
        KEY language_code (language_code),
        KEY status (status),
        KEY source_type (source_type),
        KEY source_id (source_id)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
    
    add_option('gt_db_version', GEMINI_TRANSLATOR_VERSION);
}
register_activation_hook(__FILE__, 'gt_activate');

// Add admin menu
function gt_admin_menu() {
    add_menu_page(
        'Gemini Translator',
        'Translator',
        'manage_options',
        'gemini-translator',
        'gt_admin_page',
        'dashicons-translation',
        100
    );
    
    add_submenu_page(
        'gemini-translator',
        'Dashboard',
        'Dashboard',
        'manage_options',
        'gemini-translator',
        'gt_admin_page'
    );
    
    add_submenu_page(
        'gemini-translator',
        'Translations',
        'Translations',
        'manage_options',
        'gemini-translator-list',
        'gt_translations_page'
    );
    
    add_submenu_page(
        'gemini-translator',
        'Settings',
        'Settings',
        'manage_options',
        'gemini-translator-settings',
        'gt_settings_page'
    );

    add_submenu_page(
        'gemini-translator',
        'Switcher Style',
        'Switcher Style',
        'manage_options',
        'gemini-translator-switcher',
        'gt_switcher_style_page'
    );
}
add_action('admin_menu', 'gt_admin_menu');

// Register settings
function gt_register_settings() {
    register_setting('gt_settings', 'gt_api_key', [
        'sanitize_callback' => 'sanitize_text_field',
    ]);
    register_setting('gt_settings', 'gt_source_language', [
        'sanitize_callback' => 'sanitize_text_field',
    ]);
    register_setting('gt_settings', 'gt_target_language', [
        'sanitize_callback' => 'sanitize_text_field',
    ]);

    // Switcher style settings
    register_setting('gt_switcher_settings', 'gt_switcher_style', [
        'type' => 'array',
        'default' => gt_get_default_switcher_style(),
        'sanitize_callback' => 'gt_sanitize_switcher_style',
    ]);
}
add_action('admin_init', 'gt_register_settings');

function gt_get_default_switcher_style() {
    return [
        'bg_color'          => '#ffffff',
        'text_color'        => '#333333',
        'active_bg_color'   => '#0073aa',
        'active_text_color' => '#ffffff',
        'hover_bg_color'    => '#f0f0f0',
        'hover_text_color'  => '#0073aa',
        'border_color'      => '#dddddd',
        'border_width'      => '1',
        'border_radius'     => '6',
        'font_size'         => '14',
        'padding_h'         => '16',
        'padding_v'         => '8',
        'gap'               => '0',
        'position'          => 'none',       // none, bottom-right, bottom-left, top-right, top-left
        'shadow'            => '1',
        'label_format'      => 'name',      // name, code, both
        'font_family'       => 'inherit',
    ];
}

function gt_sanitize_switcher_style($input) {
    $defaults = gt_get_default_switcher_style();
    $clean = [];

    $color_fields = ['bg_color', 'text_color', 'active_bg_color', 'active_text_color', 'hover_bg_color', 'hover_text_color', 'border_color'];
    foreach ($color_fields as $field) {
        $clean[$field] = isset($input[$field]) ? sanitize_hex_color($input[$field]) : $defaults[$field];
        if (empty($clean[$field])) {
            $clean[$field] = $defaults[$field];
        }
    }

    $number_fields = ['border_width', 'border_radius', 'font_size', 'padding_h', 'padding_v', 'gap'];
    foreach ($number_fields as $field) {
        $clean[$field] = isset($input[$field]) ? max(0, intval($input[$field])) : $defaults[$field];
    }

    $allowed_positions = ['none', 'bottom-right', 'bottom-left', 'top-right', 'top-left'];
    $clean['position'] = isset($input['position']) && in_array($input['position'], $allowed_positions) ? $input['position'] : $defaults['position'];

    $clean['shadow'] = isset($input['shadow']) ? '1' : '0';

    $allowed_formats = ['name', 'code', 'both'];
    $clean['label_format'] = isset($input['label_format']) && in_array($input['label_format'], $allowed_formats) ? $input['label_format'] : $defaults['label_format'];

    $allowed_fonts = array_merge(['inherit'], array_keys(gt_get_available_fonts()));
    $clean['font_family'] = isset($input['font_family']) && in_array($input['font_family'], $allowed_fonts) ? $input['font_family'] : $defaults['font_family'];

    return $clean;
}

function gt_get_switcher_style() {
    $style = get_option('gt_switcher_style');
    if (!is_array($style)) {
        return gt_get_default_switcher_style();
    }
    return array_merge(gt_get_default_switcher_style(), $style);
}

function gt_get_available_fonts() {
    $fonts = [
        'Arial, Helvetica, sans-serif'          => 'Arial',
        'Verdana, Geneva, sans-serif'            => 'Verdana',
        'Tahoma, Geneva, sans-serif'             => 'Tahoma',
        'Georgia, serif'                         => 'Georgia',
        '"Times New Roman", Times, serif'        => 'Times New Roman',
        '"Courier New", Courier, monospace'      => 'Courier New',
        'system-ui, -apple-system, sans-serif'   => 'System UI',
    ];

    // Detect Elementor global fonts
    $elementor_kit_id = get_option('elementor_active_kit');
    if ($elementor_kit_id) {
        $kit_settings = get_post_meta($elementor_kit_id, '_elementor_page_settings', true);
        if (is_array($kit_settings) && !empty($kit_settings['system_typography'])) {
            foreach ($kit_settings['system_typography'] as $typo) {
                if (!empty($typo['typography_font_family'])) {
                    $family = $typo['typography_font_family'];
                    $key = '"' . $family . '", sans-serif';
                    $label = $typo['title'] ?? $family;
                    $fonts[$key] = $label . ' (Elementor)';
                }
            }
        }
        if (is_array($kit_settings) && !empty($kit_settings['custom_typography'])) {
            foreach ($kit_settings['custom_typography'] as $typo) {
                if (!empty($typo['typography_font_family'])) {
                    $family = $typo['typography_font_family'];
                    $key = '"' . $family . '", sans-serif';
                    $label = $typo['title'] ?? $family;
                    $fonts[$key] = $label . ' (Elementor)';
                }
            }
        }
    }

    // Detect Google Fonts enqueued by theme
    global $wp_styles;
    if ($wp_styles) {
        foreach ($wp_styles->registered as $handle => $style) {
            if (isset($style->src) && strpos($style->src, 'fonts.googleapis.com') !== false) {
                if (preg_match_all('/family=([^&:;]+)/', $style->src, $matches)) {
                    foreach ($matches[1] as $gfont) {
                        $gfont = urldecode($gfont);
                        $gfont = str_replace('+', ' ', $gfont);
                        $key = '"' . $gfont . '", sans-serif';
                        if (!isset($fonts[$key])) {
                            $fonts[$key] = $gfont . ' (Google)';
                        }
                    }
                }
            }
        }
    }

    return $fonts;
}

function gt_format_lang_label($lang_code, $lang_name, $format) {
    switch ($format) {
        case 'code':
            return strtoupper($lang_code);
        case 'both':
            return strtoupper($lang_code) . ' - ' . $lang_name;
        default:
            return $lang_name;
    }
}

// Get WordPress default language
function gt_get_wp_language() {
    $locale = get_locale();
    $lang_code = substr($locale, 0, 2);
    return $lang_code;
}

// Get source language (with fallback to WP language)
function gt_get_source_language() {
    $source = get_option('gt_source_language');
    if (empty($source)) {
        return gt_get_wp_language();
    }
    return $source;
}

// Get all available languages
function gt_get_available_languages() {
    return [
        'en' => 'English',
        'es' => 'Español',
        'pt' => 'Português',
        'fr' => 'Français',
        'de' => 'Deutsch',
        'it' => 'Italiano',
        'nl' => 'Nederlands',
        'pl' => 'Polski',
        'ru' => 'Русский',
        'zh' => '中文',
        'ja' => '日本語',
        'ko' => '한국어',
        'ar' => 'العربية',
    ];
}

// Scan WooCommerce products
function gt_scan_products() {
    $language = get_option('gt_target_language');
    $scanned = 0;
    $offset = 0;
    $batch_size = 50;

    do {
        $query = new WP_Query([
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => $batch_size,
            'offset'         => $offset,
            'fields'         => 'all',
            'no_found_rows'  => true,
        ]);

        $products = $query->posts;

        foreach ($products as $product) {
            if (!empty($product->post_title)) {
                gt_insert_string($product->post_title, 'product_title', 'product', $product->ID, $language);
                $scanned++;
            }

            if (!empty($product->post_content)) {
                gt_insert_string($product->post_content, 'product_description', 'product', $product->ID, $language);
                $scanned++;
            }

            if (!empty($product->post_excerpt)) {
                gt_insert_string($product->post_excerpt, 'product_short_description', 'product', $product->ID, $language);
                $scanned++;
            }
        }

        $offset += $batch_size;
    } while (count($products) === $batch_size);

    return $scanned;
}

// Scan Elementor content
function gt_scan_elementor() {
    global $wpdb;
    
    $language = get_option('gt_target_language');
    $inserted = 0;
    
    // Get all posts with Elementor data
    $posts_with_elementor = $wpdb->get_results("
        SELECT post_id, meta_value 
        FROM {$wpdb->postmeta} 
        WHERE meta_key = '_elementor_data' 
        AND meta_value != ''
    ");
    
    foreach ($posts_with_elementor as $post) {
        $raw = $post->meta_value;

        // Elementor data may be serialized by some caching/migration plugins
        $raw = maybe_unserialize($raw);

        // If it's already an array after unserialize, use it directly
        if (is_array($raw)) {
            $elementor_data = $raw;
        } else {
            // Try decoding as-is first, then with stripslashes (common WP double-escaping)
            $elementor_data = json_decode($raw, true);
            if (!is_array($elementor_data)) {
                $elementor_data = json_decode(stripslashes($raw), true);
            }
            if (!is_array($elementor_data)) {
                $elementor_data = json_decode(stripslashes(stripslashes($raw)), true);
            }
        }

        if (is_array($elementor_data)) {
            $strings = gt_extract_elementor_strings($elementor_data, $post->post_id);
            
            foreach ($strings as $string_data) {
                if (!empty($string_data['text'])) {
                    $was_inserted = gt_insert_string(
                        $string_data['text'],
                        $string_data['context'],
                        'elementor',
                        $post->post_id,
                        $language
                    );
                    if ($was_inserted) {
                        $inserted++;
                    }
                }
            }
        }
    }
    
    return $inserted;
}

// Extract strings from Elementor data recursively using generic heuristic
function gt_extract_elementor_strings($elements, $post_id, $strings = []) {
    // Setting keys that contain translatable text
    static $text_keys = [
        'title', 'title_text', 'editor', 'text', 'description', 'description_text',
        'content', 'heading', 'caption', 'label', 'button_text', 'inner_text',
        'testimonial_content', 'testimonial_name', 'testimonial_job',
        'alert_title', 'alert_description', 'tab_title', 'tab_content',
        'item_description', 'prefix', 'suffix', 'before_text', 'after_text',
        'highlighted_text', 'rotating_text', 'placeholder', 'field_label',
        'button', 'ribbon_title', 'badge_text', 'price', 'sub_heading',
        'slide_heading', 'slide_description', 'slide_button_text',
    ];

    // Repeater keys whose items may contain translatable sub-keys
    static $repeater_keys = [
        'tabs', 'price_list', 'slides', 'icon_list', 'social_icon_list',
        'items', 'gallery', 'carousel', 'testimonials', 'team_members',
        'features_list', 'steps', 'list', 'form_fields',
    ];

    // Values that look like internal settings, not translatable text
    static $skip_values = [
        'yes', 'no', 'none', 'top', 'bottom', 'left', 'right', 'center',
        'middle', 'start', 'end', 'flex-start', 'flex-end', 'space-between',
        'space-around', 'stretch', 'inherit', 'initial', 'default', 'custom',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'div', 'span', 'p',
        'px', 'em', 'rem', '%', 'vh', 'vw', 'normal', 'bold', 'italic',
        'solid', 'dashed', 'dotted', 'double', 'groove', 'ridge',
        'inline', 'block', 'inline-block', 'flex', 'grid',
        'absolute', 'relative', 'fixed', 'sticky',
        'row', 'column', 'row-reverse', 'column-reverse',
        'cover', 'contain', 'auto', 'repeat', 'no-repeat',
        'uppercase', 'lowercase', 'capitalize', 'full_width', 'boxed',
    ];

    foreach ($elements as $element) {
        $widget_type = $element['widgetType'] ?? $element['elType'] ?? 'unknown';

        if (isset($element['settings']) && is_array($element['settings'])) {
            $settings = $element['settings'];

            // Check direct text keys
            foreach ($text_keys as $key) {
                if (isset($settings[$key]) && is_string($settings[$key])) {
                    $val = trim($settings[$key]);
                    if ($val !== '' && gt_is_translatable_value($val, $skip_values)) {
                        $strings[] = [
                            'text' => $val,
                            'context' => 'elementor_' . $key,
                        ];
                    }
                }
            }

            // Check repeater arrays for translatable sub-keys
            foreach ($repeater_keys as $rkey) {
                if (isset($settings[$rkey]) && is_array($settings[$rkey])) {
                    foreach ($settings[$rkey] as $item) {
                        if (!is_array($item)) {
                            continue;
                        }
                        foreach ($text_keys as $key) {
                            if (isset($item[$key]) && is_string($item[$key])) {
                                $val = trim($item[$key]);
                                if ($val !== '' && gt_is_translatable_value($val, $skip_values)) {
                                    $strings[] = [
                                        'text' => $val,
                                        'context' => 'elementor_' . $rkey . '_' . $key,
                                    ];
                                }
                            }
                        }
                    }
                }
            }
        }

        // Recursively process child elements
        if (isset($element['elements']) && is_array($element['elements'])) {
            $strings = gt_extract_elementor_strings($element['elements'], $post_id, $strings);
        }
    }

    return $strings;
}

// Check if a string value looks like translatable user content
function gt_is_translatable_value($value, $skip_values) {
    $lower = strtolower($value);

    // Skip known setting tokens
    if (in_array($lower, $skip_values, true)) {
        return false;
    }

    // Skip hex colors
    if (preg_match('/^#[0-9a-f]{3,8}$/i', $value)) {
        return false;
    }

    // Skip rgb/rgba values
    if (preg_match('/^rgba?\s*\(/i', $value)) {
        return false;
    }

    // Skip pure numbers (with optional unit)
    if (preg_match('/^-?[\d.]+(px|em|rem|%|vh|vw|s|ms)?$/', $value)) {
        return false;
    }

    // Skip URLs
    if (filter_var($value, FILTER_VALIDATE_URL)) {
        return false;
    }

    // Skip email addresses
    if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    // Must have at least one letter
    if (!preg_match('/[a-zA-Z\x{00C0}-\x{024F}\x{0400}-\x{04FF}\x{0600}-\x{06FF}\x{4E00}-\x{9FFF}]/u', $value)) {
        return false;
    }

    // Strip tags and check visible text is at least 2 chars
    $text_only = trim(strip_tags($value));
    if (mb_strlen($text_only) < 2) {
        return false;
    }

    return true;
}

// Insert string if not exists (with filtering)
function gt_insert_string($string, $context, $source_type, $source_id, $language) {
    global $wpdb;
    
    // Clean the string
    $string = trim($string);
    
    // Skip empty strings
    if (empty($string)) {
        return false;
    }
    
    // Skip very short strings (less than 2 characters)
    if (mb_strlen(strip_tags($string)) < 2) {
        return false;
    }
    
    // Skip strings that are only numbers
    if (is_numeric(strip_tags($string))) {
        return false;
    }
    
    // Skip strings that are only HTML without text
    $text_only = trim(strip_tags($string));
    if (empty($text_only)) {
        return false;
    }
    
    // Skip URLs
    if (filter_var($string, FILTER_VALIDATE_URL)) {
        return false;
    }
    
    // Skip email addresses
    if (filter_var($string, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    
    $table_name = $wpdb->prefix . 'gt_translations';
    $string_hash = hash('sha256', $string);

    // Check if already exists (any source, same string)
    $exists = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM $table_name WHERE string_hash = %s AND language_code = %s",
        $string_hash,
        $language
    ));
    
    if (!$exists) {
        $result = $wpdb->insert($table_name, [
            'original_string' => $string,
            'string_hash' => $string_hash,
            'language_code' => $language,
            'context' => $context,
            'source_type' => $source_type,
            'source_id' => $source_id,
            'status' => 'pending',
        ]);
        return ($result !== false);
    }

    return false;
}

// Clear all Elementor strings (for re-scanning)
function gt_clear_elementor_strings() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'gt_translations';

    return $wpdb->delete($table_name, ['source_type' => 'elementor']);
}

// Clear all WooCommerce product strings (for re-scanning)
function gt_clear_product_strings() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'gt_translations';

    return $wpdb->delete($table_name, ['source_type' => 'product']);
}

// Clear orphaned strings (from deleted/trashed pages/products)
function gt_clear_orphaned_strings() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'gt_translations';

    // Remove strings where post is deleted entirely OR in trash
    $orphans = $wpdb->get_results(
        "SELECT t.source_id, COUNT(*) as cnt
        FROM $table_name t
        LEFT JOIN {$wpdb->posts} p ON t.source_id = p.ID
        WHERE t.source_id IS NOT NULL
          AND (p.ID IS NULL OR p.post_status IN ('trash', 'auto-draft'))
        GROUP BY t.source_id"
    );

    $deleted_pages = 0;
    $deleted_strings = 0;

    foreach ($orphans as $orphan) {
        $wpdb->delete($table_name, ['source_id' => $orphan->source_id]);
        $deleted_pages++;
        $deleted_strings += (int) $orphan->cnt;
    }

    return ['pages' => $deleted_pages, 'strings' => $deleted_strings];
}

// Count orphaned strings (deleted or trashed posts) without removing them
function gt_count_orphaned_strings() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'gt_translations';

    return (int) $wpdb->get_var(
        "SELECT COUNT(*)
        FROM $table_name t
        LEFT JOIN {$wpdb->posts} p ON t.source_id = p.ID
        WHERE t.source_id IS NOT NULL
          AND (p.ID IS NULL OR p.post_status IN ('trash', 'auto-draft'))"
    );
}

// Test API connection
function gt_test_api_connection() {
    $api_key = get_option('gt_api_key');
    
    if (empty($api_key)) {
        return ['success' => false, 'message' => 'API key is empty'];
    }
    
    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent";

    $response = wp_remote_post($url, [
        'headers' => [
            'Content-Type' => 'application/json',
            'x-goog-api-key' => $api_key,
        ],
        'body' => json_encode([
            'contents' => [
                ['parts' => [['text' => 'Say OK']]]
            ]
        ]),
        'timeout' => 60,
    ]);
    
    if (is_wp_error($response)) {
        return [
            'success' => false, 
            'message' => 'WordPress connection error: ' . $response->get_error_message()
        ];
    }
    
    $code = wp_remote_retrieve_response_code($response);
    $body_raw = wp_remote_retrieve_body($response);
    $body = json_decode($body_raw, true);
    
    if ($code !== 200) {
        $error_msg = $body['error']['message'] ?? $body_raw;
        return [
            'success' => false, 
            'message' => "HTTP $code: $error_msg"
        ];
    }
    
    if (isset($body['candidates'][0]['content']['parts'][0]['text'])) {
        return ['success' => true, 'message' => 'API connection successful! Response: ' . $body['candidates'][0]['content']['parts'][0]['text']];
    }
    
    return ['success' => false, 'message' => 'Unexpected response: ' . substr($body_raw, 0, 200)];
}

// Call Gemini API
function gt_translate_with_gemini($text, $target_language) {
    $api_key = get_option('gt_api_key');
    
    if (empty($api_key)) {
        return ['success' => false, 'error' => 'API key not configured'];
    }
    
    $language_names = [
        'en' => 'English',
        'es' => 'Spanish',
        'pt' => 'Portuguese',
        'fr' => 'French',
        'de' => 'German',
        'it' => 'Italian',
        'nl' => 'Dutch',
        'pl' => 'Polish',
        'ru' => 'Russian',
        'zh' => 'Chinese',
        'ja' => 'Japanese',
        'ko' => 'Korean',
        'ar' => 'Arabic',
    ];
    
    $lang_name = $language_names[$target_language] ?? $target_language;
    
    $prompt = "Translate the following text to $lang_name. Return ONLY the translation, nothing else. Keep any HTML tags intact.\n\nText: $text";
    
    $response = wp_remote_post(
        "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent",
        [
            'headers' => [
                'Content-Type' => 'application/json',
                'x-goog-api-key' => $api_key,
            ],
            'body' => json_encode([
                'contents' => [
                    ['parts' => [['text' => $prompt]]]
                ]
            ]),
            'timeout' => 60,
        ]
    );
    
    if (is_wp_error($response)) {
        return ['success' => false, 'error' => 'Connection failed: ' . $response->get_error_message()];
    }
    
    $code = wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);
    
    if ($code !== 200) {
        $error_msg = $body['error']['message'] ?? "HTTP error $code";
        return ['success' => false, 'error' => $error_msg];
    }
    
    if (isset($body['candidates'][0]['content']['parts'][0]['text'])) {
        return ['success' => true, 'translation' => trim($body['candidates'][0]['content']['parts'][0]['text'])];
    }
    
    return ['success' => false, 'error' => 'Invalid response format from API'];
}

// Translate pending strings (batch)
function gt_translate_batch($limit = 10) {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'gt_translations';
    $language = get_option('gt_target_language');
    
    $pending = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table_name WHERE status = 'pending' AND language_code = %s LIMIT %d",
        $language,
        $limit
    ));
    
    $translated = 0;
    $errors = [];
    
    foreach ($pending as $item) {
        $result = gt_translate_with_gemini($item->original_string, $language);
        
        if (!$result['success']) {
            $errors[] = $result['error'];
            continue;
        }
        
        $wpdb->update(
            $table_name,
            [
                'translated_string' => $result['translation'],
                'status' => 'translated',
            ],
            ['id' => $item->id]
        );
        
        $translated++;
        
        usleep(500000);
    }
    
    return ['translated' => $translated, 'errors' => $errors];
}

// AJAX handler for batch translation
function gt_ajax_translate_batch() {
    check_ajax_referer('gt_ajax_translate', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
    }

    $batch_size = isset($_POST['batch_size']) ? intval($_POST['batch_size']) : 10;
    $result = gt_translate_batch($batch_size);

    global $wpdb;
    $table_name = $wpdb->prefix . 'gt_translations';
    $language = get_option('gt_target_language');
    $remaining = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $table_name WHERE status = 'pending' AND language_code = %s",
        $language
    ));

    wp_send_json_success([
        'translated' => $result['translated'],
        'errors' => $result['errors'],
        'remaining' => intval($remaining),
    ]);
}
add_action('wp_ajax_gt_translate_batch', 'gt_ajax_translate_batch');

// Enqueue admin scripts
function gt_admin_scripts($hook) {
    if (strpos($hook, 'gemini-translator') === false) {
        return;
    }
    wp_enqueue_script('gt-admin', GEMINI_TRANSLATOR_PLUGIN_URL . 'admin.js', ['jquery'], GEMINI_TRANSLATOR_VERSION, true);
    wp_localize_script('gt-admin', 'gt_ajax', [
        'url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('gt_ajax_translate'),
    ]);
}
add_action('admin_enqueue_scripts', 'gt_admin_scripts');

// Handle actions
function gt_handle_actions() {
    if (!current_user_can('manage_options')) {
        return;
    }

    if (isset($_POST['gt_scan']) && check_admin_referer('gt_scan_action')) {
        $count = gt_scan_products();
        add_settings_error('gt_messages', 'gt_scan_success', sprintf('Scanned %d strings from products.', $count), 'success');
    }

    if (isset($_POST['gt_test_api']) && check_admin_referer('gt_test_api_action')) {
        $result = gt_test_api_connection();
        $type = $result['success'] ? 'success' : 'error';
        add_settings_error('gt_messages', 'gt_test_result', wp_kses_post($result['message']), $type);
    }

    if (isset($_POST['gt_translate']) && check_admin_referer('gt_translate_action')) {
        $result = gt_translate_batch(20);
        if ($result['translated'] > 0) {
            add_settings_error('gt_messages', 'gt_translate_success', sprintf('Translated %d strings.', $result['translated']), 'success');
        }
        if (!empty($result['errors'])) {
            $unique_errors = array_map('sanitize_text_field', array_unique($result['errors']));
            add_settings_error('gt_messages', 'gt_translate_errors', 'Errors: ' . implode(', ', $unique_errors), 'error');
        }
    }

    // Handle inline edit save
    if (isset($_POST['gt_save_translation']) && check_admin_referer('gt_save_translation_action')) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'gt_translations';

        $id = intval($_POST['translation_id']);
        $new_translation = wp_kses_post($_POST['translated_string']);

        $wpdb->update(
            $table_name,
            [
                'translated_string' => $new_translation,
                'status' => 'edited',
            ],
            ['id' => $id]
        );

        add_settings_error('gt_messages', 'gt_save_success', 'Translation saved!', 'success');
    }

    if (isset($_POST['gt_scan_elementor']) && check_admin_referer('gt_scan_elementor_action')) {
        $count = gt_scan_elementor();
        add_settings_error('gt_messages', 'gt_scan_elementor_success', sprintf('Scanned %d strings from Elementor.', $count), 'success');
    }

    if (isset($_POST['gt_clear_products']) && check_admin_referer('gt_clear_products_action')) {
        gt_clear_product_strings();
        add_settings_error('gt_messages', 'gt_clear_products_success', 'Cleared WooCommerce strings. Ready to re-scan.', 'success');
    }

    if (isset($_POST['gt_clear_elementor']) && check_admin_referer('gt_clear_elementor_action')) {
        gt_clear_elementor_strings();
        add_settings_error('gt_messages', 'gt_clear_success', 'Cleared Elementor strings. Ready to re-scan.', 'success');
    }

    if (isset($_POST['gt_clear_orphaned']) && check_admin_referer('gt_clear_orphaned_action')) {
        $result = gt_clear_orphaned_strings();
        add_settings_error('gt_messages', 'gt_clear_orphaned_success',
            sprintf('Cleared %d strings from %d deleted pages/products.', $result['strings'], $result['pages']), 'success');
    }
}
add_action('admin_init', 'gt_handle_actions');

// Main admin page (Dashboard)
function gt_admin_page() {
    global $wpdb;
    $api_key = get_option('gt_api_key');
    $language = get_option('gt_target_language');
    $table_name = $wpdb->prefix . 'gt_translations';
    
    // Fetch all stats in a single query
    $stats_rows = $wpdb->get_results(
        "SELECT source_type, status, COUNT(*) as cnt FROM $table_name GROUP BY source_type, status"
    );

    $total_strings = 0;
    $total_pending = 0;
    $total_translated = 0;
    $total_edited = 0;

    $sources = [
        'product'   => ['label' => 'WooCommerce', 'icon' => '&#x1f6d2;'],
        'elementor' => ['label' => 'Elementor', 'icon' => '&#x1f4c4;'],
        'wcfm'      => ['label' => 'WCFM', 'icon' => '&#x1f3ea;'],
    ];

    $stats_by_source = [];
    foreach ($sources as $st => $info) {
        $stats_by_source[$st] = [
            'label'      => $info['label'],
            'icon'       => $info['icon'],
            'total'      => 0,
            'pending'    => 0,
            'translated' => 0,
            'edited'     => 0,
        ];
    }

    foreach ($stats_rows as $row) {
        $cnt = (int) $row->cnt;
        $total_strings += $cnt;
        if ($row->status === 'pending') $total_pending += $cnt;
        if ($row->status === 'translated') $total_translated += $cnt;
        if ($row->status === 'edited') $total_edited += $cnt;

        if (isset($stats_by_source[$row->source_type])) {
            $stats_by_source[$row->source_type]['total'] += $cnt;
            if (isset($stats_by_source[$row->source_type][$row->status])) {
                $stats_by_source[$row->source_type][$row->status] += $cnt;
            }
        }
    }
    
    settings_errors('gt_messages');
    ?>
    <div class="wrap">
        <h1>Gemini Translator</h1>
        
        <?php if (empty($api_key) || empty($language)): ?>
            <div class="notice notice-warning">
                <p>⚠️ Please <a href="<?php echo admin_url('admin.php?page=gemini-translator-settings'); ?>">configure your API key and language</a> to get started.</p>
            </div>
        <?php else: ?>
            <div class="notice notice-success">
                <p>&#x2705; Ready to translate to <strong><?php echo esc_html(strtoupper($language)); ?></strong></p>
            </div>
            
            <!-- General Stats -->
            <h2>Overview</h2>
            <div style="display: flex; gap: 20px; margin: 20px 0; flex-wrap: wrap;">
                <div class="card" style="padding: 15px; min-width: 120px;">
                    <h3 style="margin: 0; font-size: 32px;"><?php echo intval($total_strings); ?></h3>
                    <p style="margin: 5px 0 0;">Total strings</p>
                </div>
                <div class="card" style="padding: 15px; min-width: 120px;">
                    <h3 style="margin: 0; font-size: 32px; color: #f0ad4e;"><?php echo intval($total_pending); ?></h3>
                    <p style="margin: 5px 0 0;">Pending</p>
                </div>
                <div class="card" style="padding: 15px; min-width: 120px;">
                    <h3 style="margin: 0; font-size: 32px; color: #5cb85c;"><?php echo intval($total_translated); ?></h3>
                    <p style="margin: 5px 0 0;">Translated</p>
                </div>
                <div class="card" style="padding: 15px; min-width: 120px;">
                    <h3 style="margin: 0; font-size: 32px; color: #0073aa;"><?php echo intval($total_edited); ?></h3>
                    <p style="margin: 5px 0 0;">Edited</p>
                </div>
            </div>
            
            <!-- Stats by Source -->
            <h2>By Source</h2>
            <table class="wp-list-table widefat fixed striped" style="max-width: 600px;">
                <thead>
                    <tr>
                        <th>Source</th>
                        <th style="text-align: center;">Total</th>
                        <th style="text-align: center;">Pending</th>
                        <th style="text-align: center;">Translated</th>
                        <th style="text-align: center;">Edited</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($stats_by_source as $source_type => $stats): ?>
                        <?php if ($stats['total'] > 0): ?>
                        <tr>
                            <td><?php echo wp_kses_post($stats['icon']); ?> <?php echo esc_html($stats['label']); ?></td>
                            <td style="text-align: center;"><?php echo intval($stats['total']); ?></td>
                            <td style="text-align: center;">
                                <span style="color: #f0ad4e; font-weight: bold;"><?php echo intval($stats['pending']); ?></span>
                            </td>
                            <td style="text-align: center;">
                                <span style="color: #5cb85c; font-weight: bold;"><?php echo intval($stats['translated']); ?></span>
                            </td>
                            <td style="text-align: center;">
                                <span style="color: #0073aa; font-weight: bold;"><?php echo intval($stats['edited']); ?></span>
                            </td>
                        </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <!-- Actions -->
            <h2 style="margin-top: 30px;">Actions</h2>
            <div style="display: flex; gap: 20px; flex-wrap: wrap;">
                <div class="card" style="padding: 20px; min-width: 280px;">
                    <h3 style="margin-top: 0;">1. Scan Content</h3>
                    <p>Detect translatable strings from your site.</p>
                    <form method="post" style="display: flex; flex-direction: column; gap: 10px;">
                        <?php wp_nonce_field('gt_scan_action'); ?>
                        <button type="submit" name="gt_scan" class="button button-secondary">
                            🛒 Scan WooCommerce Products
                        </button>
                    </form>
                    <form method="post" style="margin-top: 10px;">
                        <?php wp_nonce_field('gt_scan_elementor_action'); ?>
                        <button type="submit" name="gt_scan_elementor" class="button button-secondary">
                            📄 Scan Elementor Pages
                        </button>
                    </form>
                    <form method="post" style="margin-top: 10px;">
                        <?php wp_nonce_field('gt_clear_products_action'); ?>
                        <button type="submit" name="gt_clear_products" class="button button-link-delete"
                            onclick="return confirm('This will delete all WooCommerce strings. Continue?');">
                            Clear WooCommerce Strings
                        </button>
                    </form>
                    <form method="post" style="margin-top: 5px;">
                        <?php wp_nonce_field('gt_clear_elementor_action'); ?>
                        <button type="submit" name="gt_clear_elementor" class="button button-link-delete"
                            onclick="return confirm('This will delete all Elementor strings. Continue?');">
                            Clear Elementor Strings
                        </button>
                    </form>

                    <form method="post" style="margin-top: 5px;">
                        <?php wp_nonce_field('gt_clear_orphaned_action'); ?>
                        <button type="submit" name="gt_clear_orphaned" class="button button-link-delete button-small" 
                            onclick="return confirm('Delete strings from deleted pages/products?');">
                            🧹 Clear Orphaned Strings
                        </button>
                    </form>
                </div>
                
                <div class="card" style="padding: 20px; min-width: 280px;">
                    <h3 style="margin-top: 0;">2. Translate</h3>
                    <p>Translate pending strings using Gemini AI.</p>
                    <form method="post" style="display: flex; gap: 10px; flex-wrap: wrap;">
                        <?php wp_nonce_field('gt_translate_action'); ?>
                        <button type="submit" name="gt_translate" class="button button-secondary" <?php disabled($total_pending, 0); ?>>
                            Translate Batch (20)
                        </button>
                    </form>
                    <div style="margin-top: 10px;">
                        <button type="button" id="gt-translate-all-btn" class="button button-primary" <?php disabled($total_pending, 0); ?>>
                            Translate All (<span id="gt-remaining"><?php echo intval($total_pending); ?></span> strings)
                        </button>
                        <div id="gt-translate-progress" style="display:none; margin-top: 10px;">
                            <progress id="gt-progress-bar" max="<?php echo intval($total_pending); ?>" value="0" style="width: 100%;"></progress>
                            <p id="gt-progress-text" class="description"></p>
                        </div>
                    </div>
                </div>
                
                <div class="card" style="padding: 20px; min-width: 280px;">
                    <h3 style="margin-top: 0;">3. Review & Edit</h3>
                    <p>Review and edit your translations.</p>
                    <a href="<?php echo admin_url('admin.php?page=gemini-translator-list'); ?>" class="button button-primary">
                        View Translations
                    </a>
                </div>
                
                <div class="card" style="padding: 20px; min-width: 280px;">
                    <h3 style="margin-top: 0;">Test API</h3>
                    <p>Verify your Gemini API connection.</p>
                    <form method="post">
                        <?php wp_nonce_field('gt_test_api_action'); ?>
                        <button type="submit" name="gt_test_api" class="button button-secondary">
                            Test Connection
                        </button>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <?php
}

// Translations list page — grouped by page/product with accordion UI
function gt_translations_page() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'gt_translations';

    // Handle inline orphan cleanup
    if (isset($_POST['gt_clean_orphans_inline']) && check_admin_referer('gt_clean_orphans_inline_action')) {
        $result = gt_clear_orphaned_strings();
        add_settings_error('gt_messages', 'gt_orphans_cleaned',
            sprintf('Cleaned up %d strings from %d deleted pages.', $result['strings'], $result['pages']),
            'success'
        );
    }

    // Check for orphaned strings
    $orphan_count = gt_count_orphaned_strings();

    // Filters
    $status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
    $context_filter = isset($_GET['context']) ? sanitize_text_field($_GET['context']) : '';
    $source_filter = isset($_GET['source_type']) ? sanitize_text_field($_GET['source_type']) : '';
    $page_filter = isset($_GET['source_id']) ? intval($_GET['source_id']) : 0;
    $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';

    // Build WHERE clause (applied to alias "t")
    $where = "WHERE 1=1";
    if ($status_filter) {
        $where .= $wpdb->prepare(" AND t.status = %s", $status_filter);
    }
    if ($context_filter) {
        $where .= $wpdb->prepare(" AND t.context = %s", $context_filter);
    }
    if ($source_filter) {
        $where .= $wpdb->prepare(" AND t.source_type = %s", $source_filter);
    }
    if ($page_filter) {
        $where .= $wpdb->prepare(" AND t.source_id = %d", $page_filter);
    }
    if ($search) {
        $where .= $wpdb->prepare(" AND (t.original_string LIKE %s OR t.translated_string LIKE %s)", '%' . $wpdb->esc_like($search) . '%', '%' . $wpdb->esc_like($search) . '%');
    }

    // Only show strings belonging to published posts
    $valid_join = "FROM $table_name t INNER JOIN {$wpdb->posts} p ON t.source_id = p.ID AND p.post_status IN ('publish', 'draft', 'private')";

    // Page-level pagination
    $pages_per_view = 10;
    $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $page_offset = ($current_page - 1) * $pages_per_view;

    // Count distinct pages matching filters
    $total_source_pages = (int) $wpdb->get_var("SELECT COUNT(DISTINCT t.source_id) $valid_join $where");
    $total_pages = max(1, ceil($total_source_pages / $pages_per_view));

    // Get the source_ids for the current page view
    $source_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT t.source_id $valid_join $where ORDER BY t.source_id LIMIT %d, %d",
        $page_offset, $pages_per_view
    ));

    // Get per-source stats (total + pending counts)
    $groups = [];
    if (!empty($source_ids)) {
        $id_placeholders = implode(',', array_fill(0, count($source_ids), '%d'));

        $stats_query = $wpdb->prepare(
            "SELECT t.source_id, t.source_type, COUNT(*) as total,
                SUM(CASE WHEN t.status = 'pending' THEN 1 ELSE 0 END) as pending
            $valid_join $where AND t.source_id IN ($id_placeholders)
            GROUP BY t.source_id, t.source_type
            ORDER BY t.source_id",
            ...$source_ids
        );
        $stats = $wpdb->get_results($stats_query);

        foreach ($stats as $stat) {
            $groups[$stat->source_id] = [
                'source_type' => $stat->source_type,
                'total' => (int) $stat->total,
                'pending' => (int) $stat->pending,
                'items' => [],
            ];
        }

        $items_query = $wpdb->prepare(
            "SELECT t.* $valid_join $where AND t.source_id IN ($id_placeholders) ORDER BY t.source_id, t.id",
            ...$source_ids
        );
        $items = $wpdb->get_results($items_query);

        foreach ($items as $item) {
            $groups[$item->source_id]['items'][] = $item;
        }
    }

    // Total string count (valid posts only)
    $total_strings = (int) $wpdb->get_var("SELECT COUNT(*) $valid_join $where");

    // Get available filter options (valid posts only)
    $contexts = $wpdb->get_col("SELECT DISTINCT t.context $valid_join ORDER BY t.context");
    $source_types = $wpdb->get_col("SELECT DISTINCT t.source_type $valid_join ORDER BY t.source_type");
    $all_source_pages = $wpdb->get_results("SELECT DISTINCT t.source_id, t.source_type $valid_join WHERE t.source_id IS NOT NULL ORDER BY t.source_type, t.source_id");
    $page_options = [];
    foreach ($all_source_pages as $p) {
        $title = get_the_title($p->source_id);
        $page_options[$p->source_id] = [
            'title' => $title ?: "Untitled (ID: {$p->source_id})",
            'type' => $p->source_type,
        ];
    }

    settings_errors('gt_messages');
    ?>
    <div class="wrap">
        <h1>Translations</h1>

        <?php if ($orphan_count > 0): ?>
            <div class="notice notice-warning" style="display: flex; align-items: center; gap: 10px; padding: 10px 15px;">
                <p style="margin: 0; flex: 1;">
                    <strong><?php echo intval($orphan_count); ?> strings</strong> reference deleted or trashed content.
                </p>
                <form method="post" style="margin: 0;">
                    <?php wp_nonce_field('gt_clean_orphans_inline_action'); ?>
                    <button type="submit" name="gt_clean_orphans_inline" class="button button-small">Clean Up Now</button>
                </form>
            </div>
        <?php endif; ?>

        <!-- Filters -->
        <div class="tablenav top">
            <form method="get" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                <input type="hidden" name="page" value="gemini-translator-list" />

                <select name="source_type">
                    <option value="">All sources</option>
                    <?php foreach ($source_types as $type): ?>
                        <option value="<?php echo esc_attr($type); ?>" <?php selected($source_filter, $type); ?>>
                            <?php echo esc_html(ucfirst($type)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <select name="source_id">
                    <option value="">All pages/products</option>
                    <?php foreach ($page_options as $id => $info): ?>
                        <option value="<?php echo esc_attr($id); ?>" <?php selected($page_filter, $id); ?>>
                            <?php echo esc_html("[{$info['type']}] " . wp_trim_words($info['title'], 5)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <select name="status">
                    <option value="">All statuses</option>
                    <option value="pending" <?php selected($status_filter, 'pending'); ?>>Pending</option>
                    <option value="translated" <?php selected($status_filter, 'translated'); ?>>Translated</option>
                    <option value="edited" <?php selected($status_filter, 'edited'); ?>>Edited</option>
                </select>

                <select name="context">
                    <option value="">All contexts</option>
                    <?php foreach ($contexts as $ctx): ?>
                        <option value="<?php echo esc_attr($ctx); ?>" <?php selected($context_filter, $ctx); ?>>
                            <?php echo esc_html($ctx); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Search..." />

                <button type="submit" class="button">Filter</button>

                <?php if ($status_filter || $context_filter || $search || $source_filter || $page_filter): ?>
                    <a href="<?php echo admin_url('admin.php?page=gemini-translator-list'); ?>" class="button">Clear</a>
                <?php endif; ?>
            </form>

            <div style="margin-top: 10px; display: flex; gap: 15px; align-items: center;">
                <span class="displaying-num"><?php echo intval($total_strings); ?> strings across <?php echo intval($total_source_pages); ?> pages</span>
                <?php if (!empty($groups)): ?>
                    <button type="button" class="button button-small" id="gt-expand-all">Expand All</button>
                    <button type="button" class="button button-small" id="gt-collapse-all">Collapse All</button>
                <?php endif; ?>
            </div>
        </div>

        <?php if (empty($groups)): ?>
            <p>No translations found.</p>
        <?php else: ?>
            <?php
            $status_colors = [
                'pending' => '#f0ad4e',
                'translated' => '#5cb85c',
                'edited' => '#0073aa',
            ];
            foreach ($groups as $source_id => $group):
                $page_title = get_the_title($source_id);
                $page_url = get_permalink($source_id);
                $edit_url = get_edit_post_link($source_id);
                if (empty($page_title)) $page_title = "ID: {$source_id}";
                $pending_label = $group['pending'] > 0 ? ", {$group['pending']} pending" : '';
            ?>
            <div class="gt-accordion-group" style="margin-bottom: 1px;">
                <div class="gt-accordion-header" data-source="<?php echo intval($source_id); ?>" style="background: #f9f9f9; border: 1px solid #ddd; padding: 10px 15px; cursor: pointer; display: flex; align-items: center; gap: 10px; user-select: none;">
                    <span class="gt-accordion-arrow dashicons dashicons-arrow-right-alt2" style="transition: transform 0.15s;"></span>
                    <strong style="flex: 1;">
                        <?php echo esc_html($page_title); ?>
                        <span style="font-weight: normal; color: #666; font-size: 12px; margin-left: 8px;">
                            [<?php echo esc_html($group['source_type']); ?>] &mdash; <?php echo intval($group['total']); ?> strings<?php echo esc_html($pending_label); ?>
                        </span>
                    </strong>
                    <?php if ($page_url): ?>
                        <a href="<?php echo esc_url($page_url); ?>" target="_blank" class="button button-small" onclick="event.stopPropagation();">View</a>
                    <?php endif; ?>
                    <?php if ($edit_url): ?>
                        <a href="<?php echo esc_url($edit_url); ?>" target="_blank" class="button button-small" onclick="event.stopPropagation();">Edit Post</a>
                    <?php endif; ?>
                </div>
                <div class="gt-accordion-body" data-source="<?php echo intval($source_id); ?>" style="display: none; border: 1px solid #ddd; border-top: 0;">
                    <table class="wp-list-table widefat fixed striped" style="border: 0;">
                        <thead>
                            <tr>
                                <th style="width: 40px;">ID</th>
                                <th style="width: 90px;">Context</th>
                                <th>Original</th>
                                <th>Translation</th>
                                <th style="width: 80px;">Status</th>
                                <th style="width: 70px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($group['items'] as $item): ?>
                                <tr id="row-<?php echo intval($item->id); ?>">
                                    <td><?php echo intval($item->id); ?></td>
                                    <td>
                                        <span style="background: #f0f0f0; padding: 2px 8px; border-radius: 3px; font-size: 11px;">
                                            <?php echo esc_html($item->context); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div style="max-height: 80px; overflow-y: auto; font-size: 13px;">
                                            <?php echo esc_html(wp_trim_words($item->original_string, 20)); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="translation-display" id="display-<?php echo intval($item->id); ?>" style="max-height: 80px; overflow-y: auto; font-size: 13px;">
                                            <?php echo esc_html(wp_trim_words($item->translated_string, 20)); ?>
                                        </div>
                                        <form method="post" class="translation-form" id="form-<?php echo intval($item->id); ?>" style="display: none;">
                                            <?php wp_nonce_field('gt_save_translation_action'); ?>
                                            <input type="hidden" name="translation_id" value="<?php echo intval($item->id); ?>" />
                                            <textarea name="translated_string" rows="3" style="width: 100%;"><?php echo esc_textarea($item->translated_string); ?></textarea>
                                            <div style="margin-top: 5px;">
                                                <button type="submit" name="gt_save_translation" class="button button-primary button-small">Save</button>
                                                <button type="button" class="button button-small cancel-edit" data-id="<?php echo intval($item->id); ?>">Cancel</button>
                                            </div>
                                        </form>
                                    </td>
                                    <td>
                                        <?php $color = $status_colors[$item->status] ?? '#999'; ?>
                                        <span style="background: <?php echo esc_attr($color); ?>; color: white; padding: 2px 8px; border-radius: 3px; font-size: 11px;">
                                            <?php echo esc_html($item->status); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <button type="button" class="button button-small edit-translation" data-id="<?php echo intval($item->id); ?>">Edit</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <!-- Page-level Pagination -->
        <?php if ($total_pages > 1): ?>
            <div class="tablenav bottom">
                <div class="tablenav-pages">
                    <?php
                    echo paginate_links([
                        'base' => add_query_arg('paged', '%#%'),
                        'format' => '',
                        'total' => $total_pages,
                        'current' => $current_page,
                        'prev_text' => '&laquo;',
                        'next_text' => '&raquo;',
                    ]);
                    ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script>
    jQuery(document).ready(function($) {
        // Accordion toggle
        $('.gt-accordion-header').on('click', function() {
            var $body = $(this).next('.gt-accordion-body');
            var $arrow = $(this).find('.gt-accordion-arrow');
            $body.slideToggle(150);
            $arrow.toggleClass('gt-arrow-open');
        });

        // Expand / Collapse all
        $('#gt-expand-all').on('click', function() {
            $('.gt-accordion-body').slideDown(150);
            $('.gt-accordion-arrow').addClass('gt-arrow-open');
        });
        $('#gt-collapse-all').on('click', function() {
            $('.gt-accordion-body').slideUp(150);
            $('.gt-accordion-arrow').removeClass('gt-arrow-open');
        });

        // Inline editing
        $(document).on('click', '.edit-translation', function() {
            var id = $(this).data('id');
            $('#display-' + id).hide();
            $('#form-' + id).show();
            $(this).hide();
        });
        $(document).on('click', '.cancel-edit', function() {
            var id = $(this).data('id');
            $('#form-' + id).hide();
            $('#display-' + id).show();
            $('button[data-id="' + id + '"].edit-translation').show();
        });
    });
    </script>

    <style>
        .gt-accordion-arrow { transition: transform 0.15s; }
        .gt-accordion-arrow.gt-arrow-open { transform: rotate(90deg); }
        .gt-accordion-header:hover { background: #f0f0f0 !important; }
        .wrap .tablenav.top { height: auto; }
    </style>
    <?php
}

// Settings page
function gt_settings_page() {
    $languages = gt_get_available_languages();
    $wp_lang = gt_get_wp_language();
    $source_lang = gt_get_source_language();
    $target_lang = get_option('gt_target_language');
    ?>
    <div class="wrap">
        <h1>Translator Settings</h1>
        
        <form method="post" action="options.php">
            <?php settings_fields('gt_settings'); ?>
            
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="gt_api_key">Gemini API Key</label>
                    </th>
                    <td>
                        <input 
                            type="password" 
                            id="gt_api_key" 
                            name="gt_api_key" 
                            value="<?php echo esc_attr(get_option('gt_api_key')); ?>" 
                            class="regular-text"
                        />
                        <p class="description">
                            Get your API key from <a href="https://aistudio.google.com/app/apikey" target="_blank">Google AI Studio</a>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="gt_source_language">Site Original Language</label>
                    </th>
                    <td>
                        <select id="gt_source_language" name="gt_source_language">
                            <?php foreach ($languages as $code => $name): ?>
                                <option value="<?php echo esc_attr($code); ?>" <?php selected($source_lang, $code); ?>>
                                    <?php echo esc_html($name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">
                            The original language of your site content.
                            WordPress detected: <strong><?php echo esc_html($languages[$wp_lang] ?? $wp_lang); ?></strong>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="gt_target_language">Translate To</label>
                    </th>
                    <td>
                        <select id="gt_target_language" name="gt_target_language">
                            <option value="">Select language...</option>
                            <?php foreach ($languages as $code => $name): ?>
                                <?php if ($code !== $source_lang): ?>
                                    <option value="<?php echo esc_attr($code); ?>" <?php selected($target_lang, $code); ?>>
                                        <?php echo esc_html($name); ?>
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">Language to translate your content into</p>
                    </td>
                </tr>
            </table>
            
            <?php submit_button('Save Settings'); ?>
        </form>
        
        <hr>
        
        <h2>Language Info</h2>
        <table class="widefat" style="max-width: 400px;">
            <tr>
                <td><strong>WordPress Locale:</strong></td>
                <td><?php echo esc_html(get_locale()); ?></td>
            </tr>
            <tr>
                <td><strong>Site Language:</strong></td>
                <td><?php echo esc_html($languages[$source_lang] ?? $source_lang); ?></td>
            </tr>
            <tr>
                <td><strong>Target Language:</strong></td>
                <td><?php echo esc_html($target_lang ? ($languages[$target_lang] ?? $target_lang) : 'Not set'); ?></td>
            </tr>
        </table>
    </div>
    <?php
}

// Switcher Style admin page
function gt_switcher_style_page() {
    $s = gt_get_switcher_style();
    $source_lang = gt_get_source_language();
    $target_lang = get_option('gt_target_language');
    $languages = gt_get_available_languages();
    $source_name = $languages[$source_lang] ?? $source_lang;
    $target_name = $languages[$target_lang] ?? ($target_lang ?: 'Target');
    $source_label = gt_format_lang_label($source_lang, $source_name, $s['label_format']);
    $target_label = gt_format_lang_label($target_lang ?: 'xx', $target_name, $s['label_format']);

    // Enqueue WP color picker
    wp_enqueue_style('wp-color-picker');
    wp_enqueue_script('wp-color-picker');
    ?>
    <div class="wrap">
        <h1>Switcher Style</h1>
        <p>Customize the appearance of the <code>[gt_language_switcher]</code> shortcode.</p>

        <div style="display: flex; gap: 40px; align-items: flex-start; flex-wrap: wrap;">
            <!-- Settings Form -->
            <div style="flex: 1; min-width: 380px; max-width: 520px;">
                <form method="post" action="options.php" id="gt-switcher-form">
                    <?php settings_fields('gt_switcher_settings'); ?>

                    <h2>Colors</h2>
                    <table class="form-table">
                        <tr>
                            <th>Background</th>
                            <td><input type="text" name="gt_switcher_style[bg_color]" value="<?php echo esc_attr($s['bg_color']); ?>" class="gt-color-field" data-var="bgColor" /></td>
                        </tr>
                        <tr>
                            <th>Text Color</th>
                            <td><input type="text" name="gt_switcher_style[text_color]" value="<?php echo esc_attr($s['text_color']); ?>" class="gt-color-field" data-var="textColor" /></td>
                        </tr>
                        <tr>
                            <th>Active Background</th>
                            <td><input type="text" name="gt_switcher_style[active_bg_color]" value="<?php echo esc_attr($s['active_bg_color']); ?>" class="gt-color-field" data-var="activeBgColor" /></td>
                        </tr>
                        <tr>
                            <th>Active Text</th>
                            <td><input type="text" name="gt_switcher_style[active_text_color]" value="<?php echo esc_attr($s['active_text_color']); ?>" class="gt-color-field" data-var="activeTextColor" /></td>
                        </tr>
                        <tr>
                            <th>Hover Background</th>
                            <td><input type="text" name="gt_switcher_style[hover_bg_color]" value="<?php echo esc_attr($s['hover_bg_color']); ?>" class="gt-color-field" data-var="hoverBgColor" /></td>
                        </tr>
                        <tr>
                            <th>Hover Text</th>
                            <td><input type="text" name="gt_switcher_style[hover_text_color]" value="<?php echo esc_attr($s['hover_text_color']); ?>" class="gt-color-field" data-var="hoverTextColor" /></td>
                        </tr>
                        <tr>
                            <th>Border Color</th>
                            <td><input type="text" name="gt_switcher_style[border_color]" value="<?php echo esc_attr($s['border_color']); ?>" class="gt-color-field" data-var="borderColor" /></td>
                        </tr>
                    </table>

                    <h2>Dimensions</h2>
                    <table class="form-table">
                        <tr>
                            <th>Border Width (px)</th>
                            <td><input type="number" name="gt_switcher_style[border_width]" value="<?php echo esc_attr($s['border_width']); ?>" min="0" max="10" class="small-text gt-range-field" data-var="borderWidth" /></td>
                        </tr>
                        <tr>
                            <th>Border Radius (px)</th>
                            <td><input type="number" name="gt_switcher_style[border_radius]" value="<?php echo esc_attr($s['border_radius']); ?>" min="0" max="50" class="small-text gt-range-field" data-var="borderRadius" /></td>
                        </tr>
                        <tr>
                            <th>Font Size (px)</th>
                            <td><input type="number" name="gt_switcher_style[font_size]" value="<?php echo esc_attr($s['font_size']); ?>" min="10" max="24" class="small-text gt-range-field" data-var="fontSize" /></td>
                        </tr>
                        <tr>
                            <th>Horizontal Padding (px)</th>
                            <td><input type="number" name="gt_switcher_style[padding_h]" value="<?php echo esc_attr($s['padding_h']); ?>" min="0" max="40" class="small-text gt-range-field" data-var="paddingH" /></td>
                        </tr>
                        <tr>
                            <th>Vertical Padding (px)</th>
                            <td><input type="number" name="gt_switcher_style[padding_v]" value="<?php echo esc_attr($s['padding_v']); ?>" min="0" max="30" class="small-text gt-range-field" data-var="paddingV" /></td>
                        </tr>
                        <tr>
                            <th>Gap Between Buttons (px)</th>
                            <td><input type="number" name="gt_switcher_style[gap]" value="<?php echo esc_attr($s['gap']); ?>" min="0" max="20" class="small-text gt-range-field" data-var="gap" /></td>
                        </tr>
                    </table>

                    <h2>Extras</h2>
                    <table class="form-table">
                        <tr>
                            <th>Box Shadow</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="gt_switcher_style[shadow]" value="1" <?php checked($s['shadow'], '1'); ?> class="gt-check-field" data-var="shadow" />
                                    Enable drop shadow
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th>Font Family</th>
                            <td>
                                <?php $available_fonts = gt_get_available_fonts(); ?>
                                <select name="gt_switcher_style[font_family]" class="gt-select-field" data-var="fontFamily">
                                    <option value="inherit" <?php selected($s['font_family'], 'inherit'); ?>>Inherit from site</option>
                                    <?php foreach ($available_fonts as $value => $label): ?>
                                        <option value="<?php echo esc_attr($value); ?>" <?php selected($s['font_family'], $value); ?>>
                                            <?php echo esc_html($label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th>Label Format</th>
                            <td>
                                <select name="gt_switcher_style[label_format]" class="gt-select-field" data-var="labelFormat">
                                    <option value="name" <?php selected($s['label_format'], 'name'); ?>>Full Name (English)</option>
                                    <option value="code" <?php selected($s['label_format'], 'code'); ?>>Code (EN)</option>
                                    <option value="both" <?php selected($s['label_format'], 'both'); ?>>Both (EN - English)</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th>Fixed Position</th>
                            <td>
                                <select name="gt_switcher_style[position]" class="gt-select-field" data-var="position">
                                    <option value="none" <?php selected($s['position'], 'none'); ?>>None (inline)</option>
                                    <option value="bottom-right" <?php selected($s['position'], 'bottom-right'); ?>>Bottom Right</option>
                                    <option value="bottom-left" <?php selected($s['position'], 'bottom-left'); ?>>Bottom Left</option>
                                    <option value="top-right" <?php selected($s['position'], 'top-right'); ?>>Top Right</option>
                                    <option value="top-left" <?php selected($s['position'], 'top-left'); ?>>Top Left</option>
                                </select>
                                <p class="description">Fix the switcher to a corner of the viewport.</p>
                            </td>
                        </tr>
                    </table>

                    <?php submit_button('Save Switcher Style'); ?>
                </form>
            </div>

            <!-- Live Preview -->
            <div style="flex: 0 0 340px; position: sticky; top: 40px;">
                <div class="card" style="padding: 24px;">
                    <h2 style="margin-top: 0;">Preview</h2>

                    <h3 style="margin-bottom: 8px; color: #666; font-size: 12px; text-transform: uppercase;">Buttons Style</h3>
                    <div id="gt-preview-buttons" style="display: inline-flex; margin-bottom: 24px;">
                        <a href="#" class="gt-preview-btn gt-prev-label-source" id="gt-prev-btn-source" onclick="return false;"><?php echo esc_html($source_label); ?></a>
                        <a href="#" class="gt-preview-btn gt-preview-active gt-prev-label-target" id="gt-prev-btn-target" onclick="return false;"><?php echo esc_html($target_label); ?></a>
                    </div>

                    <h3 style="margin-bottom: 8px; color: #666; font-size: 12px; text-transform: uppercase;">Dropdown Style</h3>
                    <div id="gt-preview-dropdown">
                        <select class="gt-preview-select" id="gt-prev-select">
                            <option class="gt-prev-label-source"><?php echo esc_html($source_label); ?></option>
                            <option selected class="gt-prev-label-target"><?php echo esc_html($target_label); ?></option>
                        </select>
                    </div>

                    <p class="description" style="margin-top: 20px;">
                        Use <code>[gt_language_switcher]</code> for dropdown or <code>[gt_language_switcher style="buttons"]</code> for buttons.
                    </p>
                </div>
            </div>
        </div>
    </div>

    <style>
        #gt-preview-buttons {
            gap: <?php echo intval($s['gap']); ?>px;
            border-radius: <?php echo intval($s['border_radius']); ?>px;
            overflow: hidden;
            border: <?php echo intval($s['border_width']); ?>px solid <?php echo esc_attr($s['border_color']); ?>;
            <?php if ($s['shadow'] === '1'): ?>
            box-shadow: 0 2px 8px rgba(0,0,0,0.12);
            <?php endif; ?>
        }
        .gt-preview-btn {
            display: inline-block;
            padding: <?php echo intval($s['padding_v']); ?>px <?php echo intval($s['padding_h']); ?>px;
            font-size: <?php echo intval($s['font_size']); ?>px;
            <?php if ($s['font_family'] !== 'inherit'): ?>font-family: <?php echo $s['font_family']; ?>;<?php endif; ?>
            background: <?php echo esc_attr($s['bg_color']); ?>;
            color: <?php echo esc_attr($s['text_color']); ?>;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.15s;
            line-height: 1.4;
        }
        .gt-preview-btn:hover {
            background: <?php echo esc_attr($s['hover_bg_color']); ?>;
            color: <?php echo esc_attr($s['hover_text_color']); ?>;
        }
        .gt-preview-btn.gt-preview-active {
            background: <?php echo esc_attr($s['active_bg_color']); ?>;
            color: <?php echo esc_attr($s['active_text_color']); ?>;
        }
        .gt-preview-select {
            padding: <?php echo intval($s['padding_v']); ?>px <?php echo intval($s['padding_h']); ?>px;
            font-size: <?php echo intval($s['font_size']); ?>px;
            <?php if ($s['font_family'] !== 'inherit'): ?>font-family: <?php echo $s['font_family']; ?>;<?php endif; ?>
            background: <?php echo esc_attr($s['bg_color']); ?>;
            color: <?php echo esc_attr($s['text_color']); ?>;
            border: <?php echo intval($s['border_width']); ?>px solid <?php echo esc_attr($s['border_color']); ?>;
            border-radius: <?php echo intval($s['border_radius']); ?>px;
            <?php if ($s['shadow'] === '1'): ?>
            box-shadow: 0 2px 8px rgba(0,0,0,0.12);
            <?php endif; ?>
            cursor: pointer;
            min-width: 140px;
        }
    </style>

    <script>
    jQuery(document).ready(function($) {
        // Language data for label format preview
        var gtLangs = {
            source: { code: '<?php echo esc_js(strtoupper($source_lang)); ?>', name: '<?php echo esc_js($source_name); ?>' },
            target: { code: '<?php echo esc_js(strtoupper($target_lang ?: 'XX')); ?>', name: '<?php echo esc_js($target_name); ?>' }
        };

        // Init color pickers with live update
        $('.gt-color-field').wpColorPicker({
            change: function(event, ui) {
                setTimeout(updatePreview, 50);
            },
            clear: function() {
                setTimeout(updatePreview, 50);
            }
        });

        // Live update on number/checkbox/select change
        $('.gt-range-field, .gt-check-field, .gt-select-field').on('input change', function() {
            updatePreview();
        });

        function getVal(name) {
            var $el = $('[data-var="' + name + '"]');
            if ($el.is(':checkbox')) return $el.is(':checked');
            if ($el.hasClass('gt-color-field')) return $el.wpColorPicker('color') || $el.val();
            return $el.val();
        }

        function formatLabel(lang, format) {
            if (format === 'code') return lang.code;
            if (format === 'both') return lang.code + ' - ' + lang.name;
            return lang.name;
        }

        function updatePreview() {
            var bg = getVal('bgColor');
            var text = getVal('textColor');
            var activeBg = getVal('activeBgColor');
            var activeText = getVal('activeTextColor');
            var hoverBg = getVal('hoverBgColor');
            var hoverText = getVal('hoverTextColor');
            var border = getVal('borderColor');
            var borderW = parseInt(getVal('borderWidth')) || 1;
            var radius = parseInt(getVal('borderRadius')) || 0;
            var size = parseInt(getVal('fontSize')) || 14;
            var padH = parseInt(getVal('paddingH')) || 0;
            var padV = parseInt(getVal('paddingV')) || 0;
            var gap = parseInt(getVal('gap')) || 0;
            var shadow = getVal('shadow');
            var fontFamily = getVal('fontFamily');

            var boxShadow = shadow ? '0 2px 8px rgba(0,0,0,0.12)' : 'none';
            var fontCSS = (fontFamily && fontFamily !== 'inherit') ? fontFamily : '';

            // Buttons container
            $('#gt-preview-buttons').css({
                gap: gap + 'px',
                borderRadius: radius + 'px',
                borderWidth: borderW + 'px',
                borderColor: border,
                boxShadow: boxShadow
            });

            // Inactive button
            $('#gt-prev-btn-source').css({
                padding: padV + 'px ' + padH + 'px',
                fontSize: size + 'px',
                background: bg,
                color: text,
                fontFamily: fontCSS
            });

            // Active button
            $('#gt-prev-btn-target').css({
                padding: padV + 'px ' + padH + 'px',
                fontSize: size + 'px',
                background: activeBg,
                color: activeText,
                fontFamily: fontCSS
            });

            // Dropdown
            $('#gt-prev-select').css({
                padding: padV + 'px ' + padH + 'px',
                fontSize: size + 'px',
                background: bg,
                color: text,
                borderWidth: borderW + 'px',
                borderColor: border,
                borderRadius: radius + 'px',
                boxShadow: boxShadow,
                fontFamily: fontCSS
            });

            // Update labels
            var fmt = getVal('labelFormat');
            var srcLabel = formatLabel(gtLangs.source, fmt);
            var tgtLabel = formatLabel(gtLangs.target, fmt);
            $('.gt-prev-label-source').text(srcLabel);
            $('.gt-prev-label-target').text(tgtLabel);

            // Update hover styles dynamically
            var styleTag = $('#gt-dynamic-hover');
            if (!styleTag.length) {
                styleTag = $('<style id="gt-dynamic-hover"></style>').appendTo('head');
            }
            styleTag.html(
                '.gt-preview-btn:not(.gt-preview-active):hover{background:' + hoverBg + '!important;color:' + hoverText + '!important;}'
            );
        }
    });
    </script>
    <?php
}

// ============================================
// FRONTEND FUNCTIONS
// ============================================

// Detect language prefix from the current URL
function gt_get_language_from_url() {
    $request_uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
    $target_lang = get_option('gt_target_language');

    if (!empty($target_lang) && preg_match('#^/' . preg_quote($target_lang, '#') . '(/|$)#', $request_uri)) {
        return $target_lang;
    }

    return '';
}

// Get current language from URL prefix, then cookie, then default
function gt_get_current_language() {
    $from_url = gt_get_language_from_url();
    if (!empty($from_url)) {
        return $from_url;
    }

    if (isset($_COOKIE['gt_language'])) {
        return sanitize_text_field($_COOKIE['gt_language']);
    }

    return gt_get_source_language();
}

// Check if we should show translations
function gt_should_translate() {
    $current = gt_get_current_language();
    $target = get_option('gt_target_language');
    return ($current === $target);
}

// Add rewrite rules for /{lang}/ prefix
function gt_add_rewrite_rules() {
    $target_lang = get_option('gt_target_language');
    if (empty($target_lang)) {
        return;
    }

    add_rewrite_rule(
        '^' . $target_lang . '/?$',
        'index.php?gt_lang=' . $target_lang,
        'top'
    );
    add_rewrite_rule(
        '^' . $target_lang . '/(.+?)/?$',
        'index.php?gt_lang=' . $target_lang . '&gt_original_path=$matches[1]',
        'top'
    );
}
add_action('init', 'gt_add_rewrite_rules');

// Register query vars
function gt_query_vars($vars) {
    $vars[] = 'gt_lang';
    $vars[] = 'gt_original_path';
    return $vars;
}
add_filter('query_vars', 'gt_query_vars');

// Resolve the original path when language prefix is present
function gt_parse_request($wp) {
    if (!empty($wp->query_vars['gt_lang'])) {
        $original_path = isset($wp->query_vars['gt_original_path']) ? $wp->query_vars['gt_original_path'] : '';

        if (empty($original_path)) {
            // Home page in translated language
            $wp->query_vars = ['gt_lang' => $wp->query_vars['gt_lang']];
            return;
        }

        // Re-parse the original path to find the actual post/page/product
        $url = home_url('/' . $original_path . '/');
        $post_id = url_to_postid($url);

        if ($post_id) {
            $post = get_post($post_id);
            if ($post) {
                unset($wp->query_vars['gt_original_path']);
                if ($post->post_type === 'product') {
                    $wp->query_vars['product'] = $post->post_name;
                    $wp->query_vars['post_type'] = 'product';
                    $wp->query_vars['name'] = $post->post_name;
                } elseif ($post->post_type === 'page') {
                    $wp->query_vars['pagename'] = $post->post_name;
                } else {
                    $wp->query_vars['name'] = $post->post_name;
                }
                return;
            }
        }

        // Try as a product category
        $term = get_term_by('slug', basename($original_path), 'product_cat');
        if ($term) {
            unset($wp->query_vars['gt_original_path']);
            $wp->query_vars['product_cat'] = $term->slug;
            return;
        }

        // Try as a regular category
        $term = get_term_by('slug', basename($original_path), 'category');
        if ($term) {
            unset($wp->query_vars['gt_original_path']);
            $wp->query_vars['category_name'] = $term->slug;
            return;
        }
    }
}
add_action('parse_request', 'gt_parse_request');

// Sync the language cookie with the URL prefix
function gt_sync_language_cookie() {
    if (is_admin()) {
        return;
    }

    $lang_from_url = gt_get_language_from_url();
    $source_lang = gt_get_source_language();

    if (!empty($lang_from_url)) {
        // Visiting /{lang}/ — set cookie to target language
        if (!isset($_COOKIE['gt_language']) || $_COOKIE['gt_language'] !== $lang_from_url) {
            setcookie('gt_language', $lang_from_url, [
                'expires'  => time() + YEAR_IN_SECONDS,
                'path'     => '/',
                'secure'   => is_ssl(),
                'httponly'  => false,
                'samesite' => 'Lax',
            ]);
            $_COOKIE['gt_language'] = $lang_from_url;
        }
    } else {
        // Visiting non-prefixed URL — reset cookie to source language
        if (isset($_COOKIE['gt_language']) && $_COOKIE['gt_language'] !== $source_lang) {
            setcookie('gt_language', $source_lang, [
                'expires'  => time() + YEAR_IN_SECONDS,
                'path'     => '/',
                'secure'   => is_ssl(),
                'httponly'  => false,
                'samesite' => 'Lax',
            ]);
            $_COOKIE['gt_language'] = $source_lang;
        }
    }
}
add_action('init', 'gt_sync_language_cookie', 1);

// Prefix permalinks with /{lang}/ when in translated mode
function gt_prefix_permalink($url, $post = null) {
    if (is_admin()) {
        return $url;
    }

    if (!gt_should_translate()) {
        return $url;
    }

    $target_lang = get_option('gt_target_language');
    $home_url = home_url('/');
    $prefix = home_url('/' . $target_lang . '/');

    // Avoid double-prefixing
    if (strpos($url, $prefix) === 0) {
        return $url;
    }

    // Only prefix URLs from this site
    if (strpos($url, $home_url) === 0) {
        $relative = substr($url, strlen($home_url));
        return $prefix . $relative;
    }

    return $url;
}
add_filter('post_link', 'gt_prefix_permalink', 10, 2);
add_filter('page_link', 'gt_prefix_permalink', 10, 2);
add_filter('post_type_link', 'gt_prefix_permalink', 10, 2);
add_filter('term_link', 'gt_prefix_permalink', 10, 1);
add_filter('woocommerce_product_get_permalink', 'gt_prefix_permalink', 10, 1);
// Rewrite all internal URLs in the final HTML output via output buffering
function gt_start_url_rewrite_buffer() {
    if (is_admin() || !gt_should_translate()) {
        return;
    }
    ob_start('gt_rewrite_html_urls');
}
add_action('template_redirect', 'gt_start_url_rewrite_buffer', 1);

function gt_rewrite_html_urls($html) {
    if (empty($html)) {
        return $html;
    }

    $target_lang = get_option('gt_target_language');
    if (empty($target_lang)) {
        return $html;
    }

    // Build home URL without the language prefix filter interfering
    remove_filter('home_url', 'gt_prefix_home_url', 10);
    $home_url = home_url('/');
    add_filter('home_url', 'gt_prefix_home_url', 10, 2);

    $prefix = $home_url . $target_lang . '/';
    $escaped_home = preg_quote($home_url, '#');

    // Split HTML on gt-no-rewrite markers, only rewrite the unprotected parts
    $parts = preg_split('#(<!-- gt-no-rewrite -->.*?<!-- /gt-no-rewrite -->)#s', $html, -1, PREG_SPLIT_DELIM_CAPTURE);

    foreach ($parts as &$part) {
        // Skip protected sections (the captured delimiters)
        if (strpos($part, '<!-- gt-no-rewrite -->') === 0) {
            continue;
        }

        // Rewrite href="..." pointing to internal URLs
        $part = preg_replace_callback(
            '#(href=["\'])(' . $escaped_home . ')([^"\']*["\'])#i',
            function ($m) use ($home_url, $prefix, $target_lang) {
                $path = $m[3];
                // Skip if already prefixed
                if (strpos($m[2] . $path, $prefix) === 0) {
                    return $m[0];
                }
                // Skip admin, wp-content, wp-includes, wp-json URLs
                if (preg_match('#^(wp-admin|wp-content|wp-includes|wp-json|wp-login)#', $path)) {
                    return $m[0];
                }
                return $m[1] . $prefix . $path;
            },
            $part
        );
    }
    unset($part);

    return implode('', $parts);
}

// Prefix the home URL on frontend when translated
function gt_prefix_home_url($url, $path) {
    static $filtering = false;

    if ($filtering || is_admin() || !gt_should_translate()) {
        return $url;
    }

    $target_lang = get_option('gt_target_language');

    // Avoid double-prefixing and only affect root/empty paths
    if (!empty($path) && $path !== '/') {
        return $url;
    }

    // Prevent recursion
    $filtering = true;
    $result = rtrim($url, '/') . '/' . $target_lang . '/';
    $filtering = false;

    return $result;
}
add_filter('home_url', 'gt_prefix_home_url', 10, 2);

// Flush rewrite rules when target language setting is updated
function gt_flush_on_language_change($old_value, $new_value) {
    if ($old_value !== $new_value) {
        flush_rewrite_rules();
    }
}
add_action('update_option_gt_target_language', 'gt_flush_on_language_change', 10, 2);

// Also flush on activation
function gt_flush_rewrite_rules() {
    gt_add_rewrite_rules();
    flush_rewrite_rules();
}
register_activation_hook(__FILE__, 'gt_flush_rewrite_rules');
register_deactivation_hook(__FILE__, 'flush_rewrite_rules');

// Load all translations into a static cache (called once per request)
function gt_load_translation_cache() {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'gt_translations';
    $target_lang = get_option('gt_target_language');

    $results = $wpdb->get_results($wpdb->prepare(
        "SELECT string_hash, translated_string FROM $table_name
        WHERE language_code = %s AND status IN ('translated', 'edited') AND translated_string IS NOT NULL",
        $target_lang
    ));

    $cache = [];
    foreach ($results as $row) {
        $cache[$row->string_hash] = $row->translated_string;
    }

    return $cache;
}

// Get translation for a string
function gt_get_translation($original_string) {
    if (!gt_should_translate()) {
        return $original_string;
    }

    $cache = gt_load_translation_cache();
    $string_hash = hash('sha256', $original_string);

    return isset($cache[$string_hash]) ? $cache[$string_hash] : $original_string;
}

// Output frontend switcher CSS
function gt_frontend_switcher_css() {
    $s = gt_get_switcher_style();
    $shadow = $s['shadow'] === '1' ? '0 2px 8px rgba(0,0,0,0.12)' : 'none';
    $radius = intval($s['border_radius']);
    $padV = intval($s['padding_v']);
    $padH = intval($s['padding_h']);
    $fontSize = intval($s['font_size']);
    $gap = intval($s['gap']);
    $borderW = intval($s['border_width']);
    $fontFamily = $s['font_family'] ?? 'inherit';
    ?>
    <style id="gt-switcher-css">
    .gt-language-switcher.gt-buttons {
        display: inline-flex;
        gap: <?php echo $gap; ?>px;
        border: <?php echo $borderW; ?>px solid <?php echo esc_attr($s['border_color']); ?>;
        border-radius: <?php echo $radius; ?>px;
        overflow: hidden;
        box-shadow: <?php echo $shadow; ?>;
    }
    .gt-language-switcher.gt-buttons .gt-lang-btn {
        display: inline-block;
        padding: <?php echo $padV; ?>px <?php echo $padH; ?>px;
        font-size: <?php echo $fontSize; ?>px;
        line-height: 1.4;
        <?php if ($fontFamily !== 'inherit'): ?>font-family: <?php echo $fontFamily; ?>;<?php endif; ?>
        background: <?php echo esc_attr($s['bg_color']); ?>;
        color: <?php echo esc_attr($s['text_color']); ?>;
        text-decoration: none;
        cursor: pointer;
        transition: background 0.15s, color 0.15s;
    }
    .gt-language-switcher.gt-buttons .gt-lang-btn:hover {
        background: <?php echo esc_attr($s['hover_bg_color']); ?>;
        color: <?php echo esc_attr($s['hover_text_color']); ?>;
    }
    .gt-language-switcher.gt-buttons .gt-lang-btn.active {
        background: <?php echo esc_attr($s['active_bg_color']); ?>;
        color: <?php echo esc_attr($s['active_text_color']); ?>;
        cursor: not-allowed;
        pointer-events: none;
        opacity: 0.85;
    }
    .gt-language-switcher.gt-dropdown select option:disabled {
        color: #999;
    }
    .gt-language-switcher.gt-dropdown select {
        padding: <?php echo $padV; ?>px <?php echo $padH; ?>px;
        font-size: <?php echo $fontSize; ?>px;
        <?php if ($fontFamily !== 'inherit'): ?>font-family: <?php echo $fontFamily; ?>;<?php endif; ?>
        background: <?php echo esc_attr($s['bg_color']); ?>;
        color: <?php echo esc_attr($s['text_color']); ?>;
        border: <?php echo $borderW; ?>px solid <?php echo esc_attr($s['border_color']); ?>;
        border-radius: <?php echo $radius; ?>px;
        box-shadow: <?php echo $shadow; ?>;
        cursor: pointer;
        min-width: 140px;
        -webkit-appearance: none;
        appearance: none;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='<?php echo urlencode($s['text_color']); ?>' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
        background-repeat: no-repeat;
        background-position: right <?php echo $padH; ?>px center;
        padding-right: <?php echo $padH + 16; ?>px;
    }
    .gt-language-switcher.gt-dropdown select:hover {
        border-color: <?php echo esc_attr($s['hover_bg_color']); ?>;
    }
    <?php if ($s['position'] !== 'none'):
        $pos = explode('-', $s['position']);
        $vertical = $pos[0]; // top or bottom
        $horizontal = $pos[1]; // left or right
    ?>
    .gt-switcher-fixed {
        position: fixed;
        <?php echo $vertical; ?>: 20px;
        <?php echo $horizontal; ?>: 20px;
        z-index: 99999;
    }
    <?php endif; ?>
    </style>
    <?php
}
add_action('wp_head', 'gt_frontend_switcher_css');

// Language switcher shortcode [gt_language_switcher]
function gt_language_switcher_shortcode($atts) {
    $atts = shortcode_atts([
        'style' => 'dropdown', // dropdown, buttons
    ], $atts);

    $current_lang = gt_get_current_language();
    $source_lang = gt_get_source_language();
    $target_lang = get_option('gt_target_language');
    $languages = gt_get_available_languages();
    $s = gt_get_switcher_style();

    if (empty($target_lang)) {
        return '<!-- GT: No target language configured -->';
    }

    ob_start();

    $current_path = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
    // Strip existing language prefix if present
    if (strpos($current_path, $target_lang . '/') === 0 || $current_path === $target_lang) {
        $current_path = substr($current_path, strlen($target_lang) + 1);
    }
    // Temporarily remove the home_url filter to prevent the language slug
    // from being re-appended when generating the source (clean) URL.
    remove_filter('home_url', 'gt_prefix_home_url', 10);
    $source_url = home_url('/' . $current_path);
    add_filter('home_url', 'gt_prefix_home_url', 10, 2);

    $target_url = home_url('/' . $target_lang . '/' . $current_path);

    $fixed_class = ($s['position'] !== 'none') ? ' gt-switcher-fixed' : '';
    $fmt = $s['label_format'];
    $source_label = gt_format_lang_label($source_lang, $languages[$source_lang], $fmt);
    $target_label = gt_format_lang_label($target_lang, $languages[$target_lang], $fmt);

    $source_active = ($current_lang === $source_lang);
    $target_active = ($current_lang === $target_lang);

    if ($atts['style'] === 'buttons'): ?>
        <!-- gt-no-rewrite --><div class="gt-language-switcher gt-buttons<?php echo $fixed_class; ?>">
            <?php if ($source_active): ?>
                <span class="gt-lang-btn active" aria-disabled="true"><?php echo esc_html($source_label); ?></span>
            <?php else: ?>
                <a href="<?php echo esc_url($source_url); ?>" class="gt-lang-btn"><?php echo esc_html($source_label); ?></a>
            <?php endif; ?>
            <?php if ($target_active): ?>
                <span class="gt-lang-btn active" aria-disabled="true"><?php echo esc_html($target_label); ?></span>
            <?php else: ?>
                <a href="<?php echo esc_url($target_url); ?>" class="gt-lang-btn"><?php echo esc_html($target_label); ?></a>
            <?php endif; ?>
        </div><!-- /gt-no-rewrite -->
    <?php else: ?>
        <!-- gt-no-rewrite --><div class="gt-language-switcher gt-dropdown<?php echo $fixed_class; ?>">
            <select onchange="if(this.options[this.selectedIndex].disabled)return;window.location.href=this.value">
                <option value="<?php echo esc_url($source_url); ?>" <?php selected($source_active); ?> <?php disabled($source_active); ?>>
                    <?php echo esc_html($source_label); ?>
                </option>
                <option value="<?php echo esc_url($target_url); ?>" <?php selected($target_active); ?> <?php disabled($target_active); ?>>
                    <?php echo esc_html($target_label); ?>
                </option>
            </select>
        </div><!-- /gt-no-rewrite -->
    <?php endif; ?>
    <?php

    return ob_get_clean();
}
add_shortcode('gt_language_switcher', 'gt_language_switcher_shortcode');

// Filter WooCommerce product title — only when translating, skip admin
function gt_translate_product_title($title, $id) {
    if (is_admin() || !gt_should_translate()) {
        return $title;
    }
    return gt_get_translation($title);
}
add_filter('the_title', 'gt_translate_product_title', 10, 2);

// Filter WooCommerce product short description
function gt_translate_product_excerpt($excerpt) {
    if (is_admin() || !gt_should_translate()) {
        return $excerpt;
    }
    if (is_product() || is_shop() || is_product_category()) {
        return gt_get_translation($excerpt);
    }
    return $excerpt;
}
add_filter('the_excerpt', 'gt_translate_product_excerpt', 10, 1);
add_filter('woocommerce_short_description', 'gt_translate_product_excerpt', 10, 1);

// Get cached Elementor translations
function gt_get_elementor_translations() {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'gt_translations';
    $target_lang = get_option('gt_target_language');

    $cached = $wpdb->get_results($wpdb->prepare(
        "SELECT original_string, translated_string FROM $table_name
        WHERE source_type = 'elementor' AND status IN ('translated', 'edited') AND language_code = %s",
        $target_lang
    ));

    return $cached;
}

// Apply Elementor translations to content string using strtr for single-pass replacement
function gt_apply_elementor_translations($content) {
    static $replacement_map = null;

    if ($replacement_map === null) {
        $replacement_map = [];
        $translations = gt_get_elementor_translations();
        foreach ($translations as $t) {
            if (!empty($t->translated_string) && !empty($t->original_string)) {
                $replacement_map[$t->original_string] = $t->translated_string;
            }
        }
    }

    if (empty($replacement_map)) {
        return $content;
    }

    return strtr($content, $replacement_map);
}

// Filter Elementor widget content
function gt_translate_elementor_widget($content, $widget) {
    if (!gt_should_translate()) {
        return $content;
    }
    return gt_apply_elementor_translations($content);
}
add_filter('elementor/widget/render_content', 'gt_translate_elementor_widget', 10, 2);

// Single the_content filter for all translation (products + Elementor pages)
function gt_translate_page_content($content) {
    if (is_admin() || !gt_should_translate()) {
        return $content;
    }

    // Product pages: translate entire content as a single string
    if (is_product() || is_shop() || is_product_category()) {
        return gt_get_translation($content);
    }

    // Elementor pages: apply string replacements
    if (defined('ELEMENTOR_VERSION') && \Elementor\Plugin::$instance->documents->get(get_the_ID())) {
        $content = gt_apply_elementor_translations($content);
    }

    return $content;
}
add_filter('the_content', 'gt_translate_page_content', 999, 1);