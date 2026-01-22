<?php
/**
 * Plugin Name: Gemini Translator
 * Plugin URI: https://github.com/tu-usuario/gemini-translator
 * Description: Translate your WooCommerce store using Google Gemini AI
 * Version: 0.1.0
 * Author: Tu Nombre
 * Author URI: https://tu-sitio.com
 * License: GPL v2 or later
 * Text Domain: gemini-translator
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('GT_VERSION', '0.1.0');
define('GT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('GT_PLUGIN_URL', plugin_dir_url(__FILE__));

// Create database table on activation
function gt_activate() {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'gt_translations';
    $charset_collate = $wpdb->get_charset_collate();
    
    $sql = "CREATE TABLE $table_name (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        original_string text NOT NULL,
        string_hash varchar(32) NOT NULL,
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
        KEY status (status)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
    
    add_option('gt_db_version', GT_VERSION);
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
}
add_action('admin_menu', 'gt_admin_menu');

// Register settings
function gt_register_settings() {
    register_setting('gt_settings', 'gt_api_key');
    register_setting('gt_settings', 'gt_target_language');
}
add_action('admin_init', 'gt_register_settings');

// Scan WooCommerce products
function gt_scan_products() {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'gt_translations';
    $language = get_option('gt_target_language');
    $scanned = 0;
    
    $products = get_posts([
        'post_type' => 'product',
        'post_status' => 'publish',
        'numberposts' => -1,
    ]);
    
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
        $elementor_data = json_decode($post->meta_value, true);
        
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

// Extract strings from Elementor data recursively
function gt_extract_elementor_strings($elements, $post_id, $strings = []) {
    foreach ($elements as $element) {
        // Get widget type
        $widget_type = $element['widgetType'] ?? $element['elType'] ?? 'unknown';
        
        // Extract settings
        if (isset($element['settings'])) {
            $settings = $element['settings'];
            
            // Heading widget
            if (isset($settings['title'])) {
                $strings[] = [
                    'text' => $settings['title'],
                    'context' => 'elementor_heading',
                ];
            }
            
            // Text editor widget
            if (isset($settings['editor'])) {
                $strings[] = [
                    'text' => $settings['editor'],
                    'context' => 'elementor_text',
                ];
            }
            
            // Button widget
            if (isset($settings['text'])) {
                $strings[] = [
                    'text' => $settings['text'],
                    'context' => 'elementor_button',
                ];
            }
            
            // Image box widget
            if (isset($settings['title_text'])) {
                $strings[] = [
                    'text' => $settings['title_text'],
                    'context' => 'elementor_image_box_title',
                ];
            }
            if (isset($settings['description_text'])) {
                $strings[] = [
                    'text' => $settings['description_text'],
                    'context' => 'elementor_image_box_desc',
                ];
            }
            
            // Icon box widget
            if (isset($settings['title_text'])) {
                $strings[] = [
                    'text' => $settings['title_text'],
                    'context' => 'elementor_icon_box_title',
                ];
            }
            
            // Call to action
            if (isset($settings['title'])) {
                $strings[] = [
                    'text' => $settings['title'],
                    'context' => 'elementor_cta_title',
                ];
            }
            if (isset($settings['description'])) {
                $strings[] = [
                    'text' => $settings['description'],
                    'context' => 'elementor_cta_desc',
                ];
            }
            
            // Testimonial
            if (isset($settings['testimonial_content'])) {
                $strings[] = [
                    'text' => $settings['testimonial_content'],
                    'context' => 'elementor_testimonial',
                ];
            }
            if (isset($settings['testimonial_name'])) {
                $strings[] = [
                    'text' => $settings['testimonial_name'],
                    'context' => 'elementor_testimonial_name',
                ];
            }
            
            // Accordion / Toggle
            if (isset($settings['tabs']) && is_array($settings['tabs'])) {
                foreach ($settings['tabs'] as $tab) {
                    if (isset($tab['tab_title'])) {
                        $strings[] = [
                            'text' => $tab['tab_title'],
                            'context' => 'elementor_accordion_title',
                        ];
                    }
                    if (isset($tab['tab_content'])) {
                        $strings[] = [
                            'text' => $tab['tab_content'],
                            'context' => 'elementor_accordion_content',
                        ];
                    }
                }
            }
            
            // Price list
            if (isset($settings['price_list']) && is_array($settings['price_list'])) {
                foreach ($settings['price_list'] as $item) {
                    if (isset($item['title'])) {
                        $strings[] = [
                            'text' => $item['title'],
                            'context' => 'elementor_price_title',
                        ];
                    }
                    if (isset($item['item_description'])) {
                        $strings[] = [
                            'text' => $item['item_description'],
                            'context' => 'elementor_price_desc',
                        ];
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

// Insert string if not exists
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
    $string_hash = md5($string);
    
    // Check if already exists (any source, same string)
    $exists = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM $table_name WHERE string_hash = %s AND language_code = %s",
        $string_hash,
        $language
    ));
    
    if (!$exists) {
        $wpdb->insert($table_name, [
            'original_string' => $string,
            'string_hash' => $string_hash,
            'language_code' => $language,
            'context' => $context,
            'source_type' => $source_type,
            'source_id' => $source_id,
            'status' => 'pending',
        ]);
        return true;
    }
    
    return false;
}

// Clear all Elementor strings (for re-scanning)
function gt_clear_elementor_strings() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'gt_translations';
    
    return $wpdb->delete($table_name, ['source_type' => 'elementor']);
}

// Test API connection
function gt_test_api_connection() {
    $api_key = get_option('gt_api_key');
    
    if (empty($api_key)) {
        return ['success' => false, 'message' => 'API key is empty'];
    }
    
    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=$api_key";
    
    $response = wp_remote_post($url, [
        'headers' => ['Content-Type' => 'application/json'],
        'body' => json_encode([
            'contents' => [
                ['parts' => [['text' => 'Say OK']]]
            ]
        ]),
        'timeout' => 60,
        'sslverify' => false,
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
        'es' => 'Spanish',
        'pt' => 'Portuguese',
        'fr' => 'French',
        'de' => 'German',
        'it' => 'Italian',
        'en' => 'English',
    ];
    
    $lang_name = $language_names[$target_language] ?? $target_language;
    
    $prompt = "Translate the following text to $lang_name. Return ONLY the translation, nothing else. Keep any HTML tags intact.\n\nText: $text";
    
    $response = wp_remote_post(
        "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=$api_key",
        [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode([
                'contents' => [
                    ['parts' => [['text' => $prompt]]]
                ]
            ]),
            'timeout' => 60,
            'sslverify' => false,
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

// Translate ALL pending strings
function gt_translate_all() {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'gt_translations';
    $language = get_option('gt_target_language');
    
    $total_pending = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $table_name WHERE status = 'pending' AND language_code = %s",
        $language
    ));
    
    $translated = 0;
    $errors = [];
    $batch_size = 10;
    
    while ($translated < $total_pending && count($errors) < 5) {
        $result = gt_translate_batch($batch_size);
        $translated += $result['translated'];
        $errors = array_merge($errors, $result['errors']);
        
        if ($result['translated'] === 0 && !empty($result['errors'])) {
            break;
        }
    }
    
    return ['translated' => $translated, 'errors' => $errors, 'total' => $total_pending];
}

// Handle actions
function gt_handle_actions() {
    if (isset($_POST['gt_scan']) && check_admin_referer('gt_scan_action')) {
        $count = gt_scan_products();
        add_settings_error('gt_messages', 'gt_scan_success', "Scanned $count strings from products.", 'success');
    }
    
    if (isset($_POST['gt_test_api']) && check_admin_referer('gt_test_api_action')) {
        $result = gt_test_api_connection();
        $type = $result['success'] ? 'success' : 'error';
        add_settings_error('gt_messages', 'gt_test_result', $result['message'], $type);
    }
    
    if (isset($_POST['gt_translate']) && check_admin_referer('gt_translate_action')) {
        $result = gt_translate_batch(20);
        if ($result['translated'] > 0) {
            add_settings_error('gt_messages', 'gt_translate_success', "Translated {$result['translated']} strings.", 'success');
        }
        if (!empty($result['errors'])) {
            $unique_errors = array_unique($result['errors']);
            add_settings_error('gt_messages', 'gt_translate_errors', 'Errors: ' . implode(', ', $unique_errors), 'error');
        }
    }
    
    if (isset($_POST['gt_translate_all']) && check_admin_referer('gt_translate_all_action')) {
        $result = gt_translate_all();
        if ($result['translated'] > 0) {
            add_settings_error('gt_messages', 'gt_translate_success', "Translated {$result['translated']} of {$result['total']} strings.", 'success');
        }
        if (!empty($result['errors'])) {
            $unique_errors = array_unique($result['errors']);
            add_settings_error('gt_messages', 'gt_translate_errors', 'Errors: ' . implode(', ', $unique_errors), 'error');
        }
        if ($result['translated'] === 0 && empty($result['errors'])) {
            add_settings_error('gt_messages', 'gt_translate_none', 'No strings to translate.', 'warning');
        }
    }
    
    // Handle inline edit save
    if (isset($_POST['gt_save_translation']) && check_admin_referer('gt_save_translation_action')) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'gt_translations';
        
        $id = intval($_POST['translation_id']);
        $new_translation = sanitize_textarea_field($_POST['translated_string']);
        
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
        add_settings_error('gt_messages', 'gt_scan_elementor_success', "Scanned $count strings from Elementor.", 'success');
    }

    if (isset($_POST['gt_clear_elementor']) && check_admin_referer('gt_clear_elementor_action')) {
    $deleted = gt_clear_elementor_strings();
    add_settings_error('gt_messages', 'gt_clear_success', "Cleared Elementor strings. Ready to re-scan.", 'success');
}
}
add_action('admin_init', 'gt_handle_actions');

// Main admin page (Dashboard)
function gt_admin_page() {
    global $wpdb;
    $api_key = get_option('gt_api_key');
    $language = get_option('gt_target_language');
    $table_name = $wpdb->prefix . 'gt_translations';
    
    // General stats
    $total_strings = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
    $total_pending = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE status = 'pending'");
    $total_translated = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE status = 'translated'");
    $total_edited = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE status = 'edited'");
    
    // Stats by source
    $sources = [
        'product' => ['label' => 'WooCommerce', 'icon' => '🛒'],
        'elementor' => ['label' => 'Elementor', 'icon' => '📄'],
        'wcfm' => ['label' => 'WCFM', 'icon' => '🏪'],
    ];
    
    $stats_by_source = [];
    foreach ($sources as $source_type => $source_info) {
        $stats_by_source[$source_type] = [
            'label' => $source_info['label'],
            'icon' => $source_info['icon'],
            'total' => $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table_name WHERE source_type = %s", 
                $source_type
            )),
            'pending' => $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table_name WHERE source_type = %s AND status = 'pending'", 
                $source_type
            )),
            'translated' => $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table_name WHERE source_type = %s AND status = 'translated'", 
                $source_type
            )),
            'edited' => $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table_name WHERE source_type = %s AND status = 'edited'", 
                $source_type
            )),
        ];
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
                <p>✅ Ready to translate to <strong><?php echo strtoupper($language); ?></strong></p>
            </div>
            
            <!-- General Stats -->
            <h2>Overview</h2>
            <div style="display: flex; gap: 20px; margin: 20px 0; flex-wrap: wrap;">
                <div class="card" style="padding: 15px; min-width: 120px;">
                    <h3 style="margin: 0; font-size: 32px;"><?php echo $total_strings; ?></h3>
                    <p style="margin: 5px 0 0;">Total strings</p>
                </div>
                <div class="card" style="padding: 15px; min-width: 120px;">
                    <h3 style="margin: 0; font-size: 32px; color: #f0ad4e;"><?php echo $total_pending; ?></h3>
                    <p style="margin: 5px 0 0;">Pending</p>
                </div>
                <div class="card" style="padding: 15px; min-width: 120px;">
                    <h3 style="margin: 0; font-size: 32px; color: #5cb85c;"><?php echo $total_translated; ?></h3>
                    <p style="margin: 5px 0 0;">Translated</p>
                </div>
                <div class="card" style="padding: 15px; min-width: 120px;">
                    <h3 style="margin: 0; font-size: 32px; color: #0073aa;"><?php echo $total_edited; ?></h3>
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
                            <td><?php echo $stats['icon']; ?> <?php echo $stats['label']; ?></td>
                            <td style="text-align: center;"><?php echo $stats['total']; ?></td>
                            <td style="text-align: center;">
                                <span style="color: #f0ad4e; font-weight: bold;"><?php echo $stats['pending']; ?></span>
                            </td>
                            <td style="text-align: center;">
                                <span style="color: #5cb85c; font-weight: bold;"><?php echo $stats['translated']; ?></span>
                            </td>
                            <td style="text-align: center;">
                                <span style="color: #0073aa; font-weight: bold;"><?php echo $stats['edited']; ?></span>
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
                        <?php wp_nonce_field('gt_clear_elementor_action'); ?>
                        <button type="submit" name="gt_clear_elementor" class="button button-link-delete" 
                            onclick="return confirm('This will delete all Elementor strings. Continue?');">
                            Clear Elementor Strings
                        </button>
                    </form>
                </div>
                
                <div class="card" style="padding: 20px; min-width: 280px;">
                    <h3 style="margin-top: 0;">2. Translate</h3>
                    <p>Translate pending strings using Gemini AI.</p>
                    <form method="post" style="display: flex; gap: 10px; flex-wrap: wrap;">
                        <?php wp_nonce_field('gt_translate_action'); ?>
                        <button type="submit" name="gt_translate" class="button button-secondary" <?php echo $total_pending == 0 ? 'disabled' : ''; ?>>
                            Translate Batch (20)
                        </button>
                    </form>
                    <form method="post" style="margin-top: 10px;">
                        <?php wp_nonce_field('gt_translate_all_action'); ?>
                        <button type="submit" name="gt_translate_all" class="button button-primary" <?php echo $total_pending == 0 ? 'disabled' : ''; ?>
                            onclick="return confirm('This will translate all <?php echo $total_pending; ?> pending strings. This may take a while. Continue?');">
                            Translate All (<?php echo $total_pending; ?> strings)
                        </button>
                    </form>
                    <?php if ($total_pending > 0): ?>
                        <p class="description" style="margin-top: 10px;">
                            ⏱️ Estimated time: ~<?php echo ceil($total_pending * 0.5 / 60); ?> minutes
                        </p>
                    <?php endif; ?>
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

// Translations list page
function gt_translations_page() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'gt_translations';
    
    // Pagination
    $per_page = 20;
    $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $offset = ($current_page - 1) * $per_page;
    
    // Filters
    $status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
    $context_filter = isset($_GET['context']) ? sanitize_text_field($_GET['context']) : '';
    $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
    
    // Build query
    $where = "WHERE 1=1";
    if ($status_filter) {
        $where .= $wpdb->prepare(" AND status = %s", $status_filter);
    }
    if ($context_filter) {
        $where .= $wpdb->prepare(" AND context = %s", $context_filter);
    }
    if ($search) {
        $where .= $wpdb->prepare(" AND (original_string LIKE %s OR translated_string LIKE %s)", '%' . $wpdb->esc_like($search) . '%', '%' . $wpdb->esc_like($search) . '%');
    }
    
    // Get total count
    $total_items = $wpdb->get_var("SELECT COUNT(*) FROM $table_name $where");
    $total_pages = ceil($total_items / $per_page);
    
    // Get items
    $items = $wpdb->get_results("SELECT * FROM $table_name $where ORDER BY id DESC LIMIT $offset, $per_page");
    
    // Get available contexts for filter
    $contexts = $wpdb->get_col("SELECT DISTINCT context FROM $table_name ORDER BY context");
    
    settings_errors('gt_messages');
    ?>
    <div class="wrap">
        <h1>Translations</h1>
        
        <!-- Filters -->
        <div class="tablenav top">
            <form method="get" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                <input type="hidden" name="page" value="gemini-translator-list" />
                
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
                
                <?php if ($status_filter || $context_filter || $search): ?>
                    <a href="<?php echo admin_url('admin.php?page=gemini-translator-list'); ?>" class="button">Clear</a>
                <?php endif; ?>
            </form>
            
            <div class="tablenav-pages" style="margin-top: 10px;">
                <span class="displaying-num"><?php echo $total_items; ?> items</span>
            </div>
        </div>
        
        <!-- Table -->
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width: 50px;">ID</th>
                    <th style="width: 120px;">Context</th>
                    <th>Original</th>
                    <th>Translation</th>
                    <th style="width: 100px;">Status</th>
                    <th style="width: 100px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($items)): ?>
                    <tr>
                        <td colspan="6">No translations found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($items as $item): ?>
                        <tr id="row-<?php echo $item->id; ?>">
                            <td><?php echo $item->id; ?></td>
                            <td>
                                <span class="context-badge" style="background: #f0f0f0; padding: 2px 8px; border-radius: 3px; font-size: 12px;">
                                    <?php echo esc_html($item->context); ?>
                                </span>
                            </td>
                            <td>
                                <div style="max-height: 100px; overflow-y: auto;">
                                    <?php echo esc_html(wp_trim_words($item->original_string, 30)); ?>
                                </div>
                            </td>
                            <td>
                                <div class="translation-display" id="display-<?php echo $item->id; ?>" style="max-height: 100px; overflow-y: auto;">
                                    <?php echo esc_html(wp_trim_words($item->translated_string, 30)); ?>
                                </div>
                                <form method="post" class="translation-form" id="form-<?php echo $item->id; ?>" style="display: none;">
                                    <?php wp_nonce_field('gt_save_translation_action'); ?>
                                    <input type="hidden" name="translation_id" value="<?php echo $item->id; ?>" />
                                    <textarea name="translated_string" rows="3" style="width: 100%;"><?php echo esc_textarea($item->translated_string); ?></textarea>
                                    <div style="margin-top: 5px;">
                                        <button type="submit" name="gt_save_translation" class="button button-primary button-small">Save</button>
                                        <button type="button" class="button button-small cancel-edit" data-id="<?php echo $item->id; ?>">Cancel</button>
                                    </div>
                                </form>
                            </td>
                            <td>
                                <?php
                                $status_colors = [
                                    'pending' => '#f0ad4e',
                                    'translated' => '#5cb85c',
                                    'edited' => '#0073aa',
                                ];
                                $color = $status_colors[$item->status] ?? '#999';
                                ?>
                                <span style="background: <?php echo $color; ?>; color: white; padding: 2px 8px; border-radius: 3px; font-size: 12px;">
                                    <?php echo esc_html($item->status); ?>
                                </span>
                            </td>
                            <td>
                                <button type="button" class="button button-small edit-translation" data-id="<?php echo $item->id; ?>">
                                    Edit
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        
        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
            <div class="tablenav bottom">
                <div class="tablenav-pages">
                    <?php
                    $pagination_args = [
                        'base' => add_query_arg('paged', '%#%'),
                        'format' => '',
                        'total' => $total_pages,
                        'current' => $current_page,
                        'prev_text' => '&laquo;',
                        'next_text' => '&raquo;',
                    ];
                    echo paginate_links($pagination_args);
                    ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
    
    <script>
    jQuery(document).ready(function($) {
        // Edit button click
        $('.edit-translation').on('click', function() {
            var id = $(this).data('id');
            $('#display-' + id).hide();
            $('#form-' + id).show();
            $(this).hide();
        });
        
        // Cancel button click
        $('.cancel-edit').on('click', function() {
            var id = $(this).data('id');
            $('#form-' + id).hide();
            $('#display-' + id).show();
            $('button[data-id="' + id + '"].edit-translation').show();
        });
    });
    </script>
    <?php
}

// Settings page
function gt_settings_page() {
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
                        <label for="gt_target_language">Target Language</label>
                    </th>
                    <td>
                        <select id="gt_target_language" name="gt_target_language">
                            <option value="">Select language...</option>
                            <option value="es" <?php selected(get_option('gt_target_language'), 'es'); ?>>Spanish</option>
                            <option value="pt" <?php selected(get_option('gt_target_language'), 'pt'); ?>>Portuguese</option>
                            <option value="fr" <?php selected(get_option('gt_target_language'), 'fr'); ?>>French</option>
                            <option value="de" <?php selected(get_option('gt_target_language'), 'de'); ?>>German</option>
                            <option value="it" <?php selected(get_option('gt_target_language'), 'it'); ?>>Italian</option>
                            <option value="en" <?php selected(get_option('gt_target_language'), 'en'); ?>>English</option>
                        </select>
                        <p class="description">Language to translate your content into</p>
                    </td>
                </tr>
            </table>
            
            <?php submit_button('Save Settings'); ?>
        </form>
    </div>
    <?php
}