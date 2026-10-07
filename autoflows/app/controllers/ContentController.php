<?php
/**
 * Content library — edit, approve, schedule, publish, regenerate, export.
 */
declare(strict_types=1);

final class ContentController extends Controller
{
    public function index(): void
    {
        $channel = $this->input('channel', 'all');
        $status  = $this->input('status', 'all');
        $q       = $this->input('q', '');

        $rows = Content::decorateAll(Content::all([
            'channel' => $channel,
            'status'  => $status,
            'q'       => $q,
            'limit'   => 200,
        ]));

        $this->view->render('content/index', [
            'title'     => 'Content',
            'items'     => $rows,
            'channel'   => $channel,
            'status'    => $status,
            'q'         => $q,
            'stats'     => Content::stats(),
            'channels'  => (array) config('channels'),
            'statuses'  => (array) config('statuses'),
            'platforms' => (array) config('channels.social.platforms'),
            'flashes'   => take_flash(),
        ]);
    }

    public function edit(): void
    {
        $id   = $this->int('id', 0);
        $item = $id > 0 ? Content::find($id) : null;
        if ($item === null) {
            flash('warning', 'That content item no longer exists.');
            redirect('content');
        }

        $run = $item['run_id'] ? Run::find((int) $item['run_id']) : null;

        $this->view->render('content/edit', [
            'title'     => excerpt((string) $item['title'], 40),
            'item'      => $item,
            'run'       => $run,
            'statuses'  => (array) config('statuses'),
            'platforms' => (array) config('channels.social.platforms'),
            'channelCfg'=> (array) config('channels.' . $item['channel']),
            'sibling'   => $item['run_id'] ? Content::decorateAll(Content::forRun((int) $item['run_id'])) : [],
            'connections' => class_exists('SocialAccount') ? SocialAccount::statusForUser(auth_user_id()) : [],
            'gmailToDefault' => (string) config('publish.default_gmail_to', ''),
            'flashes'   => take_flash(),
        ]);
    }

    // ---------------------------------------------------------- endpoints ---

    public function save(): void
    {
        $this->requireCsrf();
        $body = $this->jsonBody();
        $id   = (int) ($body['id'] ?? 0);

        $item = Content::find($id);
        if ($item === null) {
            json_response(['ok' => false, 'error' => 'Not found'], 404);
        }

        $title = (string) ($body['title'] ?? $item['title']);
        $text  = (string) ($body['body'] ?? $item['body']);
        $slug  = (string) ($body['slug'] ?? $item['slug']);
        if ($item['channel'] === 'blog' && $slug === '') {
            $slug = slugify($title);
        }

        $meta = $item['meta_arr'];
        foreach (['headline', 'cta', 'preheader'] as $k) {
            if (array_key_exists($k, $body)) {
                $meta[$k] = (string) $body[$k];
            }
        }

        $status = (string) ($body['status'] ?? $item['status']);
        if (!in_array($status, (array) config('statuses'), true)) {
            $status = $item['status'];
        }

        Content::update($id, [
            'title'      => $title,
            'slug'       => $slug,
            'excerpt'    => (string) ($body['excerpt'] ?? $item['excerpt']),
            'body'       => $text,
            'hashtags'   => (string) ($body['hashtags'] ?? $item['hashtags']),
            'status'     => $status,
            'score'      => (int) ($body['score'] ?? $item['score']),
            'publish_at' => (string) ($body['publish_at'] ?? $item['publish_at']) ?: null,
            'meta'       => $meta,
        ]);

        Database::log('content.save', "#{$id} [{$status}] {$title}");
        json_response([
            'ok'    => true,
            'id'    => $id,
            'chars' => mb_strlen($text),
            'item'  => Content::decorate(Content::find($id)),
        ]);
    }

    public function delete(): void
    {
        $this->requireCsrf();
        $body = $this->jsonBody();
        $id   = (int) ($body['id'] ?? $this->int('id', 0));
        if (Content::find($id) === null) {
            json_response(['ok' => false, 'error' => 'Not found'], 404);
        }
        Content::destroy($id);
        Database::log('content.delete', "#{$id}");
        json_response(['ok' => true]);
    }

    /** Bulk / single status transition used by the library buttons. */
    public function status(): void
    {
        $this->requireCsrf();
        $body   = $this->jsonBody();
        $ids    = (array) ($body['ids'] ?? []);
        $status = (string) ($body['status'] ?? '');

        if (!in_array($status, (array) config('statuses'), true)) {
            json_response(['ok' => false, 'error' => 'Unknown status'], 422);
        }

        $publishAt = (string) ($body['publish_at'] ?? '');
        $changed   = 0;
        $published = [];
        foreach ($ids as $rawId) {
            $id = (int) $rawId;
            if (Content::find($id) === null) {
                continue;
            }
            if ($status === 'scheduled' && $publishAt !== '') {
                Content::update($id, ['status' => $status, 'publish_at' => $publishAt]);
            } elseif ($status === 'published' && class_exists('Publisher')) {
                // Real delivery through the connected Gmail / Facebook channel
                // (or a recorded simulation when offline). Never blocks the
                // status flip on API failure — the error lands in meta.
                $res = Publisher::publishContent($id, auth_user_id());
                if (!empty($res['ok'])) {
                    $published[] = $id;
                }
            } else {
                Content::setStatus($id, $status);
            }
            $changed++;
        }

        Database::log('content.status', "{$changed} item(s) -> {$status}");
        json_response(['ok' => true, 'changed' => $changed, 'status' => $status, 'stats' => Content::stats(), 'published' => $published]);
    }

    /**
     * Re-write one artifact, keeping its channel and brief.
     * Streams the new copy over SSE so the editor shows it arriving.
     */
    public function regenerate(): void
    {
        $this->requireCsrf();
        $body = $this->jsonBody();
        $id   = (int) ($body['id'] ?? 0);
        $item = Content::find($id);
        if ($item === null) {
            json_response(['ok' => false, 'error' => 'Not found'], 404);
        }

        Sse::open();
        Sse::send(['type' => 'start', 'id' => $id, 'channel' => $item['channel']]);

        $meta = $item['meta_arr'];
        $ctx  = ContentFactory::context([
            'brief'     => (string) ($meta['brief'] ?? '') ?: (string) $item['title'],
            'tone'      => (string) ($meta['tone'] ?? '') ?: (string) config('brand.voice'),
            'audience'  => (string) ($meta['audience'] ?? '') ?: (string) config('brand.audience'),
            'platforms' => $item['platform'] ? [$item['platform']] : ['twitter', 'linkedin'],
            'count'     => 1,
        ]);

        $offline = TinyLLM::isLocal() || !TinyLLM::status(3)['ok'];

        if ($offline) {
            $raw  = ContentFactory::localDraft($item['channel'], $ctx);
            $rows = ContentFactory::parse($item['channel'], $raw, $ctx);
            foreach (preg_split('/(\s+)/u', $raw, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [] as $piece) {
                if ($piece !== '') {
                    Sse::send(['type' => 'delta', 't' => $piece]);
                    usleep(4000);
                }
            }
        } else {
            $res  = ContentFactory::generate($item['channel'], $ctx, function (string $d): void {
                Sse::send(['type' => 'delta', 't' => $d]);
            });
            $rows = $res['rows'];
            $raw  = $res['raw'];
        }

        $fresh = $rows[0] ?? null;
        if ($fresh === null) {
            Sse::send(['type' => 'error', 'error' => 'The generator returned nothing']);
            Sse::done(['ok' => false]);
        }

        Content::update($id, [
            'title'    => $fresh['title'],
            'slug'     => $fresh['slug'] ?? $item['slug'],
            'excerpt'  => $fresh['excerpt'] ?? $item['excerpt'],
            'body'     => $fresh['body'],
            'hashtags' => $fresh['hashtags'] ?? $item['hashtags'],
            'meta'     => $meta,
        ]);

        $updated = Content::decorate(Content::find($id));
        Database::log('content.regenerate', "#{$id} via " . ($offline ? 'local' : 'ollama'));

        Sse::send(['type' => 'done', 'ok' => true, 'item' => $updated]);
        exit;
    }

    /** Download one artifact as Markdown, HTML email, or CSV for social. */
    public function export(): void
    {
        $id   = $this->int('id', 0);
        $item = Content::find($id);
        if ($item === null) {
            json_response(['ok' => false, 'error' => 'Not found'], 404);
        }

        $type = $this->input('type', 'md');
        $meta = $item['meta_arr'];

        if ($type === 'html' || $item['channel'] === 'email') {
            $html = self::emailHtml($item, $meta);
            header('Content-Type: text/html; charset=utf-8');
            header('Content-Disposition: attachment; filename="content_' . $id . '.html"');
            echo $html;
            exit;
        }

        if ($type === 'csv') {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['id', 'channel', 'platform', 'title', 'chars', 'status', 'body', 'hashtags']);
            fputcsv($handle, [
                $item['id'], $item['channel'], $item['platform'], $item['title'],
                $item['chars'], $item['status'], $item['body'], $item['hashtags'],
            ]);
            fclose($handle);
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="content_' . $id . '.csv"');
            exit;
        }

        $md = '# ' . $item['title'] . "\n\n"
            . ($item['excerpt'] ? '> ' . $item['excerpt'] . "\n\n" : '')
            . ($item['hashtags'] ? $item['hashtags'] . "\n\n" : '')
            . $item['body'] . "\n";

        header('Content-Type: text/markdown; charset=utf-8');
        header('Content-Disposition: attachment; filename="content_' . $id . '.md"');
        echo $md;
        exit;
    }

    /** Standalone HTML email document for export/preview. */
    private static function emailHtml(array $item, array $meta): string
    {
        $headline = (string) ($meta['headline'] ?? '');
        $cta      = (string) ($meta['cta'] ?? config('brand.cta'));
        $link     = (string) config('brand.links');

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

    /**
     * Publish one item through its connected channel.
     * POST api/content/publish {id, to?} — Gmail needs `to` for live sends.
     */
    public function publish(): void
    {
        $this->requireCsrf();
        $body = $this->jsonBody();
        $id = (int) ($body['id'] ?? $this->int('id', 0));
        if ($id <= 0) {
            json_response(['ok' => false, 'error' => 'Missing id'], 422);
        }
        if (!class_exists('Publisher')) {
            json_response(['ok' => false, 'error' => 'Publisher unavailable'], 500);
        }
        $res = Publisher::publishContent($id, auth_user_id(), [
            'to' => trim((string) ($body['to'] ?? $_POST['to'] ?? '')),
        ]);
        json_response($res, !empty($res['ok']) ? 200 : 422);
    }
}
