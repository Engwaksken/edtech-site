<?php
declare(strict_types=1);

/*
 * Admin-written HTML for public pages.
 *
 * The HTML is parsed and rebuilt from an allowlist: only the tags below
 * survive, attributes are dropped except a safe href/title on links and
 * colspan/rowspan on table cells, and any other tag is reduced to its text.
 * Scripts, styles, frames, forms and event handlers never reach the page.
 */

const SITE_RICH_TAGS = [
    'p', 'br', 'hr', 'strong', 'b', 'em', 'i', 'u', 's', 'small', 'sub', 'sup', 'mark',
    'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'pre', 'code',
    'ul', 'ol', 'li', 'dl', 'dt', 'dd', 'a',
    'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'caption',
];

const SITE_RICH_DROP_WITH_CONTENT = [
    'script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet',
    'template', 'noscript', 'svg', 'math', 'form', 'input', 'button', 'select',
    'textarea', 'link', 'meta', 'base', 'title', 'head',
];

function site_rich_safe_href(string $href): ?string
{
    $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $href = preg_replace('/[\x00-\x20\x7F]+/u', '', $href) ?? '';

    if ($href === '') return null;
    if (preg_match('#\A(https?:|mailto:|tel:)#i', $href)) return $href;
    if (preg_match('#\A[a-z][a-z0-9+.\-]*:#i', $href)) return null; // javascript:, data:, vbscript: ...
    if (str_starts_with($href, '//') || str_starts_with($href, '\\')) return null;

    return $href; // relative path or #anchor
}

function site_rich_clean_children(DOMNode $from, DOMDocument $out, DOMNode $to): void
{
    foreach (iterator_to_array($from->childNodes) as $child) {
        if ($child instanceof DOMText) {
            $to->appendChild($out->createTextNode($child->nodeValue ?? ''));
            continue;
        }
        if (!$child instanceof DOMElement) {
            continue; // comments, processing instructions
        }

        $tag = strtolower($child->tagName);

        if (in_array($tag, SITE_RICH_DROP_WITH_CONTENT, true)) {
            continue;
        }
        if (!in_array($tag, SITE_RICH_TAGS, true)) {
            site_rich_clean_children($child, $out, $to); // keep the text, lose the tag
            continue;
        }

        $el = $out->createElement($tag);

        if ($tag === 'a') {
            $href = site_rich_safe_href($child->getAttribute('href'));
            if ($href !== null) {
                $el->setAttribute('href', $href);
                if (preg_match('#\Ahttps?://#i', $href)) {
                    $el->setAttribute('target', '_blank');
                    $el->setAttribute('rel', 'noopener noreferrer');
                }
            }
            $title = trim($child->getAttribute('title'));
            if ($title !== '') $el->setAttribute('title', mb_substr($title, 0, 200));
        } elseif ($tag === 'td' || $tag === 'th') {
            foreach (['colspan', 'rowspan'] as $attr) {
                $n = (int)$child->getAttribute($attr);
                if ($n > 1 && $n <= 50) $el->setAttribute($attr, (string)$n);
            }
        } elseif (in_array($tag, ['h2', 'h3', 'h4', 'h5', 'h6'], true)) {
            $id = $child->getAttribute('id');
            if (preg_match('/\A[a-z][a-z0-9_-]{0,60}\z/i', $id)) $el->setAttribute('id', $id); // in-page anchors
        }

        $to->appendChild($el);
        site_rich_clean_children($child, $out, $el);
    }
}

/**
 * Safe HTML from admin input. Text without any tags keeps its line breaks.
 * An <h1> is turned into <h2> because the page already has its own title.
 */
function site_rich_html(?string $html): string
{
    $html = trim((string)$html);
    if ($html === '') return '';

    if (!preg_match('/<[a-z!\/]/i', $html)) {
        $paragraphs = preg_split('/\R{2,}/u', $html) ?: [$html];
        return implode("\n", array_map(
            static fn(string $p): string => '<p>' . nl2br(htmlspecialchars(trim($p), ENT_QUOTES, 'UTF-8')) . '</p>',
            $paragraphs
        ));
    }

    $html = preg_replace('#<(/?)h1\b#i', '<$1h2', $html) ?? $html;

    $in = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $in->loadHTML('<?xml encoding="UTF-8"><div id="site-rich-root">' . $html . '</div>', LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    $root = $in->getElementById('site-rich-root');
    if ($root === null) {
        return '<p>' . nl2br(htmlspecialchars(strip_tags($html), ENT_QUOTES, 'UTF-8')) . '</p>';
    }

    $out = new DOMDocument('1.0', 'UTF-8');
    $wrap = $out->createElement('div');
    $out->appendChild($wrap);
    site_rich_clean_children($root, $out, $wrap);

    $result = '';
    foreach ($wrap->childNodes as $node) {
        $result .= $out->saveHTML($node);
    }
    return $result;
}
