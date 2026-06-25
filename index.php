<?php
header('Content-Type: text/plain; charset=utf-8');

// 4GTV 官方常量配置
$HOST = "api2.4gtv.tv";
$APP_VERSION = "1.5.4";
$ANDROID_UA = "Dalvik/2.1.0 (Linux; U; Android 13; Android TV Build/TP1A.220624.014)";
$WEBVIEW_UA = "Mozilla/5.0 (Linux; Android TV 13; XIAOMI 17 Pro Build/TP1A.220624.014; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/148.0.7778.120 Mobile Safari/537.36";
$APP_NAME = "四季線上電視版";
$APP_BUNDLE = "tv.fourgtv.video";

$SYSTEM_PROPS = [
    "key" => "bD5tN0VpW3pCjXhCIf9MhuuB2A39cCk5",
    "iv" => "CaIiNVDSAPKfraXs"
];

// 核心密码学与动态指纹算法
function generate_uuid() {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

function decrypt_header_key($cipher_b64, $key, $iv) {
    return openssl_decrypt(base64_decode($cipher_b64), 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
}

function fourgtv_auth($header_key) {
    return base64_encode(hash("sha512", gmdate("Ymd") . $header_key, true));
}

function tag_replace($media_url, $device_uuid, $adid, $webview_ua, $channel_name, $asset_id) {
    global $APP_NAME, $APP_BUNDLE;
    $values = [
        "appname" => [$APP_NAME, 4], "adid" => [$adid, 0], "is-lat" => ["0", 0],
        "user-agent" => [$webview_ua, 4], "useragent" => [$webview_ua, 4],
        "vtitle" => $channel_name, "assetid" => $asset_id,
        "deviceid" => $device_uuid, "timestamp" => (string)floor(microtime(true) * 1000),
        "app_bundle" => $APP_BUNDLE, "uid2" => "", "vkind" => "live",
        "vtype" => "", "referrer_url" => "https://www.4gtv.tv/", "description_url" => "https://www.4gtv.tv/",
    ];
    return preg_replace_callback('/\[(.*?)]/', function($matches) use ($values) {
        $tag = $matches[0];
        $key = strtolower(substr($tag, 1, -1));
        $key = preg_replace('/_e\d+$/', '', $key);
        if (!isset($values[$key])) return "";
        
        $val_data = $values[$key];
        $value = is_array($val_data) ? $val_data[0] : $val_data;
        $default_times = is_array($val_data) ? $val_data[1] : 0;
        
        $times = preg_match('/_e(\d+)/i', $tag, $m) ? (int)$m[1] : $default_times;
        for ($i = 0; $i < $times; $i++) { 
            $value = str_replace('+', '%20', urlencode((string)$value)); 
        }
        return $value;
    }, $media_url);
}

function request_api($method, $path, $body, $device_uuid, $header_key = null) {
    global $HOST, $APP_VERSION, $ANDROID_UA;
    $ch = curl_init("https://" . $HOST . $path);
    $headers = ["fsDEVICE: TV", "fsENC_KEY: " . $device_uuid, "fsVERSION: " . $APP_VERSION, "Content-Type: application/json", "Accept-Encoding: gzip", "Host: " . $HOST, "Connection: Keep-Alive", "User-Agent: " . $ANDROID_UA];
    if ($header_key) $headers[] = "4GTV_AUTH: " . fourgtv_auth($header_key);
    
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_ENCODING => "gzip",
        CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_3, // 强启 TLS 1.3 破盾
        CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false
    ]);
    if ($method === "POST") {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
    $res = curl_exec($ch); curl_close($ch);
    return json_decode($res, true);
}

// ---------------- 路由逻辑划分 ----------------
$device_uuid = generate_uuid();
$config = request_api("POST", "/App/GetAPPConfig", ["fsDEVICE" => "TV", "fsVERSION" => $APP_VERSION], $device_uuid);
if (!$config || !isset($config["Data"]["header_key"])) die("初始化官方参数失败，请检查网络或更换Koyeb区域。");
$header_key = decrypt_header_key($config["Data"]["header_key"], $SYSTEM_PROPS["key"], $SYSTEM_PROPS["iv"]);

if (isset($_GET['id']) && isset($_GET['pid'])) {
    // 【模式 1】当携带参数时：电视盒子发起换台请求，执行原生 TLS 1.3 解密直连
    $asset_id = $_GET['id'];
    $channel_id = (int)$_GET['pid'];
    
    $url_body = [
        "fnCHANNEL_ID" => $channel_id, "fsASSET_ID" => $asset_id, "fsDEVICE_TYPE" => "tv",
        "clsAPP_IDENTITY_VALIDATE_ARUS" => ["fsVALUE" => "", "fsENC_KEY" => $device_uuid]
    ];
    $url_payload = request_api("POST", "/TV/GetChannelUrl", $url_body, $device_uuid, $header_key);
    
    if (!empty($url_payload['Success']) && !empty($url_payload["Data"]["flstURLs"])) {
        $raw_url = $url_payload["Data"]["flstURLs"][0]; // 取第一条主线路
        $final_url = tag_replace($raw_url, $device_uuid, generate_uuid(), $WEBVIEW_UA, "LiveChannel", $asset_id);
        
        // 关键性能优化：使用 302 重定向将流量引给官方 CDN，Koyeb 不费任何流量带宽，秒开流媒体
        header("Location: " . $final_url);
        exit;
    } else {
        http_response_code(404);
        echo "无法实时解析该频道直链";
    }
} else {
    // 【模式 2】当直接访问链接时：输出完美兼容 TVBox / DIYP 的 TXT 频道列表格式
    $channels_response = request_api("GET", "/Channel/GetAllChannel2/TV", null, $device_uuid, $header_key);
    $channels = $channels_response["Data"] ?? [];
    
    // 动态获取当前脚本在 Koyeb 上的公网 URL
    $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
    $current_url = $scheme . "://" . $_SERVER['HTTP_HOST'] . $_SERVER['SCRIPT_NAME'];
    
    foreach ($channels as $ch) {
        // 过滤保留免费频道（如果有付费 Token 可以去掉此限制）
        if ($ch["fcFREE"] === "Y") {
            echo "{$ch['fsNAME']},{$current_url}?id={$ch['fs4GTV_ID']}&pid={$ch['fnID']}\n";
        }
    }
}
?>