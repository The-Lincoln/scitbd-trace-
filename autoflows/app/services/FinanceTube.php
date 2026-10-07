<?php
/**
 * FinanceTube — faceless finance YouTube agent for AutoFlows.
 *
 * Lightweight OpenAI-compatible generator for high-CPM faceless finance
 * video concepts (Titles, Hooks, Script Outlines). Offline-first like the
 * rest of AutoFlows: no key / no network still yields deterministic
 * template concepts so the CLI never dead-ends.
 *
 * Storage: `agent_logs` table inside storage/app.db (same contract as the
 * standalone agent.db pattern) + one `content` row per concept
 * (channel=youtube, platform=youtube, status=draft) so ideas are editable
 * before they ship.
 */
declare(strict_types=1);

final class FinanceTube
{
    public const NICHE = 'Faceless Finance';

    public static function systemPrompt(): string
    {
        $base = 'You are Digital Maker AI, an expert content strategist for faceless YouTube automation channels. '
            . 'Your focus is the Finance & Wealth niche. Generate viral, high-CPM video concepts, script outlines, '
            . 'and title hooks optimized for faceless channels (using stock footage, motion graphics, and voiceovers).';
        if (class_exists('HermesSkills')) {
            try {
                $block = HermesSkills::promptBlock(['youtube-auto', 'marketing-seo-specialist', 'finance-investment-researcher'], 1500);
                if ($block !== '') {
                    $base .= "\n\n" . $block;
                }
            } catch (Throwable) {
                // skills are enhancement-only; never break generation
            }
        }
        return $base;
    }

    public static function userQuery(int $count = 3, string $angle = ''): string
    {
        $angle = trim($angle);
        $q = "Generate {$count} high-CPM faceless finance video concepts (Titles, Hooks, and Script Outlines) "
            . 'estimated to perform well for a faceless YouTube automation channel.';
        if ($angle !== '') {
            $q .= " Angle / newsletter theme: {$angle}.";
        }
        $q .= ' Format each concept exactly as: TITLE: <title> | HOOK (first 15s): <hook> | SCRIPT OUTLINE: <5-7 beats> | B-ROLL: <stock/motion notes> | CPM NOTE: <why high-CPM>.';
        return $q;
    }

    /** Ensure the agent_logs table exists (agent.db contract, AutoFlows path). */
    public static function ensureTable(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS agent_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                niche TEXT NOT NULL,
                prompt_used TEXT NOT NULL,
                response TEXT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )'
        );
    }

    /**
     * Call any OpenAI-compatible /chat/completions endpoint.
     *
     * @return array{ok:bool,text:string,error:?string,model:string,http:int}
     */
    public static function callOpenAI(string $system, string $user, string $model, float $temperature = 0.7, int $timeout = 60): array
    {
        $apiKey = (string) (getenv('OPENAI_API_KEY') ?: '');
        $base = rtrim((string) (getenv('OPENAI_BASE_URL') ?: getenv('OPENAI_API_BASE') ?: 'https://api.openai.com/v1'), '/');
        $url = $base . '/chat/completions';

        if ($apiKey === '' || $apiKey === 'YOUR_API_KEY_HERE') {
            return ['ok' => false, 'text' => '', 'error' => 'OPENAI_API_KEY not set', 'model' => $model, 'http' => 0];
        }

        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
            'temperature' => $temperature,
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
        ]);
        $body = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($body === false || $http !== 200) {
            $msg = $curlErr !== '' ? $curlErr : substr((string) $body, 0, 500);
            return ['ok' => false, 'text' => '', 'error' => "HTTP {$http}: {$msg}", 'model' => $model, 'http' => $http];
        }

        $data = json_decode((string) $body, true);
        $text = trim((string) ($data['choices'][0]['message']['content'] ?? ''));
        if ($text === '') {
            return ['ok' => false, 'text' => '', 'error' => 'Empty model reply', 'model' => $model, 'http' => $http];
        }
        return ['ok' => true, 'text' => $text, 'error' => null, 'model' => $model, 'http' => $http];
    }

    /** Deterministic offline fallback — same output contract, no network. */
    public static function localDraft(int $count = 3): string
    {
        $concepts = [
            [
                'title' => '7 Silent Money Traps Keeping You Poor (Fix #3 Today)',
                'hook' => 'You are not broke because you earn too little — you are broke because of trap #3. Stay for 8 minutes and I will prove it with numbers.',
                'outline' => "1) Cold open + promise (0:00-0:30) | 2) Trap 1: lifestyle subscriptions stacking (0:30-2:00) | 3) Trap 2: BNPL minimums (2:00-3:30) | 4) Trap 3: idle cash losing to inflation + fix with HYSA/T-bills (3:30-5:30) | 5) Traps 4-7 rapid-fire (5:30-7:00) | 6) 3-step escape plan + CTA (7:00-8:30)",
                'broll' => 'Stock: bank statements, phone notifications, red charts turning green; motion graphics: compounding bar chart, subscription stack animation.',
                'cpm' => 'High-CPM: banking, credit, HYSA keywords attract finance advertisers ($18-35 CPM).',
            ],
            [
                'title' => 'I Asked AI to Build a $1,000/Month Faceless Side Hustle (Full Plan)',
                'hook' => 'No face, no voice, $0 start. This exact 30-day finance-channel playbook made $1,000/month — here is week one.',
                'outline' => "1) Result tease + constraints (0:00-0:45) | 2) Niche pick: why finance pays 5x vlogging (0:45-2:15) | 3) 10-video content calendar (2:15-4:00) | 4) Script + voiceover + editing stack (4:00-6:00) | 5) Monetization math: views to dollars (6:00-7:30) | 6) Day-1 checklist + CTA (7:30-9:00)",
                'broll' => 'Stock: laptop desk setups, analytics dashboards; motion graphics: revenue calculator, calendar timeline, workflow diagram.',
                'cpm' => 'High-CPM: make-money + software affiliate intent; strong retention = higher RPM.',
            ],
            [
                'title' => 'The 50/30/20 Budget Is Dead — Do This Instead in 2026',
                'hook' => 'The 50/30/20 rule was built for 2019 rent. In 2026 it breaks. Here is the 3-bucket system that actually works.',
                'outline' => "1) Why 50/30/20 fails now with real numbers (0:00-1:30) | 2) New buckets: Fixed/Freedom/Future (1:30-3:00) | 3) Live paycheck walkthrough $4,500 example (3:00-5:00) | 4) Automate transfers in 10 minutes (5:00-6:30) | 5) Common mistakes + fix table (6:30-7:30) | 6) Free template + CTA (7:30-8:30)",
                'broll' => 'Stock: paycheck, budgeting app close-ups; motion graphics: pie-to-bar morph, auto-transfer flow, before/after balances.',
                'cpm' => 'High-CPM: budgeting apps, banks, credit cards bid aggressively on intent keywords.',
            ],
            [
                'title' => '5 Index Funds That Pay You While You Sleep (2026 Update)',
                'hook' => 'One $500/month habit beats a second job. These 5 funds do the heavy lifting — #4 pays quarterly like clockwork.',
                'outline' => "1) Compounding demo $500/mo x 20y (0:00-1:15) | 2) Funds 1-2: total market + S&P core (1:15-3:30) | 3) Fund 3: dividend growth (3:30-4:45) | 4) Fund 4: SCHD-style payers (4:45-6:00) | 5) Fund 5: bond buffer + allocation by age (6:00-7:15) | 6) Fees warning + CTA (7:15-8:30)",
                'broll' => 'Stock: trading screens, calm family footage; motion graphics: growth curves, fee drag comparison, payout calendar.',
                'cpm' => 'High-CPM: brokerages and fund sponsors; evergreen search traffic.',
            ],
        ];

        $count = max(1, min($count, count($concepts)));
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $c = $concepts[$i];
            $n = $i + 1;
            $out[] = "CONCEPT {$n}\n"
                . "TITLE: {$c['title']}\n"
                . "HOOK (first 15s): {$c['hook']}\n"
                . "SCRIPT OUTLINE: {$c['outline']}\n"
                . "B-ROLL: {$c['broll']}\n"
                . "CPM NOTE: {$c['cpm']}";
        }
        return implode("\n\n---\n\n", $out);
    }

    /** Split concepts on `---` so each can become a content row. */
    public static function splitConcepts(string $raw): array
    {
        $parts = preg_split('/^\s*---\s*$/m', trim($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [$raw];
        return array_values(array_filter(array_map('trim', $parts), fn ($p) => $p !== ''));
    }

    public static function titleOf(string $concept): string
    {
        if (preg_match('/^.*TITLE\s*:\s*(.+)$/mi', $concept, $m)) {
            return trim(explode("\n", trim($m[1]))[0]);
        }
        $first = trim((string) (preg_split('/\R/', $concept)[0] ?? ''));
        return mb_substr($first !== '' ? $first : 'Untitled finance video', 0, 300);
    }
}
