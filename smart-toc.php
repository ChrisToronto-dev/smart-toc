<?php
/**
 * Plugin Name: Smart TOC Generator
 * Plugin URI: https://yourwebsite.com/smart-toc
 * Description: Automatically generates table of contents from H1-H6 tags with scroll highlighting
 * Version: 1.0.0
 * Author: Your Name
 * License: GPL v2 or later
 * Text Domain: smart-toc
 * Domain Path: /languages
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('SMART_TOC_VERSION', '1.0.0');
define('SMART_TOC_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SMART_TOC_PLUGIN_PATH', plugin_dir_path(__FILE__));

class SmartTOC {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->init();
    }
    
    private function init() {
        // Hook into WordPress
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_filter('the_content', array($this, 'add_toc_to_content'));
        add_action('wp_head', array($this, 'add_inline_styles'));
        
        // Admin hooks
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        
        // Activation/Deactivation hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }
    
    /**
     * Enqueue scripts and styles
     */
    public function enqueue_scripts() {
        wp_enqueue_script(
            'smart-toc-js',
            SMART_TOC_PLUGIN_URL . 'assets/js/smart-toc.js',
            array('jquery'),
            SMART_TOC_VERSION,
            true
        );
        
        wp_enqueue_style(
            'smart-toc-css',
            SMART_TOC_PLUGIN_URL . 'assets/css/smart-toc.css',
            array(),
            SMART_TOC_VERSION
        );
        
        // Pass data to JavaScript
        wp_localize_script('smart-toc-js', 'smartTOC', array(
            'offset' => get_option('smart_toc_scroll_offset', 100),
            'smoothScroll' => get_option('smart_toc_smooth_scroll', 1)
        ));
    }
    
    /**
     * Add TOC to post content
     */
    public function add_toc_to_content($content) {
        // Only add TOC on single posts/pages
        if (!is_single() && !is_page()) {
            return $content;
        }
        
        // Check if TOC is enabled for this post type
        $post_type = get_post_type();
        $enabled_post_types = get_option('smart_toc_post_types', array('post', 'page'));
        
        if (!in_array($post_type, $enabled_post_types)) {
            return $content;
        }
        
        // Extract headings from content
        $headings = $this->extract_headings($content);
        
        if (count($headings) < get_option('smart_toc_min_headings', 3)) {
            return $content;
        }
        
        // Generate TOC HTML
        $toc_html = $this->generate_toc_html($headings);
        
        // Insert TOC into content
        $insert_position = get_option('smart_toc_position', 'before_first_heading');
        
        switch ($insert_position) {
            case 'top':
                return $toc_html . $content;
            case 'before_first_heading':
                return $this->insert_before_first_heading($content, $toc_html);
            default:
                return $content;
        }
    }
    
    /**
     * Extract headings from content
     */
    private function extract_headings($content) {
        $headings = array();
        
        // Pattern to match H1-H6 tags
        $pattern = '/<(h[1-6])[^>]*>(.*?)<\/h[1-6]>/i';
        
        if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $level = intval(substr($match[1], 1)); // Extract number from h1-h6
                $text = strip_tags($match[2]);
                $id = $this->generate_heading_id($text);
                
                $headings[] = array(
                    'level' => $level,
                    'text' => $text,
                    'id' => $id,
                    'original' => $match[0]
                );
            }
        }
        
        return $headings;
    }
    
    /**
     * Generate unique ID for heading
     */
    private function generate_heading_id($text) {
        // Clean and sanitize text
        $id = sanitize_title($text);
        
        // Handle Korean text better
        if (empty($id) || strlen($id) < 2) {
            // Fallback for Korean or special characters
            $id = 'heading-' . md5($text);
            $id = substr($id, 0, 10);
        }
        
        // Ensure uniqueness
        static $used_ids = array();
        $original_id = $id;
        $counter = 1;
        
        while (in_array($id, $used_ids)) {
            $id = $original_id . '-' . $counter;
            $counter++;
        }
        
        $used_ids[] = $id;
        return $id;
    }
    
    /**
     * Generate TOC HTML
     */
    private function generate_toc_html($headings) {
        if (empty($headings)) {
            return '';
        }
        
        $html = '<div class="smart-toc-container">';
        $html .= '<div class="smart-toc-header">';
        $html .= '<h3 class="smart-toc-title">' . __('Table of Contents', 'smart-toc') . '</h3>';
        $html .= '<button class="smart-toc-toggle" aria-label="' . __('Toggle Table of Contents', 'smart-toc') . '">';
        $html .= '<span class="smart-toc-toggle-icon">−</span>';
        $html .= '</button>';
        $html .= '</div>';
        
        $html .= '<nav class="smart-toc-nav">';
        $html .= '<ol class="smart-toc-list">';
        
        $prev_level = 0;
        $list_stack = array();
        
        foreach ($headings as $heading) {
            $level = $heading['level'];
            
            if ($level > $prev_level) {
                // Going deeper - open new nested list
                for ($i = $prev_level; $i < $level - 1; $i++) {
                    $html .= '<li><ol>';
                    $list_stack[] = 'ol';
                }
            } elseif ($level < $prev_level) {
                // Going up - close nested lists
                for ($i = $prev_level; $i > $level; $i--) {
                    $html .= '</li>';
                    if (!empty($list_stack)) {
                        $tag = array_pop($list_stack);
                        $html .= '</' . $tag . '>';
                    }
                }
                $html .= '</li>';
            } elseif ($prev_level > 0) {
                // Same level - close previous item
                $html .= '</li>';
            }
            
            $html .= '<li>';
            $html .= '<a href="#' . esc_attr($heading['id']) . '" class="smart-toc-link" data-target="' . esc_attr($heading['id']) . '">';
            $html .= esc_html($heading['text']);
            $html .= '</a>';
            
            $prev_level = $level;
        }
        
        // Close remaining open tags
        $html .= '</li>';
        while (!empty($list_stack)) {
            $tag = array_pop($list_stack);
            $html .= '</' . $tag . '></li>';
        }
        
        $html .= '</ol>';
        $html .= '</nav>';
        $html .= '</div>';
        
        return $html;
    }
    
    /**
     * Insert TOC before first heading
     */
    private function insert_before_first_heading($content, $toc_html) {
        $pattern = '/<h[1-6][^>]*>/i';
        return preg_replace($pattern, $toc_html . '$0', $content, 1);
    }
    
    /**
     * Add inline styles to head
     */
    public function add_inline_styles() {
        $custom_css = get_option('smart_toc_custom_css', '');
        if (!empty($custom_css)) {
            echo '<style type="text/css">' . wp_strip_all_tags($custom_css) . '</style>';
        }
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_options_page(
            __('Smart TOC Settings', 'smart-toc'),
            __('Smart TOC', 'smart-toc'),
            'manage_options',
            'smart-toc',
            array($this, 'admin_page')
        );
    }
    
    /**
     * Register plugin settings
     */
    public function register_settings() {
        register_setting('smart_toc_settings', 'smart_toc_post_types');
        register_setting('smart_toc_settings', 'smart_toc_min_headings');
        register_setting('smart_toc_settings', 'smart_toc_position');
        register_setting('smart_toc_settings', 'smart_toc_scroll_offset');
        register_setting('smart_toc_settings', 'smart_toc_smooth_scroll');
        register_setting('smart_toc_settings', 'smart_toc_custom_css');
    }
    
    /**
     * Admin page HTML
     */
    public function admin_page() {
        ?>
        <div class="wrap">
            <h1><?php _e('Smart TOC Settings', 'smart-toc'); ?></h1>
            
            <form method="post" action="options.php">
                <?php settings_fields('smart_toc_settings'); ?>
                <?php do_settings_sections('smart_toc_settings'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Post Types', 'smart-toc'); ?></th>
                        <td>
                            <?php
                            $enabled_types = get_option('smart_toc_post_types', array('post', 'page'));
                            $post_types = get_post_types(array('public' => true), 'objects');
                            
                            foreach ($post_types as $post_type) {
                                $checked = in_array($post_type->name, $enabled_types) ? 'checked' : '';
                                echo '<label>';
                                echo '<input type="checkbox" name="smart_toc_post_types[]" value="' . esc_attr($post_type->name) . '" ' . $checked . '>';
                                echo esc_html($post_type->label);
                                echo '</label><br>';
                            }
                            ?>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><?php _e('Minimum Headings', 'smart-toc'); ?></th>
                        <td>
                            <input type="number" name="smart_toc_min_headings" value="<?php echo esc_attr(get_option('smart_toc_min_headings', 3)); ?>" min="1" max="10">
                            <p class="description"><?php _e('Minimum number of headings required to show TOC', 'smart-toc'); ?></p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><?php _e('Position', 'smart-toc'); ?></th>
                        <td>
                            <?php
                            $position = get_option('smart_toc_position', 'before_first_heading');
                            $positions = array(
                                'top' => __('Top of content', 'smart-toc'),
                                'before_first_heading' => __('Before first heading', 'smart-toc')
                            );
                            
                            foreach ($positions as $value => $label) {
                                $checked = ($position === $value) ? 'checked' : '';
                                echo '<label>';
                                echo '<input type="radio" name="smart_toc_position" value="' . esc_attr($value) . '" ' . $checked . '>';
                                echo esc_html($label);
                                echo '</label><br>';
                            }
                            ?>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><?php _e('Scroll Offset', 'smart-toc'); ?></th>
                        <td>
                            <input type="number" name="smart_toc_scroll_offset" value="<?php echo esc_attr(get_option('smart_toc_scroll_offset', 100)); ?>" min="0" max="500">
                            <p class="description"><?php _e('Pixels from top when highlighting active heading', 'smart-toc'); ?></p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><?php _e('Smooth Scroll', 'smart-toc'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="smart_toc_smooth_scroll" value="1" <?php checked(1, get_option('smart_toc_smooth_scroll', 1)); ?>>
                                <?php _e('Enable smooth scrolling when clicking TOC links', 'smart-toc'); ?>
                            </label>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><?php _e('Custom CSS', 'smart-toc'); ?></th>
                        <td>
                            <textarea name="smart_toc_custom_css" rows="10" cols="50" class="large-text code"><?php echo esc_textarea(get_option('smart_toc_custom_css', '')); ?></textarea>
                            <p class="description"><?php _e('Add custom CSS to style your TOC', 'smart-toc'); ?></p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
    
    /**
     * Plugin activation
     */
    public function activate() {
        // Set default options
        add_option('smart_toc_post_types', array('post', 'page'));
        add_option('smart_toc_min_headings', 3);
        add_option('smart_toc_position', 'before_first_heading');
        add_option('smart_toc_scroll_offset', 100);
        add_option('smart_toc_smooth_scroll', 1);
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Clean up if needed
    }
}

// Initialize the plugin
SmartTOC::get_instance();
?>