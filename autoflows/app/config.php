<?php
/**
 * AutoFlows — global configuration.
 * Defaults live here; anything saved on the Settings page overrides them at
 * runtime through the `settings` table (see Helpers::config()).
 */
declare(strict_types=1);

return [
    'app_name'    => 'AutoFlows Content Studio',
    'app_short'   => 'AutoFlows',
    'app_version' => '1.0.0',

    // ---- Storage ---------------------------------------------------------
    'db_path'    => BASE_PATH . '/storage/app.db',
    'log_dir'    => BASE_PATH . '/storage/logs',
    'cache_dir'  => BASE_PATH . '/storage/cache',
    'export_dir' => BASE_PATH . '/storage/exports',
    'per_page'   => 12,

    // ---- Channels --------------------------------------------------------
    'channels' => [
        'social' => [
            'label'    => 'Social posts',
            'icon'     => 'bi-share',
            'blurb'    => 'Platform-tuned posts with hashtags and a hook.',
            'platforms' => [
                'twitter'    => ['label' => 'X / Twitter', 'limit' => 280,  'icon' => 'bi-twitter-x'],
                'linkedin'   => ['label' => 'LinkedIn',    'limit' => 3000, 'icon' => 'bi-linkedin'],
                'instagram'  => ['label' => 'Instagram',   'limit' => 2200, 'icon' => 'bi-instagram'],
                'facebook'   => ['label' => 'Facebook',    'limit' => 5000, 'icon' => 'bi-facebook'],
                'threads'    => ['label' => 'Threads',     'limit' => 500,  'icon' => 'bi-at'],
                'tiktok'     => ['label' => 'TikTok',      'limit' => 2200, 'icon' => 'bi-tiktok'],
            ],
        ],
        'blog' => [
            'label' => 'Blog post',
            'icon'  => 'bi-journal-richtext',
            'blurb' => 'SEO-aware article: title, slug, excerpt, body, tags.',
        ],
        'email' => [
            'label' => 'Marketing email',
            'icon'  => 'bi-envelope-paper',
            'blurb' => 'Subject, preheader, headline, body and a single CTA.',
        ],
    ],

    'statuses' => ['draft', 'approved', 'scheduled', 'published', 'archived'],

    // ---- TinyLLM chat / agent -------------------------------------------
    // provider: ollama | local
    'chat' => [
        'provider' => getenv('AF_CHAT_PROVIDER') ?: 'ollama',
        'providers' => [
            'ollama' => [
                'label'    => 'TinyLLM via Ollama (local)',
                'endpoint' => rtrim((string) (getenv('OLLAMA_HOST') ?: 'http://127.0.0.1:11434'), '/'),
                'models'   => [
                    'tinyllama' => 'TinyLLM 1.1B Chat (Q4_K_M, ~0.6 GB)',
                    'gemma3:1b' => 'Gemma 3 1B (compact fallback)',
                    'qwen2.5:1.5b' => 'Qwen 2.5 1.5B (stronger writing)',
                ],
                'model'    => getenv('OLLAMA_MODEL') ?: 'tinyllama',
                'num_ctx'  => 4096,
                'timeout'  => 180,
            ],
            'local' => [
                'label' => 'Built-in template engine (offline)',
                'model' => 'template-engine-v1',
            ],
        ],
        'defaults' => [
            'temperature' => 0.75,
            'top_p'       => 0.9,
            'top_k'       => 40,
            'repeat_pen'  => 1.1,
            'num_predict' => 768,
            'system'      => 'You are AutoFlows, a senior content-marketing assistant running '
                           . 'locally on TinyLLM. You write daily social posts, blog articles and '
                           . 'marketing emails. Be concrete, specific and skimmable. Use Markdown. '
                           . 'Never invent statistics you cannot source, and never claim to browse '
                           . 'the internet.',
        ],
    ],

    // ---- Brand voice (overridden from Settings) ---------------------------
    'brand' => [
        'name'     => 'Acme Studio',
        'voice'    => 'friendly, practical, lightly witty; short sentences; no hype',
        'audience' => 'founders and small marketing teams',
        'products' => 'a content-automation toolkit',
        'links'    => 'https://example.com',
        'cta'      => 'Start your free week',
        'emoji'    => 1,
        'hashtags' => 1,
    ],

    // ---- Agent ------------------------------------------------------------
    'agent' => [
        'name'   => 'FlowAgent',
        'steps'  => ['brief', 'outline', 'social', 'blog', 'email', 'review'],
        'score_threshold' => 70,
    ],

    // ---- Auth / Social login ----------------------------------------------
    // Session user. Login is optional for local use, but connecting a
    // Gmail or Facebook account is what unlocks real publishing.
    'auth' => [
        'enabled'       => true,
        'session_key'   => 'af_uid',
        // When true and no OAuth keys are configured, the login page offers
        // one-click dev accounts so the flow can be tried offline.
        'allow_dev_login' => true,
    ],

    // ---- OAuth: Gmail (Google) + Facebook ----------------------------------
    // Fill from Settings page (DB wins) or env. Redirect URI is auto-derived
    // per request: {base}/index.php?r=auth/{provider}/callback
    'oauth' => [
        'google' => [
            'label'         => 'Google (Gmail)',
            'icon'          => 'bi-google',
            'client_id'     => getenv('GOOGLE_CLIENT_ID') ?: '',
            'client_secret' => getenv('GOOGLE_CLIENT_SECRET') ?: '',
            'scopes'        => [
                'openid', 'email', 'profile',
                'https://www.googleapis.com/auth/gmail.send',
            ],
            'auth_url'      => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token_url'     => 'https://oauth2.googleapis.com/token',
            'userinfo_url'  => 'https://www.googleapis.com/oauth2/v3/userinfo',
        ],
        'facebook' => [
            'label'         => 'Facebook',
            'icon'          => 'bi-facebook',
            'app_id'        => getenv('FACEBOOK_APP_ID') ?: '',
            'app_secret'    => getenv('FACEBOOK_APP_SECRET') ?: '',
            'graph_version' => 'v18.0',
            'scopes'        => ['public_profile', 'email', 'pages_manage_posts', 'pages_read_engagement'],
            'auth_url'      => 'https://www.facebook.com/v18.0/dialog/oauth',
            'token_url'     => 'https://graph.facebook.com/v18.0/oauth/access_token',
        ],
        // Optional shared secret for token obfuscation at rest.
        // Falls back to base64 when empty / openssl missing.
        'seal_key' => getenv('AF_SEAL_KEY') ?: '',
    ],

    // ---- Slack ------------------------------------------------------------
    // Workspace T0AGURY3K1D. App token (xapp, Socket/Events) + Bot token
    // (xoxb, chat.postMessage) stored sealed via Settings (DB wins) or env.
    'slack' => [
        'workspace_id' => 'T0AGURY3K1D',
        'workspace_url' => 'https://app.slack.com/client/T0AGURY3K1D',
        'agent_dm_id' => 'D0BDHAC8RJL',
        'bot_token' => getenv('SLACK_BOT_TOKEN') ?: '',
        'app_token' => getenv('SLACK_APP_TOKEN') ?: '',
        'signing_secret' => getenv('SLACK_SIGNING_SECRET') ?: '',
        'default_channel' => getenv('SLACK_DEFAULT_CHANNEL') ?: '#general',
    ],

    // ---- Browser automation (cloud) ----------------------------------------
    // BROWSER_USE_API_KEY (bu_…) powers BrowserUse Cloud + agent-browser -p browseruse.
    // Set in trace/.env (loaded by BrowserUseKey) or real env. Never commit .env.
    'browser' => [
        'use_api_key' => getenv('BROWSER_USE_API_KEY') ?: '',
        'provider' => getenv('AGENT_BROWSER_PROVIDER') ?: 'local', // local|browseruse
    ],

    // ---- Team memory hub (TencentDB Agent Memory) ---------------------------
    // Stack: external/tencentdb-agent-memory/deploy/global-images/./start-all.sh
    // core :8420 (memory /v3/…), knowledge :8424 (/v3/tools/*), proxy :8096, panel :8125.
    // Env wins; empty service_id/user_key = local-cache mode (agents keep working).
    'memory' => [
        'core_url' => getenv('MEMORY_CORE_URL') ?: 'http://127.0.0.1:8420',
        'knowledge_url' => getenv('MEMORY_KNOWLEDGE_URL') ?: 'http://127.0.0.1:8424/v3',
        'proxy_url' => getenv('MEMORY_PROXY_URL') ?: 'http://127.0.0.1:8096',
        'panel_url' => getenv('MEMORY_PANEL_URL') ?: 'http://127.0.0.1:8125',
        'service_id' => getenv('MEMORY_SERVICE_ID') ?: '',
        'gateway_key' => getenv('MEMORY_GATEWAY_KEY') ?: '',
        'user_key' => getenv('TDAI_MEMORY_KEY') ?: '',
        'team' => getenv('MEMORY_TEAM') ?: 'scitbd',
        'user' => getenv('MEMORY_USER') ?: 'scitbd-operator',
    ],

    // ---- Low-code dashboards/apps (ToolJet) ----------------------------------
    // Self-host: docker run -p 80:80 tooljet/try:ee-lts-latest (docs.tooljet.com).
    // Env wins; empty token/slugs = panels show setup card, agents keep working.
    'tooljet' => [
        'host' => getenv('TOOLJET_HOST') ?: 'http://127.0.0.1',
        'api_token' => getenv('TOOLJET_API_TOKEN') ?: '',
        'workspace' => getenv('TOOLJET_WORKSPACE_ID') ?: '',
        'app_ceo' => getenv('TOOLJET_APP_CEO') ?: '',
        'app_sccrm' => getenv('TOOLJET_APP_SCCRM') ?: '',
        'app_trace' => getenv('TOOLJET_APP_TRACE') ?: '',
        'webhook_lead' => getenv('TOOLJET_WORKFLOW_LEAD') ?: '',
        'webhook_trace' => getenv('TOOLJET_WORKFLOW_TRACE') ?: '',
    ],

    // ---- WhatsApp messaging (Evolution API) ----------------------------------
    // Self-host: docker run -p 8080:8080 evoapicloud/evolution-api:latest
    // Auth: `apikey` header = global key or per-instance token (token wins).
    // Env wins; empty keys = senders return ok=false, agents keep working.
    'evolution' => [
        'base_url' => getenv('EVOLUTION_API_URL') ?: 'http://127.0.0.1:8080',
        'api_key' => getenv('EVOLUTION_API_KEY') ?: '',
        'instance' => getenv('EVOLUTION_INSTANCE') ?: 'scitbd',
        'instance_token' => getenv('EVOLUTION_INSTANCE_TOKEN') ?: '',
        'ceo_number' => getenv('EVOLUTION_CEO_NUMBER') ?: '',
    ],

    // ---- Agentic video production (OpenMontage) ------------------------------
    // Vendor: external/openmontage (AGENT_GUIDE.md contract). Requests scaffold
    // data/video-projects/<slug>/brief.md; renders run in the checkout.
    // Provider keys live in external/openmontage/.env (never here).
    'openmontage' => [
        'projects_dir' => getenv('OPENMONTAGE_PROJECTS') ?: '',
        'budget_cap_usd' => getenv('OPENMONTAGE_BUDGET_CAP') ?: '10',
        'approval_mode' => getenv('OPENMONTAGE_APPROVAL') ?: 'gated',
    ],

    // ---- Context database (OpenViking viking://) ------------------------------
    // Install: curl -fsSL https://openviking.ai/install | bash -s -- --yes --url <SERVER_URL>
    // Auth needs a USER key (root keys can't read/write memories).
    // Empty URL/key = wrappers return ok=false, agents use AgentMemory/local fallback.
    'openviking' => [
        'server_url' => getenv('OPENVIKING_URL') ?: '',
        'api_key' => getenv('OPENVIKING_API_KEY') ?: '',
    ],

    // ---- Scientific skills catalog (file-based, no daemon) --------------------
    // Vendor: external/scientific-agent-skills (177 SKILL.md). No keys needed
    // for files; per-skill deps install via uv; upstream API keys per skill.
    'sciskills' => [
        'enabled' => getenv('SCISKILLS_ENABLED') ?: '1',
    ],

    // ---- Ticket AI drafts (TinyLLM-first, OpenAI-compatible override) --------
    // Pattern: external/ai-response-generator. Drafts only — agent sends.
    'ticket_ai' => [
        'api_url' => getenv('TICKET_AI_API_URL') ?: '',
        'api_key' => getenv('TICKET_AI_API_KEY') ?: '',
        'model' => getenv('TICKET_AI_MODEL') ?: '',
        'system' => getenv('TICKET_AI_SYSTEM') ?: '',
    ],

    // ---- Publishing ---------------------------------------------------------
    'publish' => [
        // When true and no real token exists, publishing is simulated and
        // recorded in content.meta.publish_result = {simulated:true,…}.
        'simulate_when_offline' => true,
        'default_gmail_to'      => getenv('AF_GMAIL_TO') ?: '',
        'facebook_default_visibility' => 'SELF',
    ],
];
