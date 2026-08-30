<?php

header('Content-Type: application/json; charset=utf-8');

$allowedOrigin = 'https://dw321.vercel.app';

if (isset($_SERVER['HTTP_ORIGIN']) && $_SERVER['HTTP_ORIGIN'] === $allowedOrigin) {
    header('Access-Control-Allow-Origin: ' . $allowedOrigin);
}

header('Vary: Origin');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

class RobloxSearchProxy
{
    private string $cacheDir = '/tmp/roblox_search_cache';
    private string $avatarCacheDir = '/tmp/roblox_avatar_cache';
    private string $profileCacheDir = '/tmp/roblox_profile_cache';
    private string $rateLimitFile = '/tmp/roblox_rate_limits.json';

    private int $maxRequestsPerMinute = 20;
    private float $minRequestInterval = 0.75;
    private int $cacheTTL = 3600;
    private int $avatarCacheTTL = 86400;
    private int $profileCacheTTL = 3600;

    public function __construct()
    {
        foreach ([
            $this->cacheDir,
            $this->avatarCacheDir,
            $this->profileCacheDir
        ] as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
        }
    }

    private function getClientIP(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }

    private function normalizeKeyword(string $keyword): string
    {
        return strtolower(trim($keyword));
    }

    private function getCacheKey(string $keyword, int $limit): string
    {
        return hash('sha256', $this->normalizeKeyword($keyword) . '|' . $limit) . '.json';
    }

    private function getCacheFile(string $keyword, int $limit): string
    {
        return $this->cacheDir . '/' . $this->getCacheKey($keyword, $limit);
    }

    private function getFromCache(string $keyword, int $limit): ?array
    {
        $file = $this->getCacheFile($keyword, $limit);

        if (!is_file($file)) {
            return null;
        }

        $raw = @file_get_contents($file);
        if ($raw === false) {
            return null;
        }

        $data = json_decode($raw, true);

        if (!is_array($data)) {
            @unlink($file);
            return null;
        }

        if (!isset($data['expires']) || $data['expires'] <= time()) {
            @unlink($file);
            return null;
        }

        return isset($data['results']) && is_array($data['results'])
            ? $data['results']
            : null;
    }

    private function saveToCache(string $keyword, int $limit, array $results): void
    {
        @file_put_contents(
            $this->getCacheFile($keyword, $limit),
            json_encode([
                'results' => $results,
                'expires' => time() + $this->cacheTTL,
                'cached' => time()
            ], JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }

    private function checkRateLimit(): array
    {
        $ip = $this->getClientIP();
        $now = microtime(true);
        $windowStart = $now - 60;
        $limits = [];

        if (is_file($this->rateLimitFile)) {
            $raw = @file_get_contents($this->rateLimitFile);
            if ($raw !== false) {
                $limits = json_decode($raw, true) ?? [];
            }
        }

        if (!isset($limits[$ip])) {
            $limits[$ip] = [
                'requests' => [],
                'lastRequest' => 0
            ];
        }

        $requests = [];
        foreach (($limits[$ip]['requests'] ?? []) as $timestamp) {
            if ((float)$timestamp > $windowStart) {
                $requests[] = (float)$timestamp;
            }
        }

        $limits[$ip]['requests'] = $requests;
        $requestCount = count($requests);
        $lastRequest = (float)($limits[$ip]['lastRequest'] ?? 0);

        if ($lastRequest > 0 && ($now - $lastRequest) < $this->minRequestInterval) {
            $retryAfter = $this->minRequestInterval - ($now - $lastRequest);

            return [
                'allowed' => false,
                'reason' => 'Please wait before searching again.',
                'retryAfter' => round(max($retryAfter, 0.1), 2)
            ];
        }

        if ($requestCount >= $this->maxRequestsPerMinute) {
            return [
                'allowed' => false,
                'reason' => 'Too many searches. Please try again later.',
                'retryAfter' => 60
            ];
        }

        $limits[$ip]['requests'][] = $now;
        $limits[$ip]['lastRequest'] = $now;

        @file_put_contents($this->rateLimitFile, json_encode($limits), LOCK_EX);

        return ['allowed' => true];
    }

    private function curlJson(string $url): array
    {
        if (!function_exists('curl_init')) {
            return [
                'status' => 500,
                'error' => 'PHP cURL extension is not enabled on this server.'
            ];
        }

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'User-Agent: dw321-roblox-search/1.1'
            ]
        ]);

        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            return [
                'status' => 502,
                'error' => 'Could not connect to Roblox.',
                'details' => $error
            ];
        }

        $data = json_decode($body, true);

        if ($status === 200 && is_array($data)) {
            return [
                'status' => 200,
                'data' => $data
            ];
        }

        if ($status === 429) {
            return [
                'status' => 429,
                'error' => 'Roblox is temporarily rate limiting the server.',
                'retryAfter' => 60
            ];
        }

        return [
            'status' => $status > 0 ? $status : 502,
            'error' => 'Roblox returned HTTP ' . $status
        ];
    }

    private function fetchUsers(string $keyword, int $limit): array
    {
        $url = 'https://users.roblox.com/v1/users/search?' . http_build_query([
            'keyword' => $keyword,
            'limit' => $limit
        ]);

        return $this->curlJson($url);
    }

    private function getAvatarCacheFile(string $userId): string
    {
        return $this->avatarCacheDir . '/' . hash('sha256', $userId) . '.json';
    }

    private function getCachedAvatar(string $userId): ?string
    {
        $file = $this->getAvatarCacheFile($userId);

        if (!is_file($file)) {
            return null;
        }

        $raw = @file_get_contents($file);
        $data = $raw !== false ? json_decode($raw, true) : null;

        if (!is_array($data) || ($data['expires'] ?? 0) <= time()) {
            @unlink($file);
            return null;
        }

        return !empty($data['imageUrl']) ? (string)$data['imageUrl'] : null;
    }

    private function saveAvatarCache(string $userId, string $imageUrl): void
    {
        @file_put_contents(
            $this->getAvatarCacheFile($userId),
            json_encode([
                'imageUrl' => $imageUrl,
                'expires' => time() + $this->avatarCacheTTL
            ], JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }

    private function fetchAvatars(array $userIds): array
    {
        $result = [];
        $missing = [];

        foreach ($userIds as $id) {
            $id = (string)$id;
            $cached = $this->getCachedAvatar($id);

            if ($cached !== null) {
                $result[$id] = $cached;
            } else {
                $missing[] = $id;
            }
        }

        if (!$missing) {
            return [
                'status' => 200,
                'data' => $result
            ];
        }

        // Smaller batches make the proxy more tolerant of partial thumbnail failures.
        foreach (array_chunk($missing, 25) as $batch) {
            $url = 'https://thumbnails.roblox.com/v1/users/avatar-headshot?' . http_build_query([
                'userIds' => implode(',', $batch),
                'size' => '150x150',
                'format' => 'Png',
                'isCircular' => 'false'
            ]);

            $response = $this->curlJson($url);

            if ($response['status'] !== 200) {
                continue;
            }

            foreach (($response['data']['data'] ?? []) as $entry) {
                if (!empty($entry['targetId']) && !empty($entry['imageUrl'])) {
                    $id = (string)$entry['targetId'];
                    $imageUrl = (string)$entry['imageUrl'];
                    $result[$id] = $imageUrl;
                    $this->saveAvatarCache($id, $imageUrl);
                }
            }
        }

        return [
            'status' => 200,
            'data' => $result
        ];
    }

    private function getProfileCacheFile(string $userId): string
    {
        return $this->profileCacheDir . '/' . hash('sha256', $userId) . '.json';
    }

    private function getCachedProfile(string $userId): ?array
    {
        $file = $this->getProfileCacheFile($userId);

        if (!is_file($file)) {
            return null;
        }

        $raw = @file_get_contents($file);
        $data = $raw !== false ? json_decode($raw, true) : null;

        if (!is_array($data) || ($data['expires'] ?? 0) <= time()) {
            @unlink($file);
            return null;
        }

        return isset($data['profile']) && is_array($data['profile'])
            ? $data['profile']
            : null;
    }

    private function saveProfileCache(string $userId, array $profile): void
    {
        @file_put_contents(
            $this->getProfileCacheFile($userId),
            json_encode([
                'profile' => $profile,
                'expires' => time() + $this->profileCacheTTL
            ], JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }

    private function fetchUserProfile(string $userId): array
    {
        if (!ctype_digit($userId) || (int)$userId <= 0) {
            return [
                'status' => 400,
                'error' => 'Invalid user ID.'
            ];
        }

        $cached = $this->getCachedProfile($userId);
        if ($cached !== null) {
            return [
                'status' => 200,
                'data' => $cached,
                'cached' => true
            ];
        }

        $url = 'https://users.roblox.com/v1/users/' . rawurlencode($userId);
        $response = $this->curlJson($url);

        if ($response['status'] !== 200) {
            return $response;
        }

        $profile = $response['data'];

        $normalized = [
            'id' => $profile['id'] ?? (int)$userId,
            'name' => $profile['name'] ?? '',
            'displayName' => $profile['displayName'] ?? '',
            'created' => $profile['created'] ?? null,
            'isBanned' => (bool)($profile['isBanned'] ?? false),
            'hasVerifiedBadge' => (bool)($profile['hasVerifiedBadge'] ?? false)
        ];

        $this->saveProfileCache($userId, $normalized);

        return [
            'status' => 200,
            'data' => $normalized
        ];
    }

    private function fetchFriendStatus(string $currentUserId, string $targetUserId): array
    {
        if (!ctype_digit($currentUserId) || (int)$currentUserId <= 0 ||
            !ctype_digit($targetUserId) || (int)$targetUserId <= 0) {
            return [
                'status' => 400,
                'error' => 'Invalid user ID.'
            ];
        }

        $url = 'https://friends.roblox.com/v1/users/' . rawurlencode($currentUserId) .
            '/friends/statuses?userIds=' . rawurlencode($targetUserId);

        return $this->curlJson($url);
    }

    private function normalizeConnection(array $response, string $targetUserId): string
    {
        if ($response['status'] !== 200) {
            return 'Connection unavailable';
        }

        $entries = $response['data']['data'] ?? [];

        if (!is_array($entries)) {
            return 'Connection unavailable';
        }

        foreach ($entries as $entry) {
            if ((string)($entry['id'] ?? $entry['userId'] ?? $entry['targetId'] ?? '') !== $targetUserId) {
                continue;
            }

            $status = strtolower(trim((string)($entry['status'] ?? '')));

            if ($status === 'friends' || $status === 'friend') {
                return 'Friends';
            }

            if ($status === 'pending') {
                return 'Pending';
            }

            if ($status === 'following') {
                return 'Following';
            }

            if ($status === 'notfriends' || $status === 'not friends' || $status === 'none') {
                return 'Not friends';
            }

            return $entry['status'] ? ucfirst((string)$entry['status']) : 'Connection unavailable';
        }

        return 'Not friends';
    }

    public function handle(): void
    {
        $action = strtolower(trim($_GET['action'] ?? 'search'));

        /* ---------------- USER PROFILE ---------------- */
        if ($action === 'profile') {
            $userId = trim((string)($_GET['userId'] ?? ''));

            if ($userId === '') {
                http_response_code(400);
                echo json_encode(['error' => 'Missing userId']);
                return;
            }

            $result = $this->fetchUserProfile($userId);

            if ($result['status'] !== 200) {
                http_response_code($result['status']);
                echo json_encode([
                    'error' => $result['error'] ?? 'Unable to fetch user profile.',
                    'retryAfter' => $result['retryAfter'] ?? null
                ]);
                return;
            }

            $profile = $result['data'];

            // Optional relationship lookup. A public site cannot know the visitor's
            // Roblox identity unless you explicitly provide a current user ID.
            $currentUserId = trim((string)($_GET['currentUserId'] ?? ''));
            if ($currentUserId !== '' && $currentUserId !== $userId) {
                $friendResponse = $this->fetchFriendStatus($currentUserId, $userId);
                $profile['connection'] = $this->normalizeConnection($friendResponse, $userId);
            } else {
                $profile['connection'] = 'Connection unavailable';
            }

            http_response_code(200);
            echo json_encode($profile, JSON_UNESCAPED_SLASHES);
            return;
        }

        /* ---------------- AVATARS ---------------- */
        if ($action === 'avatars') {
            $rawIds = trim((string)($_GET['userIds'] ?? ''));

            if ($rawIds === '') {
                http_response_code(400);
                echo json_encode(['error' => 'Missing userIds']);
                return;
            }

            $ids = array_values(array_unique(array_filter(
                array_map('trim', explode(',', $rawIds)),
                function ($id) {
                    return ctype_digit($id) && (int)$id > 0;
                }
            )));

            $ids = array_slice($ids, 0, 100);

            if (!$ids) {
                http_response_code(400);
                echo json_encode(['error' => 'No valid user IDs']);
                return;
            }

            $result = $this->fetchAvatars($ids);

            if ($result['status'] !== 200) {
                http_response_code($result['status']);
                echo json_encode([
                    'error' => $result['error'] ?? 'Avatar service unavailable.',
                    'retryAfter' => $result['retryAfter'] ?? null
                ]);
                return;
            }

            header('X-Cache: AVATAR');
            http_response_code(200);
            echo json_encode([
                'data' => $result['data']
            ], JSON_UNESCAPED_SLASHES);
            return;
        }

        /* ---------------- USER SEARCH ---------------- */
        $keyword = trim((string)($_GET['keyword'] ?? ''));
        $limit = (int)($_GET['limit'] ?? 10);

        if ($keyword === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Missing keyword']);
            return;
        }

        if (mb_strlen($keyword) < 3) {
            http_response_code(400);
            echo json_encode(['error' => 'Username must contain at least 3 characters.']);
            return;
        }

        $limit = max(1, min($limit, 10));

        // Cache FIRST so repeated searches do not consume the rate limit.
        $cached = $this->getFromCache($keyword, $limit);

        if ($cached !== null) {
            header('X-Cache: HIT');
            http_response_code(200);
            echo json_encode($cached, JSON_UNESCAPED_SLASHES);
            return;
        }

        $rateCheck = $this->checkRateLimit();

        if (!$rateCheck['allowed']) {
            http_response_code(429);
            header('Retry-After: ' . ceil($rateCheck['retryAfter']));
            echo json_encode([
                'error' => $rateCheck['reason'],
                'retryAfter' => $rateCheck['retryAfter']
            ]);
            return;
        }

        $result = $this->fetchUsers($keyword, $limit);

        if ($result['status'] !== 200) {
            http_response_code($result['status']);
            echo json_encode([
                'error' => $result['error'] ?? 'Unknown error',
                'retryAfter' => $result['retryAfter'] ?? null
            ]);
            return;
        }

        $this->saveToCache($keyword, $limit, $result['data']);

        header('X-Cache: MISS');
        http_response_code(200);
        echo json_encode($result['data'], JSON_UNESCAPED_SLASHES);
    }
}

$proxy = new RobloxSearchProxy();
$proxy->handle();
