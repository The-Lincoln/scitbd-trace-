<?php
/**
 * Unified Skills Index - Aggregates all skills from external repos
 */
return [
    // LLPhant libraries
    'libraries' => [
        'LLPhant' => 'PHP LLM library supporting OpenAI, Anthropic, Ollama, LLaMA',
        'phpML' => 'PHP Machine Learning library (KMeans, DataAnalyzer, LinearRegression)',
        'Rubix ML' => 'Machine learning library for PHP',
    ],
    // Awesome AI PHP libraries
    'ai_php' => [
        'Neuron AI', 'LLPhant', 'TransformersPHP', 'openai-php/client',
        'Rubix ML', 'MLPHP', 'PhpMl', 'TensorFlow PHP',
    ],
    // Browserbase skills
    'browserbase' => [
        'add-webmcp', 'agent-experience', 'autobrowse', 'browser',
        'browser-to-api', 'browser-trace', 'browser-use-to-stagehand',
        'company-research', 'competitor-analysis', 'cookie-sync',
    ],
    // HuggingFace skills
    'huggingface' => [
        'hf-cli', 'hf-cloud-aws-context-discovery', 'hf-cloud-python-env-setup',
        'hf-cloud-sagemaker-deployment-planner', 'hf-cloud-sagemaker-iam-preflight',
        'hf-cloud-sagemaker-production-defaults', 'hf-cloud-serving-image-selection',
        'hf-mem', 'huggingface-best', 'huggingface-community-evals',
    ],
    // OpenClaw skills categories
    'openclaw' => [
        'ai-and-llms', 'apple-apps-and-services', 'browser-and-automation',
        'calendar-and-scheduling', 'clawdbot-tools', 'cli-utilities',
        'coding-agents-and-ides', 'communication', 'data-and-analytics',
        'devops-and-cloud',
    ],
    // Agent skills references
    'agent_skills' => [
        'Anthropic skills', 'Google skills', 'Stripe skills', 'Cloudflare skills',
    ],
    // Tencent BrowserSkill (vendored: external/tencent-browserskill)
    'tencent' => [
        'browser-skill', 'tencent-browserskill',
    ],
    'browserskill_drivers' => [
        'bsk' => 'Tencent BrowserSkill (logged-in Chromium, website-debugging evidence)',
        'agent-browser' => 'vercel-labs/agent-browser (offline fallback)',
    ],
    // TencentDB Agent Memory (vendored: external/tencentdb-agent-memory)
    'tencentdb_memory' => [
        'agent-memory', 'tencentdb-agent-memory', 'chat-memory', 'skill-library',
        'llm-wiki', 'code-graph',
    ],
    // ToolJet low-code apps/dashboards/workflows (vendored: external/tooljet)
    'tooljet' => [
        'tooljet', 'apps', 'dashboards', 'workflows', 'tooljet-db',
    ],
    // Evolution API WhatsApp messaging (vendored: external/evolution-api)
    'evolution' => [
        'evolution-api', 'whatsapp', 'sendText', 'webhooks', 'qr-connect',
    ],
    // OpenMontage agentic video production (vendored: external/openmontage)
    'openmontage' => [
        'openmontage', 'video-production', 'pipelines', 'remotion', 'ffmpeg',
    ],
    // OpenViking viking:// context database (vendored: external/openviking)
    'openviking' => [
        'openviking', 'viking-fs', 'context-db', 'ov-cli', 'scoped-retrieval',
    ],
    // Social Analyzer username profiler (vendored: external/social-analyzer)
    'social_analyzer' => [
        'social-analyzer', 'username-profiler', 'profile-rating', 'metadata-patterns',
    ],
    // Scientific agent skills (vendored: external/scientific-agent-skills)
    'scientific' => [
        'scientific-skills', 'database-lookup', 'paper-lookup',
        'scientific-writing', 'visualization', 'drug-discovery', 'genomics',
    ],
    // Diagram design + ticket AI + harness reading (vendored: external/*)
    'creative_ops' => [
        'diagram-design', 'ticket-ai', 'harness-guide',
    ],
    // AI support triage + escalation (vendored: external/ai-support-operations)
    'support_ops' => [
        'ai-support-ops', 'triage', 'escalation-queue',
    ],
    // BrowserSkill mirror (The-Lincoln/BrowserSkill == Tencent/BrowserSkill)
    'browserskill_mirror' => [
        'external/browserskill-lincoln',
    ],
    // Neuron AI agentic PHP framework (vendored: external/neuron-ai)
    'neuron' => [
        'neuron-ai', 'neuron-agent', 'neuron-rag', 'neuron-tool', 'neuron-workflow',
        'neuron-structured-output', 'neuron-streaming', 'neuron-evaluation',
    ],
    // Contributor profile (vendored: external/Gokul-ram-j — people asset, not code)
    'talent' => [
        'contributor-gokul',
    ],
    // SEO content-engine reference maps (vendored: external/content-engine)
    'content_ref' => [
        'content-engine',
    ],
    // SCITBD CEO directive tier — canonical lives in ceo/.agents/skills/scitbd-*
    // App implementations: skills/skills/skill_*.php ; DB: directive_* + daily_tasks
    'scitbd_directive' => [
        'scitbd-action', 'scitbd-ads', 'scitbd-ai-analytics', 'scitbd-blog', 'scitbd-ceo',
        'scitbd-content', 'scitbd-crm', 'scitbd-cro', 'scitbd-customer-success',
        'scitbd-email', 'scitbd-icp', 'scitbd-knowledge', 'scitbd-lead-generation',
        'scitbd-lead-scanner', 'scitbd-learning', 'scitbd-market-intelligence',
        'scitbd-marketing', 'scitbd-paid-media', 'scitbd-products', 'scitbd-report',
        'scitbd-rules', 'scitbd-sales', 'scitbd-seo', 'scitbd-social',
        'scitbd-social-growth', 'scitbd-social-research', 'scitbd-strategy', 'scitbd-trigger',
    ],
    // Alias pairs (both names resolve; primary listed first)
    'aliases' => [
        'tencentdb-agent-memory <=> agent-memory',
        'tencent-browserskill <=> browserskill',
    ],
];
