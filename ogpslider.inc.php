<?php
/**
 * PukiWiki OGP Slider v1.0.1
 * #ogpslider(max=6,URL1,URL2,...) / #ogpslider(recent,max=6,prefix=日記/)
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

if (!defined('PLUGIN_OGPSLIDER_DEFAULT_MAX')) define('PLUGIN_OGPSLIDER_DEFAULT_MAX', 6);
if (!defined('PLUGIN_OGPSLIDER_MAX')) define('PLUGIN_OGPSLIDER_MAX', 20);

function plugin_ogpslider_convert()
{
    $limit = max(1, (int) PLUGIN_OGPSLIDER_MAX);
    $max = min($limit, max(1, (int) PLUGIN_OGPSLIDER_DEFAULT_MAX));
    $has_max = false;
    $recent = false;
    $prefix = null;
    $urls = [];
    foreach (func_get_args() as $arg) {
        $arg = trim((string) $arg);
        if (preg_match('/^max=([0-9]+)$/D', $arg, $match)) {
            if ($has_max || (int) $match[1] < 1 || (float) $match[1] > $limit) {
                return '<p>#ogpslider: max は1〜' . $limit . 'の整数を1回だけ指定してください。</p>';
            }
            $has_max = true;
            $max = (int) $match[1];
            continue;
        }
        if ($arg === 'recent') {
            if ($recent) return '<p>#ogpslider: recent は1回だけ指定してください。</p>';
            $recent = true;
            continue;
        }
        if (strpos($arg, 'prefix=') === 0) {
            if ($prefix !== null || preg_match('/[\x00-\x1f\x7f]/', $arg)) {
                return '<p>#ogpslider: prefix はページ名の先頭部分を1回だけ指定してください。</p>';
            }
            $prefix = substr($arg, 7);
            continue;
        }
        $parts = parse_url($arg);
        if ($parts === false || !isset($parts['host'], $parts['scheme']) ||
            !in_array(strtolower($parts['scheme']), ['http', 'https'], true) ||
            isset($parts['user']) || isset($parts['pass']) ||
            preg_match('/[\x00-\x20\x7f<>"\\\\]/', $arg) ||
            filter_var($arg, FILTER_VALIDATE_URL) === false) {
            return '<p>#ogpslider: http(s)のURLと max=件数をカンマで区切って指定してください。</p>';
        }
        $urls[$arg] = $arg;
    }
    if ($recent && $urls) {
        return '<p>#ogpslider: recent と個別URLは併用できません。</p>';
    }
    if (!$recent && $prefix !== null) {
        return '<p>#ogpslider: prefix は recent と一緒に指定してください。</p>';
    }
    if ($recent) {
        $urls = plugin_ogpslider_recent_urls($max, $prefix ?? '');
        if ($urls === null) return '<p>#ogpslider: 更新履歴のキャッシュを読み込めません。</p>';
        if (!$urls) return '<p class="ogpslider-empty">最近更新された対象ページはありません。</p>';
    } elseif (!$urls) {
        return '<p>#ogpslider(max=6,URL1,URL2,...) または #ogpslider(recent,max=6,prefix=日記/) の形式で指定してください。</p>';
    }
    if (!exist_plugin_convert('ogp') || !function_exists('plugin_ogp_convert') ||
        !function_exists('plugin_ogp_fallback_link')) {
        return '<p>#ogpslider: 対応する ogp.inc.php（v3.0以降）が必要です。</p>';
    }
    if (!is_dir(CACHE_DIR . 'ogp') && !@mkdir(CACHE_DIR . 'ogp', 0775, true) && !is_dir(CACHE_DIR . 'ogp')) {
        return '<p>#ogpslider: OGPキャッシュ用ディレクトリを作成できません。</p>';
    }

    $items = '';
    foreach (array_slice(array_values($urls), 0, $max) as $url) {
        $card = plugin_ogp_convert($url);
        if ($card === false || $card === '') {
            $card = plugin_ogp_fallback_link($url);
        }
        $card = plugin_ogpslider_card_title($card, $url);
        $noimage = strpos($card, '<img ') === false ? ' ogpslider-noimage' : '';
        $items .= '<li class="ogpslider-item' . $noimage . '">' . $card . '</li>';
    }
    $label = $recent ? '最近更新された記事' : '関連記事';
    // HTMLキャッシュに保存されても各リストが単独で表示・初期化できるようにする。
    return plugin_ogpslider_style() . "\n" .
        '<section class="ogpslider" aria-label="' . $label . '">' .
        '<div class="ogpslider-controls" hidden>' .
        '<button type="button" data-direction="-1" aria-label="前のカードへ">&#8592;</button>' .
        '<button type="button" data-direction="1" aria-label="次のカードへ">&#8594;</button>' .
        '</div>' .
        '<ul class="ogpslider-list" tabindex="0" aria-label="' . $label . 'の一覧（左右キーでスクロール）" role="list">' .
        $items . '</ul></section>' . plugin_ogpslider_script();
}

function plugin_ogpslider_recent_urls(int $max, string $prefix): ?array
{
    global $vars, $whatsnew;
    $file = CACHE_DIR . 'recent.dat';
    if (!is_file($file)) put_lastmodified();
    $fp = @fopen($file, 'rb');
    if ($fp === false) return null;
    if (!flock($fp, LOCK_SH)) {
        fclose($fp);
        return null;
    }
    $urls = [];
    try {
        while (($line = fgets($fp)) !== false) {
            $entry = explode("\t", rtrim($line, "\r\n"), 2);
            if (count($entry) !== 2 || !ctype_digit($entry[0]) || $entry[1] === '') continue;
            $page = $entry[1];
            if ($page === ($vars['page'] ?? '') || $page === $whatsnew ||
                ($prefix !== '' && strncmp($page, $prefix, strlen($prefix)) !== 0) ||
                check_non_list($page) || !is_page($page) || !is_page_readable($page)) continue;
            $url = get_page_uri($page, PKWK_URI_ABSOLUTE);
            $urls[$url] = $url;
            if (count($urls) >= $max) break;
        }
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
    return array_values($urls);
}

function plugin_ogpslider_card_title(string $card, string $url): string
{
    global $script, $page_title;
    $site_host = strtolower((string) parse_url((string) $script, PHP_URL_HOST));
    $url_host = strtolower((string) parse_url($url, PHP_URL_HOST));
    if ($site_host === '' || preg_replace('/^www\./', '', $site_host) !== preg_replace('/^www\./', '', $url_host) ||
        strpos($card, 'ogp-fallback') !== false) {
        return $card;
    }
    $original = null;
    $short = null;
    $card = preg_replace_callback(
        '/(<div class="ogp-title[^"\n]*"><a\b[^>]*>)([^<]*)(<span\b)/',
        function ($match) use ($page_title, &$original, &$short) {
            $title = html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            // サイト名の接尾辞を取り除いてからページ階層の末尾を選ぶ。
            $suffix = ' - ' . (string) $page_title;
            if ((string) $page_title !== '' && substr($title, -strlen($suffix)) === $suffix) {
                $title = substr($title, 0, -strlen($suffix));
            }
            $parts = explode('/', $title);
            $title = trim(end($parts));
            if ($title === '') return $match[0];
            $original = $match[2];
            $short = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            return $match[1] . $short . $match[3];
        },
        $card,
        1
    );
    if ($original !== null) {
        $card = str_replace('alt="' . $original . '"', 'alt="' . $short . '"', $card);
    }
    return $card;
}

function plugin_ogpslider_style(): string
{
    return <<<'HTML'
<style>
.ogpslider{--ogpslider-card-width:280px;position:relative;box-sizing:border-box;width:100%;min-width:0;max-width:100%;margin:1.5em 0;padding:0 8px;color:#222}
.ogpslider .ogpslider-controls{position:absolute;z-index:2;top:4px;right:10px;left:10px;display:flex;align-items:center;justify-content:space-between;height:calc(var(--ogpslider-card-width) * 9 / 16);pointer-events:none}
.ogpslider .ogpslider-controls[hidden]{display:none}
.ogpslider .ogpslider-controls button{display:grid;place-items:center;width:44px;height:44px;padding:0;border:1px solid #d8dddb;border-radius:50%;background:rgba(255,255,255,.96);box-shadow:0 2px 8px rgba(0,0,0,.16);color:#222;font:22px/1 sans-serif;cursor:pointer;opacity:1;pointer-events:auto;touch-action:manipulation;transition:opacity .15s ease,background-color .15s ease,border-color .15s ease}
.ogpslider .ogpslider-controls button:disabled{opacity:0;pointer-events:none;cursor:default}
.ogpslider .ogpslider-controls button:not(:disabled):hover{background:#f2f5f4;border-color:#888}
.ogpslider ul.ogpslider-list{display:flex;flex-wrap:nowrap;gap:20px;overflow-x:auto;overscroll-behavior-x:contain;scroll-snap-type:x proximity;list-style:none;width:100%;max-width:100%;box-sizing:border-box;margin:0;padding:4px 2px 14px;scrollbar-width:thin;scrollbar-color:#b7c3bf #f3f5f4}
.ogpslider .ogpslider-list>.ogpslider-item{box-sizing:border-box;flex:0 0 280px;min-width:0;max-width:86%;margin:0;padding:0;list-style:none;scroll-snap-align:start}
.ogpslider .ogpslider-item>.ogp{box-sizing:border-box;position:relative;display:block;float:none;width:100%;height:100%;min-height:0;max-width:none;max-height:none;margin:0;padding:0;border:0;border-radius:0;overflow:visible;box-shadow:none;word-break:normal;overflow-wrap:anywhere}
.ogpslider .ogpslider-item .ogp-img-box{float:none;width:100%;height:auto;aspect-ratio:16/9;margin:0 0 12px;overflow:hidden;border-radius:10px;background:#f0f3f2}
.ogpslider .ogpslider-item .ogp-img{display:block;width:100%;height:100%;max-width:100%;aspect-ratio:16/9;object-fit:cover;object-position:center;opacity:1}
.ogpslider .ogpslider-item picture{display:block;width:100%;height:100%}
.ogpslider .ogpslider-item .ogp-title{display:block;max-height:none;margin:0;padding:0;font-size:16px;font-weight:700;line-height:1.6;overflow:visible;-webkit-line-clamp:unset}
.ogpslider .ogpslider-item .ogp-title a{color:inherit;text-decoration:none}
.ogpslider .ogpslider-item .ogp-title a:hover{text-decoration:underline;text-underline-offset:3px}
.ogpslider .ogpslider-item .ogp-description,.ogpslider .ogpslider-item .ogp-url{display:none}
.ogpslider .ogpslider-item .overlink{position:absolute;inset:0;width:100%;height:100%}
.ogpslider .ogpslider-noimage>.ogp:before{content:"画像なし";display:flex;align-items:center;justify-content:center;aspect-ratio:16/9;margin-bottom:12px;border-radius:10px;background:linear-gradient(135deg,#f0f4f2,#e4ebe8);color:#65736d;font-size:14px;font-weight:400}
.ogpslider :focus-visible{outline:2px solid #087f69;outline-offset:2px}
.ogpslider .ogp:focus-within{outline:2px solid #087f69;outline-offset:2px;border-radius:10px}
@media (hover:hover) and (pointer:fine){.ogpslider .ogpslider-controls button:not(:disabled){opacity:0;pointer-events:none}.ogpslider:hover .ogpslider-controls button:not(:disabled),.ogpslider:focus-within .ogpslider-controls button:not(:disabled){opacity:1;pointer-events:auto}}
@media(max-width:600px){.ogpslider{--ogpslider-card-width:240px}.ogpslider ul.ogpslider-list{gap:14px}.ogpslider .ogpslider-list>.ogpslider-item{flex-basis:var(--ogpslider-card-width)}}
@media(prefers-reduced-motion:reduce){.ogpslider .ogpslider-controls button{transition:none}}
@media print{.ogpslider .ogpslider-controls{display:none}.ogpslider ul.ogpslider-list{flex-wrap:wrap;overflow:visible}.ogpslider .ogpslider-item{break-inside:avoid}}
</style>
HTML;
}

function plugin_ogpslider_script(): string
{
    return <<<'HTML'
<script>
(function () {
    'use strict';
    var root = document.currentScript.previousElementSibling;
    if (!root || !root.classList.contains('ogpslider')) return;
    var list = root.querySelector('.ogpslider-list');
    var controls = root.querySelector('.ogpslider-controls');
    var buttons = controls.querySelectorAll('button');
    var reduced = window.matchMedia('(prefers-reduced-motion: reduce)');
    function update() {
        var end = list.scrollWidth - list.clientWidth;
        controls.hidden = end <= 2;
        buttons[0].disabled = list.scrollLeft <= 2;
        buttons[1].disabled = list.scrollLeft >= end - 2;
    }
    function move(direction) {
        var items = list.children;
        var step = items.length > 1 ? items[1].offsetLeft - items[0].offsetLeft : list.clientWidth;
        list.scrollBy({left: direction * step, behavior: reduced.matches ? 'instant' : 'smooth'});
    }
    buttons.forEach(function (button) {
        button.addEventListener('click', function () { move(Number(button.dataset.direction)); });
    });
    list.addEventListener('keydown', function (event) {
        if (event.target !== list || event.altKey || event.ctrlKey || event.metaKey) return;
        if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
            event.preventDefault();
            move(event.key === 'ArrowLeft' ? -1 : 1);
        }
    });
    list.addEventListener('scroll', update, {passive: true});
    if (window.ResizeObserver) {
        var observer = new ResizeObserver(update);
        observer.observe(list);
    } else {
        window.addEventListener('resize', update);
    }
    // 画像の読み込み失敗時もリンクと画像枠を残す。
    list.querySelectorAll('img').forEach(function (img) {
        function failed() {
            img.closest('.ogpslider-item').classList.add('ogpslider-noimage');
            img.closest('.ogp-img-box').style.display = 'none';
        }
        img.addEventListener('error', failed);
        if (img.complete && img.naturalWidth === 0) failed();
    });
    update();
})();
</script>
HTML;
}
