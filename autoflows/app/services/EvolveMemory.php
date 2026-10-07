<?php
/**
 * EvolveMemory — durable cross-session memory with tiered approval.
 *
 * PHP mirror of the dsh-evolve pattern (fact/preference/decision/lesson/
 * todo/note, user/project scope, importance 1-3, zero-token deterministic
 * recall via bigram-Jaccard fused with SQLite FTS5 BM25 through RRF).
 * Graceful degradation: if FTS5 is unavailable, recall is bigram-only.
 * Storage: `evolve_memories` (+ optional FTS index) inside storage/app.db.
 */
declare(strict_types=1);

final class EvolveMemory
{
    public const KINDS = ['fact', 'preference', 'decision', 'lesson', 'todo', 'note'];
    public const SCOPES = ['user', 'project'];

    public static function ensureTable(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS evolve_memories (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                kind TEXT NOT NULL,
                scope TEXT NOT NULL DEFAULT \'user\',
                importance INTEGER NOT NULL DEFAULT 1,
                status TEXT NOT NULL DEFAULT \'pending\',
                text TEXT NOT NULL,
                meta TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_evolve_status ON evolve_memories(status, scope, id DESC)');
        // Best-effort FTS5 index; silently skip when SQLite lacks FTS5.
        try {
            $pdo->exec(
                "CREATE VIRTUAL TABLE IF NOT EXISTS evolve_memories_fts USING fts5(text, content='evolve_memories', content_rowid='id')"
            );
            $pdo->exec(
                'CREATE TRIGGER IF NOT EXISTS evolve_memories_ai AFTER INSERT ON evolve_memories BEGIN
                    INSERT INTO evolve_memories_fts(rowid, text) VALUES (new.id, new.text);
                END'
            );
            $pdo->exec(
                'CREATE TRIGGER IF NOT EXISTS evolve_memories_ad AFTER DELETE ON evolve_memories BEGIN
                    INSERT INTO evolve_memories_fts(evolve_memories_fts, rowid, text) VALUES (\'delete\', old.id, old.text);
                END'
            );
            $pdo->exec(
                'CREATE TRIGGER IF NOT EXISTS evolve_memories_au AFTER UPDATE ON evolve_memories BEGIN
                    INSERT INTO evolve_memories_fts(evolve_memories_fts, rowid, text) VALUES (\'delete\', old.id, old.text);
                    INSERT INTO evolve_memories_fts(rowid, text) VALUES (new.id, new.text);
                END'
            );
        } catch (Throwable) {
            // bigram-only recall
        }
    }

    public static function ftsAvailable(PDO $pdo): bool
    {
        try {
            $row = $pdo->query("SELECT name FROM sqlite_master WHERE name='evolve_memories_fts'")->fetch(PDO::FETCH_ASSOC);
            return $row !== false;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array{id:int,status:string,reason:string}
     */
    public static function remember(PDO $pdo, string $kind, string $scope, int $importance, string $text, array $meta = []): array
    {
        $kind = in_array($kind, self::KINDS, true) ? $kind : 'note';
        $scope = in_array($scope, self::SCOPES, true) ? $scope : 'user';
        $importance = max(1, min(3, $importance));
        $text = trim($text);
        if ($text === '') {
            throw new InvalidArgumentException('Memory text is required.');
        }

        // Conflict: identical confirmed text already stored.
        $st = $pdo->prepare("SELECT id FROM evolve_memories WHERE status='confirmed' AND text=? LIMIT 1");
        $st->execute([$text]);
        if ($st->fetch(PDO::FETCH_ASSOC)) {
            $id = self::insert($pdo, $kind, $scope, $importance, $text, $meta, 'pending');
            return ['id' => $id, 'status' => 'pending', 'reason' => 'duplicate of confirmed memory — held for review'];
        }

        // Overlap: high bigram similarity with a confirmed memory.
        $best = self::topOverlap($pdo, $text);
        if ($best !== null && $best['score'] >= 0.6) {
            $id = self::insert($pdo, $kind, $scope, $importance, $text, $meta + ['overlap_id' => $best['id']], 'pending');
            return ['id' => $id, 'status' => 'pending', 'reason' => "overlaps #{$best['id']} (" . round($best['score'], 2) . ') — held for review'];
        }

        // Tiered gate: importance 1 + no conflict/overlap auto-confirms; 2-3 need a human.
        if ($importance === 1) {
            $id = self::insert($pdo, $kind, $scope, $importance, $text, $meta, 'confirmed');
            return ['id' => $id, 'status' => 'confirmed', 'reason' => 'importance 1, reversible — auto-confirmed'];
        }
        $id = self::insert($pdo, $kind, $scope, $importance, $text, $meta, 'pending');
        return ['id' => $id, 'status' => 'pending', 'reason' => "importance {$importance} — held for review"];
    }

    /** @return array<int,array> top memories for query (confirmed first). */
    public static function recall(PDO $pdo, string $query, int $limit = 5, ?string $scope = null): array
    {
        $limit = max(1, min(20, $limit));
        $query = trim($query);
        if ($query === '') {
            return [];
        }
        $where = "status='confirmed'";
        $args = [];
        if ($scope !== null && in_array($scope, self::SCOPES, true)) {
            $where .= ' AND scope=?';
            $args[] = $scope;
        }
        $st = $pdo->prepare("SELECT * FROM evolve_memories WHERE {$where} ORDER BY id DESC LIMIT 200");
        $st->execute($args);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        $ftsHits = [];
        if (self::ftsAvailable($pdo)) {
            try {
                $match = self::ftsQuery($query);
                $fs = $pdo->prepare("SELECT rowid FROM evolve_memories_fts WHERE evolve_memories_fts MATCH ? LIMIT 50");
                $fs->execute([$match]);
                $rank = 0;
                foreach ($fs->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $rank++;
                    $ftsHits[(int) $r['rowid']] = 1.0 / ($rank + 60); // RRF k=60
                }
            } catch (Throwable) {
                $ftsHits = [];
            }
        }

        $scored = [];
        foreach ($rows as $row) {
            $j = self::bigramJaccard($query, (string) $row['text']);
            $rrf = $ftsHits[(int) $row['id']] ?? 0.0;
            $score = 0.7 * $j + 0.3 * min(1.0, $rrf * 10);
            if ($score <= 0.0 && $j <= 0.0) {
                continue;
            }
            $row['score'] = round($score, 4);
            $scored[] = $row;
        }
        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);
        return array_slice($scored, 0, $limit);
    }

    /** @return array<int,array> */
    public static function list(PDO $pdo, string $status = 'all', int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        if ($status === 'all') {
            $st = $pdo->prepare('SELECT * FROM evolve_memories ORDER BY id DESC LIMIT ' . $limit);
            $st->execute();
        } else {
            $st = $pdo->prepare('SELECT * FROM evolve_memories WHERE status=? ORDER BY id DESC LIMIT ' . $limit);
            $st->execute([$status]);
        }
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function approve(PDO $pdo, int $id): bool
    {
        $st = $pdo->prepare("UPDATE evolve_memories SET status='confirmed', updated_at=datetime('now') WHERE id=? AND status='pending'");
        $st->execute([$id]);
        return $st->rowCount() > 0;
    }

    public static function prune(PDO $pdo, int $id): bool
    {
        $st = $pdo->prepare("UPDATE evolve_memories SET status='pruned', updated_at=datetime('now') WHERE id=? AND status!='pruned'");
        $st->execute([$id]);
        return $st->rowCount() > 0;
    }

    public static function stats(PDO $pdo): array
    {
        $out = ['total' => 0, 'confirmed' => 0, 'pending' => 0, 'pruned' => 0, 'fts' => self::ftsAvailable($pdo)];
        foreach ($pdo->query('SELECT status, COUNT(*) n FROM evolve_memories GROUP BY status') as $r) {
            $out['total'] += (int) $r['n'];
            if (isset($out[(string) $r['status']])) {
                $out[(string) $r['status']] = (int) $r['n'];
            }
        }
        return $out;
    }

    // ------------------------------------------------------------ internals ---

    private static function insert(PDO $pdo, string $kind, string $scope, int $importance, string $text, array $meta, string $status): int
    {
        $st = $pdo->prepare(
            'INSERT INTO evolve_memories (kind, scope, importance, status, text, meta) VALUES (?,?,?,?,?,?)'
        );
        $st->execute([$kind, $scope, $importance, $status, $text, $meta === [] ? null : json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
        return (int) $pdo->lastInsertId();
    }

    /** @return null|array{id:int,score:float} */
    private static function topOverlap(PDO $pdo, string $text): ?array
    {
        $best = null;
        foreach ($pdo->query("SELECT id, text FROM evolve_memories WHERE status='confirmed' ORDER BY id DESC LIMIT 200") as $row) {
            $s = self::bigramJaccard($text, (string) $row['text']);
            if ($best === null || $s > $best['score']) {
                $best = ['id' => (int) $row['id'], 'score' => $s];
            }
        }
        return $best;
    }

    /** @return array<string> char bigrams (CJK-safe). */
    private static function bigrams(string $s): array
    {
        $s = mb_strtolower(trim($s));
        $s = (string) preg_replace('/\s+/u', ' ', $s);
        $len = mb_strlen($s);
        if ($len < 2) {
            return $len === 0 ? [] : [$s];
        }
        $out = [];
        for ($i = 0; $i < $len - 1; $i++) {
            $out[] = mb_substr($s, $i, 2);
        }
        return array_values(array_unique($out));
    }

    public static function bigramJaccard(string $a, string $b): float
    {
        $sa = self::bigrams($a);
        $sb = self::bigrams($b);
        if ($sa === [] || $sb === []) {
            return 0.0;
        }
        $inter = count(array_intersect($sa, $sb));
        $union = count(array_unique(array_merge($sa, $sb)));
        return $union === 0 ? 0.0 : $inter / $union;
    }

    private static function ftsQuery(string $q): string
    {
        $terms = preg_split('/\s+/u', trim($q)) ?: [];
        $clean = [];
        foreach ($terms as $t) {
            $t = trim((string) preg_replace('/["*:()]/u', '', $t));
            if ($t !== '') {
                $clean[] = '"' . $t . '"';
            }
        }
        return $clean === [] ? '""' : implode(' OR ', array_slice($clean, 0, 10));
    }
}
