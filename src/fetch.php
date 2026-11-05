<?php
// Download a remote file safely (SSRF-protected). Returns [row, error].

function fetch_remote_file($url, $code = null) {
    if (!function_exists('curl_init')) {
        return [null, 'The PHP curl extension is required for links'];
    }
    $url = trim($url);
    // Google Drive share link -> direct download
    if (preg_match('~^https?://drive\.google\.com/file/d/([\w-]+)~', $url, $m)) {
        $url = 'https://drive.google.com/uc?export=download&id=' . $m[1];
    }

    $tmp = tempnam(sys_get_temp_dir(), 'iprint');
    $max = cfg('max_size');
    $headers = [];

    for ($hop = 0; $hop <= 5; $hop++) {
        $p = parse_url($url);
        if (!$p || empty($p['host']) || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true)) {
            @unlink($tmp);
            return [null, 'Only http(s) links are supported'];
        }
        $scheme = strtolower($p['scheme']);
        $port = $p['port'] ?? ($scheme === 'https' ? 443 : 80);
        if (!in_array($port, [80, 443], true)) {
            @unlink($tmp);
            return [null, 'That port is not allowed'];
        }
        $ip = resolve_public_ip($p['host']);
        if (!$ip) {
            @unlink($tmp);
            return [null, 'That address is not reachable or not allowed'];
        }

        $headers = [];
        $fh = fopen($tmp, 'wb');
        $tooBig = false;
        $written = 0;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RESOLVE => [$p['host'] . ':' . $port . ':' . $ip], // pin DNS result
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 90,
            CURLOPT_USERAGENT => 'iPrint/1.0',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$headers) {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($c, $data) use ($fh, &$written, $max, &$tooBig) {
                $written += strlen($data);
                if ($written > $max) {
                    $tooBig = true;
                    return 0;
                }
                return fwrite($fh, $data);
            },
        ]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        fclose($fh);

        if ($tooBig) {
            @unlink($tmp);
            return [null, 'File is too large (max ' . human_size($max) . ')'];
        }
        if ($code >= 300 && $code < 400 && !empty($headers['location'])) {
            $url = absolute_url($url, $headers['location']);
            continue;
        }
        if ($code !== 200) {
            @unlink($tmp);
            return [null, 'Could not download the file (HTTP ' . $code . ')'];
        }
        break;
    }
    if ($hop > 5) {
        @unlink($tmp);
        return [null, 'Too many redirects'];
    }

    // Work out a file name
    $name = '';
    if (!empty($headers['content-disposition'])) {
        if (preg_match("/filename\*=UTF-8''([^;]+)/i", $headers['content-disposition'], $m)) {
            $name = rawurldecode($m[1]);
        } elseif (preg_match('/filename="?([^";]+)"?/i', $headers['content-disposition'], $m)) {
            $name = $m[1];
        }
    }
    if ($name === '') {
        $path = parse_url($url, PHP_URL_PATH) ?: '';
        $name = rawurldecode(basename($path));
    }
    if (file_ext($name) === '') {
        $map = [
            'application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif',
            'image/webp' => 'webp', 'text/plain' => 'txt',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
            'application/msword' => 'doc',
        ];
        $ct = strtolower(trim(explode(';', $headers['content-type'] ?? '')[0]));
        $name = ($name === '' ? 'download' : $name) . (isset($map[$ct]) ? '.' . $map[$ct] : '');
    }

    $r = store_file($tmp, $name, 'link', $url, false, $code);
    if ($r[0] === null) {
        @unlink($tmp);
    }
    return $r;
}

function absolute_url($base, $loc) {
    if (preg_match('~^https?://~i', $loc)) {
        return $loc;
    }
    $b = parse_url($base);
    $root = $b['scheme'] . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
    if (strpos($loc, '/') === 0) {
        return $root . $loc;
    }
    $dir = rtrim(dirname($b['path'] ?? '/'), '/');
    return $root . $dir . '/' . $loc;
}

function resolve_public_ip($host) {
    $host = trim($host, '[]');
    $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return null; // any private/reserved/IPv6 result -> refuse
        }
    }
    return $ips ? $ips[0] : null;
}
