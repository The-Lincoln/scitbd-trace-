<?php
/**
 * Settings — TinyLLM connection and the brand voice every prompt inherits.
 */
declare(strict_types=1);

final class SettingsController extends Controller
{
    private const BRAND_KEYS = [
        'brand_name', 'brand_voice', 'brand_audience', 'brand_products',
        'brand_links', 'brand_cta', 'brand_emoji', 'brand_hashtags',
    ];

    private const CHAT_KEYS = ['chat_provider', 'chat_endpoint', 'chat_model', 'chat_system'];

    public function index(): void
    {
        $this->view->render('settings/index', [
            'title'     => 'Settings',
            'values'    => Setting::editableMerged(),
            'models'    => TinyLLM::models(),
            'llm'       => TinyLLM::status(3),
            'defaults'  => config('chat.defaults'),
            'brandDef'  => config('brand'),
            'dbPath'    => (string) config('db_path'),
            'tables'    => self::tables(),
            'user'      => current_user(),
            'connections' => class_exists('SocialAccount') ? SocialAccount::statusForUser(auth_user_id()) : [],
            'oauthConfigured' => class_exists('SocialAuth') ? SocialAuth::configuredMap() : ['google' => false, 'facebook' => false],
            'redirects' => [
                'google' => oauth_redirect_uri('google'),
                'facebook' => oauth_redirect_uri('facebook'),
            ],
            'flashes'   => take_flash(),
        ]);
    }

    public function save(): void
    {
        $this->requireCsrf();
        $body = $this->jsonBody();
        $get  = fn (string $k, string $d = '') => trim((string) ($body[$k] ?? $_POST[$k] ?? $d));

        $pairs = [];

        // ---- chat ---------------------------------------------------------
        $provider = $get('chat_provider', 'ollama');
        $pairs['chat_provider'] = in_array($provider, ['ollama', 'local'], true) ? $provider : 'ollama';

        $endpoint = $get('chat_endpoint');
        if (filter_var($endpoint, FILTER_VALIDATE_URL) !== false) {
            $pairs['chat_endpoint'] = rtrim($endpoint, '/');
        } elseif ($endpoint !== '') {
            json_response(['ok' => false, 'error' => 'Endpoint must be a valid http(s) URL'], 422);
        }

        $model = $get('chat_model');
        if ($model !== '') {
            $pairs['chat_model'] = preg_replace('/[^a-zA-Z0-9:._\-]/', '', $model);
        }

        $pairs['chat_temperature'] = (string) clampf((float) $get('chat_temperature', '0.75'), 0.0, 2.0);
        $pairs['chat_num_predict'] = (string) clamp((int) $get('chat_num_predict', '768'), 64, 4096);

        $system = $get('chat_system');
        if ($system !== '') {
            $pairs['chat_system'] = mb_substr($system, 0, 4000);
        }

        // ---- brand --------------------------------------------------------
        $pairs['brand_name']     = mb_substr($get('brand_name', (string) config('brand.name')), 0, 120);
        $pairs['brand_voice']    = mb_substr($get('brand_voice', (string) config('brand.voice')), 0, 400);
        $pairs['brand_audience'] = mb_substr($get('brand_audience', (string) config('brand.audience')), 0, 300);
        $pairs['brand_products'] = mb_substr($get('brand_products', (string) config('brand.products')), 0, 300);
        $pairs['brand_cta']      = mb_substr($get('brand_cta', (string) config('brand.cta')), 0, 120);

        $links = $get('brand_links');
        if ($links !== '' && filter_var($links, FILTER_VALIDATE_URL) === false) {
            json_response(['ok' => false, 'error' => 'Brand link must be a valid URL'], 422);
        }
        $pairs['brand_links']    = $links;
        $pairs['brand_emoji']    = $body['brand_emoji'] ?? $_POST['brand_emoji'] ?? '0' ? '1' : '0';
        $pairs['brand_hashtags'] = $body['brand_hashtags'] ?? $_POST['brand_hashtags'] ?? '0' ? '1' : '0';

        // ---- oauth: Gmail (Google) + Facebook ------------------------------
        $gId = $get('oauth_google_client_id');
        $gSecret = trim((string) ($body['oauth_google_client_secret'] ?? $_POST['oauth_google_client_secret'] ?? ''));
        $fbId = $get('oauth_facebook_app_id');
        $fbSecret = trim((string) ($body['oauth_facebook_app_secret'] ?? $_POST['oauth_facebook_app_secret'] ?? ''));
        // Empty secret field means "keep the saved one" (we never echo it).
        if ($gId !== '' || array_key_exists('oauth_google_client_id', $body) || isset($_POST['oauth_google_client_id'])) {
            $pairs['oauth_google_client_id'] = mb_substr($gId, 0, 300);
        }
        if ($gSecret !== '') {
            $pairs['oauth_google_client_secret'] = mb_substr($gSecret, 0, 300);
        }
        if ($fbId !== '' || array_key_exists('oauth_facebook_app_id', $body) || isset($_POST['oauth_facebook_app_id'])) {
            $pairs['oauth_facebook_app_id'] = mb_substr($fbId, 0, 300);
        }
        if ($fbSecret !== '') {
            $pairs['oauth_facebook_app_secret'] = mb_substr($fbSecret, 0, 300);
        }
        $gmailTo = $get('publish_gmail_to');
        if ($gmailTo === '' || filter_var($gmailTo, FILTER_VALIDATE_EMAIL) !== false) {
            if ($gmailTo !== '' || array_key_exists('publish_gmail_to', $body) || isset($_POST['publish_gmail_to'])) {
                $pairs['publish_gmail_to'] = $gmailTo;
            }
        } elseif ($gmailTo !== '') {
            json_response(['ok' => false, 'error' => 'Gmail test recipient must be a valid email'], 422);
        }

        Setting::putMany($pairs);
        // The config cache is latched per-request — drop it so the status
        // probe below (and any later read) uses the endpoint we just saved.
        config_refresh();
        Database::log('settings.save', implode(', ', array_keys($pairs)));

        if (is_ajax()) {
            json_response(['ok' => true, 'saved' => count($pairs), 'llm' => TinyLLM::status(3)]);
        }
        flash('success', 'Settings saved.');
        redirect('settings');
    }

    public function reset(): void
    {
        $this->requireCsrf();
        Setting::resetAll();
        Database::log('settings.reset', 'All overrides cleared');
        flash('info', 'Settings reset to the file defaults.');
        if (is_ajax()) {
            json_response(['ok' => true]);
        }
        redirect('settings');
    }

    /** Reachability probe for the Settings page. */
    public function test(): void
    {
        $llm = TinyLLM::status(6);
        json_response([
            'ok'     => true,
            'llm'    => $llm,
            'models' => TinyLLM::models(),
        ]);
    }

    private static function tables(): array
    {
        $out = [];
        foreach (['conversations', 'messages', 'flows', 'runs', 'content', 'settings', 'task_logs', 'users', 'social_accounts'] as $t) {
            try {
                $out[$t] = (int) Database::pdo()->query("SELECT COUNT(*) FROM {$t}")->fetchColumn();
            } catch (Throwable) {
                $out[$t] = 0;
            }
        }
        return $out;
    }
}
