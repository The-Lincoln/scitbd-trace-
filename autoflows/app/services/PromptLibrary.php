<?php
/**
 * Prompt templates — the single place where channel wording, output format
 * and brand voice live. Both the agent and the chat slash-commands go through
 * here, so a prompt is only ever written once.
 */
declare(strict_types=1);

final class PromptLibrary
{
    /** Brand block appended to every generation prompt. */
    public static function brandContext(): string
    {
        $b = [
            'brand'    => (string) config('brand.name'),
            'voice'    => (string) config('brand.voice'),
            'audience' => (string) config('brand.audience'),
            'product'  => (string) config('brand.products'),
            'link'     => (string) config('brand.links'),
            'cta'      => (string) config('brand.cta'),
        ];
        $emoji    = (int) config('brand.emoji') ? 'allowed sparingly' : 'do NOT use emoji';
        $hashtags = (int) config('brand.hashtags') ? 'include 3-5' : 'include none';

        return "## Brand\n"
             . "- Name: {$b['brand']}\n"
             . "- Voice: {$b['voice']}\n"
             . "- Audience: {$b['audience']}\n"
             . "- Product: {$b['product']}\n"
             . "- Link: {$b['link']}\n"
             . "- Call to action: {$b['cta']}\n"
             . "- Emoji: {$emoji}\n"
             . "- Hashtags: {$hashtags}\n";
    }

    /** Tone + audience + standing brief shared by every channel. */
    public static function brief(array $ctx): string
    {
        $tone  = (string) ($ctx['tone'] ?? 'warm');
        $aud   = (string) ($ctx['audience'] ?? config('brand.audience'));
        $brief = trim((string) ($ctx['brief'] ?? ''));
        $notes = trim((string) ($ctx['notes'] ?? ''));

        return "## Task brief\n"
             . "- Topic / angle: " . ($brief !== '' ? $brief : 'a practical lesson the audience can apply today') . "\n"
             . "- Tone: {$tone}\n"
             . "- Audience: {$aud}\n"
             . ($notes !== '' ? "\n## Extracted brief (follow it)\n{$notes}\n" : '');
    }

    /** System prompt used for structured generation (all channels). */
    public static function system(): string
    {
        return "You are AutoFlows, a senior content-marketing copywriter running locally on TinyLLM. "
             . "You write daily social posts, blog articles and marketing emails.\n"
             . "Rules:\n"
             . "- Follow the output format EXACTLY. No preamble, no commentary, no code fences.\n"
             . "- Be specific and concrete. Prefer real examples over abstractions.\n"
             . "- Short sentences. Active voice. One idea per paragraph.\n"
             . "- Never invent statistics, quotes or studies.\n"
             . "- Never mention that you are an AI or that a format was requested.";
    }

    /** The full user prompt for a channel. */
    public static function for(string $channel, array $ctx): string
    {
        $head = self::brandContext() . "\n" . self::brief($ctx) . "\n";
        $skills = self::hermesBlock($ctx);

        return $head . $skills . match ($channel) {
            'social' => self::socialSpec($ctx),
            'blog'   => self::blogSpec($ctx),
            'email'  => self::emailSpec($ctx),
            default  => self::socialSpec($ctx),
        };
    }

    /**
     * Optional Hermes skills block, opt-in via $ctx['skills'] = ['youtube-auto', ...].
     * Empty by default so existing prompts/tests are unchanged.
     */
    public static function hermesBlock(array $ctx): string
    {
        $names = $ctx['skills'] ?? [];
        if (!is_array($names) || $names === [] || !class_exists('HermesSkills')) {
            return '';
        }
        try {
            $block = HermesSkills::promptBlock(array_values(array_map('strval', $names)), 1500);
            return $block !== '' ? $block . "\n" : '';
        } catch (Throwable) {
            return '';
        }
    }

    // ------------------------------------------------------------- social ---

    private static function socialSpec(array $ctx): string
    {
        $platforms = (array) ($ctx['platforms'] ?? array_keys((array) config('channels.social.platforms', [])));
        $count     = max(1, (int) ($ctx['count'] ?? count($platforms)));
        $limits    = (array) config('channels.social.platforms', []);

        $rows = [];
        foreach ($platforms as $p) {
            $limit = (int) ($limits[$p]['limit'] ?? 280);
            $rows[] = "- {$p}: hard limit {$limit} characters including hashtags";
        }
        $list = $rows === [] ? "- twitter: 280 characters" : implode("\n", $rows);

        return "## Channels\n{$list}\n\n"
             . "## Output format (follow exactly)\n"
             . "Write {$count} variant(s). Separate every variant with a line containing only three dashes.\n"
             . "For each variant use:\n"
             . "=== POST | platform: <platform> ===\n"
             . "<post copy — one hook line, blank line, body>\n"
             . "hashtags: <space-separated hashtags, no line breaks>\n\n"
             . "The header line and the `hashtags:` line are mandatory.";
    }

    // --------------------------------------------------------------- blog ---

    private static function blogSpec(array $ctx): string
    {
        $count = max(1, (int) ($ctx['count'] ?? 1));

        return "## Output format (follow exactly)\n"
             . "Write {$count} article(s).\n\n"
             . "TITLE: <60 characters, benefit-led, no clickbait>\n"
             . "SLUG: <lowercase-hyphenated-slug>\n"
             . "EXCERPT: <140-160 characters for a search snippet>\n"
             . "TAGS: <3-6 comma separated keywords>\n"
             . "---\n"
             . "<article body in Markdown>\n"
             . "- 3-5 H2 sections, each answering one question\n"
             . "- 2-4 short paragraphs per section\n"
             . "- one concrete example in at least two sections\n"
             . "- end with a short CTA paragraph using the brand CTA\n\n"
             . "Separate further articles with a line containing only three dashes.";
    }

    // -------------------------------------------------------------- email ---

    private static function emailSpec(array $ctx): string
    {
        $count = max(1, (int) ($ctx['count'] ?? 1));

        return "## Output format (follow exactly)\n"
             . "Write {$count} email variant(s).\n\n"
             . "SUBJECT: <45 characters max, specific, no spam words>\n"
             . "PREHEADER: <60-90 characters that complement the subject>\n"
             . "HEADLINE: <the first line inside the email>\n"
             . "CTA: <verb-led button text, e.g. Start your free week>\n"
             . "---\n"
             . "<email body in Markdown: greeting, 2-3 short paragraphs, bullet list, closing>\n\n"
             . "Separate further variants with a line containing only three dashes.";
    }

    // ------------------------------------------------------------- agent ----

    /** System prompt for one FlowAgent step. */
    public static function agentSystem(string $step): string
    {
        $base = self::system() . "\n\n" . self::brandContext();

        return match ($step) {
            'brief' => $base . "\nYou extract a tight creative brief from a rough goal. "
                          . "Output Markdown with exactly these headings: **Topic**, **Angle**, "
                          . "**Audience**, **Tone**, **Keywords** (3-6), **Do not** (1-2 constraints). "
                          . "One line per value. No preamble.",

            'outline' => $base . "\nYou plan content angles. Output a Markdown outline with: "
                            . "a `## Hooks` list of 3 punchy opening lines, a `## Angles` list of "
                            . "3 distinct takes, and a `## Article skeleton` with 4-5 H2 headings. "
                            . "No preamble.",

            'review' => $base . "\nYou are a demanding editor. First line must be exactly "
                          . "`SCORE: <0-100>`. Then `## Fixes` with exactly 3 bullet points of "
                          . "concrete, specific improvements. Then `## Why` with one sentence. "
                          . "No other output.",
            default  => $base,
        };
    }

    /** Route the agent to the right channel spec for drafting steps. */
    public static function agentDraft(string $step, array $ctx): string
    {
        return self::for($step, $ctx);
    }
}
