<?php
// SPDX-License-Identifier: GPL-2.0-or-later
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$dir = sys_get_temp_dir() . '/ogpslider-test-' . bin2hex(random_bytes(8));
mkdir($dir, 0700);
define('CACHE_DIR', $dir . '/');
define('PKWK_URI_ABSOLUTE', 1);
register_shutdown_function(function () use ($dir) {
    if (!is_dir($dir)) return;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) { $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname()); }
    rmdir($dir);
});
$script = 'https://example.com/';
$page_title = 'サンプルWiki';
$vars = ['page' => 'FrontPage'];
$whatsnew = 'RecentChanges';
$available = true;
$calls = [];
$pages = [];
$hidden = [];
$unreadable = [];
$generated = 0;
$rows = '';
function exist_plugin_convert($name) { return $GLOBALS['available']; }
function esc($s) { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function plugin_ogp_convert($url) {
    $GLOBALS['calls'][] = $url;
    if (strpos($url, '/missing') !== false) return false;
    $title = '日記/2026年/新しい記事 - サンプルWiki';
    return '<div class="ogp"><div class="ogp-img-box"><img class="ogp-img" src="https://example.com/sample.png" alt="' . esc($title) . '"></div><div class="ogp-title"><a href="' . esc($url) . '">' . esc($title) . '<span class="overlink"></span></a></div></div>';
}
function plugin_ogp_fallback_link($url) {
    return '<div class="ogp ogp-fallback"><div class="ogp-title"><a href="' . esc($url) . '">' . esc($url) . '<span class="overlink"></span></a></div></div>';
}
function put_lastmodified() { $GLOBALS['generated']++; file_put_contents(CACHE_DIR . 'recent.dat', $GLOBALS['rows']); }
function check_non_list($page) { return in_array($page, $GLOBALS['hidden'], true); }
function is_page($page) { return in_array($page, $GLOBALS['pages'], true); }
function is_page_readable($page) { return !in_array($page, $GLOBALS['unreadable'], true); }
function get_page_uri($page, $kind) { return 'https://example.com/?' . rawurlencode($page); }
require dirname(__DIR__) . '/ogpslider.inc.php';
$count = 0;
function check($yes, $label) {
    if (!$yes) throw new RuntimeException('FAIL: ' . $label);
    $GLOBALS['count']++;
}
$urls = array_map(fn($n) => 'https://example.com/article' . $n, range(1, 22));
$html = plugin_ogpslider_convert('max=2', $urls[0], $urls[0], $urls[1], $urls[2]);
check($calls === array_slice($urls, 0, 2), 'deduplicate, order, no fetch past max');
check(substr_count($html, '<li class="ogpslider-item') === 2, 'two cards');
check(strpos($html, '>新しい記事<span') !== false, 'shorten local title');
check(strpos($html, 'alt="新しい記事"') !== false, 'shorten image alt');
$calls = []; plugin_ogpslider_convert(...$urls); check(count($calls) === 6, 'default max');
$calls = []; plugin_ogpslider_convert('max=20', ...$urls); check(count($calls) === 20, 'maximum count');
foreach ([[], ['max=0', $urls[0]], ['max=21', $urls[0]], ['max=2', 'max=3', $urls[0]], ['max=1.5', $urls[0]], ['javascript:alert(1)'], ['file:///tmp/example'], ['https://user:pass@example.com/'], ["https://example.com/\n" . 'bad'], ['https://example.com/"bad'], ['recent', $urls[0]], ['recent','recent'], ['prefix=日記/'], ['recent','prefix=日記/','prefix=別/'], ['recent',"prefix=日記/\t不正"]] as $args) {
    $calls = []; $html = plugin_ogpslider_convert(...$args);
    check(strpos($html, '<li') === false && !$calls, 'invalid arguments rejected before OGP');
}
$available = false;
check(strpos(plugin_ogpslider_convert($urls[0]), 'v3.0以降') !== false, 'missing dependency message');
$available = true;
$html = plugin_ogpslider_convert('https://example.com/missing?a=1&b=2');
check(strpos($html, 'ogpslider-noimage') !== false, 'fallback without image');
check(strpos($html, '?a=1&amp;b=2') !== false, 'escaped fallback URL');
$original = plugin_ogp_convert($urls[0]);
check(plugin_ogpslider_card_title($original, 'https://example.org/') === $original, 'external title untouched');
check(strpos(plugin_ogpslider_card_title($original, 'https://www.example.com/'), '>新しい記事<span') !== false, 'www host');
$attack = '<div class="ogp-title"><a>日記/&lt;img onerror=alert(1)&gt; - サンプルWiki<span></span></a></div>';
check(strpos(plugin_ogpslider_card_title($attack, $urls[0]), '<img onerror') === false, 'shortened title remains escaped');
check(strpos($original, '日記/2026年/') !== false, 'source card not changed');
$pages = ['FrontPage','RecentChanges','MenuBar','日記/非公開','日記/記事A','日記/記事B','日記/記事C','その他/記事'];
$hidden = ['MenuBar']; $unreadable = ['日記/非公開'];
$rows = "bad\nwrong\t日記/記事A\n120\tFrontPage\n119\tRecentChanges\n118\tMenuBar\n117\t日記/非公開\n116\t日記/削除済み\n115\tその他/記事\n114\t日記/記事A\n113\t日記/記事A\n112\t日記/記事B\n111\t日記/記事C\n";
check(plugin_ogpslider_recent_urls(2, '日記/') === [get_page_uri('日記/記事A', 1), get_page_uri('日記/記事B', 1)], 'recent order, prefix, dedup, exclusions and limit');
check($generated === 1, 'missing recent cache generated');
check(plugin_ogpslider_recent_urls(1, '') === [get_page_uri('その他/記事', 1)], 'no prefix');
check($generated === 1, 'existing recent cache reused');
$unreadable = [];
check(plugin_ogpslider_recent_urls(1, '日記/') === [get_page_uri('日記/非公開', 1)], 'current read permission evaluated');
$unreadable = ['日記/非公開'];
check(strpos(plugin_ogpslider_convert('recent','prefix=存在しない/'), 'ogpslider-empty') !== false, 'empty recent');
$html = plugin_ogpslider_convert('recent','max=2','prefix=日記/');
check(substr_count($html, '<li class="ogpslider-item') === 2, 'recent renders cards');
foreach (['FrontPage','RecentChanges','MenuBar','日記/非公開','日記/削除済み'] as $page) check(strpos($html, 'href="' . esc(get_page_uri($page, 1)) . '"') === false, 'excluded page not rendered');
file_put_contents(CACHE_DIR . 'recent.dat', "121\t日記/記事C\n" . $rows);
check(plugin_ogpslider_recent_urls(1,'日記/') === [get_page_uri('日記/記事C', 1)], 'recent refreshed without stale HTML cache');
echo $count . " checks passed\n";
