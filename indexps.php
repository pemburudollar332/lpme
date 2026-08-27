<?php
/**
 * Portable LP loader.
 *
 * Files in this directory:
 *   index.php  - renderer
 *   lp.html    - LP template
 *   brand.txt  - one record per line: keyword|url
 *
 * One valid line in brand.txt produces exactly one generated <a>.
 */

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$templateFile = __DIR__ . '/lp.html';
$brandFile = __DIR__ . '/brand.txt';
$lines = is_readable($brandFile)
    ? file($brandFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)
    : [];

$records = [];
$brandMap = [];

foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '') {
        continue;
    }

    $parts = array_map('trim', explode('|', $line, 2));
    $name = $parts[0] ?? '';
    $url = $parts[1] ?? '';

    if ($name === '') {
        continue;
    }

    $slug = strtolower($name);
    $slug = preg_replace('/[^a-z0-9]+/i', '-', $slug);
    $slug = trim($slug, '-');

    if ($slug === '') {
        continue;
    }

    if ($url === '') {
        $url = '?brand=' . rawurlencode($slug);
    }

    $isAbsolute = filter_var($url, FILTER_VALIDATE_URL)
        && in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true);
    $firstChar = $url[0] ?? '';
    $isRelative = in_array($firstChar, ['/', '?', '#'], true);

    if (!$isAbsolute && !$isRelative) {
        continue;
    }

    // Do not deduplicate records: each valid input line remains one anchor.
    $records[] = [
        'name' => $name,
        'slug' => $slug,
        'url' => $url,
    ];
    $brandMap[$slug] = $name;
}

if (!$records) {
    $records[] = [
        'name' => 'Brand Anda',
        'slug' => 'default',
        'url' => '?brand=default',
    ];
    $brandMap['default'] = 'Brand Anda';
}

$key = strtolower((string) ($_GET['brand'] ?? $records[0]['slug']));
$currentName = $brandMap[$key] ?? $records[0]['name'];
$currentSlug = $key;

if (!is_readable($templateFile)) {
    http_response_code(500);
    exit('File lp.html tidak ditemukan.');
}

$template = file_get_contents($templateFile);
if ($template === false) {
    http_response_code(500);
    exit('Template LP gagal dibaca.');
}

// Use LP_BASE_URL when configured; otherwise derive the current host.
$forwardedProto = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
$scheme = in_array($forwardedProto, ['http', 'https'], true)
    ? $forwardedProto
    : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$configuredBase = trim((string) getenv('LP_BASE_URL'));
$baseUrl = rtrim($configuredBase !== '' ? $configuredBase : ($scheme . '://' . $host), '/');
$lpPath = trim((string) getenv('LP_PATH'));
if ($lpPath === '') {
    $scriptName = str_replace('\\\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    $scriptDir = trim(str_replace('\\\\', '/', dirname($scriptName)), '/.');
    $lpPath = $scriptDir === '' ? '/' : '/' . $scriptDir . '/';
} else {
    $lpPath = '/' . trim($lpPath, '/') . '/';
}
$homeUrl = $baseUrl . ($lpPath === '//' ? '/' : $lpPath);
$variantUrl = $homeUrl . '?brand=' . rawurlencode($currentSlug);

$title = $currentName . ' | Link Alternatif Login & Daftar Resmi';
$description = 'Informasi resmi ' . $currentName . ', layanan, fitur, dan akses terbaru.';

// Personalize the user-owned static template.
$template = str_ireplace('PAWANGSLOT', e($currentName), $template);
$template = preg_replace('~<title>.*?</title>~is', '<title>' . e($title) . '</title>', $template, 1);
$template = preg_replace("~(<meta\\s+name=[\"']description[\"']\\s+content=[\"']).*?([\"'])~i", '$1' . e($description) . '$2', $template, 1);
$template = preg_replace("~(<meta\\s+property=[\"']og:title[\"']\\s+content=[\"']).*?([\"'])~i", '$1' . e($title) . '$2', $template, 1);
$template = preg_replace("~(<meta\\s+name=[\"']twitter:title[\"']\\s+content=[\"']).*?([\"'])~i", '$1' . e($title) . '$2', $template, 1);
$template = preg_replace("~(<link\\s+rel=[\"']canonical[\"']\\s+href=[\"']).*?([\"'])~i", '$1' . e($variantUrl) . '$2', $template, 1);

// Make links belonging to the original template use the installed domain.
$template = str_ireplace([
    'https://akaboukuramoto.com/',
    'https://grupocataratas.com/',
], $baseUrl . '/', $template);
$template = preg_replace('~https://rotalink\\.xyz/[^"\'\\s<)]+~i', e($variantUrl), $template);
$template = preg_replace("~(<meta\\s+property=[\"']og:url[\"']\\s+content=[\"']).*?([\"'])~i", '$1' . e($variantUrl) . '$2', $template, 1);
$template = preg_replace("~(<meta\\s+name=[\"']twitter:url[\"']\\s+content=[\"']).*?([\"'])~i", '$1' . e($variantUrl) . '$2', $template, 1);
$template = preg_replace("~(<meta\\s+property=[\"']og:description[\"']\\s+content=[\"']).*?([\"'])~i", '$1' . e($description) . '$2', $template, 1);
$template = preg_replace("~(<meta\\s+name=[\"']twitter:description[\"']\\s+content=[\"']).*?([\"'])~i", '$1' . e($description) . '$2', $template, 1);
$template = str_replace('"url": "' . $baseUrl . '/"', '"url": "' . $variantUrl . '"', $template);
$template = str_replace('"item": "' . $baseUrl . '/"', '"item": "' . $variantUrl . '"', $template);

// Advertise the authorized AMP document supplied for this LP.
$template = preg_replace('~\\s*<link\\s+rel=["\']amphtml["\'][^>]*>~i', '', $template, 1);
$ampUrl = 'https://slotdiataslagiyah.pages.dev/';
$ampTag = '<link rel="amphtml" href="' . e($ampUrl) . '">' . PHP_EOL;
$template = preg_replace('~</head>~i', $ampTag . '</head>', $template, 1);

// One generated anchor for each valid brand.txt record.
$brandLinks = '';
foreach ($records as $record) {
    $brandLinks .= '<a href="' . e($record['url']) . '" title="' . e($record['name']) . '">'
        . e($record['name'])
        . '</a>' . PHP_EOL;
}

$brandBlock = '<section aria-label="Daftar brand" style="max-width:1100px;margin:20px auto;padding:0 16px">'
    . '<h2>SLOT DANA GACOR</h2><div style="display:flex;flex-wrap:wrap;gap:10px">'
    . $brandLinks
    . '</div></section>';

$template = preg_replace('~(<body\\b[^>]*>)~i', '$1' . $brandBlock, $template, 1);

echo $template;
