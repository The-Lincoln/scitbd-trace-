<?php
/**
 * Auth — social login (Google / Facebook) + channel connect + dev fallback.
 *
 * Login and channel-connect share the same OAuth callback: when the flow
 * started from Settings (?connect=1) we only store the channel tokens and
 * keep the current session; otherwise we sign the user in.
 */
declare(strict_types=1);

final class AuthController extends Controller
{
    public function login(): void
    {
        if (current_user() !== null) {
            redirect('home');
        }
        $this->view->render('auth/login', [
            'title' => 'Sign in',
            'configured' => SocialAuth::configuredMap(),
            'redirects' => [
                'google' => oauth_redirect_uri('google'),
                'facebook' => oauth_redirect_uri('facebook'),
            ],
            'allowDev' => (bool) config('auth.allow_dev_login', true),
            'flashes' => take_flash(),
        ]);
    }

    public function logout(): void
    {
        auth_logout();
        flash('info', 'Signed out.');
        redirect('login');
    }

    public function google(): void
    {
        $this->start('google');
    }

    public function facebook(): void
    {
        $this->start('facebook');
    }

    public function googleCallback(): void
    {
        $this->callback('google');
    }

    public function facebookCallback(): void
    {
        $this->callback('facebook');
    }

    public function dev(): void
    {
        if (!(bool) config('auth.allow_dev_login', true)) {
            flash('warning', 'Dev login is disabled.');
            redirect('login');
        }
        $which = in_array($this->input('via', 'google'), ['google', 'facebook'], true)
            ? $this->input('via', 'google') : 'google';
        $provider = $which === 'facebook' ? 'facebook' : 'google';

        $user = User::dev($provider);
        // Dev login also plants a simulated channel so publishing can be tried.
        SocialAccount::saveSimulated((int) $user['id'], $provider, [
            'id' => 'dev-' . $provider,
            'email' => (string) $user['email'],
            'name' => (string) $user['name'],
        ]);
        auth_login($user);
        User::touchLogin((int) $user['id']);
        Database::log('auth.dev_login', $provider . ' as ' . $user['email']);
        flash('success', 'Signed in as ' . $user['name'] . ' (dev mode).');
        redirect($this->input('next', 'home') !== '' ? $this->sanitizeNext($this->input('next', 'home')) : 'home');
    }

    public function disconnect(): void
    {
        $this->requireCsrf();
        $body = $this->jsonBody();
        $provider = (string) ($body['provider'] ?? $_POST['provider'] ?? '');
        if (!in_array($provider, ['google', 'facebook'], true)) {
            json_response(['ok' => false, 'error' => 'Unknown provider'], 422);
        }
        SocialAccount::disconnect(auth_user_id(), $provider);
        Database::log('auth.disconnect', $provider . ' uid=' . auth_user_id());
        if (is_ajax()) {
            json_response(['ok' => true, 'provider' => $provider]);
        }
        flash('info', ucfirst($provider) . ' disconnected.');
        redirect('settings');
    }

    public function status(): void
    {
        $user = current_user();
        json_response([
            'ok' => true,
            'user' => $user ? ['id' => (int) $user['id'], 'name' => $user['name'], 'email' => $user['email'], 'avatar' => $user['avatar'] ?? null] : null,
            'connections' => SocialAccount::statusForUser(auth_user_id()),
            'configured' => SocialAuth::configuredMap(),
        ]);
    }

    // ------------------------------------------------------------------ ---

    private function start(string $provider): void
    {
        if (!SocialAuth::isConfigured($provider)) {
            flash('warning', $this->label($provider) . ' is not configured yet — add the keys in Settings, or use dev login.');
            redirect('login');
        }
        // ?connect=1 (from Settings) → link channel only, don't switch user.
        if ($this->input('connect', '') !== '') {
            $_SESSION['oauth_connect_only'] = 1;
        } else {
            unset($_SESSION['oauth_connect_only']);
        }
        header('Location: ' . SocialAuth::authUrl($provider));
        exit;
    }

    private function callback(string $provider): void
    {
        $err = $this->input('error', '');
        if ($err !== '') {
            flash('warning', $this->label($provider) . ' login cancelled: ' . $this->input('error_description', $err));
            redirect('login');
        }
        $code = $this->input('code', '');
        $state = $this->input('state', '');
        if ($code === '' || !SocialAuth::checkState($provider, $state)) {
            flash('warning', 'Invalid OAuth state — please try again.');
            redirect('login');
        }

        try {
            $result = SocialAuth::exchange($provider, $code);
        } catch (Throwable $e) {
            Database::log('auth.oauth_failed', $provider . ': ' . $e->getMessage(), 'error');
            flash('warning', $this->label($provider) . ' login failed: ' . $e->getMessage());
            redirect('login');
        }

        $connectOnly = !empty($_SESSION['oauth_connect_only']);
        unset($_SESSION['oauth_connect_only']);

        $profile = $result['profile'];
        $tokens = $result['tokens'];

        if ($connectOnly && current_user() !== null) {
            $uid = auth_user_id();
        } else {
            $user = User::upsertSocial($profile);
            auth_login($user);
            User::touchLogin((int) $user['id']);
            $uid = (int) $user['id'];
        }

        SocialAccount::saveConnection([
            'user_id' => $uid,
            'provider' => $provider,
            'provider_user_id' => (string) $profile['id'],
            'email' => (string) ($profile['email'] ?? ''),
            'name' => (string) ($profile['name'] ?? ''),
            'avatar' => (string) ($profile['avatar'] ?? ''),
            'access_token' => (string) ($tokens['access_token'] ?? ''),
            'refresh_token' => (string) ($tokens['refresh_token'] ?? ''),
            'expires_in' => (int) ($tokens['expires_in'] ?? 0),
            'scopes' => $provider === 'google'
                ? implode(' ', (array) config('oauth.google.scopes'))
                : implode(',', (array) config('oauth.facebook.scopes')),
            'page_id' => $tokens['page_id'] ?? null,
            'page_name' => $tokens['page_name'] ?? null,
            'page_token' => $tokens['page_token'] ?? null,
            'meta' => ['simulated' => false, 'connected_via' => $connectOnly ? 'settings' : 'login'],
        ]);

        Database::log('auth.connected', $provider . ' uid=' . $uid . ' ' . ($profile['email'] ?? ''));
        flash('success', $this->label($provider) . ' connected' . ($connectOnly ? '.' : ' — welcome, ' . ($profile['name'] ?: $profile['email']) . '!'));
        redirect($connectOnly ? 'settings' : 'home');
    }

    private function label(string $provider): string
    {
        return $provider === 'google' ? 'Google' : 'Facebook';
    }

    private function sanitizeNext(string $next): string
    {
        // Only allow internal relative routes (no open redirects).
        $next = trim($next, '/');
        if ($next === '' || str_contains($next, ':') || str_starts_with($next, '//')) {
            return 'home';
        }
        return $next;
    }
}
