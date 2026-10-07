<?php
// AutoFlows hook: fire lead_created flows after generation (engine is standalone).
require_once __DIR__ . '/../autoflows/autoflow_engine.php';

function generateLeads($db, $count = 5, $market_id = null, $service_id = null, $options = []) {
    $enrich = !empty($options['enrich']);
    if ($enrich) { ensureLeadOsintColumns($db); }
    $market_data = getMarketData($db, $market_id);
    $service_data = getServiceData($db, $service_id);
    $positions = ['CEO','CTO','CFO','VP of Sales','Marketing Director','Product Manager','Lead Engineer','IT Manager','Operations Director','Business Development Manager','Founder','Co-Founder','Head of Digital','Innovation Director','Technology Advisor','Procurement Manager','General Manager','Consultant','Managing Director','Technical Lead'];
    $company_suffixes = ['Solutions','Technologies','Systems','Digital','Group','Global','International','Consulting','Enterprises','Innovations','Labs','Networks','Dynamics','Concepts','Ventures','Partners','Infotech','Software','Data','Cloud'];

    $generated = 0;
    $errors = 0;
    $newLeadIds = [];

    try {
        $db->beginTransaction();
        $hasOsintCols = leadHasOsintColumns($db);
        if ($hasOsintCols) {
            $stmt = $db->prepare("INSERT INTO leads (market_id, service_id, first_name, last_name, email, phone, company_name, position, source, status, priority, notes, budget, score, website, tech_stack, seo_score, security_score, osint_data, generated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,datetime('now'))");
        } else {
            $stmt = $db->prepare("INSERT INTO leads (market_id, service_id, first_name, last_name, email, phone, company_name, position, source, status, priority, notes, budget, score, generated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,datetime('now'))");
        }

        $all_markets = $db->query("SELECT id, name, country_code, currency FROM markets WHERE is_active = 1")->fetchAll();
        $all_services = $db->query("SELECT id, name FROM services WHERE is_active = 1")->fetchAll();

        for ($i = 0; $i < $count; $i++) {
            $mkt = $market_data ?: $all_markets[array_rand($all_markets)];
            $svc = $service_data ?: $all_services[array_rand($all_services)];
            $person = generatePerson($mkt['name'] ?? 'Global');
            $company = $person['last'] . ' ' . $company_suffixes[array_rand($company_suffixes)];
            $email_domain = strtolower(preg_replace('/[^a-z0-9]/', '', $company)) . '.com';
            $phone = generatePhone($mkt['name'] ?? 'Global');
            $position = $positions[array_rand($positions)];
            $priority_weights = ['low'=>0.15,'medium'=>0.45,'high'=>0.30,'urgent'=>0.10];
            $priority = weightedRandom($priority_weights);
            $budget = rand(5000, 500000);
            $score = calculateLeadScore($person['email'] ? true : false, $phone ? true : false, true, $budget > 0, $position !== '', $priority === 'high' || $priority === 'urgent');

            // --- OSINT enrichment: website intel (DNS + URL trace) + recommended tool stack ---
            $website = 'https://' . $email_domain;
            $techStack = '';
            $seoScore = 0;
            $secScore = 0;
            $osintJson = null;
            $note = generateNote($svc['name'] ?? 'Service', $mkt['name'] ?? 'Market');
            if ($enrich && $hasOsintCols) {
                $intel = enrichLeadWithOsint($company, $website);
                $website = $intel['website'];
                $techStack = $intel['tech_stack'];
                $seoScore = $intel['seo_score'];
                $secScore = $intel['security_score'];
                $osintJson = $intel['osint_json'];
                $score = min(100, $score + $intel['score_bonus']);
                if ($intel['note_extra'] !== '') $note .= ' ' . $intel['note_extra'];
                // Attach the service-aligned OSINT stack to the lead intel record.
                try {
                    $stack = osintToolsForService($svc['name'] ?? '', 5);
                    if ($stack) {
                        $arr = json_decode($osintJson ?: '{}', true) ?: [];
                        $arr['recommended_tools'] = array_map(function ($t) {
                            return ['name' => $t['name'] ?? '', 'url' => $t['url'] ?? '', 'category' => $t['category'] ?? ''];
                        }, $stack);
                        $osintJson = json_encode($arr);
                    }
                } catch (Throwable $e) { /* ignore */ }
            }

            if ($hasOsintCols) {
                $stmt->execute([
                    $mkt['id'], $svc['id'],
                    $person['first'], $person['last'],
                    $person['email'] ?? strtolower($person['first'] . '.' . $person['last']) . '@' . $email_domain,
                    $phone, $company, $position,
                    $enrich ? 'osint_enriched_generator' : 'hourly_generator', 'new', $priority,
                    $note,
                    $budget, $score,
                    $website, $techStack, $seoScore, $secScore, $osintJson
                ]);
            } else {
                $stmt->execute([
                    $mkt['id'], $svc['id'],
                    $person['first'], $person['last'],
                    $person['email'] ?? strtolower($person['first'] . '.' . $person['last']) . '@' . $email_domain,
                    $phone, $company, $position,
                    'hourly_generator', 'new', $priority,
                    $note,
                    $budget, $score
                ]);
            }
            $generated++;
            try { $newLeadIds[] = (int)$db->lastInsertId(); } catch (Throwable $e) { /* ignore */ }
        }

        $log_stmt = $db->prepare("INSERT INTO lead_generation_logs (leads_generated, source, status) VALUES (?, 'hourly_generator', 'success')");
        $log_stmt->execute([$generated]);
        $db->commit();

        // AutoFlows: fire lead_created for every new lead (enrich, tasks, ...). Never breaks generation.
        if (function_exists('autoflowTrigger')) {
            foreach ($newLeadIds as $lid) {
                try { autoflowTrigger($db, 'lead_created', ['lead_id' => $lid, 'source' => $enrich ? 'osint_enriched_generator' : 'hourly_generator']); }
                catch (Throwable $e) { /* ignore */ }
            }
        }

        return ['status' => 'success', 'message' => "Successfully generated $generated leads!", 'count' => $generated];
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        $log_stmt = $db->prepare("INSERT INTO lead_generation_logs (leads_generated, source, status, error_message) VALUES (?, 'hourly_generator', 'error', ?)");
        $log_stmt->execute([$generated, $e->getMessage()]);
        return ['status' => 'error', 'message' => 'Generation failed: ' . $e->getMessage(), 'count' => $generated];
    }
}

function getMarketData($db, $id) {
    if ($id) {
        $stmt = $db->prepare("SELECT id, name, country_code, currency FROM markets WHERE id = ? AND is_active = 1");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }
    return null;
}

function getServiceData($db, $id) {
    if ($id) {
        $stmt = $db->prepare("SELECT id, name FROM services WHERE id = ? AND is_active = 1");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }
    return null;
}

function generatePerson($market) {
    $market = strtolower($market);
    $first_names = [
        'bangladesh' => ['Md.','Rafiq','Shamim','Rahim','Karim','Fahim','Tahmid','Arif','Hasan','Imran','Shahid','Kamal','Jamil','Faruk','Nazmul','Abdur','Mizan','Sabbir','Rashed','Sajib'],
        'united states' => ['James','Mary','Robert','Patricia','John','Jennifer','Michael','Linda','David','Elizabeth','William','Barbara','Richard','Susan','Joseph','Jessica','Thomas','Sarah','Charles','Karen'],
        'china' => ['Wei','Li','Zhang','Wang','Liu','Chen','Yang','Huang','Zhao','Wu','Zhou','Xu','Sun','Ma','Hu','Lin','He','Guo','Luo','Liang'],
        'united kingdom' => ['Oliver','Amelia','George','Olivia','Noah','Isla','Arthur','Ava','Harry','Mia','Leo','Ivy','Jack','Lily','Oscar','Freya','Charlie','Florence','Henry','Willow'],
        'dubai' => ['Mohammed','Ahmed','Ali','Omar','Hassan','Hussein','Khalid','Saeed','Abdullah','Yousef','Nasir','Rashid','Tariq','Jamal','Nabil','Karim','Farid','Zayed','Sultan','Mansoor'],
        'australia' => ['Jack','Charlotte','James','Olivia','William','Mia','Thomas','Grace','Oliver','Emily','Ethan','Sophie','Noah','Ella','Lucas','Chloe','Henry','Harper','Oscar','Isla']
    ];

    $last_names = [
        'bangladesh' => ['Rahman','Hossain','Khan','Islam','Ahmed','Ali','Sarker','Chowdhury','Haque','Das','Mollah','Kundu','Roy','Sen','Biswas','Ghosh','Saha','Datta','Majumder','Halder'],
        'united states' => ['Smith','Johnson','Williams','Brown','Jones','Garcia','Miller','Davis','Rodriguez','Martinez','Hernandez','Lopez','Wilson','Anderson','Thomas','Taylor','Moore','Jackson','Martin','Lee'],
        'china' => ['Li','Wang','Zhang','Liu','Chen','Yang','Huang','Wu','Zhao','Zhou','Xu','Sun','Ma','Hu','Lin','He','Guo','Luo','Liang','Tang'],
        'united kingdom' => ['Smith','Jones','Williams','Taylor','Brown','Davies','Wilson','Evans','Thomas','Roberts','Walker','Wright','Clark','Hall','Green','Hill','Baker','Adams','Turner','Collins'],
        'dubai' => ['Al Maktoum','Al Nahyan','Al Qasimi','Al Hashimi','Al Suwaidi','Al Shamsi','Al Mazrouei','Al Zaabi','Al Marri','Al Tayer','Al Mansouri','Al Kaabi','Al Dhaheri','Al Shehhi','Al Blooshi','Al Zarooni','Al Owais','Al Banna','Al Khouri','Al Hammadi'],
        'australia' => ['Smith','Jones','Williams','Brown','Wilson','Taylor','Johnson','White','Martin','Anderson','Thompson','Harris','Clark','Lewis','Walker','Hall','Young','Allen','King','Wright']
    ];

    $market_key = 'global';
    if (strpos($market, 'bangladesh') !== false) $market_key = 'bangladesh';
    elseif (strpos($market, 'united states') !== false || $market === 'us' || strpos($market, 'usa') !== false) $market_key = 'united states';
    elseif (strpos($market, 'china') !== false) $market_key = 'china';
    elseif (strpos($market, 'united kingdom') !== false || $market === 'uk') $market_key = 'united kingdom';
    elseif (strpos($market, 'dubai') !== false || strpos($market, 'uae') !== false) $market_key = 'dubai';
    elseif (strpos($market, 'australia') !== false) $market_key = 'australia';

    $first = $first_names[$market_key][array_rand($first_names[$market_key])];
    $last = $last_names[$market_key][array_rand($last_names[$market_key])];
    $email = strtolower($first . '.' . $last) . '@' . ['gmail.com','outlook.com','yahoo.com','company.com','protonmail.com'][array_rand(['gmail.com','outlook.com','yahoo.com','company.com','protonmail.com'])];

    return ['first' => $first, 'last' => $last, 'email' => $email];
}

function generatePhone($market) {
    $market = strtolower($market);
    if (strpos($market, 'bangladesh') !== false) return '+880-1' . rand(3,9) . rand(10000000, 99999999);
    if (strpos($market, 'united states') !== false || strpos($market, 'usa') !== false) return '+1-555-' . str_pad(rand(0,9999), 4, '0', STR_PAD_LEFT);
    if (strpos($market, 'china') !== false) return '+86-' . rand(130,199) . str_pad(rand(0,99999999), 8, '0', STR_PAD_LEFT);
    if (strpos($market, 'united kingdom') !== false) return '+44-7' . rand(0,9) . rand(10000000, 99999999);
    if (strpos($market, 'dubai') !== false || strpos($market, 'uae') !== false) return '+971-5' . rand(0,9) . rand(1000000, 9999999);
    if (strpos($market, 'australia') !== false) return '+61-4' . rand(0,9) . rand(10000000, 99999999);
    return '+1-555-' . str_pad(rand(0,9999), 4, '0', STR_PAD_LEFT);
}

function generateNote($service, $market) {
    $notes = [
        "Interested in $service solutions for the $market market. Follow up required.",
        "Lead expressed interest in $service. Looking for pricing and timeline.",
        "Warm lead from $market region. Needs $service consultation.",
        "Potential client seeking $service for their business in $market.",
        "Inquired about $service. Requested demo and proposal.",
        "Exploring $service options for expansion in $market. High potential.",
        "Need $service implementation support. Budget allocated for Q3.",
        "Referred by partner. Interested in $service for $market operations."
    ];
    return $notes[array_rand($notes)];
}

function calculateLeadScore($has_email, $has_phone, $has_company, $has_budget, $has_position, $high_priority) {
    $score = 0;
    if ($has_email) $score += 15;
    if ($has_phone) $score += 10;
    if ($has_company) $score += 20;
    if ($has_budget) $score += 25;
    if ($has_position) $score += 15;
    if ($high_priority) $score += 15;
    return min($score, 100);
}

function weightedRandom($weights) {
    $total = array_sum($weights);
    $rand = mt_rand(1, intval($total * 100)) / 100;
    foreach ($weights as $key => $weight) {
        $rand -= $weight;
        if ($rand <= 0) return $key;
    }
    $keys = array_keys($weights);
    return $keys[0];
}

// =====================================================================
// OSINT lead-intelligence helpers (URL trace + tools catalog + enrichment)
// =====================================================================

/** Ensure OSINT columns exist on leads (idempotent; safe for old DB files). */
function ensureLeadOsintColumns($db) {
    foreach ([
        'website' => 'TEXT',
        'tech_stack' => 'TEXT',
        'seo_score' => 'INTEGER DEFAULT 0',
        'security_score' => 'INTEGER DEFAULT 0',
        'osint_data' => 'TEXT',
    ] as $col => $ddl) {
        try {
            $names = [];
            foreach ($db->query('PRAGMA table_info(leads)') as $c) { $names[] = $c['name']; }
            if (!in_array($col, $names, true)) { $db->exec("ALTER TABLE leads ADD COLUMN $col $ddl"); }
        } catch (Throwable $e) { /* best-effort */ }
    }
}

/** True when the leads table already carries the OSINT columns. */
function leadHasOsintColumns($db) {
    try {
        $names = [];
        foreach ($db->query('PRAGMA table_info(leads)') as $c) { $names[] = $c['name']; }
        return in_array('website', $names, true) && in_array('osint_data', $names, true);
    } catch (Throwable $e) { return false; }
}

/** Path to the OSINT project root (parent of sccrm). */
function osintRootPath() {
    return dirname(__DIR__, 2);
}

/**
 * Curated keyword sets mapping each SCIT service family to the OSINT
 * capabilities that prospect/research it best (all 17 services covered).
 */
function osintServiceKeywords($serviceName) {
    $s = strtolower((string)$serviceName);
    $has = function (...$needles) use ($s) {
        foreach ($needles as $n) { if (strpos($s, $n) !== false) return true; }
        return false;
    };
    if ($has('website'))            return ['cms', 'technology stack', 'dns', 'ssl', 'seo', 'headers'];
    if ($has('mobile', 'android', 'ios', 'cross-platform', 'native')) return ['android', 'apk', 'ios', 'app search', 'mobile'];
    if ($has('cybersecurity', 'it support', 'waf', 'pen ')) return ['vulnerability', 'breach', 'malware', 'ssl', 'ip reputation', 'cve'];
    if ($has('compliance'))         return ['sanctions', 'compliance', 'breach', 'certificate', 'audit'];
    if ($has('audit'))              return ['audit', 'breach', 'ssl', 'headers', 'dns', 'compliance'];
    if ($has('marketing', 'branding', 'ads')) return ['seo', 'social media', 'backlinks', 'analytics', 'username'];
    if ($has('payment', 'commerce', 'e-wallet', 'stripe', 'bkash')) return ['domain', 'ssl', 'fraud', 'breach', 'ecommerce'];
    if ($has('government', 'enterprise', 'e-procurement', 'biometric', 'smart id')) return ['public records', 'government', 'sanctions', 'contracts', 'company'];
    if ($has('machine learning', '(ml)')) return ['datasets', 'github', 'papers', 'ai models', 'kaggle'];
    if ($has('ai agent', 'autonomous')) return ['github', 'llm', 'automation', 'ai', 'api'];
    if ($has('ai ', 'ai &', 'artificial', 'intelligent automation', 'gpt', 'nlp')) return ['ai', 'datasets', 'api', 'github', 'analytics'];
    if ($has('lms', 'e-learning', 'elearning', 'education', 'course')) return ['people search', 'email', 'social media', 'courses', 'username'];
    if ($has('custom software', 'erp', 'hrm', 'pos', 'crm', 'hospital', 'school')) return ['github', 'code search', 'api', 'technology', 'shodan'];
    if ($has('consultancy', 'transformation', 'cloud', 'strategy', 'migration')) return ['asn', 'cloud', 'ip', 'dns', 'business records', 'company'];
    return ['osint', 'search', 'domain', 'ip', 'email'];
}

/**
 * Recommended OSINT tools for a given service (searches the OSINT tools catalog).
 * Uses the curated service map with relevance scoring; falls back to top-rated.
 * Returns up to $limit rows: [name, url, description, category].
 */
function osintToolsForService($serviceName, $limit = 8) {
    $limit = max(1, min(intval($limit), 20));
    $osintDb = osintRootPath() . '/data/osint.db';
    if (!file_exists($osintDb)) return [];
    try {
        $pdo = new PDO('sqlite:' . $osintDb);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $keywords = array_values(array_unique(array_filter(array_map('trim', osintServiceKeywords($serviceName)))));
        $keywords = array_slice($keywords, 0, 6);
        if ($keywords) {
            $scoreParts = [];
            $params = [];
            foreach ($keywords as $kw) {
                $like = '%' . $kw . '%';
                $scoreParts[] = "(CASE WHEN t.name LIKE ? THEN 3 ELSE 0 END + CASE WHEN t.tags LIKE ? THEN 2 ELSE 0 END + CASE WHEN t.description LIKE ? THEN 1 ELSE 0 END)";
                $params[] = $like; $params[] = $like; $params[] = $like;
            }
            $sql = "SELECT name, url, description, category, score FROM (
                        SELECT t.name, t.url, t.description, c.name AS category,
                               (" . implode(' + ', $scoreParts) . ") AS score,
                               t.rating_avg AS rating_avg
                        FROM tools t LEFT JOIN categories c ON c.id = t.category_id
                    ) WHERE score > 0 ORDER BY score DESC, rating_avg DESC, name ASC LIMIT " . $limit;
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if ($rows) return $rows;
        }
        // Fallback: top-rated tools overall so the panel is never empty
        return $pdo->query(
            "SELECT t.name, t.url, t.description, c.name AS category
             FROM tools t LEFT JOIN categories c ON c.id = t.category_id
             ORDER BY t.rating_avg DESC, t.name ASC LIMIT " . $limit
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { return []; }
}

/**
 * Enrich a generated company with OSINT website intel.
 * Best-effort: DNS check first, then a URL trace (short, guarded).
 * Never throws — returns defaults on any failure.
 */
function enrichLeadWithOsint($companyName, $websiteGuess) {
    $out = [
        'website' => $websiteGuess,
        'tech_stack' => '',
        'seo_score' => 0,
        'security_score' => 0,
        'osint_json' => null,
        'score_bonus' => 0,
        'note_extra' => '',
    ];
    try {
        $host = parse_url($websiteGuess, PHP_URL_HOST) ?: '';
        if ($host === '') return $out;
        $ip = @gethostbyname($host);
        $dnsOk = ($ip !== '' && $ip !== $host);
        $intel = ['host' => $host, 'dns_resolves' => $dnsOk, 'ip' => $dnsOk ? $ip : null];

        if ($dnsOk) {
            // Lazy-load the tracer engine (shared with the Trace app)
            $root = osintRootPath();
            if (file_exists($root . '/vendor/autoload.php')) { require_once $root . '/vendor/autoload.php'; }
            if (file_exists($root . '/trace/url_tracer.php')) { require_once $root . '/trace/url_tracer.php'; }
            if (class_exists('OSINT\\URLTracer')) {
                $tracePdo = null;
                try {
                    if (file_exists($root . '/data/osint.db')) {
                        $tracePdo = new PDO('sqlite:' . $root . '/data/osint.db');
                        $tracePdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    }
                } catch (Throwable $e) { $tracePdo = null; }
                $tracer = new OSINT\URLTracer($tracePdo);
                $res = @$tracer->trace($websiteGuess);
                if (is_array($res) && empty($res['error'])) {
                    $techs = [];
                    foreach ((array)($res['technology']['all_technologies'] ?? []) as $t) {
                        $techs[] = is_array($t) ? ($t['technology'] ?? '') : (string)$t;
                    }
                    $techs = array_values(array_filter(array_unique($techs)));
                    $out['tech_stack'] = implode(', ', array_slice($techs, 0, 10));
                    $out['seo_score'] = (int)($res['seo']['score'] ?? 0);
                    $out['security_score'] = (int)($res['security']['score'] ?? 0);
                    $intel['http_status'] = $res['basic']['status_code'] ?? null;
                    $intel['server'] = $res['basic']['server'] ?? null;
                    $intel['title'] = $res['content']['title'] ?? null;
                    $intel['technologies'] = $techs;
                    $intel['seo_score'] = $out['seo_score'];
                    $intel['security_score'] = $out['security_score'];
                    // Verified live website + decent web presence boosts the score
                    if (($intel['http_status'] ?? 0) < 400 && ($intel['http_status'] ?? 0) > 0) {
                        $out['score_bonus'] += 5;
                    }
                    if ($out['seo_score'] >= 70) { $out['score_bonus'] += 3; }
                    $bits = [];
                    if ($out['tech_stack'] !== '') $bits[] = 'Tech: ' . $out['tech_stack'] . '.';
                    $bits[] = 'Site verified live (HTTP ' . ($intel['http_status'] ?? '?') . ').';
                    $out['note_extra'] = '[OSINT] ' . implode(' ', $bits);
                }
            }
            if ($out['note_extra'] === '') {
                $out['note_extra'] = '[OSINT] Domain resolves (' . $ip . ').';
                $out['score_bonus'] += 2;
            }
        } else {
            $out['note_extra'] = '[OSINT] Domain does not resolve yet — greenfield opportunity.';
        }
        $intel['company'] = $companyName;
        $intel['traced_at'] = date('Y-m-d H:i:s');
        $out['osint_json'] = json_encode($intel);
    } catch (Throwable $e) { /* enrichment must never break generation */ }
    return $out;
}
