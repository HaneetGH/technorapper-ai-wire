<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Google OAuth for YouTube Data API v3 uploads.
 * Client ID/secret come from wp-config or Social settings. Tokens stay in the
 * non-autoloaded social credentials option.
 */
final class TechnoRapper_AI_Wire_YouTube_OAuth {
    const STATE_TTL = 600;
    const STATE_PREFIX = 'tr_ai_wire_yt_oauth_state_';
    const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    const SCOPE_UPLOAD = 'https://www.googleapis.com/auth/youtube.upload';
    const SCOPE_READONLY = 'https://www.googleapis.com/auth/youtube.readonly';

    public static function init() {
        add_action('admin_post_tr_ai_wire_youtube_oauth_start', array(__CLASS__, 'start'));
        add_action('admin_post_tr_ai_wire_youtube_oauth_callback', array(__CLASS__, 'callback'));
        add_action('admin_post_nopriv_tr_ai_wire_youtube_oauth_callback', array(__CLASS__, 'callback'));
        add_action('admin_notices', array(__CLASS__, 'admin_notice'));
    }

    public static function scopes() {
        return array(self::SCOPE_UPLOAD, self::SCOPE_READONLY);
    }

    public static function redirect_uri() {
        return admin_url('admin-post.php?action=tr_ai_wire_youtube_oauth_callback');
    }

    public static function client_id($credentials = null) {
        if (defined('TR_AI_WIRE_GOOGLE_CLIENT_ID') && trim((string) TR_AI_WIRE_GOOGLE_CLIENT_ID) !== '') {
            return trim((string) TR_AI_WIRE_GOOGLE_CLIENT_ID);
        }
        if (!is_array($credentials)) {
            $credentials = wp_parse_args(get_option(TechnoRapper_AI_Wire::SOCIAL_CREDENTIALS_OPTION, array()), TechnoRapper_AI_Wire::social_credential_defaults());
        }
        return trim((string) ($credentials['youtube_client_id'] ?? ''));
    }

    public static function client_secret($credentials = null) {
        if (defined('TR_AI_WIRE_GOOGLE_CLIENT_SECRET') && trim((string) TR_AI_WIRE_GOOGLE_CLIENT_SECRET) !== '') {
            return trim((string) TR_AI_WIRE_GOOGLE_CLIENT_SECRET);
        }
        if (!is_array($credentials)) {
            $credentials = wp_parse_args(get_option(TechnoRapper_AI_Wire::SOCIAL_CREDENTIALS_OPTION, array()), TechnoRapper_AI_Wire::social_credential_defaults());
        }
        return trim((string) ($credentials['youtube_client_secret'] ?? ''));
    }

    public static function configured($credentials = null) {
        return self::client_id($credentials) !== '' && self::client_secret($credentials) !== '';
    }

    public static function connect_url($credentials = null) {
        if (!self::configured($credentials) || !current_user_can('manage_options')) {
            return '';
        }
        $state = wp_generate_password(48, false, false);
        set_transient(self::STATE_PREFIX . hash('sha256', $state), array(
            'user_id' => get_current_user_id(),
            'created_at' => time(),
        ), self::STATE_TTL);
        return add_query_arg(array(
            'client_id' => self::client_id($credentials),
            'redirect_uri' => self::redirect_uri(),
            'response_type' => 'code',
            'scope' => implode(' ', self::scopes()),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
        ), self::AUTH_URL);
    }

    public static function start() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to connect YouTube.', 'technorapper-ai-wire'));
        }
        check_admin_referer('tr_ai_wire_youtube_oauth_start');
        if (!self::configured()) {
            self::redirect_with_error('YouTube OAuth is not configured. Save a Google client ID and secret on Social settings, or add TR_AI_WIRE_GOOGLE_CLIENT_ID and TR_AI_WIRE_GOOGLE_CLIENT_SECRET to wp-config.php.');
        }
        $url = self::connect_url();
        if ($url === '') {
            self::redirect_with_error('Could not create the YouTube authorization URL.');
        }
        wp_redirect($url, 302, 'TechnoRapper AI Wire');
        exit;
    }

    public static function callback() {
        $state = sanitize_text_field(wp_unslash($_GET['state'] ?? ''));
        $code = sanitize_text_field(wp_unslash($_GET['code'] ?? ''));
        if ($state === '' || $code === '') {
            $error = sanitize_text_field(wp_unslash($_GET['error_description'] ?? $_GET['error'] ?? 'YouTube authorization was cancelled or did not return a code.'));
            self::redirect_with_error($error);
        }
        $key = self::STATE_PREFIX . hash('sha256', $state);
        $state_data = get_transient($key);
        delete_transient($key);
        if (!is_array($state_data) || empty($state_data['user_id']) || (int) $state_data['user_id'] < 1) {
            self::redirect_with_error('The YouTube authorization state expired or was invalid. Start the connection again.');
        }
        $token = self::exchange_code($code);
        if (is_wp_error($token)) {
            self::redirect_with_error($token->get_error_message());
        }
        $channel = self::fetch_channel((string) ($token['access_token'] ?? ''));
        if (is_wp_error($channel)) {
            self::redirect_with_error($channel->get_error_message());
        }
        $credentials = wp_parse_args(get_option(TechnoRapper_AI_Wire::SOCIAL_CREDENTIALS_OPTION, array()), TechnoRapper_AI_Wire::social_credential_defaults());
        $credentials = self::store_tokens($credentials, $token, $channel);
        if (get_option(TechnoRapper_AI_Wire::SOCIAL_CREDENTIALS_OPTION, null) === null) {
            add_option(TechnoRapper_AI_Wire::SOCIAL_CREDENTIALS_OPTION, $credentials, '', false);
        } else {
            update_option(TechnoRapper_AI_Wire::SOCIAL_CREDENTIALS_OPTION, $credentials, false);
        }
        wp_safe_redirect(add_query_arg(array(
            'page' => 'tr-ai-wire-social-settings',
            'youtube_connected' => '1',
            'youtube_channel' => rawurlencode((string) ($channel['title'] ?? '')),
        ), admin_url('admin.php')));
        exit;
    }

    public static function store_tokens($credentials, $token, $channel = array()) {
        $credentials = is_array($credentials) ? $credentials : array();
        $token = is_array($token) ? $token : array();
        $channel = is_array($channel) ? $channel : array();
        if (!empty($token['access_token'])) {
            $credentials['youtube_access_token'] = sanitize_text_field((string) $token['access_token']);
        }
        if (!empty($token['refresh_token'])) {
            $credentials['youtube_refresh_token'] = sanitize_text_field((string) $token['refresh_token']);
        }
        $expires_in = absint($token['expires_in'] ?? 3600);
        if ($expires_in < 60) {
            $expires_in = 3600;
        }
        $credentials['youtube_access_expires'] = (string) (time() + $expires_in);
        if (!empty($channel['id'])) {
            $credentials['youtube_channel_id'] = sanitize_text_field((string) $channel['id']);
        }
        if (!empty($channel['title'])) {
            $credentials['youtube_channel_title'] = sanitize_text_field((string) $channel['title']);
        }
        return $credentials;
    }

    public static function exchange_code($code) {
        $response = wp_remote_post(self::TOKEN_URL, array(
            'timeout' => 30,
            'headers' => array('Content-Type' => 'application/x-www-form-urlencoded'),
            'body' => array(
                'code' => $code,
                'client_id' => self::client_id(),
                'client_secret' => self::client_secret(),
                'redirect_uri' => self::redirect_uri(),
                'grant_type' => 'authorization_code',
            ),
        ));
        return self::parse_token_response($response, 'YouTube authorization could not be completed');
    }

    public static function refresh_access_token($credentials = null) {
        if (!is_array($credentials)) {
            $credentials = wp_parse_args(get_option(TechnoRapper_AI_Wire::SOCIAL_CREDENTIALS_OPTION, array()), TechnoRapper_AI_Wire::social_credential_defaults());
        }
        $refresh = trim((string) ($credentials['youtube_refresh_token'] ?? ''));
        if ($refresh === '') {
            return new WP_Error('tr_ai_wire_yt_oauth_refresh', 'YouTube is not connected. Connect the channel on Social settings.');
        }
        if (!self::configured($credentials)) {
            return new WP_Error('tr_ai_wire_yt_oauth_refresh', 'YouTube OAuth client ID and secret are missing, so the access token cannot be refreshed.');
        }
        $response = wp_remote_post(self::TOKEN_URL, array(
            'timeout' => 30,
            'headers' => array('Content-Type' => 'application/x-www-form-urlencoded'),
            'body' => array(
                'refresh_token' => $refresh,
                'client_id' => self::client_id($credentials),
                'client_secret' => self::client_secret($credentials),
                'grant_type' => 'refresh_token',
            ),
        ));
        $token = self::parse_token_response($response, 'YouTube access token could not be refreshed');
        if (is_wp_error($token)) {
            return $token;
        }
        $next = self::store_tokens($credentials, $token);
        if (get_option(TechnoRapper_AI_Wire::SOCIAL_CREDENTIALS_OPTION, null) === null) {
            add_option(TechnoRapper_AI_Wire::SOCIAL_CREDENTIALS_OPTION, $next, '', false);
        } else {
            update_option(TechnoRapper_AI_Wire::SOCIAL_CREDENTIALS_OPTION, $next, false);
        }
        return $next;
    }

    public static function access_token($credentials = null) {
        if (!is_array($credentials)) {
            $credentials = wp_parse_args(get_option(TechnoRapper_AI_Wire::SOCIAL_CREDENTIALS_OPTION, array()), TechnoRapper_AI_Wire::social_credential_defaults());
        }
        $token = trim((string) ($credentials['youtube_access_token'] ?? ''));
        $expires = (int) ($credentials['youtube_access_expires'] ?? 0);
        if ($token !== '' && $expires > time() + 60) {
            return $token;
        }
        $refreshed = self::refresh_access_token($credentials);
        if (is_wp_error($refreshed)) {
            return $refreshed;
        }
        $token = trim((string) ($refreshed['youtube_access_token'] ?? ''));
        if ($token === '') {
            return new WP_Error('tr_ai_wire_yt_oauth_token', 'YouTube did not return an access token.');
        }
        return $token;
    }

    public static function fetch_channel($access_token) {
        $url = add_query_arg(array(
            'part' => 'snippet',
            'mine' => 'true',
        ), 'https://www.googleapis.com/youtube/v3/channels');
        $response = wp_remote_get($url, array(
            'timeout' => 30,
            'headers' => array('Authorization' => 'Bearer ' . $access_token),
        ));
        if (is_wp_error($response)) {
            return new WP_Error('tr_ai_wire_yt_oauth_channel', $response->get_error_message());
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        $item = is_array($data['items'][0] ?? null) ? $data['items'][0] : array();
        if ($status < 200 || $status >= 300 || empty($item['id'])) {
            return new WP_Error('tr_ai_wire_yt_oauth_channel', sanitize_text_field($data['error']['message'] ?? ('YouTube channel lookup returned HTTP ' . $status . '.')));
        }
        return array(
            'id' => (string) $item['id'],
            'title' => (string) ($item['snippet']['title'] ?? ''),
        );
    }

    public static function admin_notice() {
        if (!current_user_can('manage_options') || !self::is_ai_wire_page()) {
            return;
        }
        if (!empty($_GET['youtube_connect_error'])) {
            $message = sanitize_text_field(wp_unslash($_GET['youtube_connect_error']));
            echo '<div class="notice notice-error is-dismissible"><p><strong>YouTube connection failed:</strong> ' . esc_html($message) . '</p></div>';
        }
        if (!empty($_GET['youtube_connected'])) {
            $title = sanitize_text_field(wp_unslash($_GET['youtube_channel'] ?? ''));
            echo '<div class="notice notice-success is-dismissible"><p><strong>YouTube connected.</strong> ' . ($title !== '' ? esc_html($title . ' is ready for Shorts uploads.') : 'The channel token was saved securely.') . '</p></div>';
        }
    }

    private static function is_ai_wire_page() {
        $page = sanitize_key(wp_unslash($_GET['page'] ?? ''));
        return in_array($page, array('tr-ai-wire-social-settings', 'tr-ai-wire-social'), true);
    }

    private static function parse_token_response($response, $prefix) {
        if (is_wp_error($response)) {
            return new WP_Error('tr_ai_wire_yt_oauth_token', $prefix . ': ' . $response->get_error_message());
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($status < 200 || $status >= 300 || empty($data['access_token'])) {
            $message = sanitize_text_field($data['error_description'] ?? $data['error'] ?? ('Google token exchange returned HTTP ' . $status . '.'));
            return new WP_Error('tr_ai_wire_yt_oauth_token', $prefix . ': ' . $message);
        }
        return $data;
    }

    private static function redirect_with_error($message) {
        $url = add_query_arg(array(
            'page' => 'tr-ai-wire-social-settings',
            'youtube_connect_error' => rawurlencode(sanitize_text_field($message)),
        ), admin_url('admin.php'));
        wp_safe_redirect($url);
        exit;
    }
}

TechnoRapper_AI_Wire_YouTube_OAuth::init();
