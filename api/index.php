<?php

/**
 * PHP Proxy
 *
 * Cách dùng mới:
 * Client gửi request tới proxy (ví dụ: https://proxy.example.com/index-web.php)
 * kèm 2 header:
 *   real-url-request:  https://target-site.com/path?query=1
 *   real-domain-request: target-site.com
 *
 * Proxy sẽ request tới real-url-request và set Host = real-domain-request
 */

//To enable CORS (cross-origin resource sharing) for proxied sites, set $forceCORS to true.
$forceCORS = false;

$disallowLocal = true;

$anonymize = true;

$startURL = "";

$landingExampleURL = "https://example.net";

/****************************** END CONFIGURATION ******************************/

ob_start("ob_gzhandler");

if (version_compare(PHP_VERSION, "5.4.7", "<")) {
    die(" requires PHP version 5.4.7 or later.");
}

$requiredExtensions = ["curl", "mbstring", "xml"];
foreach ($requiredExtensions as $requiredExtension) {
    if (!extension_loaded($requiredExtension)) {
        die(" requires PHP's \"" . $requiredExtension . "\" extension. Please install/enable it on your server and try again.");
    }
}


if (!function_exists('getallheaders')) {

    /**
     * Get all HTTP header key/values as an associative array for the current request.
     *
     * @return string[string] The HTTP header key/value pairs.
     */
    function getallheaders()
    {
        $headers = array();

        $copy_server = array(
            'CONTENT_TYPE' => 'Content-Type',
            'CONTENT_LENGTH' => 'Content-Length',
            'CONTENT_MD5' => 'Content-Md5',
        );

        foreach ($_SERVER as $key => $value) {
            if (substr($key, 0, 5) === 'HTTP_') {
                $key = substr($key, 5);
                if (!isset($copy_server[$key]) || !isset($_SERVER[$key])) {
                    $key = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', $key))));
                    $headers[$key] = $value;
                }
            } elseif (isset($copy_server[$key])) {
                $headers[$copy_server[$key]] = $value;
            }
        }

        if (!isset($headers['Authorization'])) {
            if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
                $headers['Authorization'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
            } elseif (isset($_SERVER['PHP_AUTH_USER'])) {
                $basic_pass = isset($_SERVER['PHP_AUTH_PW']) ? $_SERVER['PHP_AUTH_PW'] : '';
                $headers['Authorization'] = 'Basic ' . base64_encode($_SERVER['PHP_AUTH_USER'] . ':' . $basic_pass);
            } elseif (isset($_SERVER['PHP_AUTH_DIGEST'])) {
                $headers['Authorization'] = $_SERVER['PHP_AUTH_DIGEST'];
            }
        }

        return $headers;
    }

}

/**
 * Lấy header không phân biệt hoa thường
 */
function getHeaderValue($headers, $name)
{
    $nameLower = strtolower($name);
    foreach ($headers as $key => $value) {
        if (strtolower($key) === $nameLower) {
            return $value;
        }
    }
    return null;
}

// Lấy toàn bộ header từ client
$allHeaders = getallheaders();

// Lấy URL và domain từ header (theo yêu cầu mới)
$url = getHeaderValue($allHeaders, 'real-url-request');
$maindomain = getHeaderValue($allHeaders, 'real-domain-request');

if (empty($url) || empty($maindomain)) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'error' => true,
        'message' => 'Missing required headers: real-url-request and/or real-domain-request'
    ]);
    exit;
}

// Validate URL cơ bản
if (!filter_var($url, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'error' => true,
        'message' => 'Invalid real-url-request value'
    ]);
    exit;
}

// (Tùy chọn) Chặn request tới local nếu $disallowLocal = true
if ($disallowLocal) {
    $parsed = parse_url($url);
    $host = isset($parsed['host']) ? strtolower($parsed['host']) : '';
    if (
        $host === 'localhost' ||
        $host === '127.0.0.1' ||
        $host === '::1' ||
        preg_match('/^(10\.|172\.(1[6-9]|2[0-9]|3[01])\.|192\.168\.)/', $host)
    ) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'error' => true,
            'message' => 'Local/private addresses are not allowed'
        ]);
        exit;
    }
}

// Thực hiện proxy
makeRequest($url, $maindomain, $allHeaders);


/**
 * Makes an HTTP request via cURL, using request data that was passed directly to this script.
 */
function makeRequest($url, $maindomain, $browserRequestHeaders)
{
    global $anonymize;

    // Tell cURL to make the request using the browser's user-agent if there is one, or a fallback user-agent otherwise.
    $user_agent = isset($_SERVER["HTTP_USER_AGENT"]) ? $_SERVER["HTTP_USER_AGENT"] : "";
    if (empty($user_agent)) {
        $user_agent = "Mozilla/5.0 (compatible; )";
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_USERAGENT, $user_agent);

    // Normalize header keys to lowercase for easier removal
    $headersLower = [];
    foreach ($browserRequestHeaders as $name => $value) {
        $headersLower[strtolower($name)] = $value;
    }

    // Headers cần loại bỏ (không forward sang target)
    $headersToRemove = [
        'host',
        'content-length',
        'accept-encoding',
        'real-url-request',      // header custom của proxy
        'real-domain-request',   // header custom của proxy
        'connection',
        'keep-alive',
        'proxy-connection',
        'transfer-encoding',
    ];

    $curlRequestHeaders = [];

    // Proxy any received GET/POST/PUT data.
    switch ($_SERVER["REQUEST_METHOD"]) {
        case "POST":
            $postData = file_get_contents("php://input");
            if ($postData !== false && $postData !== '') {
                // Có raw body (JSON, XML, form-urlencoded, multipart thật...)
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
            } else {
                // Fallback: build multipart từ $_POST (chỉ text fields)
                unset($headersLower['content-type']);
                $postData = postFile();
                $curlRequestHeaders[] = "Content-Type: multipart/form-data; boundary=----WebKitFormBoundary26CIPAAFxqygqxTa";
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
            }
            break;

        case "PUT":
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
            $putData = file_get_contents("php://input");
            if ($putData !== false) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $putData);
            }
            break;

        case "PATCH":
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PATCH");
            $patchData = file_get_contents("php://input");
            if ($patchData !== false) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $patchData);
            }
            break;

        case "DELETE":
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
            break;
    }

    // Build header list để gửi sang target
    foreach ($browserRequestHeaders as $name => $value) {
        $lowerName = strtolower($name);
        if (in_array($lowerName, $headersToRemove, true)) {
            continue;
        }
        $curlRequestHeaders[] = $name . ": " . $value;
    }

    // Xử lý Origin nếu client gửi
    if (isset($headersLower['origin'])) {
        $urlParts = parse_url($url);
        $port = isset($urlParts["port"]) ? $urlParts["port"] : null;
        $origin = $urlParts["scheme"] . "://" . $urlParts["host"] . (empty($port) ? "" : ":" . $port);
        // Ghi đè Origin bằng origin của target
        $curlRequestHeaders[] = "Origin: " . $origin;
    }

    // Bắt buộc set Host theo real-domain-request
    $curlRequestHeaders[] = 'Host: ' . $maindomain;

    if (!$anonymize) {
        $curlRequestHeaders[] = "X-Forwarded-For: " . $_SERVER["REMOTE_ADDR"];
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, $curlRequestHeaders);

    // Other cURL options
    curl_setopt($ch, CURLOPT_HEADER, true);
    // curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // bật nếu muốn follow redirect
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_ENCODING, ""); // tự decompress

    // Set the request URL
    curl_setopt($ch, CURLOPT_URL, $url);

    // Make the request
    $response = curl_exec($ch);

    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);
        http_response_code(502);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'error' => true,
            'message' => 'cURL error: ' . $error
        ]);
        exit;
    }

    $responseInfo = curl_getinfo($ch);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);

    // Separate headers and body
    $responseHeaders = substr($response, 0, $headerSize);
    $responseBody = substr($response, $headerSize);

    $responseCode = ri($responseInfo['http_code'], 500);
    if ($responseCode === 0) {
        $responseCode = 404;
    }

    curl_close($ch);

    $responseHeaders = splitResponseHeaders($responseHeaders);

    foreach ($responseHeaders as $header) {
        $headerParts = preg_split('/:\s+/', $header, 2);
        if (count($headerParts) !== 2) {
            // Có thể là status line hoặc dòng rỗng → bỏ qua
            continue;
        }

        $headerName = $headerParts[0];
        $loweredHeaderName = strtolower($headerName);
        $headerValue = $headerParts[1];

        // Pass following headers to response
        if (in_array($loweredHeaderName, [
            'content-type',
            'content-language',
            'content-security',
            'server',
            'cache-control',
            'expires',
            'pragma',
            'etag',
            'last-modified',
        ], true)) {
            header("$headerName: $headerValue");
        } elseif (strpos($loweredHeaderName, 'x-') === 0) {
            header("$headerName: $headerValue");
        }
        // Replace cookie domain and path
        elseif ($loweredHeaderName === 'set-cookie') {
            // Thay domain cookie thành domain của proxy (hoặc để nguyên tùy nhu cầu)
            $newValue = preg_replace('/((?>domain)\s*=\s*)[^;\s]+/i', '\1.' . $maindomain, $headerValue);
            $newValue = preg_replace('/\s*;?\s*path\s*=\s*[^;\s]+/i', '', $newValue);
            header("$headerName: $newValue", false);
        }
    }

    http_response_code($responseCode);
    echo $responseBody;
}

function splitResponseHeaders($headerString)
{
    $results = [];
    $headerLines = preg_split('/[\r\n]+/', $headerString);
    foreach ($headerLines as $headerLine) {
        if (empty($headerLine)) {
            continue;
        }

        // Header contains HTTP version specification and path
        if (strpos($headerLine, 'HTTP/') === 0) {
            // Reset the output array as there may be multiple response headers
            $results = [];
            continue;
        }

        $results[] = $headerLine;
    }

    return $results;
}

function ri(&$variable, $default = null)
{
    if (isset($variable)) {
        return $variable;
    }
    return $default;
}

/**
 * Helper function used to remove/unset keys from an associative array using case insensitive matching
 */
function removeKeys(&$assoc, $keys2remove)
{
    $keys = array_keys($assoc);
    $map = [];
    $removedKeys = [];
    foreach ($keys as $key) {
        $map[strtolower($key)] = $key;
    }
    foreach ($keys2remove as $key) {
        $key = strtolower($key);
        if (isset($map[$key])) {
            unset($assoc[$map[$key]]);
            $removedKeys[] = $map[$key];
        }
    }
    return $removedKeys;
}

function postFile()
{
    $eol = "\r\n";
    $boundary = '----WebKitFormBoundary26CIPAAFxqygqxTa';
    $BODY = "";

    foreach ($_POST as $key => $value) {
        $BODY .= '--' . $boundary . $eol;
        $BODY .= 'Content-Disposition: form-data; name="' . $key . '"' . $eol . $eol;
        $BODY .= $value . $eol;
    }
    $BODY .= '--' . $boundary . '--' . $eol;

    return $BODY;
}
