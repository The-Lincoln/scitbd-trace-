<?php
/**
 * ContentFactory — turns a brief into content rows.
 *
 * Two paths, one contract:
 *   • online  : TinyLLM writes the copy, we parse the mandated block format
 *   • offline : a deterministic template engine writes the same format
 *
 * Both land in `parse()`, so the caller never branches on provider.
 */
declare(strict_types=1);

final class ContentFactory
{
    /**
     * Generate content for one channel.
     *
     * @param array      $ctx     brief, tone, audience, platforms, count
     * @param callable|null $onDelta fn(string $delta): void — live text for SSE
     * @return array{rows:array,raw:string,provider:string,model:string,ms:int,fallback:bool,error:?string}
     */
    public static function generate(string $channel, array $ctx = [], ?callable $onDelta = null): array
    {
        $onDelta ??= static function (string $t): void {};

        $prompt   = PromptLibrary::for($channel, $ctx);
        $messages = [['role' => 'user', 'content' => $prompt]];

        $result = TinyLLM::stream($messages, [
            'system'      => PromptLibrary::system(),
            'temperature' => 0.8,
            'num_predict' => max(512, (int) config('chat.defaults.num_predict')),
        ], $onDelta);

        $raw = (string) $result['content'];
        if (trim($raw) === '' || $result['fallback']) {
            // The rule engine answers questions — it does not emit channel
            // blocks. Re-run the offline writer so the format always parses.
            $raw    = self::localDraft($channel, $ctx);
            $result['provider'] = 'local';
            $result['fallback'] = true;
        }

        return [
            'rows'     => self::parse($channel, $raw, $ctx),
            'raw'      => $raw,
            'provider' => (string) $result['provider'],
            'model'    => (string) $result['model'],
            'ms'       => (int) $result['ms'],
            'fallback' => (bool) $result['fallback'],
            'error'    => $result['error'],
        ];
    }

    // -------------------------------------------------------------- parse ---

    /** @return array<int,array> ready-to-insert rows */
    public static function parse(string $channel, string $raw, array $ctx = []): array
    {
        $rows = match ($channel) {
            'social' => self::parseSocial($raw, $ctx),
            'blog'   => self::parseBlog($raw, $ctx),
            'email'  => self::parseEmail($raw, $ctx),
            default  => [],
        };

        if ($rows === []) {
            $rows = [self::genericRow($channel, $raw, $ctx)];
        }

        $status = (string) ($ctx['status'] ?? 'draft');
        foreach ($rows as $i => $row) {
            $rows[$i] = self::finalize($row, $channel, $status, $i, $ctx);
        }
        return $rows;
    }

    // ------------------------------------------------------------ social ----

    private static function parseSocial(string $raw, array $ctx): array
    {
        // Preferred: the mandated `=== POST | platform: x ===` header.
        $blocks = preg_split('/(?=^\s*===\s*POST)/mi', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($blocks) <= 1) {
            // Fallback A: model used plain `---` separators between posts.
            $parts = preg_split('/^\s*---\s*$/m', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if (count($parts) > 1) {
                $blocks = $parts;
            }
        }

        $allowed = array_keys((array) config('channels.social.platforms', []));
        $ctxPlat = (array) ($ctx['platforms'] ?? []);
        $default = $ctxPlat !== [] ? (string) $ctxPlat[0] : ($allowed[0] ?? 'twitter');

        $out = [];
        foreach ($blocks as $block) {
            $block = trim($block);
            if ($block === '') {
                continue;
            }

            $platform = $default;
            if (preg_match('/platform\s*:\s*([a-z0-9_-]+)/i', $block, $m)) {
                $guess = mb_strtolower(trim($m[1]));
                foreach ($allowed as $a) {
                    if ($a === $guess || str_starts_with($a, $guess) || str_starts_with($guess, $a)) {
                        $platform = $a;
                        break;
                    }
                }
            }

            // Drop the header line.
            $block = preg_replace('/^\s*===\s*POST.*?===\s*$/mi', '', $block, 1) ?? $block;

            $hashtags = '';
            if (preg_match('/^\s*hashtags?\s*:\s*(.*)$/mi', $block, $m, PREG_OFFSET_CAPTURE)) {
                $hashtags = trim($m[1][0]);
                $block    = substr($block, 0, $m[0][1]) . substr($block, $m[0][1] + strlen($m[0][0]));
            }

            $body = trim($block);
            // Split the hook (first non-empty line) off as the title.
            $lines = preg_split('/\R/', $body) ?: [];
            $title = '';
            foreach ($lines as $i => $line) {
                if (trim($line) !== '') {
                    $title = trim($line);
                    unset($lines[$i]);
                    break;
                }
            }

            if (trim($body) === '') {
                continue;
            }

            $out[] = [
                'platform' => $platform,
                'title'    => excerpt($title !== '' ? $title : $body, 120),
                'excerpt'  => '',
                'body'     => trim(implode("\n", $lines)),
                'hashtags' => self::normaliseHashtags($hashtags),
            ];
        }
        return $out;
    }

    // -------------------------------------------------------------- blog ----

    private static function parseBlog(string $raw, array $ctx): array
    {
        $blocks = preg_split('/(?=^\s*TITLE\s*:)/mi', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($blocks === []) {
            $blocks = [$raw];
        }

        $out = [];
        foreach ($blocks as $block) {
            $f = self::keyed($block, ['TITLE', 'SLUG', 'EXCERPT', 'TAGS']);
            if (trim($f['body']) === '' && $f['fields'] === []) {
                continue;
            }
            $title = (string) ($f['fields']['title'] ?? '');
            if ($title === '') {
                $first = trim(preg_replace('/^#+\s*/m', '', (string) preg_split('/\R/', $f['body'])[0] ?? ''));
                $title = $first !== '' ? $first : 'Untitled article';
            }
            $out[] = [
                'platform' => null,
                'title'    => excerpt($title, 300),
                'slug'     => (string) ($f['fields']['slug'] ?? '') ?: slugify($title),
                'excerpt'  => (string) ($f['fields']['excerpt'] ?? ''),
                'body'     => trim($f['body']),
                'hashtags' => (string) ($f['fields']['tags'] ?? ''),
            ];
        }
        return $out;
    }

    // ------------------------------------------------------------- email ----

    private static function parseEmail(string $raw, array $ctx): array
    {
        $blocks = preg_split('/(?=^\s*SUBJECT\s*:)/mi', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($blocks === []) {
            $blocks = [$raw];
        }

        $out = [];
        foreach ($blocks as $block) {
            $f = self::keyed($block, ['SUBJECT', 'PREHEADER', 'HEADLINE', 'CTA']);
            if (trim($f['body']) === '' && $f['fields'] === []) {
                continue;
            }
            $subject = (string) ($f['fields']['subject'] ?? '');
            if ($subject === '') {
                $subject = excerpt($f['body'], 60);
            }
            $out[] = [
                'platform' => null,
                'title'    => excerpt($subject, 300),
                'excerpt'  => (string) ($f['fields']['preheader'] ?? ''),
                'body'     => trim($f['body']),
                'hashtags' => '',
                'meta'     => [
                    'headline'   => (string) ($f['fields']['headline'] ?? ''),
                    'cta'        => (string) ($f['fields']['cta'] ?? config('brand.cta')),
                    'preheader'  => (string) ($f['fields']['preheader'] ?? ''),
                ],
            ];
        }
        return $out;
    }

    /**
     * Read `KEY: value` lines until a `---` separator; everything after it is
     * the body. Tolerates a missing separator by treating the remainder as
     * body, so a model that skips a rule still produces a usable artifact.
     *
     * @return array{fields:array<string,string>,body:string}
     */
    private static function keyed(string $block, array $keys): array
    {
        $lines     = preg_split('/\R/', trim($block)) ?: [];
        $fields    = [];
        $bodyStart = null;
        $lastKeyAt = -1;

        foreach ($lines as $i => $line) {
            $t = trim($line);
            if ($t === '---' || $t === '***' || $t === '___') {
                $bodyStart = $i + 1;
                break;
            }
            $matched = false;
            foreach ($keys as $key) {
                if (preg_match('/^' . $key . '\s*:\s*(.*)$/i', $t, $m)) {
                    $fields[strtolower($key)] = trim($m[1]);
                    $lastKeyAt = $i;
                    $matched   = true;
                    break;
                }
            }
            if (!$matched && $fields !== [] && $t !== '') {
                // Free text before the separator — fold it into the body too.
                $bodyStart = $i;
                break;
            }
        }

        if ($bodyStart === null) {
            $bodyStart = $lastKeyAt + 1;
            if ($lastKeyAt < 0) {
                $bodyStart = 0;
            }
        }

        return ['fields' => $fields, 'body' => implode("\n", array_slice($lines, $bodyStart))];
    }

    /** Last-resort row when the model produced unstructured text. */
    private static function genericRow(string $channel, string $raw, array $ctx): array
    {
        $body = trim($raw);
        return [
            'platform' => $channel === 'social' ? (string) ((array) ($ctx['platforms'] ?? ['twitter']))[0] : null,
            'title'    => excerpt((string) ($ctx['brief'] ?? 'Untitled'), 120),
            'slug'     => $channel === 'blog' ? slugify((string) ($ctx['brief'] ?? 'untitled')) : null,
            'excerpt'  => excerpt($body, 160),
            'body'     => $body,
            'hashtags' => '',
            'meta'     => [],
        ];
    }

    /** Fill derived columns (chars, slug, meta) and drop empty rows. */
    private static function finalize(array $row, string $channel, string $status, int $i, array $ctx): array
    {
        $row['channel'] = $channel;
        $row['status']  = $status;
        $row['body']    = trim((string) ($row['body'] ?? ''));
        $row['chars']   = mb_strlen($row['body']);

        if ($channel === 'social') {
            $full = $row['body'] . ($row['hashtags'] !== '' ? ' ' . $row['hashtags'] : '');
            $row['chars'] = mb_strlen($full);
        }
        if ($channel === 'blog') {
            $row['slug'] = ($row['slug'] ?? '') !== '' ? $row['slug'] : slugify((string) $row['title']);
            if (($row['excerpt'] ?? '') === '') {
                $row['excerpt'] = excerpt($row['body'], 160);
            }
        }

        $meta = $row['meta'] ?? [];
        $meta = array_merge([
            'tone'      => (string) ($ctx['tone'] ?? ''),
            'audience'  => (string) ($ctx['audience'] ?? ''),
            'brief'     => (string) ($ctx['brief'] ?? ''),
            'variant'   => $i + 1,
        ], is_array($meta) ? $meta : []);
        $row['meta'] = $meta;

        return $row;
    }

    private static function normaliseHashtags(string $raw): string
    {
        $parts = preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out   = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p === '') {
                continue;
            }
            $out[] = $p[0] === '#' ? $p : '#' . ltrim($p, '#');
        }
        return implode(' ', array_unique($out));
    }

    // --------------------------------------------------------------- save ---

    /**
     * Insert generated rows.
     *
     * @return int[] new content ids
     */
    public static function save(array $rows, array $opts = []): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $row['flow_id'] = $opts['flow_id'] ?? null;
            $row['run_id']  = $opts['run_id'] ?? null;
            if (trim((string) $row['body']) === '') {
                continue;
            }
            $ids[] = Content::create($row);
        }
        return $ids;
    }

    /** Build the ctx array shared by flows, the agent and chat commands. */
    public static function context(array $src): array
    {
        $platforms = $src['platforms'] ?? null;
        if (is_string($platforms)) {
            $platforms = array_values(array_filter(array_map('trim', explode(',', $platforms))));
        }
        return [
            'brief'     => (string) ($src['brief'] ?? ''),
            'tone'      => (string) ($src['tone'] ?? config('brand.voice')),
            'audience'  => (string) ($src['audience'] ?? config('brand.audience')),
            'platforms' => $platforms ?? ['twitter', 'linkedin'],
            'count'     => max(1, (int) ($src['count'] ?? 3)),
            'status'    => (string) ($src['status'] ?? 'draft'),
        ];
    }

    // ----------------------------------------------------- offline drafts ---

    /** Topic the offline templates write about. */
    private static function topic(array $ctx): string
    {
        $brief = trim((string) ($ctx['brief'] ?? ''));
        if ($brief !== '') {
            return rtrim($brief, '.');
        }
        return (string) config('brand.products', 'content marketing');
    }

    /**
     * Deterministic channel-format text. Mirrors the prompt spec exactly so
     * `parse()` cannot tell it apart from a model reply.
     */
    public static function localDraft(string $channel, array $ctx = []): string
    {
        return match ($channel) {
            'social' => self::draftSocial($ctx),
            'blog'   => self::draftBlog($ctx),
            'email'  => self::draftEmail($ctx),
            default  => self::draftSocial($ctx),
        };
    }

    private static function draftSocial(array $ctx): string
    {
        $topic    = self::topic($ctx);
        $cta      = (string) config('brand.cta');
        $link     = (string) config('brand.links');
        $limit    = (int) config('brand.hashtags') ? '#contentmarketing #smallteams #growth' : '';
        $platforms = (array) ($ctx['platforms'] ?? ['twitter', 'linkedin']);
        $count    = max(1, (int) ($ctx['count'] ?? count($platforms)));

        $hooks = [
            'Most teams treat ' . $topic . ' as a chore. It is a lever.',
            'A hard truth about ' . $topic . ': consistency beats intensity.',
            'We wasted six months on ' . 'the wrong version of ' . $topic . '.',
            'What nobody tells you about ' . $topic . '.',
            'Ship the boring version of ' . $topic . ' first.',
        ];

        $bodies = [
            "Pick one outcome. Ship the smallest useful version today. Measure tomorrow.\n\nDo that for a week and the difference stops being a debate.",
            "The teams that win at this do three things:\n\n- one owner, not a committee\n- a weekly slot, not a heroic push\n- a metric everyone can name\n\nNothing exotic. Just repeated.",
            "Start smaller than feels comfortable. One post, one article, one email.\n\nMomentum compounds faster than polish ever will.",
        ];

        $out = [];
        $pi = 0;
        foreach ($platforms as $p) {
            if (count($out) >= $count) {
                break;
            }
            $hook   = $hooks[$pi % count($hooks)];
            $body   = $bodies[$pi % count($bodies)];
            $sigil  = $pi === count($platforms) - 1 && $link !== '' ? "\n\n{$cta}: {$link}" : '';

            $copy = $hook . "\n\n" . $body . $sigil;
            $hashtags = $limit;

            // Respect the platform's hard limit by trimming the body first.
            $limits = (array) config('channels.social.platforms', []);
            $max    = (int) ($limits[$p]['limit'] ?? 280);
            if (mb_strlen($copy . ' ' . $hashtags) > $max) {
                $room = $max - mb_strlen($hashtags) - 1;
                if ($room < 60) {
                    $copy     = mb_substr($hook, 0, max(30, $room - 1));
                    $hashtags = '';
                } else {
                    $copy = rtrim(mb_substr($copy, 0, $room - 1)) . '…';
                }
            }

            $out[] = "=== POST | platform: {$p} ===\n{$copy}\nhashtags: {$hashtags}";
            $pi++;
        }

        return implode("\n\n---\n\n", $out);
    }

    private static function draftBlog(array $ctx): string
    {
        $topic  = self::topic($ctx);
        $title  = excerpt(ucfirst($topic) . ': a practical playbook for small teams', 60);
        $slug   = slugify($topic) . '-playbook';
        $excerpt = excerpt('A no-fluff guide to ' . $topic . ' — what to do first, what to skip, and how to keep it running without a big team.', 160);
        $cta     = (string) config('brand.cta');
        $link    = (string) config('brand.links');
        $callToAction = $cta === '' ? '' : ($link === '' ? $cta : $cta . ': ' . $link);

        return "TITLE: {$title}\n"
             . "SLUG: {$slug}\n"
             . "EXCERPT: {$excerpt}\n"
             . "TAGS: content marketing, small teams, workflow, {$slug}\n"
             . "---\n"
             . "## Why {$topic} stalls\n\n"
             . "Most teams do not fail at {$topic} because they lack ideas. They fail because "
             . "the work has no owner and no slot in the calendar. Everything urgent wins, so "
             . "the important-but-not-urgent work quietly never happens.\n\n"
             . "The fix is unglamorous: give it a name, a person and a time.\n\n"
             . "## Start smaller than you want to\n\n"
             . "The first attempt should be embarrassingly small — one post, one article, one "
             . "email. A small first version teaches you where the friction actually lives: "
             . "approvals, assets, or the blank page.\n\n"
             . "For example, a team that ships a single 200-word post every Monday learns more in "
             . "a month than a team planning a twelve-piece campaign that never launches.\n\n"
             . "## Build the repeatable loop\n\n"
             . "Once the small version works, write down the loop:\n\n"
             . "1. Pick the angle from a running list.\n"
             . "2. Draft in one sitting — no research rabbit holes.\n"
             . "3. Edit for clarity, not for length.\n"
             . "4. Publish, then note one thing to change next time.\n\n"
             . "A loop you can repeat on a bad week is worth more than a process that only runs "
             . "on a good one.\n\n"
             . "## What to skip\n\n"
             . "Skip the tooling hunt. Skip the 40-field content calendar. Skip trying to be on "
             . "every platform at once. None of those create output; they only create the feeling "
             . "of progress.\n\n"
             . "## The payoff\n\n"
             . "Done consistently, {$topic} stops being a project and becomes a habit — and habits "
             . "are what compound.\n\n"
             . $callToAction;
    }

    private static function draftEmail(array $ctx): string
    {
        $topic  = self::topic($ctx);
        $name   = (string) config('brand.name');
        $cta    = (string) config('brand.cta');
        $link   = (string) config('brand.links');

        return "SUBJECT: The 20-minute version of {$topic}\n"
             . "PREHEADER: A loop you can run even on a week when everything else slips.\n"
             . "HEADLINE: {$topic} without the overhead\n"
             . "CTA: {$cta}\n"
             . "---\n"
             . "Hi {{first_name|there}},\n\n"
             . "Most advice about {$topic} assumes you have a team of six. You probably do not.\n\n"
             . "So here is the version that fits in twenty minutes a week:\n\n"
             . "- **Pick one angle** from a list you already trust — no research session.\n"
             . "- **Draft in a single sitting.** Momentum beats a perfect outline.\n"
             . "- **Edit once**, for clarity rather than length.\n"
             . "- **Ship it**, then write down the one thing you would change.\n\n"
             . "That is the whole loop. It survives busy weeks, which is the only property that "
             . "actually matters.\n\n"
             . "If you want the longer version — including the three things worth skipping — it is "
             . "on the blog.\n\n"
             . "{$cta} → {$link}\n\n"
             . "Talk soon,\n"
             . "The {$name} team";
    }
}
