<?php


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

$maindomain = $_SERVER['HTTP_HOST'];
$protocol = "https";
$url = $_SERVER["REQUEST_URI"];
$url = str_replace("/index.php/", "", $url);
//var_dump($_SERVER); die();
if ($_SERVER['HTTP_REALIP']) {
    $maindomain = $_SERVER['HTTP_REALIP'];
} 

if ($_SERVER['HTTP_REALPROTOCOL']) {
    $protocol = $_SERVER['HTTP_REALPROTOCOL'];
}

$url = $protocol."://" . $maindomain . "/" . $url;
$response = makeRequest($url);
//$rawResponseHeaders = $response["headers"];
//$responseBody = $response["body"];
//$responseInfo = $response["responseInfo"];


//A regex that indicates which server response headers should be stripped out of the proxified response.
$header_blacklist_pattern = "/^content-length|^Content-Length|^Transfer-Encoding|^Content-Encoding.*gzip/i";
//$header_blacklist_pattern = "/sss/i";

//cURL can make multiple requests internally (for example, if CURLOPT_FOLLOWLOCATION is enabled), and reports
//headers for every request it makes. Only proxy the last set of received response headers,
//corresponding to the final request made by cURL for any given call to makeRequest().
//$responseHeaderBlocks = array_filter(explode("\r\n\r\n", $rawResponseHeaders));
//$lastHeaderBlock = end($responseHeaderBlocks);
//$headerLines = explode("\r\n", $lastHeaderBlock);

//var_dump($headerLines);
//die();

//unset($browserRequestHeaders['accept-encoding']);
//
//foreach ($headerLines as $header) {
//    header($header, true);
//}

//foreach ($headerLines as $header) {
//    $header = trim($header);
//    if (!preg_match($header_blacklist_pattern, $header)) {
//        header($header, false);
//    }
//}


//$contentType = "";
//if (isset($responseInfo["content_type"])) $contentType = $responseInfo["content_type"];
//
//if (stripos($contentType, "text/html") !== false && stripos($contentType, "text/css") !== false) {
//    echo $responseBody;
//} else {
////    header("Content-Length: " . strlen($responseBody), true);
//    echo $responseBody;
//}


//Makes an HTTP request via cURL, using request data that was passed directly to this script.
function makeRequest($url)
{
    global $anonymize;
    global $maindomain;
    //Tell cURL to make the request using the brower's user-agent if there is one, or a fallback user-agent otherwise.
    $user_agent = $_SERVER["HTTP_USER_AGENT"];
    if (empty($user_agent)) {
        $user_agent = "Mozilla/5.0 (compatible; )";
    }
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_USERAGENT, $user_agent);

    //Get ready to proxy the browser's request headers...
    $browserRequestHeaders = getallheaders();

    $removedHeaders = array_map("strtolower", $browserRequestHeaders);

    curl_setopt($ch, CURLOPT_ENCODING, "");
    //Transform the associative array from getallheaders() into an
    //indexed array of header strings to be passed to cURL.
    $curlRequestHeaders = [];


    //Proxy any received GET/POST/PUT data.
    switch ($_SERVER["REQUEST_METHOD"]) {
        case "POST":
            $postData = file_get_contents("php://input");
            if ($postData) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
            } else {
                unset($browserRequestHeaders['content-type']);
                unset($browserRequestHeaders['Content-Type']);
                $postData = postFile();
                $curlRequestHeaders[] = "Content-Type: multipart/form-data; boundary=----WebKitFormBoundary26CIPAAFxqygqxTa";
                curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
            }

            break;
        case "PUT":
            curl_setopt($ch, CURLOPT_PUT, true);
            curl_setopt($ch, CURLOPT_INFILE, fopen("php://input", "r"));
            break;
    }

    unset($browserRequestHeaders['Host']);
    unset($browserRequestHeaders['host']);
    unset($browserRequestHeaders['Content-Length']);
    unset($browserRequestHeaders['content-length']);
    unset($browserRequestHeaders['Accept-Encoding']);
    unset($browserRequestHeaders['accept-encoding']);
    unset($browserRequestHeaders['Accept-Encoding']);


    foreach ($browserRequestHeaders as $name => $value) {
        $curlRequestHeaders[] = $name . ": " . $value;
    }

    if (in_array("origin", $removedHeaders)) {
        $urlParts = parse_url($url);
        $port = $urlParts["port"];
        unset($browserRequestHeaders['Origin']);
        unset($browserRequestHeaders['origin']);
        $curlRequestHeaders[] = "Origin: " . $urlParts["scheme"] . "://" . $urlParts["host"] . (empty($port) ? "" : ":" . $port);
    };

    $curlRequestHeaders[] = 'Host: ' . $maindomain;


    if (!$anonymize) {
        $curlRequestHeaders[] = "X-Forwarded-For: " . $_SERVER["REMOTE_ADDR"];
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, $curlRequestHeaders);

    //Other cURL options.
    curl_setopt($ch, CURLOPT_HEADER, true);
    //curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_ENCODING, "");

    //Set the request URL.
    curl_setopt($ch, CURLOPT_URL, $url);

    //Make the request.
    $response = curl_exec($ch);

    $responseInfo = curl_getinfo($ch);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);


    //Setting CURLOPT_HEADER to true above forces the response headers and body
    //to be output together--separate them.
    $responseHeaders = substr($response, 0, $headerSize);
    $responseBody = substr($response, $headerSize);


    $responseCode = ri($responseInfo['http_code'], 500);
    $redirectCount = ri($responseInfo['redirect_count'], 0);
    $requestHeaders = preg_split('/[\r\n]+/', ri($responseInfo['request_header'], ''));
    if ($responseCode === 0) {
        $responseCode = 404;
    }


    $finalRequestURL = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    if ($redirectCount > 0 && !empty($finalRequestURL)) {
        $finalRequestURLParts = parse_url($finalRequestURL);
        $effectiveURL = ri($finalRequestURLParts['scheme'], 'http') . '://' .
            ri($finalRequestURLParts['host']) . ri($finalRequestURLParts['path'], '');
    }

    curl_close($ch);

    $responseHeaders = splitResponseHeaders($responseHeaders);

    foreach ($responseHeaders as $header) {
        $headerParts = preg_split('/:\s+/', $header, 2);
        if (count($headerParts) !== 2) {
            throw new RuntimeException("Can not parse header \"$header\"");
        }

        $headerName = $headerParts[0];
        $loweredHeaderName = strtolower($headerName);

        $headerValue = $headerParts[1];
        $loweredHeaderValue = strtolower($headerValue);

        // Pass following headers to response
        if (in_array($loweredHeaderName,
            ['content-type', 'content-language', 'content-security', 'server'])) {
            header("$headerName: $headerValue");
        } elseif (strpos($loweredHeaderName, 'x-') === 0) {
            header("$headerName: $headerValue");
        } // Replace cookie domain and path
        elseif ($loweredHeaderName === 'set-cookie') {
            $newValue = preg_replace('/((?>domain)\s*=\s*)[^;\s]+/', '\1.' . $maindomain, $headerValue);
            $newValue = preg_replace('/\s*;?\s*path\s*=\s*[^;\s]+/', '', $newValue);
            header("$headerName: $newValue", false);
        } // Decode response body if gzip encoding is used
//        elseif ($loweredHeaderName === 'content-encoding' && $loweredHeaderValue === 'gzip') {
//            $responseBody = gzdecode($responseBody);
////            $responseBody = $responseBody;
//        }
    }

    http_response_code($responseCode);

    echo $responseBody;
//    return ["headers" => $responseHeaders, "body" => $responseBody, "responseInfo" => $responseInfo];
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
            // Reset the output array as there may by multiple response headers
            $results = [];
            continue;
        }

        $results[] = "$headerLine";
    }

    return $results;
}

function ri(&$variable, $default = null)
{
    if (isset($variable)) {
        return $variable;
    } else {
        return $default;
    }
}

//Helper function used to removes/unset keys from an associative array using case insensitive matching
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
    $eol = "\r\n"; //default line-break for mime type
    $BODY = ""; //init my curl body
    foreach ($_POST as $key => $value) {
        $BODY .= '------WebKitFormBoundary26CIPAAFxqygqxTa' . $eol; //start param header
        $BODY .= 'Content-Disposition: form-data; name="' . $key . '"' . $eol . $eol; // last Content with 2 $eol, in this case is only 1 content.
        $BODY .= $value . $eol;//param data in this case is a simple post data and 1 $eol for the end of the data
    }
    $BODY .= '------WebKitFormBoundary26CIPAAFxqygqxTa--'; // we close the param and the post width "--" and 2 $eol at the end of our boundary header.

    return $BODY;
}
