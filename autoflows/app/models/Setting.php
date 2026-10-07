<?php
/**
 * Key/value settings stored on top of app/config.php.
 */
declare(strict_types=1);

final class Setting extends Model
{
    /** @return array<string,string> */
    public static function all(): array
    {
        $out = [];
        foreach (self::rows('SELECT key, value FROM settings') as $r) {
            $out[(string) $r['key']] = (string) $r['value'];
        }
        return $out;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $v = self::scalar('SELECT value FROM settings WHERE key = ?', [$key]);
        return $v === null ? $default : (string) $v;
    }

    public static function put(string $key, string $value): void
    {
        self::write(
            'INSERT INTO settings (key, value, updated_at) VALUES (?,?,?)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at',
            [$key, $value, self::now()]
        );
    }

    public static function putMany(array $pairs): void
    {
        foreach ($pairs as $k => $v) {
            self::put((string) $k, (string) $v);
        }
    }

    public static function resetAll(): void
    {
        self::write('DELETE FROM settings');
    }

    /** Keys the Settings page owns, with their config defaults. */
    public static function editable(): array
    {
        return [
            'chat_provider'    => (string) config('chat.provider'),
            'chat_endpoint'    => (string) config('chat.providers.ollama.endpoint'),
            'chat_model'       => (string) config('chat.providers.ollama.model'),
            'chat_temperature' => (string) config('chat.defaults.temperature'),
            'chat_num_predict' => (string) config('chat.defaults.num_predict'),
            'chat_system'      => (string) config('chat.defaults.system'),
            'brand_name'       => (string) config('brand.name'),
            'brand_voice'      => (string) config('brand.voice'),
            'brand_audience'   => (string) config('brand.audience'),
            'brand_products'   => (string) config('brand.products'),
            'brand_links'      => (string) config('brand.links'),
            'brand_cta'        => (string) config('brand.cta'),
            'brand_emoji'      => (string) config('brand.emoji'),
            'brand_hashtags'   => (string) config('brand.hashtags'),
            'oauth_google_client_id'     => (string) config('oauth.google.client_id'),
            'oauth_google_client_secret' => (string) config('oauth.google.client_secret'),
            'oauth_facebook_app_id'      => (string) config('oauth.facebook.app_id'),
            'oauth_facebook_app_secret'  => (string) config('oauth.facebook.app_secret'),
            'publish_gmail_to'           => (string) config('publish.default_gmail_to'),
            'slack_bot_token'      => (string) config('slack.bot_token'),
            'slack_app_token'      => (string) config('slack.app_token'),
            'slack_signing_secret' => (string) config('slack.signing_secret'),
            'slack_default_channel'=> (string) config('slack.default_channel'),
        ];
    }

    /** Editable defaults with saved values applied. */
    public static function editableMerged(): array
    {
        $saved = self::all();
        $out   = [];
        foreach (self::editable() as $k => $default) {
            $out[$k] = array_key_exists($k, $saved) && $saved[$k] !== '' ? $saved[$k] : $default;
        }
        return $out;
    }
}
