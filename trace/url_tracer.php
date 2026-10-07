<?php
/**
 * URL Tracer - Comprehensive URL Analysis Tool
 * Traces all details information from any URL
 * Includes: First Published, Time, Location, IP, MAC Address
 */

namespace OSINT;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Utils;

class URLTracer
{
    private $client;
    private $pdo;
    private $url;
    private $results;
    private $traceId;
    // Reuse single GET body across phases to avoid 6x duplicate requests
    private $cachedResponse = null;
    private $cachedBody = null;
    
    public function __construct($pdo = null)
    {
        // Allow long multi-phase trace to run past default 30s limit.
        // Vendor CurlFactory.php line 2524 is just where PHP kills the script
        // while waiting on curl — the fix belongs here, not in vendor/.
        @set_time_limit(120);
        @ini_set('max_execution_time', '120');
        @ini_set('default_socket_timeout', '10');
        $this->pdo = $pdo;
        $this->client = new Client([
            'timeout' => 10,
            'connect_timeout' => 5,
            'http_errors' => false,
            'allow_redirects' => ['max' => 5],
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 OSINT-Framework/1.0',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.5',
                'Accept-Encoding' => 'gzip, deflate',
                'DNT' => '1',
            ],
        ]);
    }
    
    /**
     * Set PDO connection for storing results
     */
    public function setPDO($pdo)
    {
        $this->pdo = $pdo;
        return $this;
    }
    
    /**
     * Get or create trace ID
     */
    private function getTraceId(): string
    {
        if (!$this->traceId) {
            $this->traceId = uniqid('trace_', true);
        }
        return $this->traceId;
    }
    
    /**
     * Main trace method - traces everything from a URL
     */
    public function trace(string $url): array
    {
        // Renew execution budget on every trace (entry scripts may have their own limit)
        @set_time_limit(120);
        @ini_set('max_execution_time', '120');
        $this->url = $this->normalizeUrl($url);
        $this->results = [];
        $this->traceId = uniqid('trace_', true);
        // Reset page cache for this URL
        $this->cachedResponse = null;
        $this->cachedBody = null;
        
        try {
            // Phase 1: Basic HTTP Request
            $this->results['basic'] = $this->traceBasic();
            
            // Phase 2: Headers Analysis
            $this->results['headers'] = $this->traceHeaders();
            
            // Phase 3: SSL/TLS Analysis
            $this->results['ssl'] = $this->traceSSL();
            
            // Phase 4: HTML Content Analysis
            $this->results['content'] = $this->traceContent();
            
            // Phase 5: Technology Detection
            $this->results['technology'] = $this->detectTechnology();
            
            // Phase 6: Links Extraction
            $this->results['links'] = $this->extractLinks();
            
            // Phase 7: Form Detection
            $this->results['forms'] = $this->detectForms();
            
            // Phase 8: SEO Analysis
            $this->results['seo'] = $this->analyzeSEO();
            
            // Phase 9: Security Analysis
            $this->results['security'] = $this->analyzeSecurity();
            
            // Phase 10: Performance Indicators
            $this->results['performance'] = $this->analyzePerformance();
            
            // Phase 11: Identity & Location
            $this->results['identity'] = $this->traceIdentity();
            
            // Save to database
            $this->saveToDatabase();
            
        } catch (\Throwable $e) {
            $this->results['error'] = [
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ];
        }
        
        return $this->results;
    }
    
    /**
     * Fetch target page once and reuse across phases.
     * Previously traceBasic/content/technology/links/forms/seo each did
     * their own GET (6x sequential requests) — easily exceeding 30s.
     * @return array{0:\Psr\Http\Message\ResponseInterface,1:string}
     */
    private function fetchPage(): array
    {
        if ($this->cachedResponse !== null && $this->cachedBody !== null) {
            return [$this->cachedResponse, $this->cachedBody];
        }
        $response = $this->client->request('GET', $this->url);
        $body = (string)$response->getBody();
        $this->cachedResponse = $response;
        $this->cachedBody = $body;
        return [$response, $body];
    }

    /**
     * Phase 1: Basic HTTP Request and Response
     */
    private function traceBasic(): array
    {
        $start = microtime(true);
        
        try {
            $response = $this->client->request('GET', $this->url, [
                'allow_redirects' => ['max' => 5, 'track_redirects' => true],
            ]);
            $body = (string)$response->getBody();
            // Prime shared cache so later phases don't re-download
            $this->cachedResponse = $response;
            $this->cachedBody = $body;
            
            $end = microtime(true);
            $responseTime = round(($end - $start) * 1000, 2); // ms

            // Effective URL + redirect count via Guzzle redirect-tracking headers
            // (Response has no getEffectiveUrl()/getHandlerContext() methods)
            $redirectHistory = $response->getHeader('X-Guzzle-Redirect-History');
            $finalUrl = !empty($redirectHistory) ? end($redirectHistory) : $this->url;
            $redirectCount = is_array($redirectHistory) ? count($redirectHistory) : 0;
            
            return [
                'status' => 'success',
                'url' => $this->url,
                'final_url' => (string)$finalUrl,
                'status_code' => (int)$response->getStatusCode(),
                'status_text' => $response->getReasonPhrase(),
                'redirects' => $redirectCount,
                'response_time_ms' => $responseTime,
                'content_length' => strlen($body),
                'content_type' => $response->getHeaderLine('Content-Type'),
                'encoding' => $this->detectEncoding($response),
                'body_preview' => substr($body, 0, 500),
                'server_ip' => $this->resolveIP($this->url),
                'http_version' => (string)$response->getProtocolVersion(),
                'date' => (string)$response->getHeaderLine('Date'),
                'server' => (string)$response->getHeaderLine('Server'),
                'request_size' => strlen($this->buildRequestString()),
                'first_request_timestamp' => date('Y-m-d H:i:s'),
                'request_uuid' => $this->getTraceId(),
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'url' => $this->url,
                'error' => $e->getMessage(),
            ];
        }
    }
    
    /**
     * Phase 11: Identity & Location - First published, Time, Location, IP, MAC
     */
    private function traceIdentity(): array
    {
        $parsedUrl = parse_url($this->url);
        $host = $parsedUrl['host'] ?? '';
        
        $identity = [
            'status' => 'success',
            'trace_id' => $this->getTraceId(),
            'url' => $this->url,
            'host' => $host,
            'trace_timestamp' => date('Y-m-d H:i:s'),
            'trace_date' => date('Y-m-d'),
            'trace_time' => date('H:i:s'),
            'trace_timezone' => date_default_timezone_get(),
            'trace_epoch' => time(),
        ];
        
        // 1. IP Address Resolution
        $identity['ip_address'] = $this->resolveIP($this->url);
        
        // 2. Geographic Location from IP
        $identity['location'] = $this->getLocation($identity['ip_address']);
        
        // 3. First Published Data (WHOIS creation date)
        $identity['first_published'] = $this->getFirstPublished($host);
        
        // 4. MAC Address (Network Interface)
        $identity['mac_address'] = $this->getMACAddress();
        
        // 5. Network Information
        $identity['network'] = [
            'subnet' => $this->getSubnet($identity['ip_address']),
            'broadcast' => $this->getBroadcast($identity['ip_address']),
            'gateway' => $this->getGateway(),
            'dns_servers' => $this->getDNSServers(),
        ];
        
        // 6. ASN Information
        $identity['asn'] = $this->getASN($identity['ip_address']);
        
        // 7. Reverse DNS
        $identity['reverse_dns'] = @gethostbyaddr($identity['ip_address']) ?: 'N/A';
        
        // 8. Time Zone and Local Time
        $identity['local_time'] = [
            'timezone' => date_default_timezone_get(),
            'current_time' => date('Y-m-d H:i:s'),
            'current_date' => date('Y-m-d'),
            'day_of_week' => date('l'),
            'day_of_year' => date('z'),
            'week_number' => date('W'),
            'month' => date('F'),
            'year' => date('Y'),
            'is_dst' => (bool)date('I'),
        ];
        
        return $identity;
    }
    
    /**
     * Get geographic location from IP
     */
    private function getLocation(string $ip): array
    {
        $location = [
            'ip' => $ip,
            'country' => 'N/A',
            'region' => 'N/A',
            'city' => 'N/A',
            'latitude' => null,
            'longitude' => null,
            'timezone' => 'N/A',
            'isp' => 'N/A',
            'organization' => 'N/A',
            'asn' => 'N/A',
        ];
        
        if ($ip === '127.0.0.1' || $ip === '::1' || empty($ip)) {
            $location['country'] = 'Local';
            $location['city'] = 'Localhost';
            return $location;
        }
        
        // Try to get location from multiple sources
        try {
            // Method 1: ipinfo.io
            $client = new Client(['timeout' => 5, 'connect_timeout' => 3]);
            $resp = $client->get("https://ipinfo.io/{$ip}/json");
            $data = json_decode((string)$resp->getBody(), true);
           
            if ($data) {
                $location['country'] = $data['country'] ?? 'N/A';
                $location['region'] = $data['region'] ?? 'N/A';
                $location['city'] = $data['city'] ?? 'N/A';
                $location['latitude'] = $data['loc'] ? explode(',', $data['loc'])[0] : null;
                $location['longitude'] = $data['loc'] ? explode(',', $data['loc'])[1] : null;
                $location['timezone'] = $data['timezone'] ?? 'N/A';
                $location['isp'] = $data['org'] ?? 'N/A';
                $location['organization'] = $data['org'] ?? 'N/A';
            }
        } catch (\Throwable $e) {
            // Fallback: Try ipapi
            try {
                $resp = $client->get("https://ipapi.co/{$ip}/json/");
                $data = json_decode((string)$resp->getBody(), true);
                if ($data) {
                    $location['country'] = $data['country_code'] ?? 'N/A';
                    $location['region'] = $data['region'] ?? 'N/A';
                    $location['city'] = $data['city'] ?? 'N/A';
                    $location['latitude'] = $data['latitude'] ?? null;
                    $location['longitude'] = $data['longitude'] ?? null;
                    $location['timezone'] = $data['timezone'] ?? 'N/A';
                    $location['isp'] = $data['org'] ?? 'N/A';
                    $location['organization'] = $data['org'] ?? 'N/A';
                }
            } catch (\Throwable $e2) {
                $location['error'] = 'Could not resolve location';
            }
        }
        
        return $location;
    }
    
    /**
     * Get first published date from WHOIS
     */
    private function getFirstPublished(string $host): array
    {
        $whoisData = [
            'domain' => $host,
            'created_date' => 'N/A',
            'first_published' => 'N/A',
            'registrar' => 'N/A',
            'registrar_url' => 'N/A',
            'registrant_country' => 'N/A',
            'updated_date' => 'N/A',
            'expiry_date' => 'N/A',
            'name_servers' => [],
        ];
        
        if (empty($host) || filter_var($host, FILTER_VALIDATE_IP)) {
            return $whoisData;
        }
        
        try {
            // Try to get WHOIS data via external API
            $client = new Client(['timeout' => 6, 'connect_timeout' => 3]);
            
            // Try WHOIS.com API
            $resp = $client->get("https://www.whois.com/whois/{$host}");
            $body = (string)$resp->getBody();
            
            // Extract creation date
            if (preg_match('/Creation Date:\s*([^\n]+)/i', $body, $m)) {
                $whoisData['created_date'] = trim($m[1]);
                $whoisData['first_published'] = trim($m[1]);
            }
            if (preg_match('/Created Date:\s*([^\n]+)/i', $body, $m)) {
                $whoisData['created_date'] = trim($m[1]);
                $whoisData['first_published'] = trim($m[1]);
            }
            if (preg_match('/Registered On:\s*([^\n]+)/i', $body, $m)) {
                $whoisData['created_date'] = trim($m[1]);
                $whoisData['first_published'] = trim($m[1]);
            }
            
            // Extract registrar
            if (preg_match('/Registrar:\s*([^\n]+)/i', $body, $m)) {
                $whoisData['registrar'] = trim($m[1]);
            }
            
            // Extract expiry date
            if (preg_match('/Registry Expiry Date:\s*([^\n]+)/i', $body, $m)) {
                $whoisData['expiry_date'] = trim($m[1]);
            }
            if (preg_match('/Expiration Date:\s*([^\n]+)/i', $body, $m)) {
                $whoisData['expiry_date'] = trim($m[1]);
            }
            
            // Extract registrant country
            if (preg_match('/Registrant Country:\s*([^\n]+)/i', $body, $m)) {
                $whoisData['registrant_country'] = trim($m[1]);
            }
            
            // Extract name servers
            preg_match_all('/Name Server:\s*([^\n]+)/i', $body, $nsMatches);
            $whoisData['name_servers'] = array_map('trim', $nsMatches[1]);
            
        } catch (\Throwable $e) {
            // Fallback: Try Cloudflare/other WHOIS
            try {
                $client2 = new Client(['timeout' => 4, 'connect_timeout' => 3]);
                $resp2 = $client2->get("https://lookup.domaintools.com/{$host}");
                // Basic extraction
            } catch (\Throwable $e2) {
                $whoisData['error'] = 'WHOIS lookup unavailable';
            }
        }
        
        return $whoisData;
    }
    
    /**
     * Get MAC address from network interface
     */
    private function getMACAddress(): array
    {
        $macAddresses = [];
        
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            // Windows
            exec('getmac /v /fo csv 2>nul', $output);
            foreach ($output as $line) {
                $line = trim($line, '"');
                $parts = str_getcsv($line);
                if (count($parts) >= 3 && !empty($parts[1]) && $parts[1] !== 'N/A') {
                    $macAddresses[] = [
                        'interface' => $parts[0] ?? 'Unknown',
                        'mac_address' => $parts[1],
                        'network_module' => $parts[2] ?? 'N/A',
                    ];
                }
            }
        } else {
            // Linux/Mac
            exec('ip link show 2>/dev/null || ifconfig 2>/dev/null', $output);
            foreach ($output as $line) {
                if (preg_match('/([0-9a-fA-F]{2}:[0-9a-fA-F]{2}:[0-9a-fA-F]{2}:[0-9a-fA-F]{2}:[0-9a-fA-F]{2}:[0-9a-fA-F]{2})/', $line, $m)) {
                    $macAddresses[] = ['mac_address' => strtolower($m[1])];
                }
            }
        }
        
        // Remove duplicates
        $uniqueMACs = [];
        foreach ($macAddresses as $mac) {
            if (!empty($mac['mac_address'])) {
                $uniqueMACs[$mac['mac_address']] = $mac;
            }
        }
        
        return array_values($uniqueMACs);
    }
    
    /**
     * Get subnet information
     */
    private function getSubnet(string $ip): string
    {
        if (empty($ip) || $ip === 'N/A') return 'N/A';
        // Simple subnet calculation for common IPs
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            if (count($parts) === 4) {
                return $parts[0] . '.' . $parts[1] . '.' . $parts[2] . '.0/24';
            }
        }
        return 'N/A';
    }
    
    /**
     * Get broadcast address
     */
    private function getBroadcast(string $ip): string
    {
        if (empty($ip) || $ip === 'N/A') return 'N/A';
        return 'N/A'; // Simplified
    }
    
    /**
     * Get default gateway
     */
    private function getGateway(): string
    {
        $gateway = 'N/A';
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            exec('route print 2>nul', $output);
            foreach ($output as $line) {
                if (preg_match('/0\.0\.0\.0\s+([\d\.]+)/', $line, $m)) {
                    $gateway = $m[1];
                    break;
                }
            }
        } else {
            exec('ip route show default 2>/dev/null', $output);
            foreach ($output as $line) {
                if (preg_match('/via\s+([\d\.]+)/', $line, $m)) {
                    $gateway = $m[1];
                    break;
                }
            }
        }
        return $gateway;
    }
    
    /**
     * Get DNS servers
     */
    private function getDNSServers(): array
    {
        $dnsServers = [];
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            exec('ipconfig /all 2>nul', $output);
            foreach ($output as $line) {
                if (preg_match('/DNS Servers?\s*\.\.\.\.\.\.\.\.:\s*([\d\.]+)/i', $line, $m)) {
                    $dnsServers[] = $m[1];
                }
            }
        } else {
            exec('cat /etc/resolv.conf 2>/dev/null', $output);
            foreach ($output as $line) {
                if (preg_match('/nameserver\s+([\d\:a-fA-F\.]+)/', $line, $m)) {
                    $dnsServers[] = $m[1];
                }
            }
        }
        return empty($dnsServers) ? ['N/A'] : $dnsServers;
    }
    
    /**
     * Get ASN information
     */
    private function getASN(string $ip): array
    {
        $asnData = ['asn' => 'N/A', 'asn_name' => 'N/A', 'asn_org' => 'N/A'];
        
        if (empty($ip) || $ip === 'N/A') return $asnData;
        
        try {
            $client = new Client(['timeout' => 5, 'connect_timeout' => 3]);
            $resp = $client->get("https://ipinfo.io/{$ip}/json");
            $data = json_decode((string)$resp->getBody(), true);
            if ($data && isset($data['org'])) {
                $asnData['asn_org'] = $data['org'];
                // Extract ASN number
                if (preg_match('/AS(\d+)/', $data['org'], $m)) {
                    $asnData['asn'] = 'AS' . $m[1];
                    $asnData['asn_name'] = $data['org'];
                }
            }
        } catch (\Throwable $e) {
            // Try alternative
        }
        
        return $asnData;
    }
    
    /**
     * Resolve IP from URL
     */
    private function resolveIP(string $url): string
    {
        $parsed = parse_url($url);
        $host = $parsed['host'] ?? '';
        if (empty($host)) return 'N/A';
        return @gethostbyname($host) ?: 'N/A';
    }
    
    /**
     * Phase 2: HTTP Headers Analysis
     */
    private function traceHeaders(): array
    {
        try {
            $response = $this->client->request('HEAD', $this->url, ['allow_redirects' => true]);
            $headers = $response->getHeaders();
            
            // Parse all headers into associative array
            $parsedHeaders = [];
            foreach ($headers as $name => $values) {
                $parsedHeaders[$name] = is_array($values) ? implode(', ', $values) : $values;
            }
            
            return [
                'status' => 'success',
                'total_headers' => count($parsedHeaders),
                'headers' => $parsedHeaders,
                'important_headers' => [
                    'cache_control' => $parsedHeaders['Cache-Control'] ?? null,
                    'content_security_policy' => $parsedHeaders['Content-Security-Policy'] ?? null,
                    'x_frame_options' => $parsedHeaders['X-Frame-Options'] ?? null,
                    'strict_transport_security' => $parsedHeaders['Strict-Transport-Security'] ?? null,
                    'x_content_type_options' => $parsedHeaders['X-Content-Type-Options'] ?? null,
                    'referrer_policy' => $parsedHeaders['Referrer-Policy'] ?? null,
                    'permissions_policy' => $parsedHeaders['Permissions-Policy'] ?? null,
                    'x_xss_protection' => $parsedHeaders['X-XSS-Protection'] ?? null,
                    'server' => $parsedHeaders['Server'] ?? null,
                    'x_powered_by' => $parsedHeaders['X-Powered-By'] ?? null,
                    'set_cookie' => $parsedHeaders['Set-Cookie'] ?? null,
                    'location' => $parsedHeaders['Location'] ?? null,
                    'last_modified' => $parsedHeaders['Last-Modified'] ?? null,
                    'etag' => $parsedHeaders['ETag'] ?? null,
                    'expires' => $parsedHeaders['Expires'] ?? null,
                    'vary' => $parsedHeaders['Vary'] ?? null,
                    'content_length' => $parsedHeaders['Content-Length'] ?? null,
                    'content_encoding' => $parsedHeaders['Content-Encoding'] ?? null,
                    'connection' => $parsedHeaders['Connection'] ?? null,
                    'access_control_allow_origin' => $parsedHeaders['Access-Control-Allow-Origin'] ?? null,
                    'access_control_allow_methods' => $parsedHeaders['Access-Control-Allow-Methods'] ?? null,
                    'access_control_allow_headers' => $parsedHeaders['Access-Control-Allow-Headers'] ?? null,
                ],
            ];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Phase 3: SSL/TLS Analysis
     */
    private function traceSSL(): array
    {
        $parsedUrl = parse_url($this->url);
        $host = $parsedUrl['host'] ?? '';
        $scheme = $parsedUrl['scheme'] ?? '';
        
        if ($scheme !== 'https') {
            return ['status' => 'skipped', 'reason' => 'URL is not HTTPS'];
        }
        
        $sslInfo = [
            'status' => 'success',
            'host' => $host,
            'url' => $this->url,
            'has_ssl' => true,
            'port' => 443,
        ];
        
        // Try to get SSL context info
        $context = stream_context_create([
            'ssl' => [
                'capture_peer_cert' => true,
                'verify_peer' => false,
                'verify_peer_name' => false,
            ]
        ]);
        
        try {
            $client = @stream_socket_client(
                "ssl://{$host}:443",
                $errno,
                $errstr,
                5,
                STREAM_CLIENT_CONNECT,
                $context
            );
            
            if ($client) {
                $params = stream_context_get_params($client);
                $cert = $params['options']['ssl']['peer_certificate'] ?? null;
                
                if ($cert) {
                    $certData = openssl_x509_parse($cert);
                    if ($certData) {
                        // Public key size: parse cert resource properly (openssl_x509_parse(...,false) has no 'bits')
                        $keySize = 'N/A';
                        $pubKey = @openssl_pkey_get_public($cert);
                        if ($pubKey) {
                            $keyDetails = @openssl_pkey_get_details($pubKey);
                            if (!empty($keyDetails['bits'])) $keySize = $keyDetails['bits'];
                        }
                        $sslInfo['certificate'] = [
                            'subject' => $certData['subject']['CN'] ?? 'N/A',
                            'issuer' => $certData['issuer']['CN'] ?? 'N/A',
                            'valid_from' => date('Y-m-d H:i:s', $certData['validFrom_time_t'] ?? 0),
                            'valid_to' => date('Y-m-d H:i:s', $certData['validTo_time_t'] ?? 0),
                            'serial_number' => $certData['serialNumber'] ?? 'N/A',
                            'signature_algorithm' => $certData['signatureTypeSN'] ?? 'N/A',
                            'public_key_size' => $keySize,
                            'version' => $certData['version'] ?? 'N/A',
                            'extensions' => $this->getCertExtensions($cert),
                        ];
                        
                        // Check if certificate is valid
                        $now = time();
                        $sslInfo['certificate']['is_valid'] = ($now >= $certData['validFrom_time_t'] && $now <= $certData['validTo_time_t']);
                        $sslInfo['certificate']['days_remaining'] = round(($certData['validTo_time_t'] - $now) / 86400, 1);
                    }
                }
                
                // Get cipher info
                $crypto = stream_get_meta_data($client);
                $sslInfo['cipher'] = $crypto['crypto_method'] ?? 'N/A';
                
                fclose($client);
            }
        } catch (\Throwable $e) {
            $sslInfo['error'] = $e->getMessage();
        }
        
        return $sslInfo;
    }
    
    /**
     * Phase 4: HTML Content Analysis
     */
    private function traceContent(): array
    {
        try {
            [$response, $body] = $this->fetchPage();
            
            // Detect charset
            $charset = $this->detectEncoding($response);
            
            // Parse title
            preg_match('/<title[^>]*>(.*?)<\/title>/is', $body, $titleMatch);
            $title = $titleMatch[1] ?? '';
            $title = html_entity_decode(trim($title), ENT_QUOTES, $charset);
            
            // Parse meta description
            preg_match('/<meta[^>]*name=["\']description["\'][^>]*content=["\'](.*?)["\']/is', $body, $descMatch);
            $metaDesc = $descMatch[1] ?? '';
            if (!$metaDesc) {
                preg_match('/<meta[^>]*content=["\'](.*?)["\'][^>]*name=["\']description["\']/is', $body, $descMatch2);
                $metaDesc = $descMatch2[1] ?? '';
            }
            $metaDesc = html_entity_decode(trim($metaDesc), ENT_QUOTES, $charset);
            
            // Parse meta keywords
            preg_match('/<meta[^>]*name=["\']keywords["\'][^>]*content=["\'](.*?)["\']/is', $body, $kwMatch);
            $metaKeywords = $kwMatch[1] ?? '';
            $metaKeywords = html_entity_decode(trim($metaKeywords), ENT_QUOTES, $charset);
            
            // Parse meta viewport
            preg_match('/<meta[^>]*name=["\']viewport["\'][^>]*content=["\'](.*?)["\']/is', $body, $vpMatch);
            $viewport = $vpMatch[1] ?? '';
            
            // Parse meta robots
            preg_match('/<meta[^>]*name=["\']robots["\'][^>]*content=["\'](.*?)["\']/is', $body, $robotsMatch);
            $robots = $robotsMatch[1] ?? '';
            
            // Parse OG tags
            $ogTags = [];
            preg_match_all('/<meta[^>]*property=["\']og:([^"\']+)["\'][^>]*content=["\'](.*?)["\']/is', $body, $ogMatches);
            for ($i = 0; $i < count($ogMatches[1]); $i++) {
                $ogTags['og:' . $ogMatches[1][$i]] = html_entity_decode($ogMatches[2][$i], ENT_QUOTES, $charset);
            }
            
            // Parse Twitter Cards
            $twitterTags = [];
            preg_match_all('/<meta[^>]*name=["\']twitter:([^"\']+)["\'][^>]*content=["\'](.*?)["\']/is', $body, $twMatches);
            for ($i = 0; $i < count($twMatches[1]); $i++) {
                $twitterTags['twitter:' . $twMatches[1][$i]] = html_entity_decode($twMatches[2][$i], ENT_QUOTES, $charset);
            }
            
            // Parse favicon
            preg_match_all('/<link[^>]*rel=["\']?(?:icon|shortcut icon|apple-touch-icon)["\']?[^>]*href=["\'](.*?)["\']/is', $body, $favMatches);
            $favicons = [];
            foreach ($favMatches[1] as $fav) {
                $favicons[] = $this->makeAbsoluteUrl($fav);
            }
            
            // Count elements
            $elementCounts = [
                'html_tags' => substr_count($body, '<') - substr_count($body, '</'),
                'head_count' => substr_count($body, '<head>') + substr_count($body, '<head '),
                'body_count' => substr_count($body, '<body>') + substr_count($body, '<body '),
                'div_count' => substr_count($body, '<div'),
                'script_count' => substr_count($body, '<script'),
                'style_count' => substr_count($body, '<style'),
                'img_count' => substr_count($body, '<img'),
                'a_count' => substr_count($body, '<a '),
                'form_count' => substr_count($body, '<form'),
                'input_count' => substr_count($body, '<input'),
                'h1_count' => substr_count($body, '<h1'),
                'h2_count' => substr_count($body, '<h2'),
                'h3_count' => substr_count($body, '<h3'),
                'h4_count' => substr_count($body, '<h4'),
                'h5_count' => substr_count($body, '<h5'),
                'h6_count' => substr_count($body, '<h6'),
                'ul_count' => substr_count($body, '<ul'),
                'ol_count' => substr_count($body, '<ol'),
                'table_count' => substr_count($body, '<table'),
                'iframe_count' => substr_count($body, '<iframe'),
                'video_count' => substr_count($body, '<video'),
                'audio_count' => substr_count($body, '<audio'),
                'canvas_count' => substr_count($body, '<canvas'),
                'svg_count' => substr_count($body, '<svg'),
                'nav_count' => substr_count($body, '<nav'),
                'footer_count' => substr_count($body, '<footer'),
                'header_count' => substr_count($body, '<header'),
                'main_count' => substr_count($body, '<main'),
                'section_count' => substr_count($body, '<section'),
                'article_count' => substr_count($body, '<article'),
                'aside_count' => substr_count($body, '<aside'),
            ];
            
            // Detect language
            preg_match('/<html[^>]*lang=["\']?([a-zA-Z-]+)/is', $body, $langMatch);
            $language = $langMatch[1] ?? 'Not detected';
            
            // Detect charset from meta
            preg_match('/<meta[^>]*charset=["\']?([a-zA-Z0-9-]+)/is', $body, $charsetMatch);
            $metaCharset = $charsetMatch[1] ?? '';
            
            // Calculate word count and text length
            $textContent = strip_tags($body);
            $textContent = preg_replace('/\s+/', ' ', trim($textContent));
            $wordCount = str_word_count($textContent);
            $charCount = strlen($textContent);
            
            // Image analysis
            $images = [];
            preg_match_all('/<img[^>]*src=["\'](.*?)["\']/is', $body, $imgMatches);
            foreach ($imgMatches[1] as $src) {
                $images[] = [
                    'src' => $this->makeAbsoluteUrl($src),
                    'alt' => '',
                ];
            }
            preg_match_all('/<img[^>]*src=["\'](.*?)["\'][^>]*alt=["\'](.*?)["\']/is', $body, $imgAltMatches);
            $i = 0;
            foreach ($imgMatches[1] as $src) {
                $images[$i]['alt'] = html_entity_decode($imgAltMatches[2][$i] ?? '', ENT_QUOTES, $charset);
                $i++;
            }
            
            return [
                'status' => 'success',
                'title' => $title,
                'meta_description' => $metaDesc,
                'meta_keywords' => $metaKeywords,
                'meta_viewport' => $viewport,
                'meta_robots' => $robots,
                'language' => $language,
                'charset' => $charset,
                'meta_charset' => $metaCharset,
                'og_tags' => $ogTags,
                'twitter_tags' => $twitterTags,
                'favicons' => $favicons,
                'element_counts' => $elementCounts,
                'word_count' => $wordCount,
                'character_count' => $charCount,
                'images' => $images,
                'total_images' => count($images),
                'has_mobile_viewport' => !empty($viewport) && stripos($viewport, 'width=device-width') !== false,
                'has_meta_description' => !empty($metaDesc),
                'has_og_tags' => count($ogTags) > 0,
                'has_twitter_cards' => count($twitterTags) > 0,
                'html_size' => strlen($body),
                'plain_text_size' => strlen($textContent),
                'code_density' => $charCount > 0 ? round((strlen($body) - $charCount) / strlen($body) * 100, 2) : 0,
            ];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Phase 5: Technology Detection
     */
    private function detectTechnology(): array
    {
        try {
            [$response, $body] = $this->fetchPage();
            $headers = $response->getHeaders();
            
            $tech = [
                'status' => 'success',
                'categories' => [],
                'details' => [],
            ];
            
            $serverHeader = strtolower($headers['Server'][0] ?? '');
            $poweredBy = strtolower($headers['X-Powered-By'][0] ?? '');
            $contentType = strtolower($headers['Content-Type'][0] ?? '');
            
            // Server detection
            $servers = [];
            if (stripos($serverHeader, 'nginx') !== false) {
                $servers[] = 'Nginx';
                // Try to get Nginx version
                if (preg_match('/nginx\/([\d.]+)/i', $serverHeader, $m)) {
                    $tech['details']['nginx_version'] = $m[1];
                }
            }
            if (stripos($serverHeader, 'apache') !== false) {
                $servers[] = 'Apache';
                if (preg_match('/apache\/([\d.]+)/i', $serverHeader, $m)) {
                    $tech['details']['apache_version'] = $m[1];
                }
            }
            if (stripos($serverHeader, 'iis') !== false) {
                $servers[] = 'Microsoft IIS';
            }
            if (stripos($serverHeader, 'cloudflare') !== false || stripos($serverHeader, 'cf-worker') !== false) {
                $servers[] = 'Cloudflare';
            }
            if (stripos($serverHeader, 'litespeed') !== false) {
                $servers[] = 'LiteSpeed';
            }
            if (empty($servers)) {
                $servers[] = 'Unknown';
            }
            $tech['categories']['server'] = $servers;
            
            // Framework detection
            $frameworks = [];
            // PHP frameworks
            if (stripos($serverHeader, 'php') !== false || stripos($poweredBy, 'php') !== false) {
                $frameworks[] = 'PHP';
            }
            if (stripos($body, 'laravel') !== false) {
                $frameworks[] = 'Laravel';
            }
            if (stripos($body, 'codeigniter') !== false) {
                $frameworks[] = 'CodeIgniter';
            }
            if (stripos($body, 'symfony') !== false || stripos($body, 'symfony.js') !== false) {
                $frameworks[] = 'Symfony';
            }
            if (stripos($body, 'yii') !== false) {
                $frameworks[] = 'Yii';
            }
            if (stripos($body, 'cakephp') !== false) {
                $frameworks[] = 'CakePHP';
            }
            // JS frameworks
            if (stripos($body, 'react') !== false && stripos($body, 'create-react') !== false) {
                $frameworks[] = 'React';
            }
            if (stripos($body, 'vue') !== false && stripos($body, 'vue.js') !== false) {
                $frameworks[] = 'Vue.js';
            }
            if (stripos($body, 'angular') !== false) {
                $frameworks[] = 'Angular';
            }
            if (stripos($body, 'next') !== false) {
                $frameworks[] = 'Next.js';
            }
            if (stripos($body, 'nuxt') !== false) {
                $frameworks[] = 'Nuxt.js';
            }
            if (stripos($body, 'svelte') !== false) {
                $frameworks[] = 'Svelte';
            }
            // CMS detection
            if (stripos($body, 'wp-content') !== false || stripos($body, 'wordpress') !== false) {
                $frameworks[] = 'WordPress';
                if (preg_match('/wp-json\/wp\/v\d\//', $body)) {
                    $tech['details']['wordpress_api'] = true;
                }
            }
            if (stripos($body, 'craft') !== false || stripos($body, 'craft-cms') !== false) {
                $frameworks[] = 'Craft CMS';
            }
            if (stripos($body, 'shopify') !== false) {
                $frameworks[] = 'Shopify';
            }
            if (stripos($body, 'woocommerce') !== false) {
                $frameworks[] = 'WooCommerce';
            }
            if (stripos($body, 'magento') !== false) {
                $frameworks[] = 'Magento';
            }
            if (stripos($body, 'joomla') !== false) {
                $frameworks[] = 'Joomla';
            }
            if (stripos($body, 'drupal') !== false) {
                $frameworks[] = 'Drupal';
            }
            if (stripos($body, 'ghost') !== false) {
                $frameworks[] = 'Ghost';
            }
            // CDN detection
            if (stripos($serverHeader, 'cloudflare') !== false) {
                $tech['categories']['cdn'] = 'Cloudflare';
            }
            if (stripos($body, 'cdnjs.cloudflare.com') !== false) {
                $tech['categories']['cdn'] = 'Cloudflare CDN';
            }
            if (stripos($body, 'cdn.jsdelivr.net') !== false) {
                $tech['categories']['cdn'] = 'jsDelivr CDN';
            }
            // Other tech
            if (preg_match('/jquery/', $body)) {
                $frameworks[] = 'jQuery';
            }
            if (preg_match('/bootstrap/', $body)) {
                $frameworks[] = 'Bootstrap';
            }
            if (preg_match('/tailwind/', $body)) {
                $frameworks[] = 'Tailwind CSS';
            }
            if (preg_match('/font-awesome/', $body) || preg_match('/fontawesome/', $body)) {
                $frameworks[] = 'Font Awesome';
            }
            if (preg_match('/materialize/', $body)) {
                $frameworks[] = 'Materialize';
            }
            if (stripos($body, 'analytics') !== false || stripos($body, 'ga.js') !== false || stripos($body, 'gtag') !== false) {
                $frameworks[] = 'Google Analytics';
            }
            if (stripos($body, 'google-analytics') !== false || stripos($body, 'googletagmanager') !== false) {
                $tech['details']['google_tag_manager'] = true;
            }
            if (stripos($body, 'facebook.net') !== false || stripos($body, 'fbq') !== false) {
                $tech['details']['facebook_pixel'] = true;
            }
            if (stripos($body, 'hubspot') !== false) {
                $frameworks[] = 'HubSpot';
            }
            if (stripos($body, 'hotjar') !== false) {
                $frameworks[] = 'Hotjar';
            }
            if (stripos($body, 'optimizely') !== false) {
                $frameworks[] = 'Optimizely';
            }
            
            $tech['categories']['frameworks'] = array_unique($frameworks);
            $tech['categories']['frontend'] = array_filter($frameworks, function($f) {
                return !in_array($f, ['PHP', 'WordPress', 'WooCommerce', 'Magento', 'Joomla', 'Drupal', 'Ghost', 'Shopify', 'HubSpot']);
            });
            
            // JavaScript libraries
            $jsLibs = [];
            if (preg_match('/jquery-[^\.]+/i', $body, $m) || preg_match('/jquery\.js/', $body)) {
                $jsLibs[] = 'jQuery';
            }
            if (preg_match('/bootstrap\.js/', $body)) {
                $jsLibs[] = 'Bootstrap JS';
            }
            if (preg_match('/popper\.js|popper\.min/', $body)) {
                $jsLibs[] = 'Popper.js';
            }
            if (preg_match('/moment\.js/', $body)) {
                $jsLibs[] = 'Moment.js';
            }
            if (preg_match('/lodash/', $body)) {
                $jsLibs[] = 'Lodash';
            }
            if (preg_match('/axios/', $body)) {
                $jsLibs[] = 'Axios';
            }
            $tech['categories']['javascript_libraries'] = $jsLibs;
            
            // Content Management System
            if (!empty($tech['categories']['frameworks'])) {
                $cms = array_intersect($tech['categories']['frameworks'], ['WordPress', 'Joomla', 'Drupal', 'Magento', 'Shopify', 'Ghost', 'Craft CMS', 'WooCommerce']);
                if (!empty($cms)) {
                    $tech['categories']['cms'] = array_values($cms);
                }
            }
            
            // Technologies array
            $allTech = [];
            foreach ($tech['categories'] as $cat => $items) {
                foreach ((array)$items as $item) {
                    $allTech[] = ['category' => $cat, 'technology' => $item];
                }
            }
            $tech['all_technologies'] = $allTech;
            
        } catch (\Throwable $e) {
            $tech = ['status' => 'error', 'error' => $e->getMessage()];
        }
        
        return $tech;
    }
    
    /**
     * Phase 6: Links Extraction
     */
    private function extractLinks(): array
    {
        try {
            [$response, $body] = $this->fetchPage();
            
            $links = [];
            $domains = [];
            
            // Extract all href links
            $baseHost = parse_url($this->url, PHP_URL_HOST) ?? '';
            preg_match_all('/<a\s[^>]*href=["\'](.*?)["\']/is', $body, $hrefMatches);
            foreach ($hrefMatches[1] as $href) {
                $absoluteUrl = $this->makeAbsoluteUrl($href);
                $parsed = parse_url($absoluteUrl);
                $domain = $parsed['host'] ?? '';
                
                $links[] = [
                    'href' => $href,
                    'absolute_url' => $absoluteUrl,
                    'domain' => $domain,
                    'is_internal' => empty($domain) || $domain === $baseHost,
                ];
                
                if (!empty($domain) && !in_array($domain, $domains)) {
                    $domains[] = $domain;
                }
            }
            
            // Extract all src links
            $srcLinks = [];
            preg_match_all('/<img[^>]*src=["\'](.*?)["\']/is', $body, $imgSrc);
            preg_match_all('/<script[^>]*src=["\'](.*?)["\']/is', $body, $scriptSrc);
            preg_match_all('/<link[^>]*href=["\'](.*?)["\']/is', $body, $linkHref);
            
            foreach ($imgSrc[1] as $src) {
                $srcLinks[] = ['type' => 'image', 'url' => $this->makeAbsoluteUrl($src)];
            }
            foreach ($scriptSrc[1] as $src) {
                $srcLinks[] = ['type' => 'script', 'url' => $this->makeAbsoluteUrl($src)];
            }
            foreach ($linkHref[1] as $href) {
                $srcLinks[] = ['type' => 'stylesheet', 'url' => $this->makeAbsoluteUrl($href)];
            }
            
            // Extract form actions
            $formActions = [];
            preg_match_all('/<form[^>]*action=["\'](.*?)["\']/is', $body, $formMatches);
            foreach ($formMatches[1] as $action) {
                $formActions[] = $this->makeAbsoluteUrl($action);
            }
            
            // Count by type
            $linkStats = [
                'total_links' => count($links),
                'internal_links' => count(array_filter($links, fn($l) => $l['is_internal'])),
                'external_links' => count(array_filter($links, fn($l) => !$l['is_internal'])),
                'unique_domains' => count($domains),
                'total_assets' => count($srcLinks),
                'form_actions' => count($formActions),
            ];
            
            return [
                'status' => 'success',
                'links' => array_slice($links, 0, 50), // Limit to 50 links
                'src_links' => array_slice($srcLinks, 0, 30),
                'form_actions' => array_slice($formActions, 0, 10),
                'stats' => $linkStats,
                'unique_domains' => array_slice($domains, 0, 20),
            ];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Phase 7: Form Detection
     */
    private function detectForms(): array
    {
        try {
            [$response, $body] = $this->fetchPage();
            
            $forms = [];
            
            preg_match_all('/<form[^>]*>/is', $body, $formMatches);
            foreach ($formMatches[0] as $index => $formHtml) {
                $form = ['index' => $index];
                
                // Extract action
                if (preg_match('/action=["\'](.*?)["\']/i', $formHtml, $m)) {
                    $form['action'] = $this->makeAbsoluteUrl($m[1]);
                }
                
                // Extract method
                if (preg_match('/method=["\'](\w+)["\']/i', $formHtml, $m)) {
                    $form['method'] = strtoupper($m[1]);
                } else {
                    $form['method'] = 'GET';
                }
                
                // Extract enctype
                if (preg_match('/enctype=["\'](.*?)["\']/i', $formHtml, $m)) {
                    $form['enctype'] = $m[1];
                }
                
                // Extract inputs
                preg_match_all('/<input[^>]*name=["\'](.*?)["\']/i', $formHtml, $inputNames);
                $form['input_count'] = count($inputNames[1]);
                $form['input_names'] = $inputNames[1];
                
                // Extract input types
                preg_match_all('/<input[^>]*(?:type=["\'](\w+)["\'])?/i', $formHtml, $inputTypes);
                $form['input_types'] = array_filter($inputTypes[1]);
                
                // Check for password fields
                $form['has_password'] = stripos($formHtml, 'type="password"') !== false || stripos($formHtml, "type='password'") !== false;
                
                // Check for hidden fields
                $form['has_hidden'] = stripos($formHtml, 'type="hidden"') !== false;
                
                // Check for file upload
                $form['has_file'] = stripos($formHtml, 'type="file"') !== false || stripos($formHtml, 'enctype="multipart/form-data"') !== false;
                
                // Extract selects
                preg_match_all('/<select[^>]*name=["\'](.*?)["\']/i', $formHtml, $selectNames);
                $form['select_count'] = count($selectNames[1]);
                
                // Extract textareas
                preg_match_all('/<textarea[^>]*name=["\'](.*?)["\']/i', $formHtml, $textareaNames);
                $form['textarea_count'] = count($textareaNames[1]);
                
                // Extract labels
                preg_match_all('/<label[^>]*>(.*?)<\/label>/is', $formHtml, $labelMatches);
                $form['label_count'] = count($labelMatches[1]);
                $form['labels'] = array_map('trim', $labelMatches[1]);
                
                // Extract CSRF tokens
                preg_match_all('/<input[^>]*type=["\']hidden["\'][^>]*name=["\']([^"\']+)["\'][^>]*value=["\']([^"\']+)["\']/i', $formHtml, $hiddenMatches);
                $form['hidden_fields'] = array_map(fn($n, $v) => ['name' => $n, 'value' => $v], $hiddenMatches[1], $hiddenMatches[2]);
                
                $forms[] = $form;
            }
            
            return [
                'status' => 'success',
                'total_forms' => count($forms),
                'forms' => $forms,
                'summary' => [
                    'total_inputs' => array_sum(array_column($forms, 'input_count')),
                    'total_selects' => array_sum(array_column($forms, 'select_count')),
                    'total_textareas' => array_sum(array_column($forms, 'textarea_count')),
                    'forms_with_password' => count(array_filter($forms, fn($f) => $f['has_password'])),
                    'forms_with_file' => count(array_filter($forms, fn($f) => $f['has_file'])),
                    'forms_with_hidden' => count(array_filter($forms, fn($f) => $f['has_hidden'])),
                ],
            ];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Phase 8: SEO Analysis
     */
    private function analyzeSEO(): array
    {
        try {
            [$response, $body] = $this->fetchPage();
            
            $seo = ['status' => 'success', 'score' => 0, 'checks' => []];
            $score = 0;
            
            // Check title tag
            $hasTitle = preg_match('/<title[^>]*>(.*?)<\/title>/is', $body, $m);
            if ($hasTitle) {
                $titleLen = strlen(strip_tags($m[1]));
                $score += 20;
                $seo['checks']['title_tag'] = [
                    'pass' => true,
                    'length' => $titleLen,
                    'optimal' => $titleLen >= 30 && $titleLen <= 60,
                    'message' => $titleLen >= 30 && $titleLen <= 60 ? 'Title length is optimal' : ($titleLen > 60 ? 'Title is too long (optimal: 30-60 chars)' : 'Title should be longer (optimal: 30-60 chars)'),
                ];
            } else {
                $seo['checks']['title_tag'] = ['pass' => false, 'message' => 'No title tag found'];
            }
            
            // Check meta description
            $hasMetaDesc = preg_match('/<meta[^>]*name=["\']description["\'][^>]*content=["\'](.*?)["\']/is', $body, $m);
            if ($hasMetaDesc) {
                $descLen = strlen($m[1]);
                $score += 15;
                $seo['checks']['meta_description'] = [
                    'pass' => true,
                    'length' => $descLen,
                    'optimal' => $descLen >= 120 && $descLen <= 160,
                    'message' => 'Meta description found (' . $descLen . ' chars)',
                ];
            } else {
                $seo['checks']['meta_description'] = ['pass' => false, 'message' => 'No meta description found'];
            }
            
            // Check H1 tag
            $hasH1 = preg_match_all('/<h1[^>]*>(.*?)<\/h1>/is', $body, $m);
            $seo['checks']['h1_tag'] = [
                'pass' => $hasH1 > 0,
                'count' => $hasH1,
                'message' => $hasH1 > 0 ? "Found $hasH1 H1 tag(s)" : 'No H1 tag found',
            ];
            if ($hasH1 > 0) $score += 15;
            
            // Check heading hierarchy
            $headings = ['h1' => 0, 'h2' => 0, 'h3' => 0, 'h4' => 0, 'h5' => 0, 'h6' => 0];
            foreach (['h1', 'h2', 'h3', 'h4', 'h5', 'h6'] as $level) {
                preg_match_all('/<' . $level . '[^>]*>/', $body, $m);
                $headings[$level] = count($m[0]);
            }
            $seo['checks']['heading_hierarchy'] = ['counts' => $headings];
            
            // Check alt attributes on images
            $imagesWithoutAlt = 0;
            preg_match_all('/<img[^>]*>/i', $body, $imgMatches);
            $totalImages = count($imgMatches[0]);
            foreach ($imgMatches[0] as $img) {
                if (!preg_match('/alt=["\'][^"\']*["\']/i', $img) && !preg_match('/alt=["\']*["\']/i', $img)) {
                    $imagesWithoutAlt++;
                }
            }
            if ($totalImages > 0 && $imagesWithoutAlt === 0) {
                $score += 10;
            }
            $seo['checks']['image_alt'] = [
                'total_images' => $totalImages,
                'without_alt' => $imagesWithoutAlt,
                'pass' => $imagesWithoutAlt === 0 || $totalImages === 0,
            ];
            
            // Check canonical URL
            $hasCanonical = preg_match('/<link[^>]*rel=["\']canonical["\'][^>]*href=["\'](.*?)["\']/i', $body, $m);
            $seo['checks']['canonical_url'] = ['pass' => $hasCanonical, 'url' => $hasCanonical ? $m[1] : ''];
            if ($hasCanonical) $score += 10;
            
            // Check meta robots
            preg_match('/<meta[^>]*name=["\']robots["\'][^>]*content=["\'](.*?)["\']/i', $body, $m);
            $seo['checks']['meta_robots'] = ['pass' => true, 'content' => $m[1] ?? 'Not set'];
            if (!empty($m[1])) $score += 5;
            
            // Check OG tags
            $hasOg = preg_match_all('/<meta[^>]*property=["\']og:/', $body);
            $seo['checks']['open_graph'] = ['pass' => $hasOg > 0, 'count' => $hasOg];
            if ($hasOg > 0) $score += 10;
            
            // Check mobile viewport
            $hasViewport = preg_match('/<meta[^>]*name=["\']viewport["\']/i', $body);
            $seo['checks']['mobile_viewport'] = ['pass' => $hasViewport];
            if ($hasViewport) $score += 10;
            
            // Check HTTPS
            $isHttps = stripos($this->url, 'https://') === 0;
            $seo['checks']['https'] = ['pass' => $isHttps];
            if ($isHttps) $score += 5;
            
            // Check XML sitemap
            $hasSitemap = stripos($body, 'sitemap') !== false;
            preg_match('/<link[^>]*rel=["\']sitemap["\']/i', $body, $smMatch);
            $seo['checks']['xml_sitemap'] = ['pass' => (bool)$smMatch];
            
            // Check page speed indicators
            $scriptCount = substr_count($body, '<script');
            $cssCount = substr_count($body, '<link');
            $bodySize = strlen($body);
            
            $seo['checks']['page_size'] = [
                'html_size_bytes' => $bodySize,
                'script_tags' => $scriptCount,
                'css_links' => $cssCount,
                'pass' => $bodySize < 500000, // < 500KB
            ];
            
            // Check for render-blocking resources
            $renderBlocking = [];
            preg_match_all('/<link[^>]*rel=["\']stylesheet["\'][^>]*>/i', $body, $cssMatches);
            preg_match_all('/<script[^>]*>(?!<script[^>]*src)/i', $body, $inlineScriptMatches);
            $renderBlocking['inline_scripts'] = count($inlineScriptMatches[0]);
            $renderBlocking['stylesheets'] = count($cssMatches[0]);
            $seo['checks']['render_blocking'] = $renderBlocking;
            
            $seo['score'] = min(100, $score);
            $seo['score_label'] = $score >= 80 ? 'Excellent' : ($score >= 60 ? 'Good' : ($score >= 40 ? 'Fair' : 'Needs Improvement'));
            
        } catch (\Throwable $e) {
            $seo = ['status' => 'error', 'error' => $e->getMessage()];
        }
        
        return $seo;
    }
    
    /**
     * Phase 9: Security Analysis
     */
    private function analyzeSecurity(): array
    {
        try {
            $response = $this->client->request('HEAD', $this->url, ['allow_redirects' => true]);
            $headers = $response->getHeaders();
            
            $security = ['status' => 'success', 'headers' => [], 'score' => 0];
            $score = 0;
            
            // Check security headers
            $checks = [
                'Strict-Transport-Security' => ['label' => 'HSTS', 'weight' => 20],
                'Content-Security-Policy' => ['label' => 'CSP', 'weight' => 25],
                'X-Content-Type-Options' => ['label' => 'X-Content-Type-Options', 'weight' => 10],
                'X-Frame-Options' => ['label' => 'X-Frame-Options', 'weight' => 10],
                'X-XSS-Protection' => ['label' => 'X-XSS-Protection', 'weight' => 5],
                'Referrer-Policy' => ['label' => 'Referrer-Policy', 'weight' => 5],
                'Permissions-Policy' => ['label' => 'Permissions-Policy', 'weight' => 5],
                'Access-Control-Allow-Origin' => ['label' => 'CORS', 'weight' => 5],
            ];
            
            foreach ($checks as $headerName => $check) {
                $value = $headers[$headerName][0] ?? null;
                $present = !empty($value);
                
                if ($present) {
                    $score += $check['weight'];
                }
                
                $security['headers'][$headerName] = [
                    'present' => $present,
                    'value' => $value,
                    'weight' => $check['weight'],
                ];
            }
            
            // Check cookie security
            $setCookie = $headers['Set-Cookie'][0] ?? null;
            $cookieSecurity = [
                'has_set_cookie' => !empty($setCookie),
                'has_secure' => false,
                'has_httponly' => false,
                'has_samesite' => false,
            ];
            if ($setCookie) {
                $cookieSecurity['has_secure'] = stripos($setCookie, 'Secure') !== false;
                $cookieSecurity['has_httponly'] = stripos($setCookie, 'HttpOnly') !== false;
                $cookieSecurity['has_samesite'] = stripos($setCookie, 'SameSite') !== false;
            }
            $security['cookie_security'] = $cookieSecurity;
            
            // Check if HTTPS
            $security['is_https'] = stripos($this->url, 'https://') === 0;
            
            // Check for outdated server software
            $serverHeader = strtolower($headers['Server'][0] ?? '');
            if (preg_match('/apache\/([\d.]+)/i', $serverHeader, $m)) {
                $apacheVersion = (float)$m[1];
                $security['server_vulnerable'] = $apacheVersion < 2.4;
                $security['apache_version'] = $m[1];
            }
            
            $score = min(100, $score);
            $security['score'] = $score;
            $security['score_label'] = $score >= 70 ? 'Good' : ($score >= 40 ? 'Fair' : 'Poor');
            
        } catch (\Throwable $e) {
            $security = ['status' => 'error', 'error' => $e->getMessage()];
        }
        
        return $security;
    }
    
    /**
     * Phase 10: Performance Analysis
     */
    private function analyzePerformance(): array
    {
        try {
            $basic = $this->results['basic'] ?? [];
            
            $performance = [
                'status' => 'success',
                'response_time_ms' => $basic['response_time_ms'] ?? null,
                'content_length' => $basic['content_length'] ?? null,
                'performance_score' => 0,
                'indicators' => [],
            ];
            
            // Response time score
            $responseTime = $basic['response_time_ms'] ?? 0;
            if ($responseTime < 200) {
                $performance['indicators']['response_time'] = ['grade' => 'A+', 'value' => $responseTime . 'ms', 'weight' => 25];
                $performance['performance_score'] += 25;
            } elseif ($responseTime < 500) {
                $performance['indicators']['response_time'] = ['grade' => 'A', 'value' => $responseTime . 'ms', 'weight' => 25];
                $performance['performance_score'] += 20;
            } elseif ($responseTime < 1000) {
                $performance['indicators']['response_time'] = ['grade' => 'B', 'value' => $responseTime . 'ms', 'weight' => 20];
                $performance['performance_score'] += 15;
            } else {
                $performance['indicators']['response_time'] = ['grade' => 'C', 'value' => $responseTime . 'ms', 'weight' => 10];
                $performance['performance_score'] += 5;
            }
            
            // Content size score
            $contentLength = $basic['content_length'] ?? 0;
            if ($contentLength < 50000) {
                $performance['indicators']['content_size'] = ['grade' => 'A+', 'value' => round($contentLength / 1024, 1) . ' KB', 'weight' => 20];
                $performance['performance_score'] += 20;
            } elseif ($contentLength < 200000) {
                $performance['indicators']['content_size'] = ['grade' => 'A', 'value' => round($contentLength / 1024, 1) . ' KB', 'weight' => 18];
                $performance['performance_score'] += 18;
            } elseif ($contentLength < 500000) {
                $performance['indicators']['content_size'] = ['grade' => 'B', 'value' => round($contentLength / 1024, 1) . ' KB', 'weight' => 12];
                $performance['performance_score'] += 12;
            } else {
                $performance['indicators']['content_size'] = ['grade' => 'C', 'value' => round($contentLength / 1024, 1) . ' KB', 'weight' => 5];
                $performance['performance_score'] += 5;
            }
            
            // Server response grade
            $performance['performance_score'] += 15; // Base score
            
            $performance['indicators']['overall_score'] = min(100, $performance['performance_score']);
            
        } catch (\Throwable $e) {
            $performance = ['status' => 'error', 'error' => $e->getMessage()];
        }
        
        return $performance;
    }
    
    /**
     * Helper: Normalize URL
     */
    private function normalizeUrl(string $url): string
    {
        // Add scheme if missing
        if (!preg_match('/^https?:\/\//i', $url)) {
            $url = 'https://' . $url;
        }
        return rtrim($url, '/');
    }
    
    /**
     * Helper: Detect encoding
     */
    private function detectEncoding($response): string
    {
        $contentType = $response->getHeaderLine('Content-Type');
        if (preg_match('/charset=([a-zA-Z0-9-]+)/i', $contentType, $m)) {
            return $m[1];
        }
        return 'UTF-8';
    }
    
    /**
     * Helper: Make relative URL absolute
     */
    private function makeAbsoluteUrl(string $url): string
    {
        if (preg_match('/^https?:\/\//i', $url)) return $url;
        if (preg_match('/^\/\//', $url)) return 'https:' . $url;
        if (preg_match('/^\//', $url)) {
            $parsed = parse_url($this->url);
            return $parsed['scheme'] . '://' . $parsed['host'] . $url;
        }
        return $this->url . '/' . $url;
    }
    
    /**
     * Helper: Count redirects (from redirect-tracking header history)
     */
    private function countRedirects($response): int
    {
        if (is_array($response)) {
            return count($response);
        }
        if (is_object($response) && method_exists($response, 'getHeader')) {
            return count($response->getHeader('X-Guzzle-Redirect-History'));
        }
        return 0;
    }
    
    /**
     * Helper: Build request string size estimate
     */
    private function buildRequestString(): string
    {
        $request = "GET {$this->url} HTTP/1.1\r\n";
        $request .= "Host: " . parse_url($this->url, PHP_URL_HOST) . "\r\n";
        $request .= "User-Agent: OSINT-Framework/1.0\r\n";
        $request .= "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8\r\n";
        $request .= "Connection: close\r\n\r\n";
        return $request;
    }
    
    /**
     * Helper: Get certificate extensions
     */
    private function getCertExtensions($cert): array
    {
        $extensions = [];
        // Basic constraints, key usage, etc.
        return $extensions;
    }
    
    /**
     * Save results to SQLite database
     */
    private function saveToDatabase()
    {
        if (!$this->pdo) return;
        
        try {
            // Create table if not exists with identity fields
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS url_traces (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                trace_id TEXT,
                url TEXT NOT NULL,
                final_url TEXT,
                status_code INTEGER,
                response_time_ms REAL,
                content_length INTEGER,
                title TEXT,
                meta_description TEXT,
                language TEXT,
                charset TEXT,
                server TEXT,
                ssl_valid INTEGER DEFAULT 0,
                ssl_days_remaining REAL DEFAULT 0,
                technologies TEXT,
                seo_score INTEGER DEFAULT 0,
                security_score INTEGER DEFAULT 0,
                performance_score INTEGER DEFAULT 0,
                links_count INTEGER DEFAULT 0,
                forms_count INTEGER DEFAULT 0,
                headers_json TEXT,
                -- Identity fields
                trace_timestamp TEXT,
                trace_date TEXT,
                trace_time TEXT,
                trace_timezone TEXT,
                trace_epoch INTEGER,
                ip_address TEXT,
                ip_country TEXT,
                ip_region TEXT,
                ip_city TEXT,
                ip_latitude REAL,
                ip_longitude REAL,
                ip_timezone TEXT,
                ip_isp TEXT,
                ip_organization TEXT,
                ip_asn TEXT,
                reverse_dns TEXT,
                first_published_created TEXT,
                first_published_registrar TEXT,
                first_published_expiry TEXT,
                first_published_country TEXT,
                first_published_ns TEXT,
                -- Network identity
                mac_address TEXT,
                subnet TEXT,
                gateway TEXT,
                dns_servers TEXT,
                -- Time data
                local_time_date TEXT,
                local_time_full TEXT,
                local_time_zone TEXT,
                local_time_dst INTEGER,
                created_at TEXT DEFAULT (datetime('now')),
                updated_at TEXT DEFAULT (datetime('now')),
                UNIQUE(url)
            )");
            
            $identity = $this->results['identity'] ?? [];
            $location = $identity['location'] ?? [];
            $firstPublished = $identity['first_published'] ?? [];
            $network = $identity['network'] ?? [];
            $localTime = $identity['local_time'] ?? [];
            $macAddresses = $identity['mac_address'] ?? [];
            
            // Build MAC address string
            $macStr = '';
            foreach ($macAddresses as $macEntry) {
                if (!empty($macStr)) $macStr .= '; ';
                $macStr .= ($macEntry['mac_address'] ?? '') . ($macEntry['interface'] ? ' (' . $macEntry['interface'] . ')' : '');
            }
            
            $stmt = $this->pdo->prepare("INSERT OR REPLACE INTO url_traces 
                (trace_id, url, final_url, status_code, response_time_ms, content_length, title, meta_description, 
                 language, charset, server, ssl_valid, ssl_days_remaining, technologies, seo_score, 
                 security_score, performance_score, links_count, forms_count, headers_json,
                 trace_timestamp, trace_date, trace_time, trace_timezone, trace_epoch,
                 ip_address, ip_country, ip_region, ip_city, ip_latitude, ip_longitude, ip_timezone, ip_isp, ip_organization, ip_asn, reverse_dns,
                 first_published_created, first_published_registrar, first_published_expiry, first_published_country, first_published_ns,
                 mac_address, subnet, gateway, dns_servers,
                 local_time_date, local_time_full, local_time_zone, local_time_dst, updated_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?,
                        ?, ?, ?, ?,
                        ?, ?, ?, ?, datetime('now'))");
            
            $stmt->execute([
                $this->getTraceId(),
                $this->url,
                $this->results['basic']['final_url'] ?? null,
                $this->results['basic']['status_code'] ?? null,
                $this->results['basic']['response_time_ms'] ?? null,
                $this->results['basic']['content_length'] ?? null,
                $this->results['content']['title'] ?? null,
                $this->results['content']['meta_description'] ?? null,
                $this->results['content']['language'] ?? null,
                $this->results['content']['charset'] ?? null,
                $this->results['basic']['server'] ?? null,
                $this->results['ssl']['certificate']['is_valid'] ?? 0,
                $this->results['ssl']['certificate']['days_remaining'] ?? 0,
                json_encode($this->results['technology']['all_technologies'] ?? []),
                $this->results['seo']['score'] ?? 0,
                $this->results['security']['score'] ?? 0,
                $this->results['performance']['performance_score'] ?? 0,
                $this->results['links']['stats']['total_links'] ?? 0,
                $this->results['forms']['total_forms'] ?? 0,
                json_encode($this->results['headers'] ?? []),
                // Identity fields
                $identity['trace_timestamp'] ?? null,
                $identity['trace_date'] ?? null,
                $identity['trace_time'] ?? null,
                $identity['trace_timezone'] ?? null,
                $identity['trace_epoch'] ?? null,
                $identity['ip_address'] ?? null,
                $location['country'] ?? null,
                $location['region'] ?? null,
                $location['city'] ?? null,
                $location['latitude'] ?? null,
                $location['longitude'] ?? null,
                $location['timezone'] ?? null,
                $location['isp'] ?? null,
                $location['organization'] ?? null,
                $identity['asn']['asn'] ?? null,
                $identity['reverse_dns'] ?? null,
                // First published
                $firstPublished['created_date'] ?? null,
                $firstPublished['registrar'] ?? null,
                $firstPublished['expiry_date'] ?? null,
                $firstPublished['registrant_country'] ?? null,
                json_encode($firstPublished['name_servers'] ?? []),
                // Network identity
                $macStr ?: null,
                $network['subnet'] ?? null,
                $network['gateway'] ?? null,
                json_encode($network['dns_servers'] ?? []),
                // Local time
                $localTime['current_date'] ?? null,
                $localTime['current_time'] ?? null,
                $localTime['timezone'] ?? null,
                $localTime['is_dst'] ? 1 : 0,
            ]);
        } catch (\Throwable $e) {
            // Silently fail - don't break the trace
        }
    }
    
    /**
     * Get all traces from database
     */
    public function getTraces(): array
    {
        if (!$this->pdo) return [];
        try {
            $stmt = $this->pdo->query("SELECT * FROM url_traces ORDER BY created_at DESC LIMIT 50");
            return $stmt->fetchAll();
        } catch (\Throwable $e) {
            return [];
        }
    }
    
    /**
     * Get trace by URL
     */
    public function getTraceByUrl(string $url): ?array
    {
        if (!$this->pdo) return null;
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM url_traces WHERE url = ?");
            $stmt->execute([$this->normalizeUrl($url)]);
            return $stmt->fetch() ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
