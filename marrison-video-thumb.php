<?php
/**
 * Plugin Name: Marrison Video Thumbnail
 * Description: Paste a YouTube URL, pick a thumbnail, add it to WordPress Media Library.
 * Version: 1.0.0
 * Author: Marrison
 */

if (!defined('ABSPATH')) {
    exit;
}

class Marrison_Video_Thumb {

    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_page']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_mvt_get_thumbnails', [$this, 'ajax_get_thumbnails']);
        add_action('wp_ajax_mvt_import_thumbnail', [$this, 'ajax_import_thumbnail']);
    }

    public function add_admin_page() {
        add_media_page(
            'YouTube Thumbnail Extractor',
            'YouTube Thumbnails',
            'upload_files',
            'marrison-video-thumb',
            [$this, 'render_admin_page']
        );
    }

    public function enqueue_assets($hook) {
        if ('media_page_marrison-video-thumb' !== $hook) {
            return;
        }
        wp_enqueue_style('mvt-style', plugin_dir_url(__FILE__) . 'assets/style.css', [], '1.0.1');
        wp_enqueue_script('mvt-script', plugin_dir_url(__FILE__) . 'assets/script.js', ['jquery'], '1.0.1', true);
        wp_localize_script('mvt-script', 'mvtData', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('mvt_nonce'),
        ]);
    }

    public function render_admin_page() {
        ?>
        <div class="wrap mvt-wrap">
            <h1>YouTube Thumbnail Extractor</h1>
            <p>Incolla l'URL del video YouTube per estrarre le thumbnail disponibili.</p>

            <div class="mvt-form">
                <input type="text" id="mvt-url" class="regular-text" placeholder="https://www.youtube.com/watch?v=..." />
                <button id="mvt-fetch" type="button" class="button button-primary">Estrai Thumbnail</button>
            </div>

            <div id="mvt-status" class="mvt-status"></div>

            <div id="mvt-results" class="mvt-results"></div>
        </div>
        <script>
            if (typeof jQuery === 'undefined') {
                document.getElementById('mvt-results').innerHTML = '<p style="color:red;">Errore: jQuery non caricato.</p>';
            }
        </script>
        <?php
    }

    /**
     * Extract YouTube video ID from various URL formats.
     */
    private function extract_video_id($url) {
        $patterns = [
            '/youtube\.com\/watch\?v=([a-zA-Z0-9_-]{11})/',
            '/youtu\.be\/([a-zA-Z0-9_-]{11})/',
            '/youtube\.com\/embed\/([a-zA-Z0-9_-]{11})/',
            '/youtube\.com\/shorts\/([a-zA-Z0-9_-]{11})/',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $url, $matches)) {
                return $matches[1];
            }
        }
        return false;
    }

    /**
     * AJAX: Return available thumbnails for a given YouTube URL.
     */
    public function ajax_get_thumbnails() {
        check_ajax_referer('mvt_nonce', 'nonce');

        $url = isset($_POST['url']) ? sanitize_text_field(wp_unslash($_POST['url'])) : '';
        $video_id = $this->extract_video_id($url);

        if (!$video_id) {
            wp_send_json_error('URL YouTube non valido. Controlla il formato.');
        }

        // Available YouTube thumbnail variants
        $candidates = [
            ['url' => "https://img.youtube.com/vi/{$video_id}/maxresdefault.jpg", 'label' => 'Max Resolution (1280×720)', 'w' => 1280, 'h' => 720],
            ['url' => "https://img.youtube.com/vi/{$video_id}/sddefault.jpg",    'label' => 'Standard (640×480)',         'w' => 640,  'h' => 480],
            ['url' => "https://img.youtube.com/vi/{$video_id}/hqdefault.jpg",    'label' => 'High Quality (480×360)',     'w' => 480,  'h' => 360],
            ['url' => "https://img.youtube.com/vi/{$video_id}/1.jpg",             'label' => 'Frame 25% (120×90)',         'w' => 120,  'h' => 90],
            ['url' => "https://img.youtube.com/vi/{$video_id}/2.jpg",             'label' => 'Frame 50% (120×90)',         'w' => 120,  'h' => 90],
            ['url' => "https://img.youtube.com/vi/{$video_id}/3.jpg",             'label' => 'Frame 75% (120×90)',         'w' => 120,  'h' => 90],
        ];

        // Verify which thumbnails actually exist (maxresdefault and sddefault may not)
        $thumbnails = [];
        foreach ($candidates as $thumb) {
            $response = wp_remote_head($thumb['url'], ['timeout' => 10]);
            $code = wp_remote_retrieve_response_code($response);
            if ($code === 200) {
                $thumb['video_id'] = $video_id;
                $thumbnails[] = $thumb;
            }
        }

        if (empty($thumbnails)) {
            wp_send_json_error('Nessuna thumbnail trovata per questo video.');
        }

        wp_send_json_success(['thumbnails' => $thumbnails]);
    }

    /**
     * AJAX: Import a thumbnail URL into the WordPress Media Library.
     */
    public function ajax_import_thumbnail() {
        check_ajax_referer('mvt_nonce', 'nonce');

        $image_url = isset($_POST['image_url']) ? esc_url_raw(wp_unslash($_POST['image_url'])) : '';
        $video_id  = isset($_POST['video_id']) ? sanitize_text_field(wp_unslash($_POST['video_id'])) : '';

        if (empty($image_url)) {
            wp_send_json_error('URL immagine mancante.');
        }

        if (!function_exists('media_sideload_image')) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        $filename = 'youtube_' . $video_id . '_' . time();

        // Download the image
        $tmp = download_url($image_url);
        if (is_wp_error($tmp)) {
            wp_send_json_error('Impossibile scaricare l\'immagine: ' . $tmp->get_error_message());
        }

        // Determine file extension from content-type or URL
        $ext = 'jpg';
        $content_type = '';
        $head_response = wp_remote_head($image_url, ['timeout' => 10]);
        if (!is_wp_error($head_response)) {
            $content_type = wp_remote_retrieve_header($head_response, 'content-type');
        }
        if (strpos($content_type, 'png') !== false) {
            $ext = 'png';
        } elseif (strpos($content_type, 'webp') !== false) {
            $ext = 'webp';
        }

        $file_array = [
            'name'     => $filename . '.' . $ext,
            'tmp_name' => $tmp,
        ];

        // Validate the file is an image
        if (!file_is_valid_image($tmp)) {
            @unlink($tmp);
            wp_send_json_error('Il file scaricato non è un\'immagine valida.');
        }

        $attachment_id = media_handle_sideload($file_array, 0, 'YouTube Thumbnail - ' . $video_id);

        if (is_wp_error($attachment_id)) {
            @unlink($tmp);
            wp_send_json_error('Errore importazione: ' . $attachment_id->get_error_message());
        }

        $edit_url = admin_url('post.php?post=' . $attachment_id . '&action=edit');

        wp_send_json_success([
            'attachment_id' => $attachment_id,
            'edit_url'      => $edit_url,
            'media_url'     => admin_url('upload.php?item=' . $attachment_id),
        ]);
    }
}

new Marrison_Video_Thumb();
