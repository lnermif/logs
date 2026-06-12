<?php

declare(strict_types=1);

namespace Nermif\Logs;

use Ramsey\Uuid\Uuid;

/**
 * 静态日志类（协程安全，PHP ≥ 7.2）
 *
 * 用法：
 *   Logs::init();                                                     // 使用类默认值，自动生成 UUID trace_id
 *   Logs::init(['min_level' => 'debug', 'base_path' => '/data/logs']); // 可选：传入配置覆盖默认值
 *   Logs::feat('order');                                              // 设置后，后续日志均会携带 "feat":"order"
 *   Logs::endRequest();                                               // 常驻进程必须调用（Swoole 自动清理）
 *
 * 快捷函数：
 *   Logs::debug();
 *   Logs::info();
 *   Logs::notice();
 *   Logs::warning();
 *   Logs::error();
 *   Logs::critical();
 *   Logs::alert();
 *   Logs::emergency();
 *
 * 异常记录例子：
 *   Logs::error('支付失败', $e);                                       // 自定义消息 + 异常
 *   Logs::error('支付失败', ['a' => 1, 'b' => 2, 'exception' => $e]);  // 自定义消息 + 自定义上下文 + 异常
 *   Logs::error($e);                                                  // 只传异常
 *
 * 特性：
 *   - 协程隔离 trace_id 和 feat，防止并发串扰。
 *   - 所有日志写入同一主文件（按年月/日分割），通过 feat 字段标识来源，不拆分文件。
 *   - 异常对象自动提取消息、堆栈、额外属性（含 protected/private），堆栈参数详细展开。
 *   - JSON 单行输出，带微秒时间戳，便于日志分析工具处理。
 *   - 支持敏感字段过滤、消息净化、GC 优化、UUID 安全降级、mbstring 降级。
 *   - 兼容 PHP 7.2+，零外部依赖。
 */
class Logs
{
    const DEBUG = 100;
    const INFO = 200;
    const NOTICE = 250;
    const WARNING = 300;
    const ERROR = 400;
    const CRITICAL = 500;
    const ALERT = 550;
    const EMERGENCY = 600;

    private static $contexts = [];

    /** @var string */
    private static $basePath = '';

    /** @var int */
    private static $minLevel = self::INFO;

    private static $levelMap = [
        'debug' => self::DEBUG,
        'info' => self::INFO,
        'notice' => self::NOTICE,
        'warning' => self::WARNING,
        'error' => self::ERROR,
        'critical' => self::CRITICAL,
        'alert' => self::ALERT,
        'emergency' => self::EMERGENCY,
    ];

    private static $reverseLevelMap = [
        self::DEBUG => 'DEBUG',
        self::INFO => 'INFO',
        self::NOTICE => 'NOTICE',
        self::WARNING => 'WARNING',
        self::ERROR => 'ERROR',
        self::CRITICAL => 'CRITICAL',
        self::ALERT => 'ALERT',
        self::EMERGENCY => 'EMERGENCY',
    ];

    private const MAX_FIELD_LEN = 8000;
    private const MAX_TRACE_LEN = 524288;
    private const MAX_FILE_SIZE = 100 * 1024 * 1024; // 100MB

    /** @var int GC 过期阈值（秒），长协程可调大，0 表示永不过期 */
    private static $gcTtl = 300;

    /** @var int 上次日志轮转失败的时间戳，用于冷却 */
    private static $lastRotationFailTime = 0;

    /** @var string[] 需要脱敏的上下文键名（小写） */
    private static $sensitiveKeys = [
        'password', 'passwd', 'secret', 'token', 'authorization', 'api_key', 'access_token', 'refresh_token',
    ];

    /** @var bool 是否清理消息中的控制字符 */
    private static $sanitizeMessage = true;

    /** @var bool 是否展开异常堆栈参数的值（默认关闭以避免泄漏敏感信息） */
    private static $expandTraceArgs = false;

    /** @var bool 是否对高熵字符串（如长随机 token）自动脱敏，默认关闭以保证可读性 */
    private static $autoMaskHighEntropyStrings = false;

    /** @var string 敏感键名匹配模式：'contains' 子串匹配（默认，兼容），'exact' 精确匹配 */
    private static $sensitiveKeyMatchMode = 'contains';

    private const DEFAULT_LOG_DIR = 'logs';

    /**
     * 设置日志根目录（必须为绝对路径且可写）。
     *
     * 注意：仅检测路径存在与可写权限，不再强制必须位于项目根下。
     * 因为 webman / ThinkPHP 5.x/6.x/8.x 对 ROOT_PATH 的定义方式和位置各不同，
     * 且生产环境常希望把日志落到独立磁盘或系统日志目录。
     */
    public static function setBasePath(string $path): void
    {
        if ($path === '') {
            throw new \InvalidArgumentException('Log path must be a non-empty string');
        }

        $realPath = realpath($path);
        if ($realPath === false || !is_dir($realPath) || !is_writable($realPath)) {
            throw new \InvalidArgumentException("Invalid or non-writable log base path: {$path}");
        }

        // ThinkPHP 定义了 ROOT_PATH 时做一次软提示（仅警告级别），帮助开发者定位配置问题
        if (defined('ROOT_PATH')) {
            $root = rtrim(realpath(ROOT_PATH), '/\\') . DIRECTORY_SEPARATOR;
            if ($root !== false && strpos($realPath . DIRECTORY_SEPARATOR, $root) !== 0) {
                error_log('Logs::setBasePath - 日志目录 [' . $realPath . '] 不在项目根 [' . $root . '] 下，请确认符合部署策略');
            }
        }

        self::$basePath = rtrim($realPath, '/\\');
    }

    /**
     * 设置全局最低日志级别
     */
    public static function setMinLevel($level): void
    {
        if (is_string($level)) {
            if (is_numeric($level) && (int)$level > 0) {
                self::$minLevel = (int)$level;
                return;
            }
            $level = strtolower($level);
            if (!isset(self::$levelMap[$level])) {
                throw new \InvalidArgumentException("Unknown log level: {$level}");
            }
            $level = self::$levelMap[$level];
        }
        self::$minLevel = (int)$level;
    }

    /**
     * 设置 GC 上下文过期时间（秒），长协程建议调大，0 表示永不过期
     */
    public static function setGcTtl(int $seconds): void
    {
        self::$gcTtl = max(0, $seconds);
    }

    /**
     * 设置需要脱敏的上下文键名（全小写），传入空数组关闭脱敏
     */
    public static function setSensitiveKeys(array $keys): void
    {
        self::$sensitiveKeys = array_map('strtolower', $keys);
    }

    /**
     * 设置是否过滤消息中的控制字符（默认开启）
     */
    public static function setSanitizeMessage(bool $sanitize): void
    {
        self::$sanitizeMessage = $sanitize;
    }

    /**
     * 设置是否展开异常堆栈中的参数值（默认 false 以避免泄漏敏感信息）
     */
    public static function setExpandTraceArgs(bool $expand): void
    {
        self::$expandTraceArgs = $expand;
    }

    /**
     * 设置是否对高熵字符串（如长随机 token/JWT/Bearer）自动脱敏，默认关闭。
     * 关闭时仅对明确命中的 Bearer/JWT 格式脱敏，UUID/订单号等正常记录。
     */
    public static function setAutoMaskHighEntropyStrings(bool $auto): void
    {
        self::$autoMaskHighEntropyStrings = $auto;
    }

    /**
     * 设置敏感键名的匹配策略。
     * - 'contains'：子串包含匹配（默认，兼容旧行为），如 'secret' 匹配 'my_secret_key'
     * - 'exact'：精确匹配，如 'secret' 只匹配键名恰好为 'secret' 的字段
     */
    public static function setSensitiveKeyMatchMode(string $mode): void
    {
        if (!in_array($mode, ['contains', 'exact'], true)) {
            throw new \InvalidArgumentException('Sensitive key match mode must be "contains" or "exact"');
        }
        self::$sensitiveKeyMatchMode = $mode;
    }

    /**
     * 从配置数组一次性加载所有选项，未提供的键保持默认值。
     *
     * 支持的键：
     *   base_path                      => string  日志根目录（绝对路径）
     *   min_level                      => string|int  最低日志级别，如 'debug' / 100
     *   gc_ttl                         => int     GC 上下文过期时间（秒），0 表示永不过期
     *   sensitive_keys                 => array   需要脱敏的上下文键名（全小写），空数组关闭脱敏
     *   sanitize_message               => bool    是否过滤消息中的控制字符
     *   expand_trace_args              => bool    是否展开异常堆栈中的参数值
     *   auto_mask_high_entropy_strings => bool    是否对高熵字符串自动脱敏
     *   sensitive_key_match_mode       => string  敏感键匹配模式：'contains'（默认）或 'exact'
     */
    public static function configure(array $config): void
    {
        if (!empty($config['base_path']) && is_string($config['base_path'])) {
            self::setBasePath($config['base_path']);
        }
        if (array_key_exists('min_level', $config)) {
            self::setMinLevel($config['min_level']);
        }
        if (isset($config['gc_ttl']) && is_numeric($config['gc_ttl'])) {
            self::setGcTtl((int)$config['gc_ttl']);
        }
        if (isset($config['sensitive_keys']) && is_array($config['sensitive_keys'])) {
            self::setSensitiveKeys($config['sensitive_keys']);
        }
        if (array_key_exists('sanitize_message', $config)) {
            self::setSanitizeMessage((bool)$config['sanitize_message']);
        }
        if (array_key_exists('expand_trace_args', $config)) {
            self::setExpandTraceArgs((bool)$config['expand_trace_args']);
        }
        if (array_key_exists('auto_mask_high_entropy_strings', $config)) {
            self::setAutoMaskHighEntropyStrings((bool)$config['auto_mask_high_entropy_strings']);
        }
        if (isset($config['sensitive_key_match_mode']) && is_string($config['sensitive_key_match_mode'])) {
            self::setSensitiveKeyMatchMode($config['sensitive_key_match_mode']);
        }
    }

    /**
     * 初始化当前协程/请求的日志上下文。
     *
     * @param array|null $config 可选配置数组，传入时直接交给 configure() 处理
     *
     * 注意：在 Webman / ThinkPHP 常驻 / Workerman 等长驻进程中，
     * 非协程环境下多次请求会共享同一个 `__main__` 槽位，
     * 因此必须每次都清零 feat / trace_id，禁止基于"已有 trace_id 就跳过"的短路逻辑。
     */
    public static function init(?array $config = null): void
    {
        if ($config !== null) {
            self::configure($config);
        }
        $ctx = &self::context();
        $isCoroutine = self::isCoroutineContext();

        // 协程环境下同一个 cid 通常只会进入一次 init（由中间件触发），
        // 但为安全起见仍然强制重置 feat，避免上层业务重复调用产生污染。
        $ctx['feat'] = null;

        if (method_exists(Uuid::class, 'uuid7')) {
            $uuid = Uuid::uuid7();
        } else {
            $uuid = Uuid::uuid4();
        }
        $traceId = $uuid->toString();
        $ctx['trace_id'] = $traceId;

        // 自动清理：优先走各协程/框架的 defer 机制，否则由中间件在请求结束时显式调用 endRequest()。
        if ($isCoroutine && extension_loaded('swoole') && class_exists('Swoole\Coroutine', false) && \Swoole\Coroutine::getCid() > 0) {
            \Swoole\Coroutine::defer([self::class, 'endRequest']);
        }
    }

    /**
     * 结束当前协程的日志上下文
     */
    public static function endRequest(): void
    {
        $cid = self::getCoroutineId();
        unset(self::$contexts[$cid]);
    }

    /**
     * 设置当前请求的功能标识，该值会作为 "feat" 字段出现在每条日志的 JSON 中
     *
     * @param string|null $feat 功能名（只保留安全字符），传 null 清除
     */
    public static function feat(?string $feat = null): void
    {
        $ctx = &self::context();
        if ($feat === null) {
            $ctx['feat'] = null;
        } else {
            $ctx['feat'] = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $feat);
        }
    }

    public static function getTraceId(): ?string
    {
        return self::context()['trace_id'] ?? null;
    }

    // ---------- 快捷方法（支持异常直接传入） ----------
    public static function debug($message, $context = []): void
    {
        self::logInternal(self::DEBUG, $message, $context);
    }

    public static function info($message, $context = []): void
    {
        self::logInternal(self::INFO, $message, $context);
    }

    public static function notice($message, $context = []): void
    {
        self::logInternal(self::NOTICE, $message, $context);
    }

    public static function warning($message, $context = []): void
    {
        self::logInternal(self::WARNING, $message, $context);
    }

    public static function error($message, $context = []): void
    {
        self::logInternal(self::ERROR, $message, $context);
    }

    public static function critical($message, $context = []): void
    {
        self::logInternal(self::CRITICAL, $message, $context);
    }

    public static function alert($message, $context = []): void
    {
        self::logInternal(self::ALERT, $message, $context);
    }

    public static function emergency($message, $context = []): void
    {
        self::logInternal(self::EMERGENCY, $message, $context);
    }

    /**
     * 统一处理参数标准化并写入日志
     */
    private static function logInternal(int $level, $message, $context): void
    {
        if ($message instanceof \Throwable) {
            $context = is_array($context) ? $context : [];
            $context['exception'] = $message;
            $message = $message->getMessage();
        } elseif ($context instanceof \Throwable) {
            $context = ['exception' => $context];
        }

        if (self::$sanitizeMessage) {
            $message = self::sanitizeString((string)$message);
        }

        self::write($level, (string)$message, (array)$context);
    }

    /**
     * 写入日志（统一写入主文件，feat 作为字段记录）
     */
    private static function write(int $level, string $message, array $context): void
    {
        if ($level < self::$minLevel) {
            return;
        }

        // 极低概率触发 GC
        if (mt_rand(0, 999) === 0) {
            self::gcContexts();
        }

        $levelName = self::$reverseLevelMap[$level] ?? 'UNKNOWN';
        $ctx = self::context();

        $data = [
            'datetime' => self::getMicrotimeDatetime(),
            'trace_id' => $ctx['trace_id'] ?? null,
            'feat' => $ctx['feat'] ?? null,
            'level' => $levelName,
            'message' => $message,
            'context' => self::normalizeContext($context),
        ];

        $line = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($line === false) {
            $data['context'] = ['json_error' => json_last_error_msg()];
            $line = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($line === false) {
                $line = '{}';
            }
        }
        $line .= "\n";

        try {
            // 统一写入主日志文件（不再根据 feat 分文件）
            $file = self::buildLogFilePath();
            self::writeFile($file, $line);
        } catch (\Throwable $e) {
            error_log('Log write failed: ' . $e->getMessage());
        }
    }

    private static function &context(): array
    {
        $cid = self::getCoroutineId();
        if (!isset(self::$contexts[$cid])) {
            // 在高并发常驻进程下降低触发阈值，避免内存线性增长
            if (count(self::$contexts) > 512) {
                self::gcContexts();
            }
            self::$contexts[$cid] = [
                'trace_id' => null,
                'feat' => null,
                'created_at' => time(),
            ];
        }
        return self::$contexts[$cid];
    }

    private static function gcContexts(): void
    {
        $now = time();
        $hardLimit = 2048;
        $emergencyTtl = 300;
        $count = count(self::$contexts);

        foreach (self::$contexts as $key => $val) {
            if ($val['trace_id'] === null && $val['feat'] === null) {
                unset(self::$contexts[$key]);
                continue;
            }
            if (self::$gcTtl > 0 && $now - $val['created_at'] > self::$gcTtl) {
                unset(self::$contexts[$key]);
                continue;
            }
            if ($count > $hardLimit && $now - $val['created_at'] > $emergencyTtl) {
                unset(self::$contexts[$key]);
            }
        }

        // 兜底：若仍然超过硬上限，按创建时间清理最老的 50%
        if (count(self::$contexts) > $hardLimit) {
            uasort(self::$contexts, function ($a, $b) {
                return ($a['created_at'] ?? 0) <=> ($b['created_at'] ?? 0);
            });
            $keepFrom = (int)(count(self::$contexts) * 0.5);
            $keys = array_slice(array_keys(self::$contexts), 0, $keepFrom);
            foreach ($keys as $key) {
                unset(self::$contexts[$key]);
            }
            error_log('Logs::gcContexts - 上下文数量超过硬上限，执行兜底清理，保留 ' . count(self::$contexts) . ' 条');
        }
    }

    /**
     * 判断当前是否处于协程（Swoole / Swow / Fiber）上下文中
     */
    private static function isCoroutineContext(): bool
    {
        if (extension_loaded('swoole') && class_exists('Swoole\Coroutine', false)) {
            $cid = @\Swoole\Coroutine::getCid();
            if ($cid !== false && $cid > 0) {
                return true;
            }
        }
        if (extension_loaded('swow') && class_exists('Swow\Coroutine', false) && method_exists('Swow\Coroutine', 'getCurrent')) {
            $coroutine = @\Swow\Coroutine::getCurrent();
            if ($coroutine !== null) {
                return true;
            }
        }
        if (PHP_VERSION_ID >= 80100 && class_exists('Fiber', false) && method_exists('Fiber', 'getCurrent')) {
            $fiber = @\Fiber::getCurrent();
            if ($fiber !== null) {
                return true;
            }
        }
        return false;
    }

    private static function getCoroutineId(): string
    {
        if (extension_loaded('swoole') && class_exists('Swoole\Coroutine', false)) {
            $cid = @\Swoole\Coroutine::getCid();
            if ($cid !== false && $cid > 0) {
                return 'swoole_' . $cid;
            }
        }
        if (extension_loaded('swow') && class_exists('Swow\Coroutine', false) && method_exists('Swow\Coroutine', 'getCurrent')) {
            $coroutine = @\Swow\Coroutine::getCurrent();
            if ($coroutine !== null && method_exists($coroutine, 'getId')) {
                return 'swow_' . $coroutine->getId();
            }
        }
        if (PHP_VERSION_ID >= 80100 && class_exists('Fiber', false) && method_exists('Fiber', 'getCurrent')) {
            $fiber = @\Fiber::getCurrent();
            if ($fiber !== null) {
                return 'fiber_' . spl_object_id($fiber);
            }
        }
        return '__main__';
    }

    private static function buildLogFilePath(): string
    {
        $base = self::$basePath ?: self::getDefaultBasePath();

        // 未显式调用 setBasePath 时在开发环境告警，帮助快速发现配置遗漏
        if (self::$basePath === '' && php_sapi_name() !== 'cli' && !self::isProductionEnv()) {
            trigger_error(
                'Logs::setBasePath() has not been called; log path fell back to: ' . $base,
                E_USER_WARNING
            );
        }
        $ym = date('Ym');
        $day = date('Ymd');
        $dir = $base . DIRECTORY_SEPARATOR . $ym;
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new \RuntimeException("Cannot create log directory: {$dir}");
            }
        }

        $file = $dir . DIRECTORY_SEPARATOR . $day . '.log';

        if (is_file($file) && filesize($file) > self::MAX_FILE_SIZE) {
            if (self::$lastRotationFailTime > 0 && time() - self::$lastRotationFailTime < 600) {
                return $file;
            }

            $lockFile = $file . '.rotatelock';
            $lockHandle = @fopen($lockFile, 'c+');
            $locked = $lockHandle !== false && flock($lockHandle, LOCK_EX | LOCK_NB);

            if ($locked) {
                try {
                    // 锁过期清理：读取锁文件中的时间戳，超过 LOCK_TTL 秒视为过期锁
                    $lockTtl = 300;
                    $stat = fstat($lockHandle);
                    if ($stat['size'] > 0) {
                        rewind($lockHandle);
                        $lockTs = (int)fread($lockHandle, $stat['size']);
                        if ($lockTs > 0 && time() - $lockTs > $lockTtl) {
                            ftruncate($lockHandle, 0);
                            rewind($lockHandle);
                        }
                    }

                    // 双重检查：持锁后再次确认文件大小
                    if (!is_file($file) || filesize($file) <= self::MAX_FILE_SIZE) {
                        return $file;
                    }

                    // 时间戳后缀命名，一次生成唯一文件，无 O(n) 扫描
                    $rollFile = $file . '.' . date('YmdHis');
                    if (is_file($rollFile)) {
                        $rollFile .= '.' . bin2hex(random_bytes(3));
                    }

                    if (!@rename($file, $rollFile)) {
                        error_log("Log rotation failed: {$file}");
                        self::$lastRotationFailTime = time();
                    } else {
                        self::$lastRotationFailTime = 0;
                        // 轮转成功后 touch 新主文件，保证日志文件始终存在
                        @touch($file);
                    }

                    // 写入心跳时间戳
                    rewind($lockHandle);
                    fwrite($lockHandle, (string)time());
                    fflush($lockHandle);
                } finally {
                    flock($lockHandle, LOCK_UN);
                    fclose($lockHandle);
                }
            } else {
                error_log("Log rotation lock failed: {$file}");
                self::$lastRotationFailTime = time();
            }
        }

        return $file;
    }

    private static function getDefaultBasePath(): string
    {
        $logDir = self::DEFAULT_LOG_DIR;

        // webman & thinkphp^5.1.0
        if (function_exists('runtime_path')) {
            return rtrim(runtime_path($logDir), DIRECTORY_SEPARATOR);
        }

        // thinkphp 5.0
        if (defined('RUNTIME_PATH')) {
            return rtrim(RUNTIME_PATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $logDir;
        }

        if (defined('ROOT_PATH')) {
            return rtrim(ROOT_PATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . $logDir;
        }

        return dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . $logDir;
    }

    private static function writeFile(string $file, string $content): void
    {
        if (file_put_contents($file, $content, FILE_APPEND | LOCK_EX) === false) {
            throw new \RuntimeException("Unable to write log file: {$file}");
        }
    }

    private static function normalizeContext(array $context, int $depth = 0): array
    {
        if ($depth > 5) {
            return ['...(truncated)' => 'max depth'];
        }

        $result = [];
        foreach ($context as $key => $value) {
            if (self::isSensitiveKey((string)$key)) {
                $result[$key] = '***';
                continue;
            }

            if ($value instanceof \Throwable) {
                $entry = [
                    'class' => get_class($value),
                    'message' => $value->getMessage(),
                    'code' => $value->getCode(),
                    'file' => $value->getFile(),
                    'line' => $value->getLine(),
                    'trace' => self::truncateBytes(self::formatTrace($value->getTrace()), self::MAX_TRACE_LEN),
                ];
                $extra = self::extractExceptionExtra($value);
                if (!empty($extra)) {
                    $entry['extra'] = self::normalizeContext($extra, $depth + 1);
                }
                $result[$key] = $entry;
            } elseif (is_array($value)) {
                $result[$key] = self::normalizeContext($value, $depth + 1);
            } elseif (is_object($value)) {
                if ($value instanceof \JsonSerializable) {
                    $serialized = $value->jsonSerialize();
                    if (is_array($serialized)) {
                        $result[$key] = self::normalizeContext($serialized, $depth + 1);
                    } else {
                        // 非数组返回值包装为结构化形式，保留可读性
                        $result[$key] = [
                            'type' => gettype($serialized),
                            'value' => self::truncateBytes((string)$serialized, self::MAX_FIELD_LEN),
                        ];
                    }
                } elseif (method_exists($value, '__toString')) {
                    $result[$key] = self::truncateBytes((string)$value, self::MAX_FIELD_LEN);
                } else {
                    $result[$key] = 'object(' . get_class($value) . ')';
                }
            } elseif (is_resource($value)) {
                $result[$key] = 'resource(' . get_resource_type($value) . ')';
            } elseif (is_string($value)) {
                $result[$key] = self::truncateBytes($value, self::MAX_FIELD_LEN);
            } else {
                $result[$key] = $value;
            }
        }
        return $result;
    }

    /**
     * 清理字符串中的控制字符，失败时保留原始字符串
     */
    private static function sanitizeString(string $str): string
    {
        $cleaned = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $str);
        if ($cleaned === null) {
            $cleaned = $str;
        }
        return str_replace(["\r\n", "\r"], "\n", $cleaned);
    }

    /**
     * 判断字符串 key 是否命中敏感字段列表（支持 contains/exact 两种匹配策略）
     */
    private static function isSensitiveKey(string $key): bool
    {
        $lower = strtolower($key);
        foreach (self::$sensitiveKeys as $sensitive) {
            if (self::$sensitiveKeyMatchMode === 'exact') {
                if ($lower === $sensitive) {
                    return true;
                }
            } else {
                if (strpos($lower, $sensitive) !== false) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * 启发式判断字符串值是否像敏感信息。
     * 仅检测明确的格式特征（Bearer/JWT），高熵长随机串的脱敏由 extractExceptionExtra
     * 中单独的开关控制，保持关注点分离。
     */
    private static function looksLikeSensitiveString(string $value): bool
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return false;
        }

        // Bearer / Basic 等 Authorization 头始终脱敏
        if (preg_match('/^(Bearer|Basic|Token|OAuth)\s+/i', $trimmed) === 1) {
            return true;
        }
        // JWT 格式始终脱敏
        if (preg_match('/^eyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $trimmed) === 1) {
            return true;
        }
        return false;
    }

    /**
     * 格式化异常堆栈（默认只输出参数类型，开启 expandTraceArgs 后展开值）
     */
    private static function formatTrace(array $trace): string
    {
        $lines = [];
        foreach ($trace as $i => $frame) {
            $line = "#{$i} ";
            if (isset($frame['file'])) {
                $line .= $frame['file'] . '(' . ($frame['line'] ?? '') . '): ';
            }
            if (isset($frame['class'])) {
                $line .= $frame['class'] . ($frame['type'] ?? '->');
            }
            $line .= ($frame['function'] ?? '{main}') . '(';
            $objects = new \SplObjectStorage();
            if (!empty($frame['args'])) {
                $args = [];
                if (self::$expandTraceArgs) {
                    foreach ($frame['args'] as $arg) {
                        $args[] = self::formatArg($arg, 0, $objects, true);
                    }
                } else {
                    foreach ($frame['args'] as $arg) {
                        $args[] = self::describeArgType($arg);
                    }
                }
                $line .= implode(', ', $args);
            }
            $line .= ')';
            $lines[] = $line;
        }
        return implode("\n", $lines);
    }

    /**
     * 仅描述参数的类型与规模，不展开值，避免泄漏敏感信息
     */
    private static function describeArgType($arg): string
    {
        if (is_null($arg)) {
            return 'null';
        }
        if (is_bool($arg)) {
            return 'bool';
        }
        if (is_int($arg)) {
            return 'int';
        }
        if (is_float($arg)) {
            return 'float';
        }
        if (is_string($arg)) {
            return 'string(' . strlen($arg) . ')';
        }
        if (is_array($arg)) {
            return 'array(' . count($arg) . ')';
        }
        if (is_object($arg)) {
            return 'object(' . get_class($arg) . ')';
        }
        if (is_resource($arg)) {
            return 'resource(' . get_resource_type($arg) . ')';
        }
        return gettype($arg);
    }

    /**
     * 格式化单个参数（递归展开数组、对象，限制深度和长度）
     * @param bool $isTraceContext 是否处于异常堆栈上下文中（会对字符串参数做启发式脱敏）
     */
    private static function formatArg($arg, int $depth = 0, ?\SplObjectStorage $objects = null, bool $isTraceContext = false): string
    {
        if ($depth > 3) {
            return '...';
        }
        if ($objects === null) {
            $objects = new \SplObjectStorage();
        }

        if (is_null($arg)) {
            return 'null';
        }
        if (is_bool($arg)) {
            return $arg ? 'true' : 'false';
        }
        if (is_int($arg) || is_float($arg)) {
            return (string)$arg;
        }
        if (is_string($arg)) {
            $str = $arg;
            if ($isTraceContext && self::looksLikeSensitiveString($str)) {
                return "'***(masked string " . strlen($str) . ")'";
            }
            if (mb_strlen($str, 'UTF-8') > 100) {
                $str = mb_substr($str, 0, 100, 'UTF-8') . '…';
            }
            $encoded = json_encode($str, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($encoded !== false) {
                $str = "'" . addcslashes(substr($encoded, 1, -1), "'\\") . "'";
            } else {
                $str = "'(binary)'";
            }
            return $str;
        }
        if (is_array($arg)) {
            if ($depth >= 3) {
                return '[' . count($arg) . ' elements]';
            }
            $items = [];
            $count = count($arg);
            $i = 0;
            foreach ($arg as $key => $val) {
                if ($i >= 5) {
                    $items[] = '…(' . ($count - $i) . ' more)';
                    break;
                }
                if ($isTraceContext && is_string($key) && self::isSensitiveKey($key)) {
                    $items[] = "'" . addcslashes($key, "'\\") . "' => '***(masked)'";
                    $i++;
                    continue;
                }
                $safeKey = is_string($key)
                    ? "'" . addcslashes($key, "'\\") . "'"
                    : (string)$key;
                $items[] = $safeKey . ' => ' . self::formatArg($val, $depth + 1, $objects, $isTraceContext);
                $i++;
            }
            return '[' . implode(', ', $items) . ']';
        }
        if (is_object($arg)) {
            if ($objects->contains($arg)) {
                return 'object(' . get_class($arg) . ') [RECURSION]';
            }
            $objects->attach($arg);
            $result = 'object(' . get_class($arg) . ')';
            if ($arg instanceof \Throwable) {
                $msg = self::truncateBytes($arg->getMessage(), 50);
                if ($msg !== '') {
                    $result .= ' ' . $msg;
                }
            } elseif (method_exists($arg, '__toString')) {
                try {
                    $str = self::truncateBytes((string)$arg, 50);
                    $result .= ' "' . addcslashes($str, "\"\\") . '"';
                } catch (\Throwable $e) {
                    $result .= ' (__toString exception)';
                }
            }
            return $result;
        }
        if (is_resource($arg)) {
            return 'resource(' . get_resource_type($arg) . ')';
        }
        return (string)$arg;
    }

    private static function extractExceptionExtra(\Throwable $exception): array
    {
        $standardKeys = ['message', 'code', 'file', 'line', 'xdebug_message'];
        $custom = [];
        try {
            $reflect = new \ReflectionClass($exception);
            $props = $reflect->getProperties();
            foreach ($props as $prop) {
                $propName = $prop->getName();
                if (in_array($propName, $standardKeys, true)) {
                    continue;
                }
                $prop->setAccessible(true);
                $value = $prop->getValue($exception);

                // 属性名命中敏感词时，键名与值均脱敏
                if (self::isSensitiveKey($propName)) {
                    $maskedName = preg_replace('/^(.{1,3}).*$/', '$1***', $propName);
                    $custom[$maskedName] = '***';
                    continue;
                }

                // 值本身若是长高熵字符串，替换为提示
                if (is_string($value) && strlen($value) >= 16) {
                    if (self::looksLikeSensitiveString($value)) {
                        $value = '***(sensitive string)';
                    } elseif (self::$autoMaskHighEntropyStrings) {
                        $alnumCount = preg_match_all('/[A-Za-z0-9]/', $value);
                        if ($alnumCount / strlen($value) >= 0.8) {
                            $value = '***(high entropy)';
                        }
                    }
                }

                $custom[$propName] = $value;
            }
        } catch (\Throwable $e) {
            // 反射失败忽略
        }
        return $custom;
    }

    /**
     * 判断当前是否运行在生产环境（通过 APP_ENV / ENV 常量判断）。
     */
    private static function isProductionEnv(): bool
    {
        $env = defined('APP_ENV') ? APP_ENV : (defined('ENV') ? ENV : '');
        return in_array(strtolower($env), ['production', 'prod'], true);
    }

    /**
     * 获取带微秒精度的时间字符串（Y-m-d H:i:s.u）。
     * 基于 microtime() 构造，绕过 DateTime 在某些环境下微秒为 000000 的问题。
     */
    private static function getMicrotimeDatetime(): string
    {
        $mtime = sprintf('%.6f', microtime(true));
        $sec = (int)$mtime;
        $usec = (int)substr($mtime, strpos($mtime, '.') + 1);
        return date('Y-m-d H:i:s', $sec) . '.' . str_pad((string)$usec, 6, '0', STR_PAD_LEFT);
    }

    private static function truncateBytes(string $string, int $maxBytes): string
    {
        if (strlen($string) <= $maxBytes) {
            return $string;
        }
        if (function_exists('mb_strcut')) {
            return mb_strcut($string, 0, $maxBytes, 'UTF-8') . '...(truncated)';
        }
        $sub = substr($string, 0, $maxBytes);
        while (strlen($sub) > 0) {
            $last = ord($sub[strlen($sub) - 1]);
            if ($last < 0x80 || $last >= 0xC0) {
                break;
            }
            $sub = substr($sub, 0, -1);
        }
        return $sub . '...(truncated)';
    }

    /** 防止实例化 */
    private function __construct()
    {
    }

    /** 防止克隆 */
    private function __clone()
    {
    }

    /** 防止反序列化 */
    public function __wakeup()
    {
        throw new \RuntimeException('Cannot unserialize ' . __CLASS__);
    }
}