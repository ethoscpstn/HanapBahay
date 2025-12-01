<?php
/**
 * Rate Limiting System
 * Prevents abuse and brute force attacks
 */

require_once __DIR__ . '/secure_config.php';

class RateLimiter {
    private $redis;
    private $fallback_file;
    
    public function __construct() {
        $this->fallback_file = __DIR__ . '/../cache/rate_limit.json';
        
        // Try to use Redis if available, otherwise use file-based storage
        if (class_exists('Redis')) {
            try {
                $this->redis = new Redis();
                $this->redis->connect('127.0.0.1', 6379);
            } catch (Exception $e) {
                $this->redis = null;
            }
        }
    }
    
    /**
     * Check if request is within rate limit
     * @param string $key Unique identifier (IP, user ID, etc.)
     * @param int $limit Number of requests allowed
     * @param int $window Time window in seconds
     * @return bool True if within limit, false if exceeded
     */
    public function isAllowed($key, $limit = null, $window = null) {
        $config = SecureConfig::getRateLimitConfig();
        $limit = $limit ?: $config['requests'];
        $window = $window ?: $config['window'];
        
        $current_time = time();
        $window_start = $current_time - $window;
        
        if ($this->redis) {
            return $this->checkRedis($key, $limit, $window_start);
        } else {
            return $this->checkFile($key, $limit, $window_start);
        }
    }
    
    /**
     * Record a request
     * @param string $key Unique identifier
     * @param int $window Time window in seconds
     */
    public function recordRequest($key, $window = null) {
        $config = SecureConfig::getRateLimitConfig();
        $window = $window ?: $config['window'];
        
        $current_time = time();
        
        if ($this->redis) {
            $this->recordRedis($key, $current_time, $window);
        } else {
            $this->recordFile($key, $current_time, $window);
        }
    }
    
    /**
     * Get remaining requests for a key
     * @param string $key Unique identifier
     * @param int $limit Number of requests allowed
     * @param int $window Time window in seconds
     * @return int Number of remaining requests
     */
    public function getRemainingRequests($key, $limit = null, $window = null) {
        $config = SecureConfig::getRateLimitConfig();
        $limit = $limit ?: $config['requests'];
        $window = $window ?: $config['window'];
        
        $current_time = time();
        $window_start = $current_time - $window;
        
        if ($this->redis) {
            $count = $this->getRedisCount($key, $window_start);
        } else {
            $count = $this->getFileCount($key, $window_start);
        }
        
        return max(0, $limit - $count);
    }
    
    /**
     * Reset rate limit for a key
     * @param string $key Unique identifier
     */
    public function reset($key) {
        if ($this->redis) {
            $this->redis->del("rate_limit:$key");
        } else {
            $data = $this->loadFileData();
            unset($data[$key]);
            $this->saveFileData($data);
        }
    }
    
    // Redis methods
    private function checkRedis($key, $limit, $window_start) {
        $count = $this->redis->zcount("rate_limit:$key", $window_start, '+inf');
        return $count < $limit;
    }
    
    private function recordRedis($key, $current_time, $window) {
        $this->redis->zadd("rate_limit:$key", $current_time, $current_time);
        $this->redis->expire("rate_limit:$key", $window);
    }
    
    private function getRedisCount($key, $window_start) {
        return $this->redis->zcount("rate_limit:$key", $window_start, '+inf');
    }
    
    // File-based methods
    private function checkFile($key, $limit, $window_start) {
        $count = $this->getFileCount($key, $window_start);
        return $count < $limit;
    }
    
    private function recordFile($key, $current_time, $window) {
        $data = $this->loadFileData();
        
        if (!isset($data[$key])) {
            $data[$key] = [];
        }
        
        $data[$key][] = $current_time;
        
        // Clean old entries
        $data[$key] = array_filter($data[$key], function($timestamp) use ($window_start) {
            return $timestamp > $window_start;
        });
        
        $this->saveFileData($data);
    }
    
    private function getFileCount($key, $window_start) {
        $data = $this->loadFileData();
        
        if (!isset($data[$key])) {
            return 0;
        }
        
        return count(array_filter($data[$key], function($timestamp) use ($window_start) {
            return $timestamp > $window_start;
        }));
    }
    
    private function loadFileData() {
        if (!file_exists($this->fallback_file)) {
            return [];
        }
        
        $content = file_get_contents($this->fallback_file);
        return json_decode($content, true) ?: [];
    }
    
    private function saveFileData($data) {
        // Ensure cache directory exists
        $cache_dir = dirname($this->fallback_file);
        if (!is_dir($cache_dir)) {
            mkdir($cache_dir, 0755, true);
        }
        
        file_put_contents($this->fallback_file, json_encode($data));
    }
}

/**
 * Rate limiting middleware function
 * @param string $key Unique identifier
 * @param int $limit Number of requests allowed
 * @param int $window Time window in seconds
 * @return bool True if allowed, false if rate limited
 */
function rateLimit($key, $limit = null, $window = null) {
    $limiter = new RateLimiter();
    
    if (!$limiter->isAllowed($key, $limit, $window)) {
        http_response_code(429);
        header('Retry-After: ' . ($window ?: 3600));
        die('Rate limit exceeded. Please try again later.');
    }
    
    $limiter->recordRequest($key, $window);
    return true;
}

/**
 * Get client IP address
 * @return string Client IP address
 */
function getClientIP() {
    $ip_keys = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
    
    foreach ($ip_keys as $key) {
        if (array_key_exists($key, $_SERVER) === true) {
            foreach (explode(',', $_SERVER[$key]) as $ip) {
                $ip = trim($ip);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                    return $ip;
                }
            }
        }
    }
    
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}
?>
