<?php

date_default_timezone_set('UTC');

$rootDir = dirname(__DIR__);
load_dotenv($rootDir . '/.env');

$provider = 'dashscope';
$providerLabel = 'DashScope';
$model = env_value('AI_MODEL', env_value('DASHSCOPE_MODEL', 'qwen3.6-flash'));
$apiKey = env_value('AI_API_KEY', env_value('DASHSCOPE_API_KEY', ''));
$apiEndpoint = env_value(
    'AI_API_ENDPOINT',
    env_value('DASHSCOPE_API_ENDPOINT', 'https://dashscope.aliyuncs.com/compatible-mode/v1/chat/completions')
);
$maxJsonBytes = env_int('MAX_JSON_BYTES', 18 * 1024 * 1024);
$maxTokens = env_int('AI_MAX_TOKENS', 900);
$aiTimeoutMs = env_positive_int('AI_TIMEOUT_MS', 3000);
$wmsApiToken = env_value('WMS_API_TOKEN', '');
$corsAllowOrigin = env_value('CORS_ALLOW_ORIGIN', '*');
$recognitionLogPath = resolve_path($rootDir, env_value('RECOGNITION_LOG_PATH', 'logs/recognition-events.jsonl'));
$logRetentionDays = env_non_negative_int('LOG_RETENTION_DAYS', 180);
$sampleDir = resolve_path(
    $rootDir,
    env_value('SAMPLE_DIR', '../../raw/项目/WMS批次读取与人工校验_2026-06-11/批次样本_2026-06-11')
);

$triggerWords = array(
    'LOT',
    'Lot No',
    'Lot Number',
    'Batch',
    'S/N',
    'Serial No',
    'Retrace Code',
    '批号',
    '生产批号',
    '批次号',
    '批次代码',
    '序列号',
    '产品序列号',
    '序号',
    '出厂编号',
);

$valueAfterTriggerPattern = '[：:\s]*([A-Za-z0-9][A-Za-z0-9._\/-]{0,40})';
$triggerFallbackPatterns = array(
    array('trigger' => 'Lot Number', 'regex' => '~\bLot\s+Number\b' . $valueAfterTriggerPattern . '~iu'),
    array('trigger' => 'Lot No', 'regex' => '~\bLot\s+No\b\.?' . $valueAfterTriggerPattern . '~iu'),
    array('trigger' => 'LOT', 'regex' => '~\bLOT\b(?!\s+(?:No\b|Number\b))' . $valueAfterTriggerPattern . '~iu'),
    array('trigger' => 'Batch', 'regex' => '~\bBatch\b' . $valueAfterTriggerPattern . '~iu'),
    array('trigger' => 'S/N', 'regex' => '~\bS\s*/\s*N\b' . $valueAfterTriggerPattern . '~iu'),
    array('trigger' => 'Serial No', 'regex' => '~\bSerial\s+No\b\.?' . $valueAfterTriggerPattern . '~iu'),
    array('trigger' => 'Retrace Code', 'regex' => '~\bRetrace\s+Code\b' . $valueAfterTriggerPattern . '~iu'),
    array('trigger' => '生产批号', 'regex' => '~生产批号' . $valueAfterTriggerPattern . '~iu'),
    array('trigger' => '批次代码', 'regex' => '~批次代码' . $valueAfterTriggerPattern . '~iu'),
    array('trigger' => '批次号', 'regex' => '~批次号' . $valueAfterTriggerPattern . '~iu'),
    array('trigger' => '产品序列号', 'regex' => '~产品序列号' . $valueAfterTriggerPattern . '~iu'),
    array('trigger' => '序列号', 'regex' => '~序列号' . $valueAfterTriggerPattern . '~iu'),
    array('trigger' => '序号', 'regex' => '~序号' . $valueAfterTriggerPattern . '~iu'),
    array('trigger' => '出厂编号', 'regex' => '~出厂编号' . $valueAfterTriggerPattern . '~iu'),
    array('trigger' => '批号', 'regex' => '~批号' . $valueAfterTriggerPattern . '~iu'),
);

class HttpError extends Exception
{
    public $statusCode;
    public $elapsedMs;

    public function __construct($message, $statusCode = 500, $elapsedMs = null)
    {
        parent::__construct($message);
        $this->statusCode = $statusCode;
        $this->elapsedMs = $elapsedMs;
    }
}

function load_dotenv($path)
{
    if (!is_file($path) || !is_readable($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || strpos($trimmed, '#') === 0) {
            continue;
        }
        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) {
            continue;
        }
        $key = trim($parts[0]);
        $value = trim($parts[1]);
        $value = trim($value, "\"'");
        if ($key !== '' && getenv($key) === false) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }
}

function env_value($name, $default = '')
{
    $value = getenv($name);
    return $value === false ? $default : $value;
}

function env_int($name, $default)
{
    $value = getenv($name);
    if ($value === false || !is_numeric($value)) {
        return $default;
    }
    return intval($value);
}

function env_positive_int($name, $default)
{
    $value = env_int($name, $default);
    return $value > 0 ? $value : $default;
}

function env_non_negative_int($name, $default)
{
    $value = env_int($name, $default);
    return $value >= 0 ? $value : $default;
}

function resolve_path($rootDir, $path)
{
    if ($path === '') {
        return $rootDir;
    }
    if (substr($path, 0, 1) === '/') {
        return $path;
    }
    return $rootDir . '/' . $path;
}

function now_ms()
{
    return (int) round(microtime(true) * 1000);
}

function send_cors_headers()
{
    global $corsAllowOrigin;
    header('Access-Control-Allow-Origin: ' . $corsAllowOrigin);
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');
    header('Access-Control-Max-Age: 86400');
}

function send_json($statusCode, $data, $withCors = false)
{
    if ($withCors) {
        send_cors_headers();
    }
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function send_file_response($path, $contentType)
{
    if (!is_file($path) || !is_readable($path)) {
        send_json(404, array('ok' => false, 'error' => 'Not found'), false);
        return;
    }
    http_response_code(200);
    header('Content-Type: ' . $contentType);
    header('Content-Length: ' . filesize($path));
    readfile($path);
}

function read_json_body()
{
    global $maxJsonBytes;

    $raw = file_get_contents('php://input');
    if ($raw === false) {
        throw new HttpError('读取请求体失败。', 400);
    }
    if (strlen($raw) > $maxJsonBytes) {
        throw new HttpError('请求体太大，请压缩图片后再试。', 413);
    }
    if (trim($raw) === '') {
        return array();
    }

    $body = json_decode($raw, true);
    if (!is_array($body)) {
        throw new HttpError('请求 JSON 格式错误。', 400);
    }
    return $body;
}

function hash_equals_safe($known, $user)
{
    if (function_exists('hash_equals')) {
        return hash_equals($known, $user);
    }
    if (strlen($known) !== strlen($user)) {
        return false;
    }
    $result = 0;
    for ($i = 0; $i < strlen($known); $i += 1) {
        $result |= ord($known[$i]) ^ ord($user[$i]);
    }
    return $result === 0;
}

function request_header($name)
{
    $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    if (isset($_SERVER[$serverKey])) {
        return $_SERVER[$serverKey];
    }
    if (strtolower($name) === 'authorization' && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        return $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }
    return '';
}

function require_wms_api_token()
{
    global $wmsApiToken;
    if ($wmsApiToken === '') {
        return;
    }

    $authorization = (string) request_header('Authorization');
    $bearerToken = '';
    if (strpos($authorization, 'Bearer ') === 0) {
        $bearerToken = substr($authorization, strlen('Bearer '));
    }
    $apiKeyHeader = (string) request_header('X-API-Key');

    if (hash_equals_safe($wmsApiToken, $bearerToken) || hash_equals_safe($wmsApiToken, $apiKeyHeader)) {
        return;
    }
    throw new HttpError('WMS 接口鉴权失败。', 401);
}

function normalize_api_image_data_url($body)
{
    $imageDataUrl = isset($body['imageDataUrl']) ? $body['imageDataUrl'] : (isset($body['image_data_url']) ? $body['image_data_url'] : null);
    if (is_string($imageDataUrl) && strpos($imageDataUrl, 'data:image/') === 0) {
        return $imageDataUrl;
    }

    $imageBase64 = null;
    foreach (array('imageBase64', 'image_base64', 'base64') as $key) {
        if (isset($body[$key]) && is_string($body[$key]) && trim($body[$key]) !== '') {
            $imageBase64 = $body[$key];
            break;
        }
    }
    if (is_string($imageBase64) && trim($imageBase64) !== '') {
        if (strpos($imageBase64, 'data:image/') === 0) {
            return $imageBase64;
        }
        $mimeType = isset($body['mimeType']) ? $body['mimeType'] : (isset($body['mime_type']) ? $body['mime_type'] : 'image/jpeg');
        return 'data:' . $mimeType . ';base64,' . preg_replace('/\s+/', '', $imageBase64);
    }

    throw new HttpError('缺少图片字段，请传 imageDataUrl 或 imageBase64。', 400);
}

function parse_image_data_url($imageDataUrl)
{
    if (!preg_match('~^data:(image/[a-zA-Z0-9.+-]+);base64,([\s\S]+)$~', $imageDataUrl, $matches)) {
        throw new HttpError('图片 data URL 格式错误。', 400);
    }

    $mimeType = strtolower($matches[1]);
    $buffer = base64_decode(preg_replace('/\s+/', '', $matches[2]), true);
    if ($buffer === false || strlen($buffer) === 0) {
        throw new HttpError('图片 base64 内容为空。', 400);
    }
    return array('mimeType' => $mimeType, 'buffer' => $buffer);
}

function kb($bytes)
{
    return round(($bytes / 1024) * 10) / 10;
}

function optional_number($value)
{
    if ($value === null || $value === '') {
        return null;
    }
    return is_numeric($value) ? $value + 0 : null;
}

function optional_boolean($value)
{
    if (is_bool($value)) {
        return $value;
    }
    if (!is_string($value) && !is_numeric($value)) {
        return null;
    }
    $normalized = strtolower(trim((string) $value));
    if (in_array($normalized, array('true', '1', 'yes'), true)) {
        return true;
    }
    if (in_array($normalized, array('false', '0', 'no'), true)) {
        return false;
    }
    return null;
}

function nested_image_meta($body)
{
    if (isset($body['image_meta']) && is_array($body['image_meta'])) {
        return $body['image_meta'];
    }
    if (isset($body['imageMeta']) && is_array($body['imageMeta'])) {
        return $body['imageMeta'];
    }
    return array();
}

function read_uint16_be($buffer, $offset)
{
    return (ord($buffer[$offset]) << 8) | ord($buffer[$offset + 1]);
}

function read_uint32_be($buffer, $offset)
{
    return (ord($buffer[$offset]) << 24) | (ord($buffer[$offset + 1]) << 16) | (ord($buffer[$offset + 2]) << 8) | ord($buffer[$offset + 3]);
}

function read_uint16_le($buffer, $offset)
{
    return ord($buffer[$offset]) | (ord($buffer[$offset + 1]) << 8);
}

function read_uint24_le($buffer, $offset)
{
    return ord($buffer[$offset]) | (ord($buffer[$offset + 1]) << 8) | (ord($buffer[$offset + 2]) << 16);
}

function read_uint32_le($buffer, $offset)
{
    return ord($buffer[$offset]) | (ord($buffer[$offset + 1]) << 8) | (ord($buffer[$offset + 2]) << 16) | (ord($buffer[$offset + 3]) << 24);
}

function read_jpeg_dimensions($buffer)
{
    $length = strlen($buffer);
    if ($length < 4 || ord($buffer[0]) !== 0xff || ord($buffer[1]) !== 0xd8) {
        return null;
    }

    $offset = 2;
    while ($offset + 9 < $length) {
        if (ord($buffer[$offset]) !== 0xff) {
            $offset += 1;
            continue;
        }

        $marker = ord($buffer[$offset + 1]);
        $offset += 2;
        if ($marker === 0xd8 || $marker === 0xd9) {
            continue;
        }
        if ($marker >= 0xd0 && $marker <= 0xd7) {
            continue;
        }
        if ($offset + 2 > $length) {
            return null;
        }

        $segmentLength = read_uint16_be($buffer, $offset);
        if ($segmentLength < 2 || $offset + $segmentLength > $length) {
            return null;
        }

        $isSofMarker =
            ($marker >= 0xc0 && $marker <= 0xc3) ||
            ($marker >= 0xc5 && $marker <= 0xc7) ||
            ($marker >= 0xc9 && $marker <= 0xcb) ||
            ($marker >= 0xcd && $marker <= 0xcf);
        if ($isSofMarker && $offset + 7 < $length) {
            return array(
                'width' => read_uint16_be($buffer, $offset + 5),
                'height' => read_uint16_be($buffer, $offset + 3),
            );
        }

        $offset += $segmentLength;
    }

    return null;
}

function read_png_dimensions($buffer)
{
    $pngSignature = "\x89PNG\r\n\x1a\n";
    if (strlen($buffer) < 24 || substr($buffer, 0, 8) !== $pngSignature) {
        return null;
    }
    return array(
        'width' => read_uint32_be($buffer, 16),
        'height' => read_uint32_be($buffer, 20),
    );
}

function read_webp_dimensions($buffer)
{
    if (
        strlen($buffer) < 30 ||
        substr($buffer, 0, 4) !== 'RIFF' ||
        substr($buffer, 8, 4) !== 'WEBP'
    ) {
        return null;
    }

    $chunk = substr($buffer, 12, 4);
    if ($chunk === 'VP8X' && strlen($buffer) >= 30) {
        return array(
            'width' => read_uint24_le($buffer, 24) + 1,
            'height' => read_uint24_le($buffer, 27) + 1,
        );
    }
    if ($chunk === 'VP8 ' && strlen($buffer) >= 30) {
        return array(
            'width' => read_uint16_le($buffer, 26) & 0x3fff,
            'height' => read_uint16_le($buffer, 28) & 0x3fff,
        );
    }
    if ($chunk === 'VP8L' && strlen($buffer) >= 25) {
        $bits = read_uint32_le($buffer, 21);
        return array(
            'width' => ($bits & 0x3fff) + 1,
            'height' => (($bits >> 14) & 0x3fff) + 1,
        );
    }

    return null;
}

function image_dimensions($mimeType, $buffer)
{
    $dimensions = null;
    if ($mimeType === 'image/jpeg' || $mimeType === 'image/jpg') {
        $dimensions = read_jpeg_dimensions($buffer);
    } elseif ($mimeType === 'image/png') {
        $dimensions = read_png_dimensions($buffer);
    } elseif ($mimeType === 'image/webp') {
        $dimensions = read_webp_dimensions($buffer);
    }

    if ($dimensions === null) {
        $dimensions = read_png_dimensions($buffer);
    }
    if ($dimensions === null) {
        $dimensions = read_jpeg_dimensions($buffer);
    }
    if ($dimensions === null) {
        $dimensions = read_webp_dimensions($buffer);
    }
    if ($dimensions === null && function_exists('getimagesizefromstring')) {
        $size = @getimagesizefromstring($buffer);
        if (is_array($size) && isset($size[0], $size[1])) {
            $dimensions = array('width' => intval($size[0]), 'height' => intval($size[1]));
        }
    }

    return $dimensions !== null ? $dimensions : array('width' => null, 'height' => null);
}

function inspect_input_image($imageDataUrl, $body)
{
    $parsed = parse_image_data_url($imageDataUrl);
    $imageMeta = nested_image_meta($body);
    $dimensions = image_dimensions($parsed['mimeType'], $parsed['buffer']);

    return array(
        'received_image_size_kb' => kb(strlen($parsed['buffer'])),
        'received_image_bytes' => strlen($parsed['buffer']),
        'received_mime_type' => $parsed['mimeType'],
        'received_width' => $dimensions['width'],
        'received_height' => $dimensions['height'],
        'client_compressed' => first_not_null(array(
            optional_boolean(array_value($imageMeta, 'compressed')),
            optional_boolean(array_value($imageMeta, 'client_compressed')),
            optional_boolean(array_value($body, 'client_compressed')),
            optional_boolean(array_value($body, 'compressed_by_client')),
        )),
        'client_original_image_size_kb' => first_not_null(array(
            optional_number(array_value($imageMeta, 'original_image_size_kb')),
            optional_number(array_value($imageMeta, 'original_size_kb')),
            optional_number(array_value($body, 'original_image_size_kb')),
        )),
        'client_recognition_image_size_kb' => first_not_null(array(
            optional_number(array_value($imageMeta, 'recognition_image_size_kb')),
            optional_number(array_value($imageMeta, 'processed_image_size_kb')),
            optional_number(array_value($body, 'recognition_image_size_kb')),
        )),
        'client_image_width' => first_not_null(array(
            optional_number(array_value($imageMeta, 'width')),
            optional_number(array_value($imageMeta, 'recognition_width')),
            optional_number(array_value($body, 'image_width')),
        )),
        'client_image_height' => first_not_null(array(
            optional_number(array_value($imageMeta, 'height')),
            optional_number(array_value($imageMeta, 'recognition_height')),
            optional_number(array_value($body, 'image_height')),
        )),
        'client_image_max_side' => first_not_null(array(
            optional_number(array_value($imageMeta, 'max_side')),
            optional_number(array_value($body, 'image_max_side')),
        )),
        'client_image_quality' => first_not_null(array(
            optional_number(array_value($imageMeta, 'quality')),
            optional_number(array_value($imageMeta, 'jpeg_quality')),
            optional_number(array_value($body, 'image_quality')),
            optional_number(array_value($body, 'jpeg_quality')),
        )),
    );
}

function array_value($array, $key, $default = null)
{
    return is_array($array) && array_key_exists($key, $array) ? $array[$key] : $default;
}

function first_not_null($values)
{
    foreach ($values as $value) {
        if ($value !== null) {
            return $value;
        }
    }
    return null;
}

function extract_output_text($data)
{
    $message = array_value(array_value(array_value($data, 'choices', array()), 0, array()), 'message', array());
    $content = array_value($message, 'content');
    if (is_string($content)) {
        return $content;
    }
    if (is_array($content)) {
        $parts = array();
        foreach ($content as $part) {
            if (is_array($part)) {
                $text = array_value($part, 'text', array_value($part, 'content', ''));
                if ($text !== '') {
                    $parts[] = $text;
                }
            }
        }
        return trim(implode("\n", $parts));
    }
    return '';
}

function parse_model_json($text)
{
    $trimmed = trim($text);
    $trimmed = preg_replace('/^```json\s*/i', '', $trimmed);
    $trimmed = preg_replace('/```\s*$/', '', $trimmed);
    $jsonLike = extract_first_json_object(trim($trimmed));

    $parsed = json_decode($jsonLike, true);
    if (is_array($parsed)) {
        return $parsed;
    }

    $escaped = escape_control_chars_inside_json_strings($jsonLike);
    $parsed = json_decode($escaped, true);
    if (is_array($parsed)) {
        return $parsed;
    }

    $fallback = parse_json_fields_loosely($jsonLike);
    if (is_array($fallback)) {
        return $fallback;
    }

    throw new HttpError('AI 返回 JSON 解析失败：' . json_last_error_msg(), 502);
}

function extract_first_json_object($text)
{
    $start = strpos($text, '{');
    if ($start === false) {
        throw new HttpError('AI 返回内容不是 JSON。', 502);
    }

    $depth = 0;
    $inString = false;
    $escaped = false;
    $length = strlen($text);

    for ($i = $start; $i < $length; $i += 1) {
        $char = $text[$i];

        if ($inString) {
            if ($escaped) {
                $escaped = false;
            } elseif ($char === '\\') {
                $escaped = true;
            } elseif ($char === '"') {
                $inString = false;
            }
            continue;
        }

        if ($char === '"') {
            $inString = true;
        } elseif ($char === '{') {
            $depth += 1;
        } elseif ($char === '}') {
            $depth -= 1;
            if ($depth === 0) {
                return substr($text, $start, $i - $start + 1);
            }
        }
    }

    return substr($text, $start);
}

function escape_control_chars_inside_json_strings($text)
{
    $result = '';
    $inString = false;
    $escaped = false;
    $length = strlen($text);

    for ($i = 0; $i < $length; $i += 1) {
        $char = $text[$i];
        if ($inString) {
            if ($escaped) {
                $result .= $char;
                $escaped = false;
            } elseif ($char === '\\') {
                $result .= $char;
                $escaped = true;
            } elseif ($char === '"') {
                $result .= $char;
                $inString = false;
            } elseif ($char === "\n") {
                $result .= '\\n';
            } elseif ($char === "\r") {
                $result .= '\\r';
            } elseif ($char === "\t") {
                $result .= '\\t';
            } elseif (ord($char) < 32) {
                $result .= ' ';
            } else {
                $result .= $char;
            }
            continue;
        }

        $result .= $char;
        if ($char === '"') {
            $inString = true;
        }
    }

    return $result;
}

function parse_json_fields_loosely($text)
{
    $parsed = array(
        'batch_number' => loose_string_field($text, 'batch_number'),
        'production_date' => loose_string_field($text, 'production_date'),
        'expiry_date' => loose_string_field($text, 'expiry_date'),
        'status' => loose_string_field($text, 'status'),
        'confidence' => loose_string_field($text, 'confidence'),
        'trigger' => loose_string_field($text, 'trigger'),
        'candidates' => loose_array_field($text, 'candidates'),
        'reason' => loose_string_field($text, 'reason'),
        'raw_visible_text' => loose_string_field($text, 'raw_visible_text'),
    );

    return ($parsed['batch_number'] !== '' || $parsed['status'] !== '' || $parsed['reason'] !== '') ? $parsed : null;
}

function loose_string_field($text, $name)
{
    if (preg_match('/"' . preg_quote($name, '/') . '"\s*:\s*"([\s\S]*?)"\s*(?:,|\n|\r|})/', $text, $matches)) {
        return trim(preg_replace('/[\x00-\x1F]+/', ' ', $matches[1]));
    }
    return '';
}

function loose_array_field($text, $name)
{
    if (!preg_match('/"' . preg_quote($name, '/') . '"\s*:\s*\[([\s\S]*?)\]/', $text, $matches)) {
        return array();
    }
    preg_match_all('/"([^"]+)"/', $matches[1], $items);
    return array_values(array_filter(array_map('trim', $items[1])));
}

function clean_fallback_value($value)
{
    return preg_replace('/[，,。；;）)\]}】]+$/u', '', trim($value));
}

function normalize_date_value($value)
{
    if (!is_string($value)) {
        return '';
    }
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    $patterns = array(
        '/^(\d{4})[-\.\/](\d{1,2})[-\.\/](\d{1,2})$/u',
        '/^(\d{4})年(\d{1,2})月(\d{1,2})日?$/u',
        '/^(\d{2})[-\.\/](\d{1,2})[-\.\/](\d{1,2})$/u',
    );
    foreach ($patterns as $index => $pattern) {
        if (preg_match($pattern, $value, $matches)) {
            $year = intval($matches[1]);
            if ($index === 2) {
                $year += $year >= 70 ? 1900 : 2000;
            }
            $month = intval($matches[2]);
            $day = intval($matches[3]);
            return checkdate($month, $day, $year) ? sprintf('%04d-%02d-%02d', $year, $month, $day) : '';
        }
    }

    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{2}|\d{4})$/u', $value, $matches)) {
        $first = intval($matches[1]);
        $middle = intval($matches[2]);
        $last = intval($matches[3]);
        if ($first >= 1 && $first <= 12 && $middle > 12 && $middle <= 31) {
            $year = strlen($matches[3]) === 2 ? ($last >= 70 ? 1900 + $last : 2000 + $last) : $last;
            return checkdate($first, $middle, $year) ? sprintf('%04d-%02d-%02d', $year, $first, $middle) : '';
        }
    }

    if (preg_match('/^\d{6}$/', $value)) {
        $first = intval(substr($value, 0, 2));
        $middle = intval(substr($value, 2, 2));
        $last = intval(substr($value, 4, 2));
        $validCandidates = array();

        $mmddyyYear = $last >= 70 ? 1900 + $last : 2000 + $last;
        if (checkdate($first, $middle, $mmddyyYear)) {
            $validCandidates[] = sprintf('%04d-%02d-%02d', $mmddyyYear, $first, $middle);
        }

        $yymmddYear = $first >= 70 ? 1900 + $first : 2000 + $first;
        if (checkdate($middle, $last, $yymmddYear)) {
            $validCandidates[] = sprintf('%04d-%02d-%02d', $yymmddYear, $middle, $last);
        }

        $uniqueCandidates = array_values(array_unique($validCandidates));
        return count($uniqueCandidates) === 1 ? $uniqueCandidates[0] : '';
    }

    if (
        preg_match('/^(\d{4})[-\.\/](\d{1,2})$/u', $value, $matches) ||
        preg_match('/^(\d{4})年(\d{1,2})月?$/u', $value, $matches)
    ) {
        $year = intval($matches[1]);
        $month = intval($matches[2]);
        return checkdate($month, 1, $year) ? sprintf('%04d-%02d-01', $year, $month) : '';
    }

    return '';
}

function find_trigger_values($text)
{
    global $triggerFallbackPatterns;
    if (!is_string($text) || trim($text) === '') {
        return array();
    }

    $normalized = preg_replace('/\s+/u', ' ', trim($text));
    $candidatesByValue = array();

    foreach ($triggerFallbackPatterns as $pattern) {
        if (preg_match_all($pattern['regex'], $normalized, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $value = clean_fallback_value(isset($match[1]) ? $match[1] : '');
                if ($value !== '' && !isset($candidatesByValue[$value])) {
                    $candidatesByValue[$value] = array('trigger' => $pattern['trigger'], 'value' => $value);
                }
            }
        }
    }

    return array_values($candidatesByValue);
}

function apply_trigger_fallback($parsed)
{
    $status = array_value($parsed, 'status', '');
    $batchNumber = trim((string) array_value($parsed, 'batch_number', ''));
    if ($status !== 'not_found' || $batchNumber !== '') {
        return $parsed;
    }

    $fallbackCandidates = find_trigger_values(array_value($parsed, 'raw_visible_text', ''));
    if (count($fallbackCandidates) === 0) {
        return $parsed;
    }

    $candidateValues = array();
    foreach ($fallbackCandidates as $candidate) {
        $candidateValues[] = $candidate['value'];
    }

    if (count($fallbackCandidates) > 1) {
        $triggers = array();
        foreach ($fallbackCandidates as $candidate) {
            $triggers[] = $candidate['trigger'];
        }
        $parsed['batch_number'] = '';
        $parsed['status'] = 'multiple_candidates';
        $parsed['confidence'] = array_value($parsed, 'confidence', 'unknown') === 'unknown' ? 'medium' : array_value($parsed, 'confidence', 'medium');
        $parsed['trigger'] = implode('、', $triggers);
        $parsed['candidates'] = array_values(array_unique(array_merge(array_value($parsed, 'candidates', array()), $candidateValues)));
        $parsed['reason'] = array_value($parsed, 'reason', '') !== ''
            ? $parsed['reason'] . '；系统兜底：原文包含多个同级触发词候选，需人工选择。'
            : '系统兜底：原文包含多个同级触发词候选，需人工选择。';
        return $parsed;
    }

    $fallback = $fallbackCandidates[0];
    $parsed['batch_number'] = $fallback['value'];
    $parsed['status'] = 'recognized';
    $parsed['confidence'] = array_value($parsed, 'confidence', 'unknown') === 'unknown' ? 'medium' : array_value($parsed, 'confidence', 'medium');
    $parsed['trigger'] = $fallback['trigger'];
    $parsed['candidates'] = array_values(array_unique(array_merge(array($fallback['value']), array_value($parsed, 'candidates', array()))));
    $parsed['reason'] = array_value($parsed, 'reason', '') !== ''
        ? $parsed['reason'] . '；系统兜底：原文包含同级触发词“' . $fallback['trigger'] . '”及唯一值，按厂商批号候选处理。'
        : '系统兜底：原文包含同级触发词“' . $fallback['trigger'] . '”及唯一值，按厂商批号候选处理。';
    return $parsed;
}

function normalize_recognition($parsed, $elapsedMs, $imageInfo)
{
    global $provider, $providerLabel, $model;

    $fallbackParsed = apply_trigger_fallback($parsed);
    $allowedStatuses = array('recognized', 'multiple_candidates', 'not_found', 'error');
    $status = in_array(array_value($fallbackParsed, 'status', ''), $allowedStatuses, true)
        ? array_value($fallbackParsed, 'status')
        : 'error';
    $batchNumber = is_string(array_value($fallbackParsed, 'batch_number'))
        ? trim(array_value($fallbackParsed, 'batch_number'))
        : '';
    $candidates = is_array(array_value($fallbackParsed, 'candidates'))
        ? array_slice(array_values(array_filter(array_map('strval', array_value($fallbackParsed, 'candidates')))), 0, 6)
        : array();
    $productionDate = normalize_date_value(array_value($fallbackParsed, 'production_date', ''));
    $expiryDate = normalize_date_value(array_value($fallbackParsed, 'expiry_date', ''));

    return array(
        'batch_number' => $status === 'not_found' ? '' : $batchNumber,
        'production_date' => $productionDate,
        'expiry_date' => $expiryDate,
        'status' => $status,
        'confidence' => is_string(array_value($fallbackParsed, 'confidence')) ? array_value($fallbackParsed, 'confidence') : 'unknown',
        'trigger' => is_string(array_value($fallbackParsed, 'trigger')) ? array_value($fallbackParsed, 'trigger') : '',
        'candidates' => $candidates,
        'reason' => is_string(array_value($fallbackParsed, 'reason')) ? array_value($fallbackParsed, 'reason') : '',
        'raw_visible_text' => is_string(array_value($fallbackParsed, 'raw_visible_text')) ? array_value($fallbackParsed, 'raw_visible_text') : '',
        'needs_human_confirmation' => true,
        'elapsed_ms' => $elapsedMs,
        'ai_timeout_ms' => $GLOBALS['aiTimeoutMs'],
        'image_info' => $imageInfo,
        'provider' => $provider,
        'provider_label' => $providerLabel,
        'thinking' => 'disabled',
        'model' => $model,
    );
}

function build_timeout_recognition($elapsedMs, $imageInfo)
{
    return normalize_recognition(array(
        'batch_number' => '',
        'production_date' => '',
        'expiry_date' => '',
        'status' => 'not_found',
        'confidence' => 'unknown',
        'trigger' => '',
        'candidates' => array(),
        'reason' => 'AI 识别超过 ' . $GLOBALS['aiTimeoutMs'] . 'ms 未返回，按超时未识别处理，需人工填写或确认为空。',
        'raw_visible_text' => '',
    ), $elapsedMs, $imageInfo);
}

function build_prompt()
{
    global $triggerWords;
    return implode("\n", array(
        '你是 WMS 入库小程序的入库标签识别助手。',
        '目标：从图片中识别三个可人工确认字段：厂商批号、生产日期、失效日期。不要识别型号、规格、货号、数量、价格。',
        '以下触发词全部等价，都是 WMS 厂商批号来源，优先级相同：' . implode('、', $triggerWords) . '。',
        '只要图片中出现任一上述触发词，且其后或旁边有唯一对应值，就必须返回 status=recognized。',
        '不得因为字段名是 S/N、Serial No、序列号、产品序列号、序号或出厂编号而返回 not_found；这些字段在本项目中与 LOT、Batch、批号同级。',
        '生产日期触发词包括：生产日期、生产年月、制造日期、MFG、Mfg. Date、MFD、Manufacture Date、UDI 中的 (11)。',
        '失效日期触发词包括：EXP、Exp.、Expiry Date、Expiration Date、Use Before、Best Before、失效日期、有效期至、有效期、沙漏/漏斗图标后的日期、UDI 中的 (17)。',
        '日期格式统一输出 YYYY-MM-DD；如果明确只看到年月，默认补为当月 1 号，例如 2026年05月 输出 2026-05-01。',
        '可以根据明确生产日期和明确 Shelf Life/有效期年限推算失效日期，但必须在 reason 中说明由生产日期和有效期推算。',
        '业务规则：错误风险高，所以识别不到就不猜；批号、生产日期、失效日期都可以返回空值，由人工填写。',
        '如果有明确触发词和唯一值，status=recognized。',
        '如果存在多个候选，status=multiple_candidates，并把候选写入 candidates，batch_number 填最可能的一个。',
        '如果没有明确批号触发词，或无法判断哪个值是批次号，status=not_found，batch_number 为空；但 production_date 和 expiry_date 仍可填写明确识别到的日期。',
        '不能从批号、序列号、货号或普通数字中猜日期；不能输出“疑似”“待复核”。不确定就输出空字符串。',
        '无论结果如何都需要人工确认，不能自动提交。',
        '只输出 JSON，不要输出 Markdown。',
        'JSON 字段必须是：batch_number, production_date, expiry_date, status, confidence, trigger, candidates, reason, raw_visible_text。',
        'status 只能是 recognized、multiple_candidates、not_found、error。',
        'confidence 只能是 high、medium、low、unknown。',
        'raw_visible_text 只写与批次识别相关的短文本，不能换行；如果没有必要，返回空字符串。',
        '所有 JSON 字符串内禁止出现未转义换行符、制表符或其他控制字符。',
    ));
}

function recognize_batch($imageDataUrl, $imageInfo)
{
    global $apiKey, $apiEndpoint, $model, $maxTokens, $providerLabel, $aiTimeoutMs;

    if ($apiKey === '') {
        throw new HttpError('缺少 DASHSCOPE_API_KEY 或 AI_API_KEY。请在启动服务时设置环境变量。', 401);
    }
    if (!is_string($imageDataUrl) || strpos($imageDataUrl, 'data:image/') !== 0) {
        throw new HttpError('请上传 PNG、JPG 或 WebP 图片。', 400);
    }
    if (!function_exists('curl_init')) {
        throw new HttpError('PHP cURL 扩展未启用，无法调用百炼接口。', 500);
    }

    $payload = array(
        'model' => $model,
        'max_tokens' => $maxTokens,
        'temperature' => 0.1,
        'enable_thinking' => false,
        'messages' => array(
            array(
                'role' => 'user',
                'content' => array(
                    array('type' => 'text', 'text' => build_prompt()),
                    array('type' => 'image_url', 'image_url' => array('url' => $imageDataUrl)),
                ),
            ),
        ),
    );

    $started = now_ms();
    $ch = curl_init($apiEndpoint);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, min(10000, $aiTimeoutMs));
    curl_setopt($ch, CURLOPT_TIMEOUT_MS, $aiTimeoutMs);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json',
    ));
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    $rawResponse = curl_exec($ch);
    $elapsedMs = now_ms() - $started;
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErrno = curl_errno($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($rawResponse === false) {
        if ($curlErrno === 28) {
            return build_timeout_recognition($elapsedMs, $imageInfo);
        }
        throw new HttpError($providerLabel . ' API 请求失败：' . $curlError, 502, $elapsedMs);
    }

    $data = json_decode($rawResponse, true);
    if (!is_array($data)) {
        throw new HttpError($providerLabel . ' API 返回非 JSON 内容。', 502, $elapsedMs);
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        $message = array_value(array_value($data, 'error', array()), 'message', array_value($data, 'message', $providerLabel . ' API 请求失败：' . $httpCode));
        throw new HttpError($message, $httpCode ?: 502, $elapsedMs);
    }

    $outputText = extract_output_text($data);
    $parsed = parse_model_json($outputText);
    return normalize_recognition($parsed, $elapsedMs, $imageInfo);
}

function build_recognition_response($result, $requestId, $totalElapsedMs)
{
    return array(
        'ok' => true,
        'request_id' => $requestId,
        'data' => array(
            'batch_number' => $result['batch_number'],
            'production_date' => $result['production_date'],
            'expiry_date' => $result['expiry_date'],
            'status' => $result['status'],
            'confidence' => $result['confidence'],
            'trigger' => $result['trigger'],
            'candidates' => $result['candidates'],
            'needs_human_confirmation' => true,
        ),
        'audit' => array(
            'ai_raw_visible_text' => $result['raw_visible_text'],
            'ai_reason' => $result['reason'],
        ),
        'meta' => array(
            'elapsed_ms' => $result['elapsed_ms'],
            'total_elapsed_ms' => $totalElapsedMs,
            'ai_timeout_ms' => $result['ai_timeout_ms'],
            'provider' => $result['provider'],
            'provider_label' => $result['provider_label'],
            'model' => $result['model'],
            'thinking' => $result['thinking'],
            'image_info' => $result['image_info'],
        ),
    );
}

function log_line_is_expired($line, $cutoffTimestamp)
{
    $decoded = json_decode($line, true);
    if (!is_array($decoded) || !isset($decoded['created_at'])) {
        return false;
    }
    $createdAt = strtotime((string) $decoded['created_at']);
    if ($createdAt === false) {
        return false;
    }
    return $createdAt < $cutoffTimestamp;
}

function append_jsonl($event)
{
    global $recognitionLogPath, $logRetentionDays;
    $dir = dirname($recognitionLogPath);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $line = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    $handle = fopen($recognitionLogPath, 'c+');
    if ($handle === false) {
        throw new Exception('无法打开日志文件。');
    }

    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        throw new Exception('无法锁定日志文件。');
    }

    if ($logRetentionDays > 0) {
        $cutoffTimestamp = time() - $logRetentionDays * 24 * 60 * 60;
        $retainedLines = array();
        rewind($handle);
        while (($existingLine = fgets($handle)) !== false) {
            $trimmedLine = trim($existingLine);
            if ($trimmedLine === '') {
                continue;
            }
            if (!log_line_is_expired($trimmedLine, $cutoffTimestamp)) {
                $retainedLines[] = rtrim($existingLine, "\r\n");
            }
        }
        rewind($handle);
        ftruncate($handle, 0);
        foreach ($retainedLines as $retainedLine) {
            fwrite($handle, $retainedLine . "\n");
        }
    } else {
        fseek($handle, 0, SEEK_END);
    }

    fwrite($handle, $line);
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
}

function build_recognition_log_event($requestId, $body, $result, $response)
{
    return array(
        'event_type' => 'recognition',
        'request_id' => $requestId,
        'created_at' => gmdate('c'),
        'source' => array_value($body, 'source', ''),
        'client_meta' => is_array(array_value($body, 'client_meta')) ? array_value($body, 'client_meta') : (is_array(array_value($body, 'clientMeta')) ? array_value($body, 'clientMeta') : array()),
        'status' => $result['status'],
        'batch_number' => $result['batch_number'],
        'production_date' => $result['production_date'],
        'expiry_date' => $result['expiry_date'],
        'candidates' => $result['candidates'],
        'confidence' => $result['confidence'],
        'trigger' => $result['trigger'],
        'ai_raw_visible_text' => $result['raw_visible_text'],
        'ai_reason' => $result['reason'],
        'elapsed_ms' => $result['elapsed_ms'],
        'total_elapsed_ms' => $response['meta']['total_elapsed_ms'],
        'ai_timeout_ms' => $result['ai_timeout_ms'],
        'image_info' => $result['image_info'],
        'provider' => $result['provider'],
        'provider_label' => $result['provider_label'],
        'model' => $result['model'],
        'thinking' => $result['thinking'],
        'response' => $response,
    );
}

function build_recognition_error_log_event($requestId, $body, $imageInfo, $error, $totalElapsedMs)
{
    global $provider, $providerLabel, $model;
    return array(
        'event_type' => 'recognition_error',
        'request_id' => $requestId,
        'created_at' => gmdate('c'),
        'source' => array_value($body, 'source', ''),
        'client_meta' => is_array(array_value($body, 'client_meta')) ? array_value($body, 'client_meta') : (is_array(array_value($body, 'clientMeta')) ? array_value($body, 'clientMeta') : array()),
        'status' => 'error',
        'error' => $error->getMessage(),
        'status_code' => $error instanceof HttpError ? $error->statusCode : 500,
        'elapsed_ms' => $error instanceof HttpError ? $error->elapsedMs : null,
        'total_elapsed_ms' => $totalElapsedMs,
        'ai_timeout_ms' => $GLOBALS['aiTimeoutMs'],
        'image_info' => $imageInfo,
        'provider' => $provider,
        'provider_label' => $providerLabel,
        'model' => $model,
        'thinking' => 'disabled',
    );
}

function build_feedback_log_event($body)
{
    $isModified = is_bool(array_value($body, 'is_modified'))
        ? array_value($body, 'is_modified')
        : strtolower((string) array_value($body, 'is_modified', '')) === 'true';
    return array(
        'event_type' => 'feedback',
        'request_id' => array_value($body, 'request_id', ''),
        'created_at' => gmdate('c'),
        'ai_batch_number' => array_value($body, 'ai_batch_number', ''),
        'confirmed_batch_number' => array_value($body, 'confirmed_batch_number', ''),
        'ai_production_date' => array_value($body, 'ai_production_date', ''),
        'confirmed_production_date' => array_value($body, 'confirmed_production_date', ''),
        'ai_expiry_date' => array_value($body, 'ai_expiry_date', ''),
        'confirmed_expiry_date' => array_value($body, 'confirmed_expiry_date', ''),
        'is_modified' => $isModified,
        'operator' => array_value($body, 'operator', ''),
        'note' => array_value($body, 'note', ''),
    );
}

function sample_mime_type($fileName)
{
    $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    if ($extension === 'png') {
        return 'image/png';
    }
    if ($extension === 'webp') {
        return 'image/webp';
    }
    return 'image/jpeg';
}

function handle_samples()
{
    global $sampleDir;
    $samples = array();
    if (is_dir($sampleDir)) {
        $files = scandir($sampleDir);
        if (is_array($files)) {
            $imageFiles = array_values(array_filter($files, function ($file) use ($sampleDir) {
                return is_file($sampleDir . '/' . $file) && preg_match('/\.(png|jpe?g|webp)$/i', $file);
            }));
            sort($imageFiles, SORT_NATURAL);
            foreach ($imageFiles as $index => $file) {
                $samples[] = array(
                    'id' => 'S' . str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT),
                    'fileName' => $file,
                    'url' => '/sample/' . rawurlencode($file),
                );
            }
        }
    }
    send_json(200, array('samples' => $samples), true);
}

function handle_sample_file($path)
{
    global $sampleDir;
    $prefix = '/sample/';
    $encoded = substr($path, strlen($prefix));
    $fileName = basename(rawurldecode($encoded));
    if ($fileName === '' || !preg_match('/\.(png|jpe?g|webp)$/i', $fileName)) {
        send_json(404, array('ok' => false, 'error' => 'Not found'), false);
        return;
    }
    send_file_response($sampleDir . '/' . $fileName, sample_mime_type($fileName));
}

function validate_feedback_body($body)
{
    if (!array_value($body, 'request_id')) {
        throw new HttpError('缺少 request_id。', 400);
    }
    if (!array_key_exists('confirmed_batch_number', $body) || !is_string($body['confirmed_batch_number'])) {
        throw new HttpError('缺少 confirmed_batch_number。', 400);
    }
}

function generate_request_id()
{
    if (function_exists('random_bytes')) {
        return bin2hex(random_bytes(16));
    }
    return str_replace('.', '', uniqid('', true));
}

function handle_health()
{
    global $apiKey, $provider, $providerLabel, $model, $aiTimeoutMs, $logRetentionDays;
    send_json(200, array(
        'ok' => true,
        'hasApiKey' => $apiKey !== '',
        'provider' => $provider,
        'providerLabel' => $providerLabel,
        'model' => $model,
        'thinking' => 'disabled',
        'aiTimeoutMs' => $aiTimeoutMs,
        'logRetentionDays' => $logRetentionDays,
        'logEnabled' => true,
        'runtime' => 'php',
        'phpVersion' => PHP_VERSION,
    ), true);
}

function handle_recognition()
{
    $requestStarted = now_ms();
    require_wms_api_token();
    $body = read_json_body();
    $requestId = array_value($body, 'request_id', generate_request_id());
    $imageDataUrl = normalize_api_image_data_url($body);
    $imageInfo = inspect_input_image($imageDataUrl, $body);

    try {
        $result = recognize_batch($imageDataUrl, $imageInfo);
    } catch (Exception $error) {
        $totalElapsedMs = now_ms() - $requestStarted;
        try {
            append_jsonl(build_recognition_error_log_event($requestId, $body, $imageInfo, $error, $totalElapsedMs));
        } catch (Exception $ignored) {
        }
        throw $error;
    }

    $totalElapsedMs = now_ms() - $requestStarted;
    $response = build_recognition_response($result, $requestId, $totalElapsedMs);
    append_jsonl(build_recognition_log_event($requestId, $body, $result, $response));
    send_json(200, $response, true);
}

function handle_feedback()
{
    require_wms_api_token();
    $body = read_json_body();
    validate_feedback_body($body);
    $event = build_feedback_log_event($body);
    append_jsonl($event);
    send_json(200, array('ok' => true, 'request_id' => $event['request_id']), true);
}

try {
    $method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
    $path = parse_url(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH);

    if ($method === 'OPTIONS' && strpos($path, '/api/wms/') === 0) {
        send_cors_headers();
        http_response_code(204);
        exit;
    }

    if ($method === 'GET' && $path === '/api/health') {
        handle_health();
        exit;
    }

    if ($method === 'GET' && $path === '/api/samples') {
        handle_samples();
        exit;
    }

    if ($method === 'GET' && strpos($path, '/sample/') === 0) {
        handle_sample_file($path);
        exit;
    }

    if ($method === 'GET' && ($path === '/' || $path === '/demo')) {
        send_file_response(__DIR__ . '/index.html', 'text/html; charset=utf-8');
        exit;
    }

    if ($method === 'POST' && $path === '/api/wms/batch-recognize') {
        handle_recognition();
        exit;
    }

    if ($method === 'POST' && $path === '/api/wms/batch-feedback') {
        handle_feedback();
        exit;
    }

    send_json(404, array('ok' => false, 'error' => 'Not found'), false);
} catch (Exception $error) {
    $statusCode = $error instanceof HttpError ? $error->statusCode : 500;
    $withCors = isset($path) && (strpos($path, '/api/wms/') === 0 || $path === '/api/health');
    send_json($statusCode, array('ok' => false, 'error' => $error->getMessage() ?: '服务异常'), $withCors);
}
