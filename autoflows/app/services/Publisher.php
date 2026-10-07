<?php
/**
 * Publisher — deliver AutoFlows content through connected accounts.
 *
 *  social/facebook → Facebook Graph API (Page feed, else /me/feed)
 *  email           → Gmail API (users.messages.send, RFC2822 → base64url)
 *
 * No keys / no token / offline → simulated result written into
 * content.meta.publish_result so every autoflow can complete end-to-end.
 */
declare(strict_types=1);

final class Publisher
{
    /** Publish one content row via its natural channel. */
    public static function publishContent(int $contentId, int $userId = 0, array $opts = []): array
    {
        $item = Content::find($contentId);
        if ($item === null) {
            return ['ok' => false, 'error' => 'Content not found'];
        }
        if ($item['channel'] === 'email') {
            $to = (string) ($opts['to'] ?? $item['meta_arr']['send_to'] ?? config('publish.default_gmail_to', ''));
            return self::sendGmail($item, $userId, $to);
        }
        if ($item['channel'] === 'social') {
            // Only facebook platform posts go to the Graph API; other
            // platforms are recorded as simulated cross-posts.
            $platform = strtolower((string) ($item['platform'] ?? 'facebook'));
            if ($platform === '' || $platform === 'facebook') {
                return self::postFacebook($item, $userId);
            }
            return self::recordResult($contentId, [
                'ok' => true, 'simulated' => true, 'channel' => 'social',
                'platform' => $platform,
                'note' => "Recorded as cross-post copy for {$platform}. Connect its network API for live delivery.",
                'at' => date('Y-m-d H:i:s'),
            ], 'published');
        }
        // blog / pack artefacts have no external endpoint — mark published.
        return self::recordResult($contentId, [
            'ok' => true, 'simulated' => true, 'channel' => $item['channel'],
            'note' => 'Marked published locally (no external endpoint for this channel).',
            'at' => date('Y-m-d H:i:s'),
        ], 'published');
    }

    // ------------------------------------------------------------ facebook ---

    public static function postFacebook(array $item, int $userId = 0): array
    {
        $item = Content::decorate($item);
        $acc = SocialAccount::forUser($userId, 'facebook');
        $message = trim((string) $item['title'] . "\n\n" . (string) $item['body']
            . ($item['hashtags'] ? "\n\n" . $item['hashtags'] : ''));
        $link = self::firstUrl((string) $item['body'] . ' ' . (string) config('brand.links'));

        if ($acc === null || ($acc['access_token_plain'] ?? '') === '') {
            return self::recordResult((int) $item['id'], [
                'ok' => true, 'simulated' => true, 'channel' => 'facebook',
                'note' => 'No Facebook connection — simulated post. Connect Facebook in Settings → Channels.',
                'message_chars' => mb_strlen($message),
                'at' => date('Y-m-d H:i:s'),
            ], 'published');
        }
        $plain = (string) ($acc['access_token_plain'] ?? '');
        if ($plain === 'simulated' || !(bool) config('publish.simulate_when_offline', true) === false && $plain === 'simulated') {
            return self::recordResult((int) $item['id'], [
                'ok' => true, 'simulated' => true, 'channel' => 'facebook',
                'note' => 'Dev Facebook connection — simulated post.',
                'message_chars' => mb_strlen($message),
                'at' => date('Y-m-d H:i:s'),
            ], 'published');
        }

        $ver = (string) config('oauth.facebook.graph_version', 'v18.0');
        $pageToken = (string) ($acc['page_token_plain'] ?? '');
        $pageId = (string) ($acc['page_id'] ?? '');
        $token = ($pageToken !== '' && $pageId !== '') ? $pageToken : $plain;
        $target = ($pageToken !== '' && $pageId !== '') ? $pageId : 'me';

        $fields = ['message' => mb_substr($message, 0, 9000), 'access_token' => $token];
        if ($link !== '') {
            $fields['link'] = $link;
        }
        try {
            $res = self::fbPost("https://graph.facebook.com/{$ver}/{$target}/feed", $fields);
        } catch (Throwable $e) {
            return self::recordResult((int) $item['id'], [
                'ok' => false, 'simulated' => false, 'channel' => 'facebook',
                'error' => $e->getMessage(), 'at' => date('Y-m-d H:i:s'),
            ], null);
        }
        if (empty($res['id'])) {
            $msg = (string) ($res['error']['message'] ?? substr(json_encode($res), 0, 220));
            return self::recordResult((int) $item['id'], [
                'ok' => false, 'simulated' => false, 'channel' => 'facebook',
                'error' => 'Facebook rejected the post: ' . $msg,
                'response' => $res, 'at' => date('Y-m-d H:i:s'),
            ], null);
        }
        return self::recordResult((int) $item['id'], [
            'ok' => true, 'simulated' => false, 'channel' => 'facebook',
            'post_id' => (string) $res['id'],
            'target' => $target . ($acc['page_name'] ? ' (' . $acc['page_name'] . ')' : ''),
            'url' => 'https://www.facebook.com/' . (string) $res['id'],
            'at' => date('Y-m-d H:i:s'),
        ], 'published');
    }

    /** @param array<string,string> $fields @return array<string,mixed> */
    private static function fbPost(string $url, array $fields): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('Facebook HTTP error: ' . $err);
        }
        curl_close($ch);
        $data = json_decode((string) $body, true);
        return is_array($data) ? $data : ['raw' => substr((string) $body, 0, 300)];
    }

    // --------------------------------------------------------------- gmail ---

    /**
     * Send an email-channel item through the connected Gmail account.
     * $to may be a single address; without one the result stays simulated.
     */
    public static function sendGmail(array $item, int $userId = 0, string $to = ''): array
    {
        $item = Content::decorate($item);
        $to = trim($to);
        if ($to !== '' && filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            return ['ok' => false, 'error' => 'Invalid recipient email'];
        }

        $acc = SocialAccount::forUser($userId, 'google');
        $meta = $item['meta_arr'];
        $subject = trim((string) $item['title']) !== '' ? (string) $item['title'] : 'Untitled from ' . config('brand.name');
        $fromName = (string) config('brand.name', 'AutoFlows');
        $fromEmail = $acc['email'] ?? '';
        $html = self::emailHtml($item, $meta);
        $text = trim(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</h1>', '</h2>'], "\n", $html)));

        $base = [
            'ok' => true, 'channel' => 'gmail',
            'subject' => $subject, 'to' => $to,
            'from' => $fromEmail !== '' ? $fromName . ' <' . $fromEmail . '>' : $fromName,
            'at' => date('Y-m-d H:i:s'),
        ];

        if ($acc === null || ($acc['access_token_plain'] ?? '') === '' || $to === '') {
            $reason = $to === '' ? 'No recipient — enter a test address to send.' : 'No Gmail connection — simulated send.';
            return self::recordResult((int) $item['id'], $base + [
                'simulated' => true,
                'note' => $reason . ' Connect Gmail in Settings → Channels for live delivery.',
            ], 'published');
        }

        $access = (string) ($acc['access_token_plain'] ?? '');
        if ($access === 'simulated') {
            return self::recordResult((int) $item['id'], $base + [
                'simulated' => true, 'note' => 'Dev Gmail connection — simulated send.',
            ], 'published');
        }

        // Refresh once when expired and a refresh token exists.
        if (SocialAccount::isExpired($acc)) {
            $fresh = SocialAuth::refreshGoogle($acc);
            if (!empty($fresh['access_token'])) {
                SocialAccount::saveConnection([
                    'user_id' => (int) ($acc['user_id'] ?? $userId),
                    'provider' => 'google',
                    'access_token' => (string) $fresh['access_token'],
                    'expires_in' => (int) ($fresh['expires_in'] ?? 3600),
                ]);
                $access = (string) $fresh['access_token'];
            }
        }

        $raw = self::mime($fromName, (string) ($fromEmail !== '' ? $fromEmail : 'me'), $to, $subject, $text, $html);
        try {
            $res = self::gmailSend($access, $raw);
        } catch (Throwable $e) {
            return self::recordResult((int) $item['id'], $base + [
                'ok' => false, 'simulated' => false, 'error' => $e->getMessage(),
            ], null);
        }
        if (empty($res['id'])) {
            // Token may have expired between check and send — one refresh retry.
            $fresh = SocialAuth::refreshGoogle(SocialAccount::forUser($userId, 'google') ?? []);
            if (!empty($fresh['access_token'])) {
                SocialAccount::saveConnection([
                    'user_id' => $userId, 'provider' => 'google',
                    'access_token' => (string) $fresh['access_token'],
                    'expires_in' => (int) ($fresh['expires_in'] ?? 3600),
                ]);
                try {
                    $res = self::gmailSend((string) $fresh['access_token'], $raw);
                } catch (Throwable $e) {
                    return self::recordResult((int) $item['id'], $base + [
                        'ok' => false, 'simulated' => false, 'error' => $e->getMessage(),
                    ], null);
                }
            }
        }
        if (empty($res['id'])) {
            return self::recordResult((int) $item['id'], $base + [
                'ok' => false, 'simulated' => false,
                'error' => 'Gmail rejected the send: ' . substr(json_encode($res), 0, 220),
                'response' => $res,
            ], null);
        }
        return self::recordResult((int) $item['id'], $base + [
            'simulated' => false, 'message_id' => (string) $res['id'],
        ], 'published');
    }

    /** @return array<string,mixed> */
    private static function gmailSend(string $accessToken, string $rawB64Url): array
    {
        $ch = curl_init('https://gmail.googleapis.com/gmail/v1/users/me/messages/send');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json', 'Authorization: Bearer ' . $accessToken],
            CURLOPT_POSTFIELDS => json_encode(['raw' => $rawB64Url]),
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('Gmail HTTP error: ' . $err);
        }
        curl_close($ch);
        $data = json_decode((string) $body, true);
        return is_array($data) ? $data : ['raw' => substr((string) $body, 0, 300)];
    }

    private static function mime(string $fromName, string $fromEmail, string $to, string $subject, string $text, string $html): string
    {
        $boundary = 'af_' . bin2hex(random_bytes(12));
        $enc = fn (string $s): string => '=?UTF-8?B?' . base64_encode($s) . '?=';
        $headers = [
            'From: ' . $enc($fromName) . " <{$fromEmail}>",
            'To: ' . $to,
            'Subject: ' . $enc($subject),
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];
        $body = "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n{$text}\r\n"
              . "--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n{$html}\r\n"
              . "--{$boundary}--";
        $raw = implode("\r\n", $headers) . "\r\n\r\n" . $body;
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    // -------------------------------------------------------------- shared ---

    /** Persist publish_result into content.meta and optionally flip status. */
    private static function recordResult(int $id, array $result, ?string $status): array
    {
        $item = Content::find($id);
        if ($item === null) {
            return ['ok' => false, 'error' => 'Content not found'];
        }
        $meta = $item['meta_arr'];
        $meta['publish_result'] = $result;
        $meta['published_via'] = $result['channel'] ?? null;
        Content::update($id, ['meta' => $meta]);
        if ($status !== null && ($result['ok'] ?? false)) {
            Content::setStatus($id, $status);
        }
        Database::log(
            'publish.' . ($result['channel'] ?? 'local'),
            "#{$id} " . (($result['ok'] ?? false) ? 'ok' : 'fail')
            . (isset($result['simulated']) && $result['simulated'] ? ' (simulated)' : '')
            . ' — ' . substr((string) ($result['note'] ?? $result['error'] ?? $result['post_id'] ?? $result['message_id'] ?? ''), 0, 140),
            ($result['ok'] ?? false) ? 'info' : 'error'
        );
        $result['content_id'] = $id;
        $result['status'] = Content::find($id)['status'] ?? $item['status'];
        return $result;
    }

    private static function firstUrl(string $text): string
    {
        if (preg_match('#https?://[^\s)>\]]+#i', $text, $m)) {
            return rtrim($m[0], '.,;!');
        }
        return '';
    }

    private static function emailHtml(array $item, array $meta): string
    {
        $headline = (string) ($meta['headline'] ?? '');
        $cta = (string) ($meta['cta'] ?? config('brand.cta'));
        $link = (string) config('brand.links');
        return '<!doctype html><html><head><meta charset="utf-8">'
             . '<meta name="viewport" content="width=device-width,initial-scale=1"></head>'
             . '<body style="margin:0;background:#f4f5f7;font-family:Arial,Helvetica,sans-serif;color:#1a1a1a">'
             . '<div style="max-width:600px;margin:0 auto;padding:24px">'
             . '<p style="display:none;max-height:0;overflow:hidden">' . e((string) ($meta['preheader'] ?? '')) . '</p>'
             . '<div style="background:#fff;border-radius:12px;padding:32px">'
             . '<p style="font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:#6b7280;margin:0 0 16px">'
             . e((string) config('brand.name')) . '</p>'
             . '<h1 style="font-size:26px;line-height:1.25;margin:0 0 20px">' . e($headline ?: (string) $item['title']) . '</h1>'
             . '<div style="font-size:16px;line-height:1.65">' . nl2br(e((string) $item['body'])) . '</div>'
             . '<p style="margin:28px 0 0"><a href="' . e($link) . '" style="display:inline-block;background:#6d28d9;'
             . 'color:#fff;text-decoration:none;padding:12px 22px;border-radius:8px;font-weight:700">' . e($cta) . '</a></p>'
             . '</div>'
             . '<p style="font-size:12px;color:#9ca3af;text-align:center;margin-top:16px">'
             . e((string) config('brand.name')) . ' · <a href="' . e($link) . '" style="color:#9ca3af">unsubscribe</a></p>'
             . '</div></body></html>';
    }
}
