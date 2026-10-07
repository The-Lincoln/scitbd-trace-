<?php
$db_path = __DIR__ . '/../db/scit_crm.db';
$db_dir = dirname($db_path);
if (!is_dir($db_dir)) {
    mkdir($db_dir, 0755, true);
}
try {
    $db = new PDO("sqlite:$db_path");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec("PRAGMA journal_mode=WAL");
    $db->exec("PRAGMA foreign_keys=ON");

    $db->exec("CREATE TABLE IF NOT EXISTS companies (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        industry TEXT,
        website TEXT,
        email TEXT,
        phone TEXT,
        mobile TEXT,
        address TEXT,
        city TEXT,
        state TEXT,
        zip TEXT,
        country TEXT DEFAULT 'US',
        description TEXT,
        logo TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS contacts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        company_id INTEGER,
        salutation TEXT,
        first_name TEXT NOT NULL,
        last_name TEXT NOT NULL,
        email TEXT,
        phone TEXT,
        mobile TEXT,
        position TEXT,
        department TEXT,
        linkedin TEXT,
        twitter TEXT,
        avatar TEXT,
        notes TEXT,
        is_active INTEGER DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS interactions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        contact_id INTEGER,
        company_id INTEGER,
        type TEXT NOT NULL CHECK(type IN ('call','email','meeting','note','social','other')),
        subject TEXT NOT NULL,
        content TEXT,
        date DATE NOT NULL,
        follow_up_date DATE,
        created_by TEXT DEFAULT 'System',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE SET NULL,
        FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS tasks (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        contact_id INTEGER,
        company_id INTEGER,
        title TEXT NOT NULL,
        description TEXT,
        status TEXT DEFAULT 'pending' CHECK(status IN ('pending','in_progress','completed','cancelled')),
        priority TEXT DEFAULT 'medium' CHECK(priority IN ('low','medium','high','urgent')),
        due_date DATE,
        assigned_to TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE SET NULL,
        FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS notes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        contact_id INTEGER,
        company_id INTEGER,
        title TEXT NOT NULL,
        content TEXT,
        created_by TEXT DEFAULT 'System',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE SET NULL,
        FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS markets (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        country_code TEXT,
        region TEXT,
        timezone TEXT,
        currency TEXT,
        language TEXT,
        is_active INTEGER DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS services (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        category TEXT,
        description TEXT,
        icon TEXT,
        is_active INTEGER DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS leads (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        contact_id INTEGER,
        company_id INTEGER,
        market_id INTEGER,
        service_id INTEGER,
        first_name TEXT,
        last_name TEXT,
        email TEXT,
        phone TEXT,
        company_name TEXT,
        position TEXT,
        source TEXT DEFAULT 'manual',
        status TEXT DEFAULT 'new' CHECK(status IN ('new','contacted','qualified','proposal','negotiation','won','lost')),
        priority TEXT DEFAULT 'medium' CHECK(priority IN ('low','medium','high','urgent')),
        notes TEXT,
        budget DECIMAL(15,2),
        score INTEGER DEFAULT 0,
        generated_at DATETIME,
        contacted_at DATETIME,
        converted_at DATETIME,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE SET NULL,
        FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL,
        FOREIGN KEY (market_id) REFERENCES markets(id) ON DELETE SET NULL,
        FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE SET NULL
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS lead_generation_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        leads_generated INTEGER DEFAULT 0,
        source TEXT DEFAULT 'manual',
        status TEXT DEFAULT 'success',
        error_message TEXT,
        generated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS knowledge_articles (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        service_id INTEGER,
        title TEXT NOT NULL,
        content TEXT,
        excerpt TEXT,
        tags TEXT,
        status TEXT DEFAULT 'published' CHECK(status IN ('draft','published','archived')),
        views INTEGER DEFAULT 0,
        created_by TEXT DEFAULT 'System',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE SET NULL
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS faq_categories (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        description TEXT,
        icon TEXT DEFAULT 'fa-question-circle',
        sort_order INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS faqs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        category_id INTEGER,
        service_id INTEGER,
        question TEXT NOT NULL,
        answer TEXT NOT NULL,
        sort_order INTEGER DEFAULT 0,
        is_published INTEGER DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (category_id) REFERENCES faq_categories(id) ON DELETE SET NULL,
        FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE SET NULL
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS support_tickets (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        contact_id INTEGER,
        company_id INTEGER,
        subject TEXT NOT NULL,
        message TEXT,
        department TEXT DEFAULT 'general' CHECK(department IN ('general','technical','billing','sales','support')),
        priority TEXT DEFAULT 'normal' CHECK(priority IN ('low','normal','high','urgent')),
        status TEXT DEFAULT 'open' CHECK(status IN ('open','in_progress','waiting','resolved','closed')),
        source TEXT DEFAULT 'portal',
        assigned_to TEXT,
        created_by TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE SET NULL,
        FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS support_messages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ticket_id INTEGER NOT NULL,
        sender TEXT,
        message TEXT NOT NULL,
        is_staff INTEGER DEFAULT 0,
        attachment TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (ticket_id) REFERENCES support_tickets(id) ON DELETE CASCADE
    )");

    $count = $db->query("SELECT COUNT(*) FROM companies")->fetchColumn();
    if ($count == 0) {
        $db->beginTransaction();
        try {
            $db->exec("INSERT INTO companies (name, industry, website, email, phone, city, country) VALUES 
                ('TechNova Solutions', 'Technology', 'https://technova.com', 'info@technova.com', '+1-555-0101', 'San Francisco', 'US'),
                ('GreenLeaf Analytics', 'Environmental', 'https://greenleaf.io', 'contact@greenleaf.io', '+1-555-0102', 'Portland', 'US'),
                ('Pinnacle Marketing', 'Marketing', 'https://pinnaclemarketing.com', 'hello@pinnaclemarketing.com', '+1-555-0103', 'New York', 'US'),
                ('DataStream Systems', 'Data Analytics', 'https://datastreamsys.com', 'support@datastreamsys.com', '+1-555-0104', 'Austin', 'US'),
                ('CloudBase Technologies', 'Cloud Computing', 'https://cloudbase.tech', 'info@cloudbase.tech', '+1-555-0105', 'Seattle', 'US')
            ");
            $db->exec("INSERT INTO contacts (company_id, first_name, last_name, email, phone, position, department) VALUES 
                (1, 'Alice', 'Johnson', 'alice@technova.com', '+1-555-1001', 'CEO', 'Executive'),
                (1, 'Bob', 'Smith', 'bob@technova.com', '+1-555-1002', 'CTO', 'Engineering'),
                (2, 'Carol', 'Williams', 'carol@greenleaf.io', '+1-555-2001', 'VP of Sales', 'Sales'),
                (3, 'David', 'Brown', 'david@pinnaclemarketing.com', '+1-555-3001', 'Marketing Director', 'Marketing'),
                (4, 'Eve', 'Davis', 'eve@datastreamsys.com', '+1-555-4001', 'Product Manager', 'Product'),
                (5, 'Frank', 'Miller', 'frank@cloudbase.tech', '+1-555-5001', 'Lead Engineer', 'Engineering'),
                (2, 'Grace', 'Wilson', 'grace@greenleaf.io', '+1-555-2002', 'Analyst', 'Research'),
                (3, 'Henry', 'Taylor', 'henry@pinnaclemarketing.com', '+1-555-3002', 'Designer', 'Creative')
            ");
            $db->exec("INSERT INTO interactions (contact_id, company_id, type, subject, content, date) VALUES 
                (1, 1, 'meeting', 'Initial Discovery Call', 'Discussed their cloud migration needs and timeline for Q3.', '2026-06-15'),
                (3, 2, 'email', 'Proposal Follow-up', 'Sent revised proposal with updated pricing structure.', '2026-06-18'),
                (4, 3, 'call', 'Quarterly Review', 'Reviewed Q2 performance metrics and discussed Q3 campaign goals.', '2026-06-20'),
                (5, 4, 'meeting', 'Product Demo', 'Demonstrated new analytics dashboard features to the team.', '2026-06-22'),
                (2, 1, 'email', 'Technical Requirements', 'Shared technical specifications for the integration project.', '2026-06-23'),
                (7, 2, 'note', 'Research Findings', 'Compiled competitor analysis and market research data.', '2026-06-25'),
                (6, 5, 'social', 'LinkedIn Engagement', 'Engaged with their post about cloud infrastructure trends.', '2026-06-26')
            ");
            $db->exec("INSERT INTO tasks (contact_id, company_id, title, description, status, priority, due_date) VALUES 
                (1, 1, 'Prepare Contract Draft', 'Draft the service agreement for TechNova Solutions.', 'in_progress', 'high', '2026-07-10'),
                (3, 2, 'Schedule Follow-up Meeting', 'Book a meeting to finalize the partnership deal.', 'pending', 'medium', '2026-07-05'),
                (4, 3, 'Create Campaign Assets', 'Design social media assets for the Q3 campaign.', 'pending', 'high', '2026-07-15'),
                (NULL, 4, 'DataStream Onboarding', 'Complete the onboarding process for new client DataStream.', 'in_progress', 'urgent', '2026-07-08'),
                (6, 5, 'Deploy Cloud Infrastructure', 'Set up staging environment for testing deployments.', 'completed', 'high', '2026-06-30'),
                (2, 1, 'Security Audit Review', 'Review the security audit findings and plan remediation.', 'pending', 'high', '2026-07-20'),
                (5, 4, 'API Documentation', 'Write documentation for the new API endpoints.', 'in_progress', 'medium', '2026-07-12')
            ");
            $db->exec("INSERT INTO markets (name, country_code, region, timezone, currency, language) VALUES 
                ('Bangladesh', 'BD', 'South Asia', 'Asia/Dhaka', 'BDT', 'Bengali'),
                ('United States', 'US', 'North America', 'America/New_York', 'USD', 'English'),
                ('China', 'CN', 'East Asia', 'Asia/Shanghai', 'CNY', 'Mandarin'),
                ('United Kingdom', 'GB', 'Europe', 'Europe/London', 'GBP', 'English'),
                ('Dubai (UAE)', 'AE', 'Middle East', 'Asia/Dubai', 'AED', 'Arabic'),
                ('Australia', 'AU', 'Oceania', 'Australia/Sydney', 'AUD', 'English')
            ");
            $db->exec("INSERT INTO services (name, category, description, icon) VALUES 
                ('ICT Consultancy & Digital Transformation', 'Consulting', 'Strategy, ERP, Cloud, E-Gov solutions', 'fa-sitemap'),
                ('Website Development', 'Development', 'Corporate, E-commerce, LMS, News Portals', 'fa-globe'),
                ('Custom Software Development', 'Development', 'ERP, HRM, Hospital, POS, CRM, School Systems', 'fa-code'),
                ('Mobile App Development', 'Development', 'Android/iOS, GPS, Telemedicine, E-commerce', 'fa-mobile-alt'),
                ('E-Learning & LMS', 'Education', 'Live class, exams, certificates, course monetization', 'fa-graduation-cap'),
                ('AI & Data Solutions', 'AI', 'GPT-4 chatbots, NLP, face recognition, analytics', 'fa-robot'),
                ('Cybersecurity & IT Support', 'Security', 'Pen testing, WAF, AMC contracts', 'fa-shield-alt'),
                ('Digital Commerce & Payment Systems', 'E-Commerce', 'Stripe, bKash, PayPal, e-wallet integration', 'fa-credit-card'),
                ('Digital Marketing & Branding', 'Marketing', 'SEO, Google/Facebook Ads, TikTok, Motion', 'fa-bullhorn'),
                ('Government & Enterprise Solutions', 'Enterprise', 'e-Procurement, Biometric, Smart ID systems', 'fa-university'),
                ('ICT Consultancy & Cloud Strategy', 'Consulting', 'Cloud migration, infrastructure planning, IT strategy', 'fa-cloud'),
                ('Native & Cross-Platform Mobile Applications', 'Development', 'Flutter, React Native, Swift, Kotlin apps', 'fa-app-store-ios'),
                ('AI Solutions & Intelligent Automation', 'AI', 'RPA, intelligent agents, process automation', 'fa-cogs'),
                ('Cybersecurity & Compliance', 'Security', 'ISO 27001, GDPR, PCI DSS, security audits', 'fa-lock'),
                ('Digital Audits', 'Audit', 'IT audit, security audit, compliance audit', 'fa-clipboard-check'),
                ('Machine Learning (ML)', 'AI', 'Predictive models, recommendation engines, NLP', 'fa-brain'),
                ('AI Agent Development', 'AI', 'Autonomous AI agents, multi-agent systems, LLM agents', 'fa-user-astronaut')
            ");
            $db->exec("INSERT INTO knowledge_articles (service_id, title, content, excerpt, tags, status) VALUES 
                (1, 'Digital Transformation Roadmap Guide', 'A comprehensive guide to digital transformation for enterprises. Covers strategy development, technology stack selection, change management, and KPI tracking. Key phases include assessment, planning, implementation, and optimisation.\n\n## Phase 1: Assessment\nEvaluate current infrastructure, identify gaps, and define transformation goals.\n\n## Phase 2: Planning\nCreate a roadmap with milestones, resource allocation, and risk mitigation.\n\n## Phase 3: Implementation\nExecute the plan with agile methodology, continuous integration, and stakeholder communication.\n\n## Phase 4: Optimisation\nMonitor KPIs, gather feedback, and iterate for continuous improvement.', 'Step-by-step roadmap for enterprise digital transformation covering assessment to optimisation.', 'digital transformation, strategy, roadmap, enterprise', 'published'),
                (2, 'Building High-Performance Websites', 'Best practices for building websites that load fast, rank high, and convert visitors.\n\n## Performance Optimisation\n- Use CDN and caching strategies\n- Optimise images and assets\n- Implement lazy loading\n- Minify CSS, JS, and HTML\n\n## SEO Best Practices\n- Semantic HTML structure\n- Meta tags and Open Graph\n- Structured data (Schema.org)\n- Mobile-first responsive design\n\n## Security\n- HTTPS everywhere\n- XSS and CSRF protection\n- Regular security audits\n- Input validation and sanitisation', 'Best practices for fast, SEO-friendly, and secure website development.', 'website, performance, SEO, security, best practices', 'published'),
                (5, 'Setting Up Your LMS Platform', 'Complete guide to deploying and configuring your Learning Management System.\n\n## Initial Setup\n- Domain configuration\n- SSL certificate installation\n- Database setup and migration\n- Admin account creation\n\n## Course Creation\n- Create categories and courses\n- Upload video lectures\n- Configure quizzes and assignments\n- Set up grading system\n\n## Student Management\n- Registration workflow\n- Enrollment tracking\n- Progress monitoring\n- Certificate generation\n\n## Monetisation\n- Payment gateway integration (Stripe, bKash, PayPal)\n- Subscription plans\n- One-time course purchases\n- Coupon and discount system', 'Step-by-step LMS deployment guide from server setup to course monetisation.', 'LMS, e-learning, Moodle, courses, certificates', 'published'),
                (6, 'GPT-4 Chatbot Integration Handbook', 'Technical guide for integrating GPT-4 powered chatbots into your business.\n\n## API Integration\n- OpenAI API key setup\n- Endpoint configuration\n- Rate limiting and error handling\n- Streaming responses\n\n## Customisation\n- System prompts and persona\n- Knowledge base injection\n- Conversation context management\n- Multi-language support\n\n## Deployment\n- Web widget integration\n- WhatsApp/Messenger integration\n- Website live chat\n- Analytics and monitoring', 'Technical handbook for integrating and deploying GPT-4 chatbot solutions.', 'GPT-4, chatbot, AI, NLP, integration', 'published'),
                (8, 'Payment Gateway Integration Guide', 'How to integrate Stripe, bKash, PayPal, and e-wallet solutions.\n\n## Stripe\n- Create Stripe account and API keys\n- Payment Intent API workflow\n- Webhook handling\n- Refund management\n\n## bKash\n- Merchant account setup\n- Tokenised checkout API\n- IPN (Instant Payment Notification)\n- Settlement and reconciliation\n\n## PayPal\n- REST API integration\n- PayPal Checkout\n- Subscription payments\n- Dispute handling\n\n## Unified Checkout\n- Single payment form with multiple gateways\n- Fallback mechanisms\n- Transaction logging\n- Receipt generation', 'Complete payment gateway integration guide for Stripe, bKash, PayPal, and more.', 'payment, Stripe, bKash, PayPal, e-wallet, integration', 'published'),
                (10, 'Smart ID & Biometric Systems Implementation', 'Implementation guide for government-grade identity systems.\n\n## System Architecture\n- Centralised vs distributed deployment\n- Database design for biometric data\n- API gateway and microservices\n- High availability and failover\n\n## Biometric Integration\n- Fingerprint scanner SDK\n- Facial recognition API\n- Iris scanning setup\n- Multi-factor authentication\n\n## Security & Compliance\n- Data encryption at rest and in transit\n- GDPR and local regulation compliance\n- Audit logging\n- Access control and role management', 'Technical implementation guide for biometric and smart ID systems.', 'biometric, smart ID, e-government, security, identity', 'published'),
                (14, 'ISO 27001 Compliance Checklist', 'Complete checklist for achieving ISO 27001 certification.\n\n## Information Security Policy\n- Define scope and objectives\n- Management commitment\n- Policy documentation\n\n## Risk Assessment\n- Asset identification\n- Threat and vulnerability analysis\n- Risk treatment plan\n- Statement of Applicability\n\n## Controls Implementation\n- Access control\n- Cryptography\n- Physical security\n- Operations security\n- Communications security\n\n## Continuous Improvement\n- Internal audits\n- Management review\n- Corrective actions\n- Annual surveillance', 'Comprehensive ISO 27001 compliance checklist for cybersecurity certification.', 'ISO 27001, compliance, security, audit, certification', 'published'),
                (17, 'Building Autonomous AI Agents', 'Guide to designing and deploying AI agents for business automation.\n\n## Architecture Overview\n- Agent loop and decision making\n- Tool use and function calling\n- Memory and context management\n- Multi-agent coordination\n\n## Implementation\n- LLM integration (GPT-4, Claude, Llama)\n- Custom tool development\n- Knowledge retrieval (RAG)\n- Error handling and recovery\n\n## Use Cases\n- Customer support automation\n- Data analysis and reporting\n- Workflow automation\n- Research and summarisation\n\n## Best Practices\n- Prompt engineering\n- Safety guardrails\n- Performance monitoring\n- Cost optimisation', 'Technical guide for designing and deploying autonomous AI agent systems.', 'AI agents, autonomous, LLM, GPT-4, automation', 'published')
            ");
            $db->exec("INSERT INTO faq_categories (name, description, icon, sort_order) VALUES 
                ('General', 'General questions about SCIT services', 'fa-building', 1),
                ('Web Development', 'Website and web application FAQs', 'fa-globe', 2),
                ('Mobile Apps', 'Mobile app development FAQs', 'fa-mobile-alt', 3),
                ('AI & Data', 'AI solutions and data analytics FAQs', 'fa-robot', 4),
                ('Cybersecurity', 'Security services and compliance FAQs', 'fa-shield-alt', 5),
                ('Pricing & Support', 'Pricing, payment, and customer support FAQs', 'fa-credit-card', 6)
            ");
            $db->exec("INSERT INTO faqs (category_id, service_id, question, answer, sort_order) VALUES 
                (1, NULL, 'What services does SCIT offer?', 'SCIT offers 17 core services across Consulting, Development, AI, Security, Marketing, E-Commerce, Enterprise, Education, and Audit categories. Key services include ICT Consultancy, Website Development, Custom Software, Mobile Apps, E-Learning, AI Solutions, Cybersecurity, Digital Commerce, Digital Marketing, and Government Solutions.', 1),
                (1, NULL, 'Which markets do you serve?', 'We serve 6 key markets: Bangladesh, United States, China, United Kingdom, Dubai (UAE), and Australia. Our team works across time zones to provide 24/7 support.', 2),
                (1, NULL, 'How can I get a quote for my project?', 'You can request a quote by creating a lead in our system, contacting us through the support chat, or emailing our sales team directly. We typically respond within 24 hours with a detailed proposal.', 3),
                (2, 2, 'How long does it take to build a corporate website?', 'A standard corporate website typically takes 2-4 weeks. E-commerce platforms take 4-8 weeks depending on complexity. We follow an agile process with weekly milestone reviews.', 1),
                (2, 2, 'Do you provide hosting and maintenance?', 'Yes, we offer hosting solutions and Annual Maintenance Contracts (AMC) that include security updates, backups, performance monitoring, and content updates.', 2),
                (3, 4, 'Which platforms do you develop for?', 'We develop native apps for Android (Kotlin) and iOS (Swift), as well as cross-platform apps using Flutter and React Native for cost-effective solutions.', 1),
                (3, 4, 'How do you handle app store submission?', 'We manage the entire app store submission process including preparing screenshots, writing descriptions, meeting guidelines, and handling rejection appeals.', 2),
                (4, 6, 'Can ChatGPT be integrated with our existing systems?', 'Yes, we integrate GPT-4 and other LLMs via REST APIs that connect to your existing CRM, ERP, or customer support platforms. We handle authentication, rate limiting, and context management.', 1),
                (4, 17, 'What are AI Agents and how can they help my business?', 'AI Agents are autonomous programs that can perform tasks, make decisions, and interact with users without constant human input. They can automate customer support, data analysis, report generation, and workflow processes.', 2),
                (5, 7, 'What does a penetration test include?', 'Our penetration testing covers network infrastructure, web applications, mobile apps, API endpoints, and social engineering. We provide a detailed report with findings, risk ratings, and remediation steps.', 1),
                (5, 14, 'How long does ISO 27001 certification take?', 'The certification process typically takes 3-6 months depending on organization size and readiness. We guide you through gap analysis, risk assessment, control implementation, and audit preparation.', 2),
                (6, NULL, 'What payment methods do you accept?', 'We accept payments via bank transfer, Stripe (credit/debit cards), PayPal, bKash (Bangladesh), and cryptocurrency (USDT/BTC). We offer flexible payment terms for long-term projects.', 1),
                (6, NULL, 'What is your support response time?', 'Our support team responds within 1 hour for urgent issues, 4 hours for high priority, and 24 hours for normal requests. Enterprise clients get a dedicated account manager and priority support.', 2)
            ");
            $db->exec("INSERT INTO support_tickets (contact_id, company_id, subject, message, department, priority, status, created_by) VALUES 
                (1, 1, 'Cloud Migration Timeline Query', 'We need clarity on the migration timeline for our cloud infrastructure project. Can we accelerate the Q3 timeline to start in August?', 'technical', 'high', 'in_progress', 'Alice Johnson'),
                (3, 2, 'Proposal Revision Request', 'We reviewed the proposal and need some adjustments to the pricing structure for the analytics package.', 'sales', 'normal', 'open', 'Carol Williams'),
                (5, 4, 'API Documentation Access', 'The new API documentation links are not accessible. Please provide the correct credentials for the developer portal.', 'technical', 'high', 'open', 'Eve Davis'),
                (6, 5, 'Deployment Status Update', 'Could you provide an update on the staging environment deployment? We need this for our client demo on Friday.', 'support', 'urgent', 'in_progress', 'Frank Miller'),
                (2, 1, 'Invoice #INV-2026-0042', 'We noticed a discrepancy in the invoice for the security audit. The billed hours do not match the report.', 'billing', 'normal', 'open', 'Bob Smith')
            ");
            $db->exec("INSERT INTO support_messages (ticket_id, sender, message, is_staff) VALUES 
                (1, 'Alice Johnson', 'We would like to move the cloud migration start date to August 1st instead of September. Is that feasible?', 0),
                (1, 'SCIT Support', 'Thank you for reaching out. We can accommodate an August 1st start date. Our team will need to adjust resource allocation, which may require a 10% acceleration fee. Would you like to proceed with this?', 1),
                (1, 'Alice Johnson', 'Yes, please proceed. Can you send an updated proposal with the revised timeline and costs?', 0),
                (2, 'Carol Williams', 'The analytics package pricing seems higher than market rate. Can we negotiate a 15% discount for a 2-year commitment?', 0),
                (4, 'Frank Miller', 'This is urgent. Our client demo is this Friday and we need the staging environment ready by Wednesday. Please confirm.', 0),
                (4, 'SCIT Support', 'Understood. We are prioritizing this and expect the staging environment to be ready by Tuesday evening. We will keep you posted on progress.', 1),
                (3, 'Eve Davis', 'I cannot access the API documentation page. Getting a 403 error. Please help.', 0)
            ");
            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
        }
    }
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// --- Lightweight migrations for pre-existing DB files (CREATE TABLE IF NOT EXISTS skips them) ---
if (!function_exists('sccrm_ensure_column')) {
    function sccrm_ensure_column($db, $table, $column, $ddl) {
        try {
            $cols = [];
            foreach ($db->query("PRAGMA table_info($table)") as $c) { $cols[] = $c['name']; }
            if (!in_array($column, $cols, true)) {
                $db->exec("ALTER TABLE $table ADD COLUMN $column $ddl");
            }
        } catch (Throwable $e) { /* best-effort: never break the app */ }
    }
}
// OSINT lead-intel columns (used by the enriched generator)
sccrm_ensure_column($db, 'leads', 'website', 'TEXT');
sccrm_ensure_column($db, 'leads', 'tech_stack', 'TEXT');
sccrm_ensure_column($db, 'leads', 'seo_score', 'INTEGER DEFAULT 0');
sccrm_ensure_column($db, 'leads', 'security_score', 'INTEGER DEFAULT 0');
sccrm_ensure_column($db, 'leads', 'osint_data', 'TEXT');

// AutoFlows automation tables + default flows (idempotent)
require_once __DIR__ . '/../autoflows/autoflow_engine.php';
if (function_exists('autoflowEnsureTables')) { autoflowEnsureTables($db); }

// AI Chat threads + messages (idempotent)
require_once __DIR__ . '/../ai/ai_bootstrap.php';
if (function_exists('aiEnsureTables')) { aiEnsureTables($db); }

// agent-browser tables (runs + monitors, idempotent — never breaks boot)
try {
    $abCan = dirname(__DIR__, 2) . '/autoflows/app/services/AgentBrowser.php';
    if (is_file($abCan) && !class_exists('AgentBrowser')) {
        require_once $abCan;
    }
    if (class_exists('AgentBrowser')) {
        AgentBrowser::ensureTables($db);
    }
} catch (Throwable $e) { /* best-effort */ }

return $db;
