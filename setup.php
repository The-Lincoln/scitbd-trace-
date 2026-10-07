<?php
/**
 * OSINT Framework - Database Setup
 * Run once via browser or CLI to initialize SQLite DB + seed data.
 */
require_once __DIR__ . '/config.php';

function run_setup() {
    $dir = dirname(DB_PATH);
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');

    // ---------- Schema ----------
    $pdo->exec("CREATE TABLE IF NOT EXISTS categories (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        parent_id INTEGER DEFAULT NULL,
        name TEXT NOT NULL,
        slug TEXT NOT NULL UNIQUE,
        icon TEXT DEFAULT 'folder',
        description TEXT,
        sort_order INTEGER DEFAULT 0,
        created_at TEXT DEFAULT (datetime('now')),
        FOREIGN KEY (parent_id) REFERENCES categories(id) ON DELETE CASCADE
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS tools (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        category_id INTEGER NOT NULL,
        name TEXT NOT NULL,
        url TEXT NOT NULL,
        description TEXT,
        cost_type TEXT DEFAULT 'free',       -- free / freemium / paid
        access_type TEXT DEFAULT 'web',      -- web / api / software / browser-ext
        requires_auth INTEGER DEFAULT 0,
        tags TEXT,
        favicon TEXT,
        rating_avg REAL DEFAULT 0,
        rating_count INTEGER DEFAULT 0,
        created_at TEXT DEFAULT (datetime('now')),
        updated_at TEXT DEFAULT (datetime('now')),
        FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_tools_category ON tools(category_id)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL UNIQUE,
        email TEXT NOT NULL UNIQUE,
        password_hash TEXT NOT NULL,
        role TEXT DEFAULT 'user',
        created_at TEXT DEFAULT (datetime('now'))
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ratings (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        tool_id INTEGER NOT NULL,
        rating INTEGER NOT NULL CHECK(rating BETWEEN 1 AND 5),
        review TEXT,
        created_at TEXT DEFAULT (datetime('now')),
        UNIQUE(user_id, tool_id),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (tool_id) REFERENCES tools(id) ON DELETE CASCADE
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS favorites (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        tool_id INTEGER NOT NULL,
        created_at TEXT DEFAULT (datetime('now')),
        UNIQUE(user_id, tool_id),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (tool_id) REFERENCES tools(id) ON DELETE CASCADE
    )");

    // ---------- Seed categories ----------
    $cats = [
        // [name, slug, icon, description, parent_slug, children[]]
        ['Username', 'username', 'person', 'Find accounts tied to a username across platforms', null, [
            ['Username Search', 'username-search', 'search', 'General username lookup aggregators'],
            ['Social Networks', 'username-social', 'share', 'Social network username discovery'],
            ['Forums & Communities', 'username-forums', 'chat', 'Forum and community platforms'],
            ['Gaming', 'username-gaming', 'gamepad', 'Gaming platforms and IDs'],
            ['Blogging', 'username-blogging', 'pen', 'Blogging and content platforms'],
        ]],
        ['Email Address', 'email', 'envelope', 'Investigate email addresses', null, [
            ['Email Search', 'email-search', 'search', 'Email lookup services'],
            ['Breach Check', 'email-breach', 'shield', 'Check email in known breaches'],
            ['Email Verification', 'email-verify', 'check', 'Verify email deliverability'],
        ]],
        ['Domain Name', 'domain', 'globe', 'Domain and DNS intelligence', null, [
            ['WHOIS', 'domain-whois', 'id-card', 'Domain registration records'],
            ['DNS Records', 'domain-dns', 'server', 'DNS resolution and history'],
            ['Subdomains', 'domain-subdomains', 'sitemap', 'Subdomain enumeration'],
            ['Certificate Transparency', 'domain-cert', 'certificate', 'TLS certificate logs'],
            ['Web Archive', 'domain-archive', 'archive', 'Historical snapshots'],
        ]],
        ['IP Address', 'ip', 'network-wired', 'IP geolocation and reputation', null, [
            ['IP Geo', 'ip-geo', 'map', 'Geolocation lookup'],
            ['IP Reputation', 'ip-reputation', 'shield', 'Reputation and abuse checks'],
            ['Reverse DNS', 'ip-reverse', 'sync', 'Reverse DNS lookup'],
            ['ASN / BGP', 'ip-asn', 'project-diagram', 'Autonomous system info'],
        ]],
        ['Phone Number', 'phone', 'phone', 'Phone number investigation', null, [
            ['Caller ID', 'phone-caller', 'caller-id', 'Caller identification'],
            ['SMS Lookup', 'phone-sms', 'comment', 'SMS and messaging lookup'],
            ['Carrier Lookup', 'phone-carrier', 'truck', 'Mobile carrier detection'],
        ]],
        ['People', 'people', 'users', 'People search and identity', null, [
            ['People Search', 'people-search', 'search', 'Aggregated people search'],
            ['Public Records', 'people-records', 'file', 'Government public records'],
            ['Court Records', 'people-court', 'gavel', 'Court case lookup'],
            ['Obituaries', 'people-obit', 'cross', 'Death records and obituaries'],
            ['Genealogy', 'people-genealogy', 'tree', 'Family history records'],
        ]],
        ['Physical Address', 'address', 'map-marker', 'Location and address intel', null, [
            ['Maps & Sat', 'address-maps', 'satellite', 'Satellite imagery and maps'],
            ['Property Records', 'address-property', 'home', 'Real estate records'],
            ['Address Validation', 'address-validate', 'check-circle', 'Address verification'],
        ]],
        ['Image / Photo', 'image', 'image', 'Reverse image search and EXIF', null, [
            ['Reverse Search', 'image-reverse', 'search', 'Find image sources online'],
            ['EXIF Data', 'image-exif', 'camera', 'Extract embedded metadata'],
            ['Facial Recognition', 'image-facial', 'user-circle', 'Face match services'],
        ]],
        ['Social Media', 'social', 'share-alt', 'Social media intelligence', null, [
            ['Twitter/X', 'social-twitter', 'twitter', 'Twitter/X investigation'],
            ['Facebook', 'social-facebook', 'facebook', 'Facebook investigation'],
            ['Instagram', 'social-instagram', 'instagram', 'Instagram investigation'],
            ['LinkedIn', 'social-linkedin', 'linkedin', 'LinkedIn professional data'],
            ['TikTok', 'social-tiktok', 'tiktok', 'TikTok video lookup'],
            ['Reddit', 'social-reddit', 'reddit', 'Reddit user history'],
            ['Telegram', 'social-telegram', 'telegram', 'Telegram channels and users'],
        ]],
        ['Search Engines', 'search-engines', 'search', 'Specialized search engines', null, [
            ['General', 'se-general', 'globe', 'Mainstream search engines'],
            ['Privacy Search', 'se-privacy', 'lock', 'Privacy-respecting engines'],
            ['Code Search', 'se-code', 'code', 'Source code search'],
            ['Pastebin', 'se-paste', 'paste', 'Paste sites search'],
            ['Academic', 'se-academic', 'graduation-cap', 'Scholarly search'],
        ]],
        ['Documents', 'documents', 'file-alt', 'Document and file search', null, [
            ['PDF Search', 'doc-pdf', 'file-pdf', 'PDF document search'],
            ['Office Docs', 'doc-office', 'file-word', 'Word/Excel/PowerPoint search'],
            ['Source Code', 'doc-code', 'file-code', 'Source code repositories'],
        ]],
        ['Public Records', 'public-records', 'archive', 'Government records', null, [
            ['Voter Records', 'pr-voter', 'vote', 'Voter registration'],
            ['Business Records', 'pr-business', 'building', 'Company registrations'],
            ['Court Records', 'pr-court', 'gavel', 'Court case records'],
            ['Property Records', 'pr-property', 'home', 'Real property records'],
        ]],
        ['Business Records', 'business', 'briefcase', 'Corporate intelligence', null, [
            ['Company Search', 'biz-search', 'search', 'Company registry lookup'],
            ['Financial Filings', 'biz-finance', 'chart-line', 'SEC and filings'],
            ['Patents', 'biz-patents', 'lightbulb', 'Patent databases'],
            ['Trademarks', 'biz-trademarks', 'trademark', 'Trademark registries'],
        ]],
        ['Vehicle Records', 'vehicle', 'car', 'Vehicle identification', null, [
            ['License Plate', 'veh-plate', 'id-card', 'License plate lookup'],
            ['VIN Check', 'veh-vin', 'barcode', 'Vehicle history'],
        ]],
        ['Maps & Geo', 'maps', 'map', 'Geospatial intelligence', null, [
            ['Maps & Satellite', 'geo-sat', 'satellite', 'Satellite imagery'],
            ['Street View', 'geo-street', 'street-view', 'Street level imagery'],
            ['Geolocation', 'geo-locate', 'location', 'Geolocation tools'],
        ]],
        ['Network', 'network', 'network-wired', 'Network infrastructure', null, [
            ['Port Scanning', 'net-ports', 'plug', 'Port scanner services'],
            ['Web Tech', 'net-tech', 'cogs', 'Web tech fingerprinting'],
            ['SSL/TLS', 'net-ssl', 'lock', 'SSL certificate info'],
        ]],
        ['Crypto', 'crypto', 'bitcoin', 'Cryptocurrency investigation', null, [
            ['Bitcoin', 'crypto-btc', 'bitcoin', 'Bitcoin blockchain'],
            ['Ethereum', 'crypto-eth', 'ethereum', 'Ethereum blockchain'],
            ['Wallet Lookup', 'crypto-wallet', 'wallet', 'Wallet investigation'],
        ]],
        ['Breach / Leaks', 'breaches', 'shield-alt', 'Data breach and leak databases', null, [
            ['Breach Databases', 'br-db', 'database', 'Aggregated breach data'],
            ['Paste Leaks', 'br-paste', 'paste', 'Pastebin leak monitoring'],
            ['Dark Web', 'br-darkweb', 'mask', 'Dark web mention search'],
        ]],
        ['Wireless Networks', 'wifi', 'wifi', 'WiFi and wireless intel', null, [
            ['WiFi Maps', 'wifi-maps', 'map', 'Wireless network maps'],
            ['Bluetooth', 'wifi-bt', 'bluetooth-b', 'Bluetooth device info'],
        ]],
        ['Web History', 'web-history', 'history', 'Website history', null, [
            ['Web Archives', 'wh-archive', 'archive', 'Web page archives'],
            ['Domain History', 'wh-domain', 'clock', 'Historical WHOIS'],
        ]],
    ];

    $catIdMap = []; // slug => id
    $insertCat = $pdo->prepare("INSERT INTO categories (parent_id, name, slug, icon, description, sort_order) VALUES (?, ?, ?, ?, ?, ?)");
    $order = 0;
    foreach ($cats as $cat) {
        $insertCat->execute([null, $cat[0], $cat[1], $cat[2], $cat[3], $order++]);
        $parentId = $pdo->lastInsertId();
        $catIdMap[$cat[1]] = $parentId;
        $subOrder = 0;
        foreach ($cat[5] as $sub) {
            $insertCat->execute([$parentId, $sub[0], $sub[1], $sub[2], $sub[3], $subOrder++]);
            $catIdMap[$sub[1]] = $pdo->lastInsertId();
        }
    }

    // ---------- Seed tools ----------
    $tools = [
        // [category_slug, name, url, description, cost_type, access_type, requires_auth, tags]
        // Username - Search
        ['username-search', 'Namechk', 'https://namechk.com', 'Check username availability across 90+ sites', 'free', 'web', 0, 'username,availability,profiles'],
        ['username-search', 'WhatsMyName', 'https://whatsmyname.app', 'Username enumeration across 600+ sites', 'free', 'web', 0, 'username,enum,profiles'],
        ['username-search', 'Sherlock', 'https://github.com/sherlock-project/sherlock', 'CLI tool to hunt username across 400+ sites', 'free', 'software', 0, 'username,cli,enum'],
        ['username-search', 'Instant Username Search', 'https://instantusername.com', 'Fast username search across multiple platforms', 'free', 'web', 0, 'username,search'],
        ['username-search', 'UserSearch.org', 'https://usersearch.org', 'Search people by username across platforms', 'free', 'web', 0, 'username,people'],
        ['username-search', 'Maigret', 'https://github.com/soxoj/maigret', 'Username OSINT tool based on Sherlock', 'free', 'software', 0, 'username,cli'],
        // Username - Social
        ['username-social', 'KnowEm', 'https://knowem.com', 'Username search on 500+ social networks', 'freemium', 'web', 0, 'username,social'],
        ['username-social', 'Checkmarks', 'https://checkmarks.io', 'Username availability and discovery', 'freemium', 'web', 0, 'username,social'],
        ['username-social', 'Namevine', 'https://namevine.com', 'Real-time username + domain search', 'free', 'web', 0, 'username,domain'],
        // Username - Forums
        ['username-forums', 'Forum Search', 'https://www.google.com/search?q=site%3Aforum*', 'Google dork for forum posts by username', 'free', 'web', 0, 'username,forum,google'],
        ['username-forums', 'Boardreader', 'https://boardreader.com', 'Search across forums and boards', 'free', 'web', 0, 'forum,search'],
        ['username-forums', 'ProBoards Search', 'https://www.proboards.com/search', 'Search ProBoards forums', 'free', 'web', 0, 'forum'],
        // Username - Gaming
        ['username-gaming', 'SteamID', 'https://steamid.xyz', 'Look up Steam user profile data', 'free', 'web', 0, 'steam,gaming'],
        ['username-gaming', 'SteamDB', 'https://steamdb.info', 'Steam database of games and profiles', 'free', 'web', 0, 'steam,gaming'],
        ['username-gaming', 'Xbox Gamertag', 'https://xboxgamertag.com', 'Xbox Live gamertag lookup', 'freemium', 'web', 0, 'xbox,gaming'],
        ['username-gaming', 'Fortnite Tracker', 'https://fortnitetracker.com', 'Fortnite player stats by username', 'free', 'web', 0, 'fortnite,gaming'],
        // Username - Blogging
        ['username-blogging', 'WordPress.com Profiles', 'https://wordpress.com/read/search', 'Search WordPress.com user profiles', 'free', 'web', 0, 'wordpress,blog'],
        ['username-blogging', 'Blogger Profile', 'https://www.blogger.com/profile-find.g', 'Find Blogger profiles', 'free', 'web', 0, 'blogger,blog'],
        ['username-blogging', 'Medium', 'https://medium.com/search', 'Search Medium for authors and posts', 'free', 'web', 0, 'medium,blog'],

        // Email - Search
        ['email-search', 'Hunter.io', 'https://hunter.io', 'Email finder by domain', 'freemium', 'web', 1, 'email,finder'],
        ['email-search', 'EmailRep', 'https://emailrep.io', 'Email reputation and footprint', 'free', 'api', 0, 'email,reputation'],
        ['email-search', 'HaveIBeenPwned', 'https://haveibeenpwned.com', 'Check email in breach databases', 'free', 'web', 1, 'email,breach'],
        ['email-search', 'Snov.io', 'https://snov.io', 'Email finder and verifier', 'freemium', 'web', 1, 'email,finder'],
        ['email-search', 'VoilaNorbert', 'https://voilanorbert.com', 'Email finder and verification', 'freemium', 'web', 1, 'email,finder'],
        ['email-search', 'RocketReach', 'https://rocketreach.co', 'Find email and phone for professionals', 'freemium', 'web', 1, 'email,b2b'],
        ['email-search', 'Lusha', 'https://lusha.com', 'B2B email and phone finder', 'freemium', 'web', 1, 'email,b2b'],
        // Email - Breach
        ['email-breach', 'HaveIBeenPwned', 'https://haveibeenpwned.com', 'Email breach check', 'free', 'web', 1, 'email,breach'],
        ['email-breach', 'Dehashed', 'https://dehashed.com', 'Searchable breach database', 'paid', 'web', 1, 'breach,leaks'],
        ['email-breach', 'LeakCheck', 'https://leakcheck.io', 'Breach data search', 'freemium', 'web', 1, 'breach,leaks'],
        ['email-breach', 'IntelX', 'https://intelx.io', 'Pastebin, dark web and leak search', 'freemium', 'web', 1, 'breach,darkweb'],
        ['email-breach', 'BreachDirectory', 'https://breachdirectory.org', 'Free breach search', 'free', 'web', 0, 'breach,leaks'],
        // Email - Verification
        ['email-verify', 'MailTester', 'https://mailtester.com', 'Email verification service', 'freemium', 'web', 0, 'email,verify'],
        ['email-verify', 'ZeroBounce', 'https://zerobounce.net', 'Email validation and verification', 'freemium', 'web', 1, 'email,verify'],
        ['email-verify', 'NeverBounce', 'https://neverbounce.com', 'Email verification API', 'freemium', 'api', 1, 'email,verify'],
        ['email-verify', 'Verifalia', 'https://verifalia.com', 'Real-time email validation', 'freemium', 'api', 1, 'email,verify'],

        // Domain - WHOIS
        ['domain-whois', 'WHOIS.net', 'https://whois.net', 'Domain WHOIS lookup', 'free', 'web', 0, 'whois,domain'],
        ['domain-whois', 'DomainTools WHOIS', 'https://whois.domaintools.com', 'Comprehensive WHOIS data', 'freemium', 'web', 0, 'whois,domain'],
        ['domain-whois', 'ICANN Lookup', 'https://lookup.icann.org', 'Official ICANN WHOIS lookup', 'free', 'web', 0, 'whois,icann'],
        ['domain-whois', 'WhoisXML', 'https://www.whoisxmlapi.com', 'WHOIS API and data feeds', 'freemium', 'api', 1, 'whois,api'],
        ['domain-whois', 'Whois.com', 'https://www.whois.com/whois', 'Standard WHOIS lookup', 'free', 'web', 0, 'whois,domain'],
        ['domain-whois', 'ViewDNS WHOIS', 'https://viewdns.com/whois', 'WHOIS lookup with history', 'free', 'web', 0, 'whois,domain'],
        // Domain - DNS
        ['domain-dns', 'DNSDumpster', 'https://dnsdumpster.com', 'DNS recon and mapping', 'free', 'web', 0, 'dns,recon'],
        ['domain-dns', 'SecurityTrails', 'https://securitytrails.com', 'DNS history and subdomain discovery', 'freemium', 'web', 1, 'dns,history'],
        ['domain-dns', 'MXToolbox', 'https://mxtoolbox.com', 'MX, DNS, and email diagnostic tools', 'freemium', 'web', 0, 'dns,mx,email'],
        ['domain-dns', 'DNSlytics', 'https://dnslytics.com', 'DNS analytics and reverse DNS', 'freemium', 'web', 0, 'dns,analytics'],
        ['domain-dns', 'ViewDNS.info', 'https://viewdns.info', 'DNS and domain tools collection', 'free', 'web', 0, 'dns,tools'],
        ['domain-dns', 'IntoDNS', 'https://intodns.com', 'DNS health and configuration checks', 'free', 'web', 0, 'dns,health'],
        // Domain - Subdomains
        ['domain-subdomains', 'Subfinder', 'https://github.com/projectdiscovery/subfinder', 'Subdomain discovery tool', 'free', 'software', 0, 'subdomain,enum'],
        ['domain-subdomains', 'Amass', 'https://github.com/owasp-amass/amass', 'In-depth subdomain enumeration', 'free', 'software', 0, 'subdomain,enum'],
        ['domain-subdomains', 'Censys', 'https://censys.io', 'Internet-wide scanning for subdomains', 'freemium', 'web', 1, 'subdomain,scan'],
        ['domain-subdomains', 'Shodan', 'https://shodan.io', 'Search engine for internet devices', 'freemium', 'web', 1, 'subdomain,scan'],
        ['domain-subdomains', 'Sublist3r', 'https://github.com/aboul3la/Sublist3r', 'Subdomain enumeration using OSINT', 'free', 'software', 0, 'subdomain,enum'],
        ['domain-subdomains', 'crt.sh', 'https://crt.sh', 'Certificate transparency log search', 'free', 'web', 0, 'subdomain,ct'],
        // Domain - Cert Transparency
        ['domain-cert', 'crt.sh', 'https://crt.sh', 'Certificate transparency search', 'free', 'web', 0, 'cert,ct'],
        ['domain-cert', 'Google CT Search', 'https://transparencyreport.google.com/https/certificates', 'Google certificate transparency report', 'free', 'web', 0, 'cert,ct'],
        ['domain-cert', 'Censys Certs', 'https://search.censys.io/certificates', 'Certificate search engine', 'freemium', 'web', 1, 'cert,search'],
        ['domain-cert', 'CertSpotter', 'https://sslmate.com/certspotter', 'Monitor certificate transparency logs', 'freemium', 'api', 0, 'cert,monitor'],
        // Domain - Web Archive
        ['domain-archive', 'Wayback Machine', 'https://web.archive.org', 'Internet Archive web snapshots', 'free', 'web', 0, 'archive,history'],
        ['domain-archive', 'Archive.today', 'https://archive.ph', 'Web page snapshot archive', 'free', 'web', 0, 'archive,snapshot'],
        ['domain-archive', 'Cachedview', 'https://cachedview.com', 'Google cache viewer', 'free', 'web', 0, 'cache,archive'],
        ['domain-archive', 'Domain Tools Archive', 'https://archive.domaintools.com', 'Historical WHOIS and screenshots', 'paid', 'web', 1, 'archive,whois'],

        // IP - Geo
        ['ip-geo', 'IPInfo.io', 'https://ipinfo.io', 'IP geolocation and ASN data', 'freemium', 'api', 0, 'ip,geo'],
        ['ip-geo', 'MaxMind GeoLite2', 'https://dev.maxmind.com/geoip/geolite2-free-geolocation-data', 'Free IP geolocation database', 'free', 'api', 1, 'ip,geo,database'],
        ['ip-geo', 'IPAPI.co', 'https://ipapi.co', 'IP location API', 'freemium', 'api', 0, 'ip,geo'],
        ['ip-geo', 'DB-IP', 'https://db-ip.com', 'IP geolocation database and API', 'freemium', 'api', 0, 'ip,geo'],
        ['ip-geo', 'IPGeoLocation.io', 'https://ipgeolocation.io', 'IP geolocation API', 'freemium', 'api', 1, 'ip,geo'],
        ['ip-geo', 'ExtremeIPLookup', 'https://extreme-ip-lookup.com', 'IP geolocation lookup', 'free', 'web', 0, 'ip,geo'],
        // IP - Reputation
        ['ip-reputation', 'AbuseIPDB', 'https://www.abuseipdb.com', 'IP abuse reports database', 'freemium', 'api', 1, 'ip,reputation'],
        ['ip-reputation', 'VirusTotal', 'https://www.virustotal.com', 'IP, domain, file reputation', 'freemium', 'web', 1, 'reputation,malware'],
        ['ip-reputation', 'AlienVault OTX', 'https://otx.alienvault.com', 'Threat intel community', 'free', 'api', 1, 'threat,intel'],
        ['ip-reputation', 'GreyNoise', 'https://www.greynoise.io', 'Internet scanner intelligence', 'freemium', 'api', 1, 'scanner,intel'],
        ['ip-reputation', 'Talos Intelligence', 'https://talosintelligence.com/reputation', 'Cisco Talos IP reputation', 'free', 'web', 0, 'reputation,cisco'],
        ['ip-reputation', 'Spamhaus', 'https://check.spamhaus.org', 'IP blocklist check', 'free', 'web', 0, 'reputation,spam'],
        // IP - Reverse DNS
        ['ip-reverse', 'ViewDNS Reverse DNS', 'https://viewdns.info/reverseip', 'Reverse DNS lookup', 'free', 'web', 0, 'reverse,dns'],
        ['ip-reverse', 'DNSlytics Reverse', 'https://dnslytics.com/reverse-ip', 'Reverse IP lookup', 'freemium', 'web', 0, 'reverse,dns'],
        ['ip-reverse', 'Bing IP Search', 'https://www.bing.com/search?q=ip%3A8.8.8.8', 'Bing search for sites on an IP', 'free', 'web', 0, 'reverse,search'],
        ['ip-reverse', 'HackerTarget', 'https://hackertarget.com/reverse-ip-lookup', 'Reverse IP lookup with API', 'freemium', 'api', 0, 'reverse,dns'],
        // IP - ASN
        ['ip-asn', 'BGP.he.net', 'https://bgp.he.net', 'BGP and ASN search', 'free', 'web', 0, 'asn,bgp'],
        ['ip-asn', 'RIPEstat', 'https://stat.ripe.net', 'RIPE routing data', 'free', 'web', 0, 'asn,ripe'],
        ['ip-asn', 'PeeringDB', 'https://www.peeringdb.com', 'Network peering database', 'free', 'web', 0, 'asn,peering'],
        ['ip-asn', 'Censys ASNs', 'https://search.censys.io/autonomous-systems', 'ASN enumeration', 'freemium', 'web', 1, 'asn,scan'],
        ['ip-asn', 'BGPlay', 'https://bgplay.massimolicari.com', 'BGP route visualization', 'free', 'web', 0, 'bgp,visualize'],

        // Phone - Caller ID
        ['phone-caller', 'Truecaller', 'https://www.truecaller.com', 'Caller ID and spam detection', 'freemium', 'web', 1, 'phone,caller'],
        ['phone-caller', 'Whitepages', 'https://www.whitepages.com', 'Reverse phone lookup', 'freemium', 'web', 1, 'phone,reverse'],
        ['phone-caller', 'SpyDialer', 'https://www.spydialer.com', 'Free reverse phone lookup', 'freemium', 'web', 0, 'phone,reverse'],
        ['phone-caller', 'NumLookup', 'https://numlookup.com', 'Free reverse phone lookup', 'free', 'api', 0, 'phone,reverse'],
        ['phone-caller', 'Whoscall', 'https://www.whoscall.com', 'Caller ID directory', 'freemium', 'web', 1, 'phone,caller'],
        // Phone - SMS
        ['phone-sms', 'Receive-SMS-Online', 'https://www.receive-sms-online.info', 'Public phone number SMS inbox', 'free', 'web', 0, 'phone,sms'],
        ['phone-sms', 'ReceiveSMS', 'https://www.receivesms.org', 'Free public SMS numbers', 'free', 'web', 0, 'phone,sms'],
        ['phone-sms', 'SMS24.me', 'https://sms24.me', 'Public SMS numbers directory', 'free', 'web', 0, 'phone,sms'],
        // Phone - Carrier
        ['phone-carrier', 'CarrierLookup', 'https://www.carrierlookup.com', 'Phone carrier lookup', 'freemium', 'api', 1, 'phone,carrier'],
        ['phone-carrier', 'Twilio Lookup', 'https://www.twilio.com/lookup', 'Phone number carrier lookup API', 'paid', 'api', 1, 'phone,carrier'],
        ['phone-carrier', 'Numverify', 'https://numverify.com', 'Phone validation and carrier lookup', 'freemium', 'api', 1, 'phone,carrier'],

        // People - Search
        ['people-search', 'Pipl', 'https://pipl.com', 'People search engine', 'paid', 'web', 1, 'people,search'],
        ['people-search', 'Spokeo', 'https://www.spokeo.com', 'People search aggregator', 'freemium', 'web', 1, 'people,search'],
        ['people-search', 'BeenVerified', 'https://www.beenverified.com', 'Background check and people search', 'paid', 'web', 1, 'people,background'],
        ['people-search', 'PeopleFinder', 'https://www.peoplefinder.com', 'People finder service', 'freemium', 'web', 1, 'people,search'],
        ['people-search', 'ThatsThem', 'https://thatsthem.com', 'Free people search', 'free', 'web', 0, 'people,search'],
        ['people-search', 'FastPeopleSearch', 'https://www.fastpeoplesearch.com', 'Free people search', 'free', 'web', 0, 'people,search'],
        // People - Public Records
        ['people-records', 'FamilySearch', 'https://www.familysearch.org', 'Genealogy and historical records', 'free', 'web', 1, 'genealogy,records'],
        ['people-records', 'VoterRecords', 'https://voterrecords.com', 'US voter registration records', 'free', 'web', 0, 'voter,records'],
        ['people-records', 'BlackBookOnline', 'https://www.blackbookonline.info', 'Free public records directory', 'free', 'web', 0, 'public,records'],
        ['people-records', 'BRB Publications', 'https://www.brbpublications.com', 'Public records source directory', 'freemium', 'web', 0, 'public,records'],
        // People - Court
        ['people-court', 'PACER', 'https://pacer.uscourts.gov', 'US federal court records', 'paid', 'web', 1, 'court,federal'],
        ['people-court', 'UniCourt', 'https://unicourt.com', 'Court case search aggregator', 'freemium', 'web', 1, 'court,cases'],
        ['people-court', 'CourtListener', 'https://www.courtlistener.com', 'Free court opinion search', 'free', 'web', 0, 'court,cases'],
        ['people-court', 'JudyRecords', 'https://www.judyrecords.com', 'Free court records search', 'free', 'web', 0, 'court,records'],
        // People - Obituaries
        ['people-obit', 'Legacy.com', 'https://www.legacy.com/obituaries', 'Obituary search', 'free', 'web', 0, 'obituary'],
        ['people-obit', 'Tributes.com', 'https://www.tributes.com', 'Obituaries and death records', 'free', 'web', 0, 'obituary'],
        ['people-obit', 'Newspapers.com Obituaries', 'https://www.newspapers.com', 'Historical newspaper obituaries', 'paid', 'web', 1, 'obituary,newspaper'],
        ['people-obit', 'FindAGrave', 'https://www.findagrave.com', 'Grave records and memorials', 'free', 'web', 1, 'grave,records'],
        // People - Genealogy
        ['people-genealogy', 'Ancestry.com', 'https://www.ancestry.com', 'Family history records', 'paid', 'web', 1, 'genealogy,family'],
        ['people-genealogy', 'MyHeritage', 'https://www.myheritage.com', 'Family tree and records', 'freemium', 'web', 1, 'genealogy,family'],
        ['people-genealogy', 'FamilySearch', 'https://www.familysearch.org', 'Free genealogy records', 'free', 'web', 1, 'genealogy,family'],
        ['people-genealogy', 'Geni', 'https://www.geni.com', 'Collaborative family tree', 'freemium', 'web', 1, 'genealogy,family'],

        // Address - Maps
        ['address-maps', 'Google Maps', 'https://maps.google.com', 'Google maps and satellite', 'free', 'web', 0, 'maps,satellite'],
        ['address-maps', 'OpenStreetMap', 'https://www.openstreetmap.org', 'Open source world map', 'free', 'web', 0, 'maps,open'],
        ['address-maps', 'Bing Maps', 'https://www.bing.com/maps', 'Bing maps and aerial', 'free', 'web', 0, 'maps,aerial'],
        ['address-maps', 'Google Earth', 'https://earth.google.com', '3D Earth visualization', 'free', 'web', 0, 'maps,3d'],
        // Address - Property
        ['address-property', 'Zillow', 'https://www.zillow.com', 'Property records and values', 'free', 'web', 0, 'property,real-estate'],
        ['address-property', 'Redfin', 'https://www.redfin.com', 'Real estate listings and history', 'free', 'web', 0, 'property,real-estate'],
        ['address-property', 'Realtor.com', 'https://www.realtor.com', 'Property listings and records', 'free', 'web', 0, 'property,real-estate'],
        ['address-property', 'County Assessor', 'https://www.naco.org', 'County property records lookup', 'free', 'web', 0, 'property,county'],
        // Address - Validation
        ['address-validate', 'SmartyStreets', 'https://smartystreets.com', 'US address validation API', 'freemium', 'api', 1, 'address,validate'],
        ['address-validate', 'Google Address Validation', 'https://maps.googleapis.com/maps/api/addressvalidation', 'Google address validation', 'paid', 'api', 1, 'address,validate'],
        ['address-validate', 'Loqate', 'https://www.loqate.com', 'Global address verification', 'paid', 'api', 1, 'address,validate'],

        // Image - Reverse
        ['image-reverse', 'Google Images', 'https://images.google.com', 'Reverse image search', 'free', 'web', 0, 'reverse,image'],
        ['image-reverse', 'TinEye', 'https://tineye.com', 'Reverse image search engine', 'freemium', 'web', 0, 'reverse,image'],
        ['image-reverse', 'Yandex Images', 'https://yandex.com/images', 'Russian reverse image search', 'free', 'web', 0, 'reverse,image'],
        ['image-reverse', 'Bing Visual Search', 'https://www.bing.com/visualsearch', 'Bing visual search', 'free', 'web', 0, 'reverse,image'],
        ['image-reverse', 'PimEyes', 'https://pimeyes.com', 'Facial recognition reverse search', 'paid', 'web', 1, 'reverse,face'],
        // Image - EXIF
        ['image-exif', 'Jeffreys EXIF Viewer', 'http://exif.regex.info', 'Online EXIF viewer', 'free', 'web', 0, 'exif,metadata'],
        ['image-exif', 'ViewEXIF', 'https://viewexif.com', 'Online EXIF data viewer', 'free', 'web', 0, 'exif,metadata'],
        ['image-exif', 'ExifTool', 'https://exiftool.org', 'Comprehensive EXIF tool', 'free', 'software', 0, 'exif,metadata'],
        ['image-exif', 'Metadata2Go', 'https://www.metadata2go.com', 'Online metadata viewer', 'free', 'web', 0, 'exif,metadata'],
        // Image - Facial
        ['image-facial', 'PimEyes', 'https://pimeyes.com', 'Facial recognition search', 'paid', 'web', 1, 'face,recognition'],
        ['image-facial', 'Search4faces', 'https://search4faces.com', 'Face search across VK/OK', 'freemium', 'web', 0, 'face,recognition'],
        ['image-facial', 'FaceCheck.ID', 'https://facecheck.id', 'Reverse face search', 'freemium', 'web', 1, 'face,recognition'],

        // Social - Twitter
        ['social-twitter', 'TweetDeck', 'https://tweetdeck.twitter.com', 'Twitter dashboard', 'free', 'web', 1, 'twitter,dashboard'],
        ['social-twitter', 'Twint', 'https://github.com/twintproject/twint', 'Twitter intelligence tool', 'free', 'software', 0, 'twitter,scrape'],
        ['social-twitter', 'FollowerAudit', 'https://www.followeraudit.com', 'Twitter follower audit', 'freemium', 'web', 1, 'twitter,audit'],
        ['social-twitter', 'SocialBlade', 'https://socialblade.com/twitter', 'Twitter statistics', 'free', 'web', 0, 'twitter,stats'],
        // Social - Facebook
        ['social-facebook', 'Facebook Search', 'https://www.facebook.com/search', 'Facebook people and pages search', 'free', 'web', 1, 'facebook,search'],
        ['social-facebook', 'SOW Search', 'https://sowsearch.com', 'Facebook graph search tool', 'freemium', 'web', 1, 'facebook,search'],
        ['social-facebook', 'Lookup-ID', 'https://lookup-id.com', 'Find Facebook numeric ID', 'free', 'web', 0, 'facebook,id'],
        // Social - Instagram
        ['social-instagram', 'Instaloader', 'https://instaloader.github.io', 'Instagram scraping tool', 'free', 'software', 0, 'instagram,scrape'],
        ['social-instagram', 'Picuki', 'https://www.picuki.com', 'Instagram viewer without account', 'free', 'web', 0, 'instagram,viewer'],
        ['social-instagram', 'ImgInn', 'https://imginn.com', 'Instagram profile viewer', 'free', 'web', 0, 'instagram,viewer'],
        ['social-instagram', 'InstaNavigation', 'https://instanavigation.com', 'Instagram stories viewer', 'free', 'web', 0, 'instagram,stories'],
        // Social - LinkedIn
        ['social-linkedin', 'LinkedIn Search', 'https://www.linkedin.com/search', 'LinkedIn people search', 'freemium', 'web', 1, 'linkedin,people'],
        ['social-linkedin', 'LinkedIn Sales Navigator', 'https://www.linkedin.com/sales', 'LinkedIn prospecting tool', 'paid', 'web', 1, 'linkedin,sales'],
        ['social-linkedin', 'Lusha LinkedIn', 'https://lusha.com', 'LinkedIn contact finder extension', 'freemium', 'browser-ext', 1, 'linkedin,extension'],
        // Social - TikTok
        ['social-tiktok', 'TikTok Search', 'https://www.tiktok.com/search', 'TikTok user and video search', 'free', 'web', 0, 'tiktok,search'],
        ['social-tiktok', 'TikTok downloader', 'https://snaptik.app', 'Download TikTok videos', 'free', 'web', 0, 'tiktok,download'],
        ['social-tiktok', 'Exolyt', 'https://exolyt.com', 'TikTok analytics tool', 'freemium', 'web', 1, 'tiktok,analytics'],
        // Social - Reddit
        ['social-reddit', 'Reddit Search', 'https://www.reddit.com/search', 'Reddit post and user search', 'free', 'web', 0, 'reddit,search'],
        ['social-reddit', 'Reddit User Analyzer', 'https://reddituseranalyser.com', 'Reddit user activity analysis', 'free', 'web', 0, 'reddit,user'],
        ['social-reddit', 'Pushshift', 'https://pushshift.io', 'Reddit API for historical data', 'free', 'api', 0, 'reddit,api'],
        ['social-reddit', 'Snoopsnoo', 'https://snoopsnoo.com', 'Reddit user profile analyzer', 'free', 'web', 0, 'reddit,user'],
        // Social - Telegram
        ['social-telegram', 'Telegram Search', 'https://t.me/s', 'Public Telegram channel search', 'free', 'web', 0, 'telegram,channels'],
        ['social-telegram', 'Telemetr', 'https://telemetr.io', 'Telegram channel analytics', 'freemium', 'web', 0, 'telegram,analytics'],
        ['social-telegram', 'TGStat', 'https://tgstat.com', 'Telegram statistics directory', 'freemium', 'web', 0, 'telegram,stats'],

        // Search Engines - General
        ['se-general', 'Google', 'https://www.google.com', 'Google search', 'free', 'web', 0, 'search,google'],
        ['se-general', 'Bing', 'https://www.bing.com', 'Microsoft Bing search', 'free', 'web', 0, 'search,bing'],
        ['se-general', 'DuckDuckGo', 'https://duckduckgo.com', 'Privacy-focused search engine', 'free', 'web', 0, 'search,privacy'],
        ['se-general', 'Yandex', 'https://yandex.com', 'Russian search engine', 'free', 'web', 0, 'search,russia'],
        ['se-general', 'Baidu', 'https://www.baidu.com', 'Chinese search engine', 'free', 'web', 0, 'search,china'],
        ['se-general', 'Brave Search', 'https://search.brave.com', 'Independent search index', 'free', 'web', 0, 'search,privacy'],
        // Search Engines - Privacy
        ['se-privacy', 'DuckDuckGo', 'https://duckduckgo.com', 'Privacy-focused search', 'free', 'web', 0, 'search,privacy'],
        ['se-privacy', 'Startpage', 'https://www.startpage.com', 'Private Google results', 'free', 'web', 0, 'search,privacy'],
        ['se-privacy', 'SearXNG', 'https://searx.be', 'Open-source meta search engine', 'free', 'web', 0, 'search,open-source'],
        ['se-privacy', 'Mojeek', 'https://www.mojeek.com', 'Independent crawler-based search', 'free', 'web', 0, 'search,independent'],
        // Search Engines - Code
        ['se-code', 'GitHub Search', 'https://github.com/search', 'Search GitHub repositories and code', 'free', 'web', 0, 'code,github'],
        ['se-code', 'Grep.app', 'https://grep.app', 'Search across public GitHub repos', 'free', 'web', 0, 'code,search'],
        ['se-code', 'Sourcegraph', 'https://sourcegraph.com', 'Code search engine', 'freemium', 'web', 1, 'code,search'],
        ['se-code', 'SearchCode', 'https://searchcode.com', 'Search 75B lines of code', 'free', 'web', 0, 'code,search'],
        // Search Engines - Pastebin
        ['se-paste', 'Pastebin Search', 'https://psbdmp.ws', 'Pastebin dump search', 'free', 'web', 0, 'paste,leaks'],
        ['se-paste', 'Google Paste Dorks', 'https://www.google.com/search?q=site%3Apastebin.com', 'Google dork for pastebin', 'free', 'web', 0, 'paste,dork'],
        ['se-paste', 'PasteLert', 'https://pastebin.com/alerts', 'Paste alert monitoring', 'free', 'web', 1, 'paste,monitor'],
        // Search Engines - Academic
        ['se-academic', 'Google Scholar', 'https://scholar.google.com', 'Academic paper search', 'free', 'web', 0, 'academic,scholar'],
        ['se-academic', 'ResearchGate', 'https://www.researchgate.net', 'Academic social network', 'freemium', 'web', 1, 'academic,network'],
        ['se-academic', 'Semantic Scholar', 'https://www.semanticscholar.org', 'AI-powered academic search', 'free', 'web', 0, 'academic,ai'],
        ['se-academic', 'PubMed', 'https://pubmed.ncbi.nlm.nih.gov', 'Biomedical literature', 'free', 'web', 0, 'academic,medical'],

        // Documents - PDF
        ['doc-pdf', 'Google PDF Search', 'https://www.google.com/search?q=filetype%3Apdf', 'Search PDFs on Google', 'free', 'web', 0, 'pdf,search'],
        ['doc-pdf', 'PDF Drive', 'https://www.pdfdrive.com', 'PDF ebooks search engine', 'free', 'web', 0, 'pdf,ebook'],
        ['doc-pdf', 'DocDroid', 'https://www.docdroid.net', 'Document sharing platform', 'freemium', 'web', 0, 'pdf,share'],
        // Documents - Office
        ['doc-office', 'Google Docs Search', 'https://www.google.com/search?q=filetype%3Adoc', 'Search Word documents', 'free', 'web', 0, 'docx,search'],
        ['doc-office', 'Excel Search', 'https://www.google.com/search?q=filetype%3Axls', 'Search Excel files', 'free', 'web', 0, 'excel,search'],
        ['doc-office', 'PPT Search', 'https://www.google.com/search?q=filetype%3Appt', 'Search PowerPoint files', 'free', 'web', 0, 'ppt,search'],
        // Documents - Source Code
        ['doc-code', 'GitHub Gist Search', 'https://gist.github.com/discover', 'Search GitHub gists', 'free', 'web', 0, 'code,gist'],
        ['doc-code', 'GitLab Search', 'https://gitlab.com/search', 'Search GitLab repositories', 'free', 'web', 0, 'code,gitlab'],
        ['doc-code', 'Grep.app', 'https://grep.app', 'Search code across GitHub', 'free', 'web', 0, 'code,search'],

        // Public Records - Voter
        ['pr-voter', 'VoterRecords', 'https://voterrecords.com', 'US voter records search', 'free', 'web', 0, 'voter,records'],
        ['pr-voter', 'Secretary of State Voter', 'https://www.nass.org/can-I-vote', 'State voter registries directory', 'free', 'web', 0, 'voter,state'],
        // Public Records - Business
        ['pr-business', 'OpenCorporates', 'https://opencorporates.com', 'Largest open corporate database', 'freemium', 'web', 0, 'business,registry'],
        ['pr-business', 'SEC EDGAR', 'https://www.sec.gov/edgar', 'US public company filings', 'free', 'web', 0, 'business,sec'],
        ['pr-business', 'Companies House UK', 'https://find-and-update.company-information.service.gov.uk', 'UK company registry', 'free', 'web', 0, 'business,uk'],
        // Public Records - Court
        ['pr-court', 'PACER', 'https://pacer.uscourts.gov', 'US federal court records', 'paid', 'web', 1, 'court,federal'],
        ['pr-court', 'UniCourt', 'https://unicourt.com', 'Court case search', 'freemium', 'web', 1, 'court,cases'],
        ['pr-court', 'CourtListener', 'https://www.courtlistener.com', 'Free court opinion database', 'free', 'web', 0, 'court,cases'],
        // Public Records - Property
        ['pr-property', 'Zillow', 'https://www.zillow.com', 'Property search and records', 'free', 'web', 0, 'property'],
        ['pr-property', 'Trulia', 'https://www.trulia.com', 'Real estate listings', 'free', 'web', 0, 'property'],
        ['pr-property', 'RealtyTrac', 'https://www.realtytrac.com', 'Foreclosure records', 'freemium', 'web', 1, 'property,foreclosure'],

        // Business - Search
        ['biz-search', 'OpenCorporates', 'https://opencorporates.com', 'Open corporate database', 'freemium', 'web', 0, 'business,registry'],
        ['biz-search', 'Companies House', 'https://find-and-update.company-information.service.gov.uk', 'UK company registry', 'free', 'web', 0, 'business,uk'],
        ['biz-search', 'Better Business Bureau', 'https://www.bbb.org', 'Business reliability ratings', 'free', 'web', 0, 'business,bbb'],
        ['biz-search', 'Glassdoor', 'https://www.glassdoor.com', 'Company reviews and salaries', 'freemium', 'web', 1, 'business,reviews'],
        // Business - Financial
        ['biz-finance', 'SEC EDGAR', 'https://www.sec.gov/edgar', 'Public company filings', 'free', 'web', 0, 'finance,sec'],
        ['biz-finance', 'Crunchbase', 'https://www.crunchbase.com', 'Startup and company data', 'freemium', 'web', 1, 'finance,startup'],
        ['biz-finance', 'PitchBook', 'https://pitchbook.com', 'Private market intelligence', 'paid', 'web', 1, 'finance,private'],
        ['biz-finance', 'ZoomInfo', 'https://www.zoominfo.com', 'B2B company database', 'paid', 'web', 1, 'finance,b2b'],
        // Business - Patents
        ['biz-patents', 'Google Patents', 'https://patents.google.com', 'Patent search engine', 'free', 'web', 0, 'patent,search'],
        ['biz-patents', 'USPTO', 'https://uspto.gov', 'US patent office search', 'free', 'web', 0, 'patent,uspto'],
        ['biz-patents', 'Espacenet', 'https://worldwide.espacenet.com', 'EPO worldwide patents', 'free', 'web', 0, 'patent,epo'],
        ['biz-patents', 'WIPO Patentscope', 'https://patentscope.wipo.int', 'International patent search', 'free', 'web', 0, 'patent,wipo'],
        // Business - Trademarks
        ['biz-trademarks', 'TESS', 'https://tmsearch.uspto.gov', 'US trademark search', 'free', 'web', 0, 'trademark,uspto'],
        ['biz-trademarks', 'TMview', 'https://www.tmdn.org/tmview', 'EU trademark search', 'free', 'web', 0, 'trademark,eu'],
        ['biz-trademarks', 'WIPO Global Brand', 'https://www3.wipo.int/branddb/en', 'Global brand database', 'free', 'web', 0, 'trademark,global'],

        // Vehicle - Plate
        ['veh-plate', 'WorldLicensePlates', 'http://www.worldlicenseplates.com', 'Worldwide plate directory', 'free', 'web', 0, 'plate,directory'],
        ['veh-plate', 'Plate Recognizer', 'https://platerecognizer.com', 'License plate recognition API', 'freemium', 'api', 1, 'plate,api'],
        ['veh-plate', 'AutoCheck', 'https://www.autocheck.com', 'Vehicle history by plate', 'paid', 'web', 1, 'plate,history'],
        // Vehicle - VIN
        ['veh-vin', 'VINcheck', 'https://www.vehiclehistory.gov', 'National VIN check (free)', 'free', 'web', 0, 'vin,history'],
        ['veh-vin', 'Carfax', 'https://www.carfax.com', 'Vehicle history reports', 'paid', 'web', 1, 'vin,history'],
        ['veh-vin', 'AutoCheck', 'https://www.autocheck.com', 'VIN history reports', 'paid', 'web', 1, 'vin,history'],
        ['veh-vin', 'VINdecoder', 'https://vindecoderz.com', 'Free VIN decoder', 'free', 'web', 0, 'vin,decode'],

        // Maps - Satellite
        ['geo-sat', 'Google Earth', 'https://earth.google.com', '3D Earth visualization', 'free', 'web', 0, 'satellite,3d'],
        ['geo-sat', 'Sentinel Hub', 'https://www.sentinel-hub.com', 'Satellite imagery from Copernicus', 'freemium', 'web', 1, 'satellite,copernicus'],
        ['geo-sat', 'NASA Worldview', 'https://worldview.earthdata.nasa.gov', 'NASA satellite imagery', 'free', 'web', 0, 'satellite,nasa'],
        ['geo-sat', 'Planet Labs', 'https://www.planet.com', 'Daily satellite imagery', 'paid', 'web', 1, 'satellite,daily'],
        // Maps - Street View
        ['geo-street', 'Google Street View', 'https://www.google.com/streetview', 'Google street level imagery', 'free', 'web', 0, 'street,google'],
        ['geo-street', 'Mapillary', 'https://www.mapillary.com', 'Crowdsourced street imagery', 'freemium', 'web', 0, 'street,crowd'],
        ['geo-street', 'KartaView', 'https://kartaview.org', 'Open street-level imagery', 'free', 'web', 0, 'street,open'],
        // Maps - Geolocation
        ['geo-locate', 'GeoGuessr', 'https://www.geoguessr.com', 'Geolocation guessing game', 'freemium', 'web', 1, 'geo,game'],
        ['geo-locate', 'SunCalc', 'https://www.suncalc.org', 'Sun position for geolocation', 'free', 'web', 0, 'geo,sun'],
        ['geo-locate', 'ShadowMap', 'https://shadowmap.org', '3D shadow maps', 'free', 'web', 0, 'geo,shadow'],
        ['geo-locate', 'What3Words', 'https://what3words.com', '3-word location identifier', 'free', 'web', 0, 'geo,location'],

        // Network - Port Scan
        ['net-ports', 'Shodan', 'https://www.shodan.io', 'Internet device scanner', 'freemium', 'web', 1, 'port,scan'],
        ['net-ports', 'Censys', 'https://censys.io', 'Internet-wide scanner', 'freemium', 'web', 1, 'port,scan'],
        ['net-ports', 'ZoomEye', 'https://www.zoomeye.org', 'Chinese cyberspace search', 'freemium', 'web', 1, 'port,scan'],
        ['net-ports', 'Onyphe', 'https://www.onyphe.io', 'Cyber defense search engine', 'freemium', 'web', 1, 'port,scan'],
        // Network - Web Tech
        ['net-tech', 'Wappalyzer', 'https://www.wappalyzer.com', 'Website tech stack fingerprint', 'freemium', 'browser-ext', 0, 'tech,fingerprint'],
        ['net-tech', 'BuiltWith', 'https://builtwith.com', 'Website tech profile', 'freemium', 'web', 0, 'tech,fingerprint'],
        ['net-tech', 'WhatRuns', 'https://www.whatruns.com', 'Identify web technologies', 'free', 'browser-ext', 0, 'tech,fingerprint'],
        ['net-tech', 'WebTechDatabase', 'https://webtechsurvey.com', 'Web technology database', 'free', 'web', 0, 'tech,database'],
        // Network - SSL
        ['net-ssl', 'SSL Labs', 'https://www.ssllabs.com/ssltest', 'SSL/TLS configuration test', 'free', 'web', 0, 'ssl,test'],
        ['net-ssl', 'Censys Certs', 'https://search.censys.io/certificates', 'Certificate search', 'freemium', 'web', 1, 'ssl,search'],
        ['net-ssl', 'CertLog', 'https://certlog.io', 'Certificate transparency log search', 'free', 'web', 0, 'ssl,ct'],

        // Crypto - BTC
        ['crypto-btc', 'Blockchain.com Explorer', 'https://www.blockchain.com/explorer', 'Bitcoin blockchain explorer', 'free', 'web', 0, 'btc,explorer'],
        ['crypto-btc', 'Blockchair', 'https://blockchair.com', 'Multi-blockchain explorer', 'free', 'web', 0, 'btc,explorer'],
        ['crypto-btc', 'BTC.com', 'https://btc.com', 'Bitcoin explorer and stats', 'free', 'web', 0, 'btc,explorer'],
        ['crypto-btc', 'OXT', 'https://oxt.me', 'Bitcoin transaction graph explorer', 'free', 'web', 0, 'btc,graph'],
        // Crypto - ETH
        ['crypto-eth', 'Etherscan', 'https://etherscan.io', 'Ethereum blockchain explorer', 'free', 'web', 0, 'eth,explorer'],
        ['crypto-eth', 'Blockchair ETH', 'https://blockchair.com/ethereum', 'Ethereum blockchain explorer', 'free', 'web', 0, 'eth,explorer'],
        ['crypto-eth', 'Ethplorer', 'https://ethplorer.io', 'Ethereum tokens and addresses', 'freemium', 'api', 0, 'eth,tokens'],
        // Crypto - Wallet
        ['crypto-wallet', 'WalletExplorer', 'https://www.walletexplorer.com', 'Bitcoin wallet explorer', 'free', 'web', 0, 'wallet,btc'],
        ['crypto-wallet', 'BitFury Crystal', 'https://crystalblockchain.com', 'Blockchain analytics', 'paid', 'web', 1, 'wallet,analytics'],
        ['crypto-wallet', 'Chainalysis', 'https://www.chainalysis.com', 'Blockchain investigation platform', 'paid', 'web', 1, 'wallet,investigation'],

        // Breaches - DB
        ['br-db', 'HaveIBeenPwned', 'https://haveibeenpwned.com', 'Email breach check', 'free', 'web', 1, 'breach,email'],
        ['br-db', 'Dehashed', 'https://dehashed.com', 'Searchable breach database', 'paid', 'web', 1, 'breach,leaks'],
        ['br-db', 'LeakCheck', 'https://leakcheck.io', 'Breach data search', 'freemium', 'web', 1, 'breach,leaks'],
        ['br-db', 'IntelX', 'https://intelx.io', 'Leaks and dark web search', 'freemium', 'web', 1, 'breach,darkweb'],
        ['br-db', 'Snusbase', 'https://snusbase.com', 'Breach data search engine', 'paid', 'web', 1, 'breach,leaks'],
        // Breaches - Paste
        ['br-paste', 'Pastebin Search', 'https://psbdmp.ws', 'Pastebin dump search', 'free', 'web', 0, 'paste,leaks'],
        ['br-paste', 'IntelX Pastes', 'https://intelx.io', 'Paste leak search', 'freemium', 'web', 1, 'paste,leaks'],
        ['br-paste', 'PasteLert', 'https://pastelert.com', 'Paste alert monitor', 'free', 'web', 0, 'paste,monitor'],
        // Breaches - Darkweb
        ['br-darkweb', 'IntelX', 'https://intelx.io', 'Dark web mention search', 'freemium', 'web', 1, 'darkweb,search'],
        ['br-darkweb', 'Ahmia', 'https://ahmia.fi', 'Tor hidden service search', 'free', 'web', 0, 'darkweb,tor'],
        ['br-darkweb', 'Tor66', 'http://tor66sebgg6742hdq5xwqd2nrwftzjz5xvhnbqrq7njztpwqd2nrwftzjz5.onion', 'Onion search engine', 'free', 'web', 0, 'darkweb,onion'],

        // Wifi - Maps
        ['wifi-maps', 'Wigle', 'https://wigle.net', 'Wireless network map database', 'freemium', 'web', 1, 'wifi,map'],
        ['wifi-maps', 'OpenWiFiMap', 'https://openwifi.su', 'Open WiFi map database', 'free', 'web', 0, 'wifi,map'],
        // Wifi - Bluetooth
        ['wifi-bt', 'Bluefix', 'https://bluefix.io', 'Bluetooth device tracker', 'freemium', 'web', 0, 'bluetooth,tracker'],

        // Web History - Archive
        ['wh-archive', 'Wayback Machine', 'https://web.archive.org', 'Internet Archive snapshots', 'free', 'web', 0, 'archive,history'],
        ['wh-archive', 'Archive.today', 'https://archive.ph', 'Web page snapshot archive', 'free', 'web', 0, 'archive,snapshot'],
        ['wh-archive', 'Cachedview', 'https://cachedview.com', 'Google cache viewer', 'free', 'web', 0, 'archive,cache'],
        // Web History - Domain History
        ['wh-domain', 'DomainTools History', 'https://research.domaintools.com', 'Historical WHOIS data', 'paid', 'web', 1, 'domain,history'],
        ['wh-domain', 'WhoisXML History', 'https://www.whoisxmlapi.com', 'WHOIS history database', 'freemium', 'api', 1, 'domain,history'],
        ['wh-domain', 'WhoisRequest', 'https://whoisrequest.com/history', 'WHOIS history records', 'freemium', 'web', 0, 'domain,history'],
    ];

    $insertTool = $pdo->prepare("INSERT INTO tools (category_id, name, url, description, cost_type, access_type, requires_auth, tags, favicon) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ($tools as $t) {
        [$catSlug, $name, $url, $desc, $cost, $access, $auth, $tags] = $t;
        if (!isset($catIdMap[$catSlug])) continue;
        $host = parse_url($url, PHP_URL_HOST) ?: '';
        $favicon = $host ? FAVICON_SERVICE . $host : '';
        $insertTool->execute([$catIdMap[$catSlug], $name, $url, $desc, $cost, $access, $auth, $tags, $favicon]);
    }

    // ---------- Default admin user ----------
    $pdo->prepare("INSERT OR IGNORE INTO users (username, email, password_hash, role) VALUES (?, ?, ?, ?)")
        ->execute([ADMIN_USER, 'admin@local', password_hash(ADMIN_PASS, PASSWORD_DEFAULT), 'admin']);

    return true;
}

// Run if executed directly
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    if (file_exists(DB_PATH)) {
        echo "<p>Database already exists at: <code>" . htmlspecialchars(DB_PATH) . "</code></p>";
        echo "<p>To reinitialize, delete the file and run setup again.</p>";
    } else {
        run_setup();
        echo "<p style='color:green;font-family:monospace'>✓ Database created at: " . htmlspecialchars(DB_PATH) . "</p>";
        echo "<p style='font-family:monospace'>✓ Categories and tools seeded</p>";
        echo "<p><a href='index.php'>→ Go to OSINT Framework</a></p>";
    }
}
