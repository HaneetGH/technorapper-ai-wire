<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Instagram Unified Login onboarding for Meta apps created after Meta's
 * Unified Login migration. The app configuration supplies the permissions
 * and asset access; this class only handles the authorization-code callback
 * and stores the resulting system-user token plus Instagram account ID.
 */
final class TechnoRapper_AI_Wire_Instagram_OAuth {
    const GRAPH_VERSION = 'v27.0';
    const STATE_TTL = 600;
    const STATE_PREFIX = 'tr_ai_wire_ig_oauth_state_';

    public static function init() {
        add_action('admin_post_tr_ai_wire_instagram_oauth_start', array(__CLASS__, 'start'));
        add_action('admin_post_nopriv_tr_ai_wire_instagram_oauth_callback', array(__CLASS__, 'callback'));
        add_action('admin_notices', array(__CLASS__, 'admin_notice'));
    }

    public static function configured() {
        return self::app_id() !== '' && self::app_secret() !== '' && self::config_id() !== '';
    }

    public static function redirect_uri() {
        return admin_url('admin-post.php?action=tr_ai_wire_instagram_oauth_callback');
    }

    public static function connect_url() {
        if (!self::configured() || !current_user_can('manage_options')) {
            return '';
        }

        $state = wp_generate_password(48, false, false);
        set_transient(self::STATE_PREFIX . hash('sha256', $state), array(
            'user_id' => get_current_user_id(),
            'created_at' => time(),
        ), self::STATE_TTL);

        return add_query_arg(array(
            'client_id' => self::app_id(),
            'redirect_uri' => self::redirect_uri(),
            'config_id' => self::config_id(),
            'response_type' => 'code',
            'override_default_response_type' => 'true',
            'state' => $state,
            'auth_type' => 'rerequest',
        ), 'https://www.facebook.com/' . self::GRAPH_VERSION . '/dialog/oauth');
    }

    public static function start() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to connect Instagram.', 'technorapper-ai-wire'));
        }

        check_admin_referer('tr_ai_wire_instagram_oauth_start');

        if (!self::configured()) {
            self::redirect_with_error('Instagram OAuth is not configured. Add TR_AI_WIRE_META_APP_ID, TR_AI_WIRE_META_APP_SECRET and TR_AI_WIRE_META_CONFIG_ID to wp-config.php.');
        }

        $url = self::connect_url();
        if ($url === '') {
            self::redirect_with_error('Could not create the Instagram authorization URL.');
        }

        wp_redirect($url, 302, 'TechnoRapper AI Wire');
        exit;
    }

    public static function callback() {
        $state = sanitize_text_field(wp_unslash($_GET['state'] ?? ''));
        $code = sanitize_text_field(wp_unslash($_GET['code'] ?? ''));

        if ($state === '' || $code === '') {
            $error = sanitize_text_field(wp_unslash($_GET['error_message'] ?? $_GET['error'] ?? 'Instagram authorization was cancelled or did not return a code.'));
            self::redirect_with_error($error);
        }

        $key = self::STATE_PREFIX . hash('sha256', $state);
        $state_data = get_transient($key);
        delete_transient($key);

        if (!is_array($state_data) || empty($state_data['user_id']) || (int) $state_data['user_id'] < 1) {
            self::redirect_with_error('The Instagram authorization state expired or was invalid. Start the connection again.');
        }

        $token = self::exchange_code($code);
        if (is_wp_error($token)) {
            self::redirect_with_error($token->get_error_message());
        }

        $account = self::find_instagram_account($token);
        if (is_wp_error($account)) {
            self::redirect_with_error($account->get_error_message());
        }

        if (empty($account['id'])) {
            self::redirect_with_error('Meta authorized the app but did not return an Instagram Professional account.');
        }

        $credentials = wp_parse_args(get_option(TechnoRapper_AI_Wire::SOCIAL_CREDENTIALS_OPTION, array()), TechnoRapper_AI_Wire::social_credential_defaults());
        $credentials['instagram_user_access_token'] = $token;
        $credentials['instagram_user_id'] = preg_replace('/[^0-9]/', '', (string) $account['id']);

        if (get_option(TechnoRapper_AI_Wire::SOCIAL_CREDENTIALS_OPTION, null) === null) {
            add_option(TechnoRapper_AI_Wire::SOCIAL_CREDENTIALS_OPTION, $credentials, '', false);
        } else {
            update_option(TechnoRapper_AI_Wire::SOCIAL_CREDENTIALS_OPTION, $credentials, false);
        }

        wp_safe_redirect(add_query_arg(array(
            'page' => 'tr-ai-wire-social-settings',
            'instagram_connected' => '1',
            'instagram_username' => rawurlencode((string) ($account['username'] ?? '')),
        ), admin_url('admin.php')));
        exit;
    }

    private static function exchange_code($code) {
        $url = add_query_arg(array(
            'client_id' => self::app_id(),
            'client_secret' => self::app_secret(),
            'redirect_uri' => self::redirect_uri(),
            'code' => $code,
        ), 'https://graph.facebook.com/' . self::GRAPH_VERSION . '/oauth/access_token');

        $response = wp_remote_get($url, array('timeout' => 30));
        if (is_wp_error($response)) {
            return new WP_Error('tr_ai_wire_ig_oauth_exchange', 'Instagram authorization could not be completed: ' . $response->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($status < 200 || $status >= 300 || empty($data['access_token'])) {
            return new WP_Error('tr_ai_wire_ig_oauth_exchange', sanitize_text_field($data['error']['message'] ?? ('Meta token exchange returned HTTP ' . $status . '.')));
        }

        return sanitize_text_field((string) $data['access_token']);
    }

    private static function find_instagram_account($token) {
        $expected_username = self::instagram_username();

        $businesses_url = add_query_arg(array(
            'fields' => 'id,name',
            'access_token' => $token,
        ), 'https://graph.facebook.com/' . self::GRAPH_VERSION . '/me/businesses');

        $businesses = self::get_json($businesses_url);
        if (is_wp_error($businesses)) {
            return $businesses;
        }

        $matches = array();
        foreach ((array) ($businesses['data'] ?? array()) as $business) {
            if (empty($business['id'])) {
                continue;
            }

            $accounts_url = add_query_arg(array(
                'fields' => 'id,username,name',
                'access_token' => $token,
            ), 'https://graph.facebook.com/' . self::GRAPH_VERSION . '/' . rawurlencode($business['id']) . '/owned_instagram_accounts');

            $accounts = self::get_json($accounts_url);
            if (is_wp_error($accounts)) {
                continue;
            }

            foreach ((array) ($accounts['data'] ?? array()) as $account) {
                if (!empty($account['id'])) {
                    $matches[] = $account;
                }
            }
        }

        if ($expected_username !== '') {
            foreach ($matches as $account) {
                if (strtolower((string) ($account['username'] ?? '')) === strtolower($expected_username)) {
                    return $account;
                }
            }
            return new WP_Error('tr_ai_wire_ig_oauth_account', 'Meta authorized the app, but the configured Instagram account @' . $expected_username . ' was not returned. Confirm that this Instagram asset is assigned to the selected Unified Login configuration.');
        }

        if (count($matches) === 1) {
            return $matches[0];
        }

        if (count($matches) > 1) {
            return new WP_Error('tr_ai_wire_ig_oauth_account', 'Meta returned multiple Instagram accounts. Set TR_AI_WIRE_INSTAGRAM_USERNAME in wp-config.php to select @techno.rapper explicitly.');
        }

        return new WP_Error('tr_ai_wire_ig_oauth_account', 'Meta authorization succeeded, but no owned Instagram account was returned. Confirm the Instagram account is selected as an asset in the Unified Login configuration.');
    }

    private static function get_json($url) {
        $response = wp_remote_get($url, array('timeout' => 30));
        if (is_wp_error($response)) {
            return new WP_Error('tr_ai_wire_ig_oauth_api', $response->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($status < 200 || $status >= 300 || !empty($data['error'])) {
            return new WP_Error('tr_ai_wire_ig_oauth_api', sanitize_text_field($data['error']['message'] ?? ('Meta returned HTTP ' . $status . '.')));
        }

        return is_array($data) ? $data : array();
    }

    public static function admin_notice() {
        if (!current_user_can('manage_options') || !self::is_ai_wire_page()) {
            return;
        }

        if (!empty($_GET['instagram_connect_error'])) {
            $message = sanitize_text_field(wp_unslash($_GET['instagram_connect_error']));
            echo '<div class="notice notice-error is-dismissible"><p><strong>Instagram connection failed:</strong> ' . esc_html($message) . '</p></div>';
        }

        if (!empty($_GET['instagram_connected'])) {
            $username = sanitize_text_field(wp_unslash($_GET['instagram_username'] ?? ''));
            echo '<div class="notice notice-success is-dismissible"><p><strong>Instagram connected.</strong> ' . ($username !== '' ? esc_html('@' . $username . ' is ready for AI Wire publishing.') : 'The Instagram access token and account ID were saved securely.') . '</p></div>';
        }

        $credentials = get_option(TechnoRapper_AI_Wire::SOCIAL_CREDENTIALS_OPTION, array());
        $connected = !empty($credentials['instagram_user_access_token']) && !empty($credentials['instagram_user_id']);

        if (!self::configured()) {
            if (!$connected) {
                echo '<div class="notice notice-info"><p><strong>Instagram publishing:</strong> paste a Facebook Page access token and Professional account ID on Social settings. The Meta App ID / Unified Login values in <code>wp-config.php</code> are only needed for the optional one-click Connect button, not for publishing.</p></div>';
            }
            return;
        }

        echo '<div class="notice notice-info"><p><strong>Instagram publishing:</strong> ' . ($connected ? 'connected' : 'not connected') . ' · ';
        if (!$connected) {
            $url = wp_nonce_url(admin_url('admin-post.php?action=tr_ai_wire_instagram_oauth_start'), 'tr_ai_wire_instagram_oauth_start');
            echo '<a class="button button-primary" href="' . esc_url($url) . '">Connect @techno.rapper with Meta</a>';
        } else {
            echo '<a href="' . esc_url(admin_url('admin.php?page=tr-ai-wire-social')) . '">Open Social queue</a>';
        }
        echo '</p></div>';
    }

    private static function is_ai_wire_page() {
        $page = sanitize_key(wp_unslash($_GET['page'] ?? ''));
        return in_array($page, array('tr-ai-wire-social-settings', 'tr-ai-wire-social'), true);
    }

    private static function redirect_with_error($message) {
        $url = add_query_arg(array(
            'page' => 'tr-ai-wire-social-settings',
            'instagram_connect_error' => rawurlencode(sanitize_text_field($message)),
        ), admin_url('admin.php'));
        wp_safe_redirect($url);
        exit;
    }

    private static function app_id() {
        return defined('TR_AI_WIRE_META_APP_ID') ? trim((string) TR_AI_WIRE_META_APP_ID) : '';
    }

    private static function app_secret() {
        return defined('TR_AI_WIRE_META_APP_SECRET') ? trim((string) TR_AI_WIRE_META_APP_SECRET) : '';
    }

    private static function config_id() {
        return defined('TR_AI_WIRE_META_CONFIG_ID') ? trim((string) TR_AI_WIRE_META_CONFIG_ID) : '';
    }

    private static function instagram_username() {
        return defined('TR_AI_WIRE_INSTAGRAM_USERNAME') ? trim(ltrim((string) TR_AI_WIRE_INSTAGRAM_USERNAME, '@')) : '';
    }
}

TechnoRapper_AI_Wire_Instagram_OAuth::init();
