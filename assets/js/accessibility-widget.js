/**
 * Accessibility widget
 * --------------------
 * Floating bottom-right toolbox for people with disabilities: text sizing,
 * spacing, readable/dyslexia fonts, contrast & colour modes, link/heading
 * highlighting, reading guide/mask, magnifier, read-aloud, big cursor,
 * focus indicator, motion/sound/image controls, page structure and presets.
 *
 * Loaded synchronously in <head> so saved page modes apply before first
 * paint; the UI itself is built once the DOM is ready. Settings persist in
 * localStorage. Open/close with the button or Alt+A.
 */
(function () {
    'use strict';

    if (window.__a11yWidget) {
        return;
    }
    window.__a11yWidget = true;

    var STORAGE_KEY = 'a11yw-settings-v1';
    var root = document.documentElement;
    var TEXT_SCALES = [1, 1.1, 1.2, 1.35, 1.5, 1.75, 2];

    var DEFAULTS = {
        textScale: 0,
        lineHeight: 0,
        letterSpacing: 0,
        font: '',
        alignLeft: false,
        magnifier: false,
        contrast: '',
        saturation: '',
        links: false,
        headings: false,
        tts: false,
        guide: false,
        mask: false,
        cursor: false,
        focus: false,
        noMotion: false,
        hideImages: false,
        mute: false,
        profile: ''
    };

    var PROFILES = [
        { id: 'seizure', label: 'Seizure safe', icon: 'fa-bolt', settings: { noMotion: true, saturation: 'low' } },
        { id: 'vision', label: 'Low vision', icon: 'fa-low-vision', settings: { textScale: 3, contrast: 'high', cursor: true, focus: true, links: true } },
        { id: 'adhd', label: 'ADHD friendly', icon: 'fa-brain', settings: { mask: true, noMotion: true, saturation: 'low' } },
        { id: 'cognitive', label: 'Cognitive', icon: 'fa-book-open-reader', settings: { font: 'readable', links: true, headings: true, guide: true, alignLeft: true } },
        { id: 'dyslexia', label: 'Dyslexia', icon: 'fa-font', settings: { font: 'dyslexia', lineHeight: 1, letterSpacing: 1, alignLeft: true } },
        { id: 'motor', label: 'Motor / keyboard', icon: 'fa-keyboard', settings: { cursor: true, focus: true, noMotion: true } }
    ];

    var SECTIONS = [
        {
            title: 'Content',
            tiles: [
                { key: 'lineHeight', kind: 'cycle', label: 'Line height', icon: 'fa-text-height', levels: ['Default', '1.6', '1.9', '2.2'] },
                { key: 'letterSpacing', kind: 'cycle', label: 'Letter spacing', icon: 'fa-text-width', levels: ['Default', 'Wide', 'Wider', 'Widest'] },
                { key: 'font', kind: 'choice', value: 'readable', label: 'Readable font', icon: 'fa-font' },
                { key: 'font', kind: 'choice', value: 'dyslexia', label: 'Dyslexia friendly', icon: 'fa-spell-check' },
                { key: 'alignLeft', kind: 'toggle', label: 'Align text left', icon: 'fa-align-left' },
                { key: 'magnifier', kind: 'toggle', label: 'Text magnifier', icon: 'fa-magnifying-glass-plus' }
            ]
        },
        {
            title: 'Colour & highlighting',
            tiles: [
                { key: 'contrast', kind: 'choice', value: 'dark', label: 'Dark contrast', icon: 'fa-moon' },
                { key: 'contrast', kind: 'choice', value: 'light', label: 'Light contrast', icon: 'fa-sun' },
                { key: 'contrast', kind: 'choice', value: 'high', label: 'High contrast', icon: 'fa-circle-half-stroke' },
                { key: 'contrast', kind: 'choice', value: 'invert', label: 'Invert colours', icon: 'fa-fill-drip' },
                { key: 'saturation', kind: 'choice', value: 'mono', label: 'Monochrome', icon: 'fa-droplet-slash' },
                { key: 'saturation', kind: 'choice', value: 'low', label: 'Low saturation', icon: 'fa-droplet' },
                { key: 'saturation', kind: 'choice', value: 'high', label: 'High saturation', icon: 'fa-palette' },
                { key: 'links', kind: 'toggle', label: 'Highlight links', icon: 'fa-link' },
                { key: 'headings', kind: 'toggle', label: 'Highlight headings', icon: 'fa-heading' }
            ]
        },
        {
            title: 'Reading & navigation',
            tiles: [
                { key: 'tts', kind: 'toggle', label: 'Read aloud', icon: 'fa-volume-high', requires: 'speech' },
                { key: 'guide', kind: 'toggle', label: 'Reading guide', icon: 'fa-ruler-horizontal' },
                { key: 'mask', kind: 'toggle', label: 'Reading mask', icon: 'fa-glasses' },
                { key: 'cursor', kind: 'toggle', label: 'Big cursor', icon: 'fa-arrow-pointer' },
                { key: 'focus', kind: 'toggle', label: 'Focus indicator', icon: 'fa-hand-pointer' },
                { key: 'noMotion', kind: 'toggle', label: 'Stop animations', icon: 'fa-pause' },
                { key: 'hideImages', kind: 'toggle', label: 'Hide images', icon: 'fa-eye-slash' },
                { key: 'mute', kind: 'toggle', label: 'Mute sounds', icon: 'fa-volume-xmark' },
                { key: 'structure', kind: 'action', label: 'Page structure', icon: 'fa-list' }
            ]
        }
    ];

    var CHOICE_LABELS = {
        font: { readable: 'Readable font', dyslexia: 'Dyslexia friendly font' },
        contrast: { dark: 'Dark contrast', light: 'Light contrast', high: 'High contrast', invert: 'Inverted colours' },
        saturation: { mono: 'Monochrome', low: 'Low saturation', high: 'High saturation' }
    };

    var FILTERS = {
        high: 'contrast(1.45)',
        invert: 'invert(1) hue-rotate(180deg)',
        mono: 'grayscale(1)',
        low: 'saturate(0.45)',
        high_sat: 'saturate(2)'
    };

    var scriptSrc = document.currentScript ? document.currentScript.src : '';
    var state = load();
    var speechSupported = 'speechSynthesis' in window && 'SpeechSynthesisUtterance' in window;
    var ui = {};

    // Apply page modes immediately (runs in <head>, before first paint).
    applyRootClasses();

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    /* ------------------------------------------------------------------ */
    /* State                                                              */
    /* ------------------------------------------------------------------ */

    function load() {
        var saved = {};
        try {
            saved = JSON.parse(window.localStorage.getItem(STORAGE_KEY) || '{}') || {};
        } catch (e) {
            saved = {};
        }
        var out = {};
        Object.keys(DEFAULTS).forEach(function (key) {
            out[key] = Object.prototype.hasOwnProperty.call(saved, key) && typeof saved[key] === typeof DEFAULTS[key]
                ? saved[key]
                : DEFAULTS[key];
        });
        out.textScale = clamp(out.textScale, 0, TEXT_SCALES.length - 1);
        out.lineHeight = clamp(out.lineHeight, 0, 3);
        out.letterSpacing = clamp(out.letterSpacing, 0, 3);
        return out;
    }

    function save() {
        try {
            window.localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
        } catch (e) { /* storage unavailable (private mode) */ }
    }

    function clamp(n, min, max) {
        n = parseInt(n, 10);
        if (isNaN(n)) {
            return min;
        }
        return Math.min(max, Math.max(min, n));
    }

    function isActive() {
        return Object.keys(DEFAULTS).some(function (key) {
            return key !== 'profile' && state[key] !== DEFAULTS[key];
        });
    }

    /* ------------------------------------------------------------------ */
    /* Page adjustments                                                   */
    /* ------------------------------------------------------------------ */

    function applyRootClasses() {
        var cls = {
            'a11yw-lh-1': state.lineHeight === 1,
            'a11yw-lh-2': state.lineHeight === 2,
            'a11yw-lh-3': state.lineHeight === 3,
            'a11yw-ls-1': state.letterSpacing === 1,
            'a11yw-ls-2': state.letterSpacing === 2,
            'a11yw-ls-3': state.letterSpacing === 3,
            'a11yw-font-readable': state.font === 'readable',
            'a11yw-font-dyslexia': state.font === 'dyslexia',
            'a11yw-align-left': state.alignLeft,
            'a11yw-dark': state.contrast === 'dark',
            'a11yw-light': state.contrast === 'light',
            'a11yw-invert': state.contrast === 'invert',
            'a11yw-links': state.links,
            'a11yw-headings': state.headings,
            'a11yw-tts': state.tts && speechSupported,
            'a11yw-cursor': state.cursor,
            'a11yw-focus': state.focus,
            'a11yw-no-motion': state.noMotion,
            'a11yw-hide-images': state.hideImages
        };
        Object.keys(cls).forEach(function (name) {
            root.classList.toggle(name, !!cls[name]);
        });

        var filters = [];
        if (state.contrast === 'high') { filters.push(FILTERS.high); }
        if (state.contrast === 'invert') { filters.push(FILTERS.invert); }
        if (state.saturation === 'mono') { filters.push(FILTERS.mono); }
        if (state.saturation === 'low') { filters.push(FILTERS.low); }
        if (state.saturation === 'high') { filters.push(FILTERS.high_sat); }

        if (filters.length) {
            root.style.setProperty('--a11yw-filter', filters.join(' '));
            root.classList.add('a11yw-filtered');
        } else {
            root.style.removeProperty('--a11yw-filter');
            root.classList.remove('a11yw-filtered');
        }
    }

    function applyAll() {
        applyRootClasses();
        applyTextScale();
        applyMedia();
        applyOverlays();
        if (!state.tts) {
            stopSpeaking();
        }
        syncUi();
        save();
    }

    function isWidgetNode(el) {
        return !!(el && el.closest && el.closest('[data-a11yw-ui]'));
    }

    /**
     * Scale text on every element that directly holds text (plus form
     * controls). Sites mix px/rem/em, so each element gets an explicit px
     * size computed from its original size; originals are restored first so
     * repeated runs never compound.
     */
    function applyTextScale() {
        var scale = TEXT_SCALES[state.textScale] || 1;

        // 1. Restore originals.
        var touched = document.querySelectorAll('[data-a11yw-fs]');
        for (var i = 0; i < touched.length; i++) {
            var parts = touched[i].getAttribute('data-a11yw-fs').split('|');
            touched[i].style.removeProperty('font-size');
            if (parts[0]) {
                touched[i].style.setProperty('font-size', parts[0], parts[1] || '');
            }
            touched[i].removeAttribute('data-a11yw-fs');
        }

        if (scale === 1 || !document.body) {
            return;
        }

        // 2. Collect targets.
        var targets = new Set();
        var skip = { SCRIPT: 1, STYLE: 1, NOSCRIPT: 1, TEMPLATE: 1, OPTION: 1, TITLE: 1 };
        var walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT, {
            acceptNode: function (node) {
                return node.nodeValue && node.nodeValue.trim() ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_REJECT;
            }
        });
        var node;
        while ((node = walker.nextNode())) {
            var parent = node.parentElement;
            if (parent && !skip[parent.tagName] && parent.namespaceURI === 'http://www.w3.org/1999/xhtml') {
                targets.add(parent);
            }
        }
        var controls = document.body.querySelectorAll('input, select, textarea, button');
        for (var c = 0; c < controls.length; c++) {
            targets.add(controls[c]);
        }

        // 3. Read all sizes, then write (avoids layout thrashing).
        var list = [];
        targets.forEach(function (el) {
            if (!isWidgetNode(el)) {
                list.push([el, parseFloat(window.getComputedStyle(el).fontSize) || 0]);
            }
        });
        list.forEach(function (item) {
            var el = item[0];
            if (!item[1]) {
                return;
            }
            el.setAttribute('data-a11yw-fs', el.style.getPropertyValue('font-size') + '|' + el.style.getPropertyPriority('font-size'));
            el.style.setProperty('font-size', (Math.round(item[1] * scale * 100) / 100) + 'px', 'important');
        });
    }

    function applyMedia() {
        var media = document.querySelectorAll('audio, video');
        for (var i = 0; i < media.length; i++) {
            var m = media[i];
            if (isWidgetNode(m)) {
                continue;
            }
            if (state.mute) {
                if (!m.muted) {
                    m.muted = true;
                    m.setAttribute('data-a11yw-muted', '1');
                }
            } else if (m.hasAttribute('data-a11yw-muted')) {
                m.muted = false;
                m.removeAttribute('data-a11yw-muted');
            }
            if (state.noMotion && !m.paused && m.tagName === 'VIDEO') {
                try { m.pause(); } catch (e) { /* ignore */ }
            }
        }
    }

    /* ------------------------------------------------------------------ */
    /* Overlays: reading guide, reading mask, magnifier                   */
    /* ------------------------------------------------------------------ */

    var pointerY = Math.round(window.innerHeight / 2);

    // large-screens.css may zoom <html>; pointer coordinates are in screen px
    // while overlay styles are in zoomed CSS px, so convert between the two.
    function screenZoom() {
        return parseFloat(window.getComputedStyle(document.documentElement).zoom) || 1;
    }
    var MASK_GAP = 70;

    function applyOverlays() {
        if (!ui.guide) {
            return;
        }
        ui.guide.hidden = !state.guide;
        ui.maskTop.hidden = !state.mask;
        ui.maskBottom.hidden = !state.mask;
        if (!state.magnifier) {
            ui.magnifier.hidden = true;
        }
        positionOverlays();
    }

    function positionOverlays() {
        if (state.guide) {
            ui.guide.style.top = pointerY + 'px';
        }
        if (state.mask) {
            ui.maskTop.style.height = Math.max(0, pointerY - MASK_GAP) + 'px';
            ui.maskBottom.style.top = (pointerY + MASK_GAP) + 'px';
        }
    }

    function onPointerMove(e) {
        if (state.guide || state.mask) {
            pointerY = e.clientY / screenZoom();
            window.requestAnimationFrame(positionOverlays);
        }
        if (state.magnifier) {
            showMagnifier(e);
        }
    }

    var MAGNIFY_SEL = 'p, li, h1, h2, h3, h4, h5, h6, a, button, label, td, th, dt, dd, blockquote, figcaption, span, strong, em, small, input, select, textarea';

    function showMagnifier(e) {
        var target = e.target && e.target.closest ? e.target.closest(MAGNIFY_SEL) : null;
        if (!target || isWidgetNode(target)) {
            ui.magnifier.hidden = true;
            return;
        }
        var text = readableText(target);
        if (!text) {
            ui.magnifier.hidden = true;
            return;
        }
        if (text.length > 280) {
            text = text.slice(0, 277) + '…';
        }
        if (ui.magnifier.textContent !== text) {
            ui.magnifier.textContent = text;
        }
        ui.magnifier.hidden = false;

        var z = screenZoom();
        var box = ui.magnifier.getBoundingClientRect();
        var boxW = box.width / z;
        var boxH = box.height / z;
        var viewW = window.innerWidth / z;
        var viewH = window.innerHeight / z;
        var x = e.clientX / z + 16;
        var y = e.clientY / z + 24;
        if (x + boxW > viewW - 12) {
            x = Math.max(12, viewW - boxW - 12);
        }
        if (y + boxH > viewH - 12) {
            y = Math.max(12, e.clientY / z - boxH - 16);
        }
        ui.magnifier.style.left = x + 'px';
        ui.magnifier.style.top = y + 'px';
    }

    function readableText(el) {
        if (!el) {
            return '';
        }
        if (el.matches('input, textarea')) {
            return (el.value || el.getAttribute('placeholder') || el.getAttribute('aria-label') || '').trim();
        }
        if (el.matches('select')) {
            var opt = el.options[el.selectedIndex];
            return opt ? opt.text.trim() : '';
        }
        var text = (el.innerText || el.textContent || '').replace(/\s+/g, ' ').trim();
        return text || (el.getAttribute('aria-label') || el.getAttribute('title') || el.getAttribute('alt') || '').trim();
    }

    /* ------------------------------------------------------------------ */
    /* Read aloud                                                         */
    /* ------------------------------------------------------------------ */

    var SPEAK_SEL = 'p, li, h1, h2, h3, h4, h5, h6, td, th, dt, dd, label, blockquote, figcaption, a, button, img, input, select, textarea, [role="button"], [aria-label]';
    var speakingEl = null;

    function speak(text, el) {
        if (!speechSupported || !text) {
            return;
        }
        stopSpeaking();
        var utter = new window.SpeechSynthesisUtterance(text.slice(0, 4000));
        utter.lang = root.getAttribute('lang') || 'en';
        utter.rate = 1;
        if (el) {
            speakingEl = el;
            el.classList.add('a11yw-speaking');
        }
        utter.onend = utter.onerror = function () {
            if (speakingEl) {
                speakingEl.classList.remove('a11yw-speaking');
                speakingEl = null;
            }
        };
        window.speechSynthesis.speak(utter);
    }

    function stopSpeaking() {
        if (speechSupported) {
            window.speechSynthesis.cancel();
        }
        if (speakingEl) {
            speakingEl.classList.remove('a11yw-speaking');
            speakingEl = null;
        }
    }

    function onDocumentClick(e) {
        if (!state.tts || isWidgetNode(e.target)) {
            return;
        }
        var selection = window.getSelection ? String(window.getSelection()).trim() : '';
        if (selection) {
            speak(selection, null);
            return;
        }
        var el = e.target.closest ? e.target.closest(SPEAK_SEL) : null;
        if (!el) {
            return;
        }
        var text = el.tagName === 'IMG' ? (el.getAttribute('alt') || '') : readableText(el);
        speak(text, el);
    }

    function onDocumentFocus(e) {
        if (!state.tts || isWidgetNode(e.target)) {
            return;
        }
        var el = e.target;
        var focusVisible = false;
        try { focusVisible = el.matches(':focus-visible'); } catch (err) { focusVisible = true; }
        if (focusVisible) {
            speak(readableText(el), el);
        }
    }

    /* ------------------------------------------------------------------ */
    /* Page structure                                                     */
    /* ------------------------------------------------------------------ */

    function buildStructure() {
        var list = ui.structureList;
        list.innerHTML = '';
        var headings = document.querySelectorAll('h1, h2, h3, h4, [role="heading"]');
        var count = 0;
        for (var i = 0; i < headings.length; i++) {
            var h = headings[i];
            if (isWidgetNode(h) || !(h.offsetWidth || h.offsetHeight || h.getClientRects().length)) {
                continue;
            }
            var text = readableText(h);
            if (!text) {
                continue;
            }
            var level = h.getAttribute('aria-level') || (h.tagName.charAt(0) === 'H' ? h.tagName.charAt(1) : '2');
            var li = document.createElement('li');
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.style.paddingLeft = (8 + (Math.min(4, parseInt(level, 10) || 2) - 1) * 12) + 'px';
            var tag = document.createElement('span');
            tag.className = 'a11yw-lvl';
            tag.textContent = 'H' + level;
            tag.setAttribute('aria-hidden', 'true');
            var label = document.createElement('span');
            label.textContent = text.length > 90 ? text.slice(0, 87) + '…' : text;
            btn.appendChild(tag);
            btn.appendChild(label);
            btn.setAttribute('aria-label', 'Heading level ' + level + ': ' + text);
            btn.addEventListener('click', goToHeading.bind(null, h));
            li.appendChild(btn);
            list.appendChild(li);
            count++;
        }
        if (!count) {
            var empty = document.createElement('li');
            empty.className = 'a11yw-empty';
            empty.textContent = 'No headings found on this page.';
            list.appendChild(empty);
        }
    }

    function goToHeading(h) {
        closePanel(false);
        if (!h.hasAttribute('tabindex')) {
            h.setAttribute('tabindex', '-1');
        }
        h.scrollIntoView({ behavior: state.noMotion ? 'auto' : 'smooth', block: 'start' });
        h.focus({ preventScroll: true });
    }

    /* ------------------------------------------------------------------ */
    /* UI                                                                 */
    /* ------------------------------------------------------------------ */

    function tileHtml(t) {
        if (t.requires === 'speech' && !speechSupported) {
            return '';
        }
        var attrs = ' data-key="' + t.key + '" data-kind="' + t.kind + '"' + (t.value ? ' data-value="' + t.value + '"' : '');
        var dots = '';
        if (t.kind === 'cycle') {
            dots = '<span class="a11yw-dots" aria-hidden="true">' + t.levels.slice(1).map(function () { return '<span></span>'; }).join('') + '</span>';
        }
        var pressed = t.kind === 'action'
            ? ' aria-expanded="false" aria-controls="a11yw-structure"'
            : ' aria-pressed="false"';
        return '<button type="button" class="a11yw-tile"' + attrs + pressed + '>' +
            '<i class="fa-solid ' + t.icon + '" aria-hidden="true"></i>' +
            '<span>' + t.label + '</span>' + dots +
            (t.kind === 'cycle' ? '<span class="a11yw-sr a11yw-state"></span>' : '') +
            '</button>';
    }

    function buildWidget() {
        var wrap = document.createElement('div');
        wrap.className = 'a11yw';
        wrap.setAttribute('data-a11yw-ui', '');

        var profiles = PROFILES.map(function (p) {
            return '<button type="button" class="a11yw-profile" data-profile="' + p.id + '" aria-pressed="false">' +
                '<i class="fa-solid ' + p.icon + '" aria-hidden="true"></i><span>' + p.label + '</span></button>';
        }).join('');

        var sections = SECTIONS.map(function (s, idx) {
            var extra = '';
            if (idx === 0) {
                extra = '<div class="a11yw-stepper" role="group" aria-labelledby="a11yw-text-size-label">' +
                    '<span class="a11yw-stepper-label" id="a11yw-text-size-label"><i class="fa-solid fa-text-height" aria-hidden="true"></i>Text size</span>' +
                    '<button type="button" class="a11yw-step-btn" data-step="-1" aria-label="Decrease text size"><i class="fa-solid fa-minus" aria-hidden="true"></i></button>' +
                    '<span class="a11yw-stepper-value" aria-live="polite">100%</span>' +
                    '<button type="button" class="a11yw-step-btn" data-step="1" aria-label="Increase text size"><i class="fa-solid fa-plus" aria-hidden="true"></i></button>' +
                    '</div>';
            }
            var structure = idx === SECTIONS.length - 1
                ? '<div class="a11yw-structure" id="a11yw-structure" hidden><ol aria-label="Headings on this page"></ol></div>'
                : '';
            return '<div class="a11yw-section" role="group" aria-labelledby="a11yw-sec-' + idx + '">' +
                '<h3 class="a11yw-section-title" id="a11yw-sec-' + idx + '">' + s.title + '</h3>' + extra +
                '<div class="a11yw-grid">' + s.tiles.map(tileHtml).join('') + '</div>' + structure +
                '</div>';
        }).join('');

        wrap.innerHTML =
            '<div class="a11yw-panel" id="a11yw-panel" role="dialog" aria-modal="false" aria-labelledby="a11yw-title" hidden>' +
                '<div class="a11yw-header">' +
                    '<span class="a11yw-header-icon" aria-hidden="true"><i class="fa-solid fa-universal-access"></i></span>' +
                    '<h2 class="a11yw-title" id="a11yw-title">Accessibility<span class="a11yw-subtitle">Adjust this site to your needs</span></h2>' +
                    '<button type="button" class="a11yw-icon-btn" data-action="close" aria-label="Close accessibility menu"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>' +
                '</div>' +
                '<div class="a11yw-body">' +
                    '<div class="a11yw-section" role="group" aria-labelledby="a11yw-sec-profiles">' +
                        '<h3 class="a11yw-section-title" id="a11yw-sec-profiles">Accessibility profiles</h3>' +
                        '<div class="a11yw-profiles">' + profiles + '</div>' +
                    '</div>' +
                    sections +
                '</div>' +
                '<div class="a11yw-footer">' +
                    '<button type="button" class="a11yw-reset" data-action="reset"><i class="fa-solid fa-rotate-left" aria-hidden="true"></i>Reset all settings</button>' +
                    '<span class="a11yw-hint">Shortcut: <kbd>Alt</kbd> + <kbd>A</kbd></span>' +
                '</div>' +
            '</div>' +
            '<button type="button" class="a11yw-toggle" aria-expanded="false" aria-controls="a11yw-panel" aria-label="Open accessibility menu" title="Accessibility (Alt + A)">' +
                '<i class="fa-solid fa-universal-access" aria-hidden="true"></i>' +
                '<span class="a11yw-toggle-badge" hidden></span>' +
            '</button>' +
            '<div class="a11yw-sr" role="status" aria-live="polite"></div>';

        document.body.appendChild(wrap);

        ui.wrap = wrap;
        ui.panel = wrap.querySelector('.a11yw-panel');
        ui.toggle = wrap.querySelector('.a11yw-toggle');
        ui.badge = wrap.querySelector('.a11yw-toggle-badge');
        ui.live = wrap.querySelector('[role="status"]');
        ui.stepValue = wrap.querySelector('.a11yw-stepper-value');
        ui.stepDown = wrap.querySelector('[data-step="-1"]');
        ui.stepUp = wrap.querySelector('[data-step="1"]');
        ui.structure = wrap.querySelector('.a11yw-structure');
        ui.structureList = ui.structure.querySelector('ol');
        ui.structureBtn = wrap.querySelector('[data-key="structure"]');

        ui.guide = overlay('a11yw-guide');
        ui.maskTop = overlay('a11yw-mask a11yw-mask--top');
        ui.maskBottom = overlay('a11yw-mask a11yw-mask--bottom');
        ui.magnifier = overlay('a11yw-magnifier');

        wrap.addEventListener('click', onWidgetClick);
        ui.toggle.addEventListener('click', function () {
            if (ui.panel.hidden) {
                openPanel();
            } else {
                closePanel(true);
            }
        });
    }

    function overlay(className) {
        var el = document.createElement('div');
        el.className = className;
        el.setAttribute('data-a11yw-ui', '');
        el.setAttribute('aria-hidden', 'true');
        el.hidden = true;
        document.body.appendChild(el);
        return el;
    }

    function onWidgetClick(e) {
        var btn = e.target.closest('button');
        if (!btn || !ui.wrap.contains(btn) || btn === ui.toggle) {
            return;
        }

        if (btn.dataset.action === 'close') {
            closePanel(true);
            return;
        }
        if (btn.dataset.action === 'reset') {
            resetAll();
            return;
        }
        if (btn.dataset.step) {
            var next = clamp(state.textScale + parseInt(btn.dataset.step, 10), 0, TEXT_SCALES.length - 1);
            if (next !== state.textScale) {
                state.textScale = next;
                state.profile = '';
                applyAll();
                announce('Text size ' + Math.round(TEXT_SCALES[next] * 100) + '%');
            }
            return;
        }
        if (btn.dataset.profile) {
            applyProfile(btn.dataset.profile);
            return;
        }

        var key = btn.dataset.key;
        var kind = btn.dataset.kind;
        if (!key) {
            return;
        }

        if (kind === 'action' && key === 'structure') {
            var show = ui.structure.hidden;
            if (show) {
                buildStructure();
            }
            ui.structure.hidden = !show;
            btn.setAttribute('aria-expanded', String(show));
            return;
        }

        var tile = findTile(key, btn.dataset.value);
        var message;
        if (kind === 'toggle') {
            state[key] = !state[key];
            message = tile.label + (state[key] ? ' on' : ' off');
        } else if (kind === 'cycle') {
            state[key] = (state[key] + 1) % tile.levels.length;
            message = tile.label + ': ' + tile.levels[state[key]];
        } else if (kind === 'choice') {
            var value = btn.dataset.value;
            state[key] = state[key] === value ? '' : value;
            message = CHOICE_LABELS[key][value] + (state[key] ? ' on' : ' off');
        }
        state.profile = '';
        applyAll();
        announce(message);
    }

    function findTile(key, value) {
        for (var s = 0; s < SECTIONS.length; s++) {
            for (var t = 0; t < SECTIONS[s].tiles.length; t++) {
                var tile = SECTIONS[s].tiles[t];
                if (tile.key === key && (!value || tile.value === value)) {
                    return tile;
                }
            }
        }
        return { label: key };
    }

    function applyProfile(id) {
        var profile = PROFILES.filter(function (p) { return p.id === id; })[0];
        if (!profile) {
            return;
        }
        var turningOff = state.profile === id;
        state = Object.assign({}, DEFAULTS);
        if (!turningOff) {
            Object.assign(state, profile.settings);
            state.profile = id;
        }
        applyAll();
        announce(profile.label + ' profile ' + (turningOff ? 'off' : 'on'));
    }

    function resetAll() {
        state = Object.assign({}, DEFAULTS);
        ui.structure.hidden = true;
        applyAll();
        announce('All accessibility settings reset');
    }

    function syncUi() {
        if (!ui.wrap) {
            return;
        }
        var tiles = ui.wrap.querySelectorAll('.a11yw-tile');
        for (var i = 0; i < tiles.length; i++) {
            var btn = tiles[i];
            var key = btn.dataset.key;
            var kind = btn.dataset.kind;
            if (kind === 'toggle') {
                btn.setAttribute('aria-pressed', String(!!state[key]));
            } else if (kind === 'choice') {
                btn.setAttribute('aria-pressed', String(state[key] === btn.dataset.value));
            } else if (kind === 'cycle') {
                var level = state[key];
                var tile = findTile(key);
                btn.setAttribute('aria-pressed', String(level > 0));
                btn.querySelector('.a11yw-state').textContent = ': ' + tile.levels[level];
                var dots = btn.querySelectorAll('.a11yw-dots span');
                for (var d = 0; d < dots.length; d++) {
                    dots[d].classList.toggle('is-on', d < level);
                }
            } else if (kind === 'action') {
                btn.setAttribute('aria-expanded', String(!ui.structure.hidden));
            }
        }

        var profiles = ui.wrap.querySelectorAll('.a11yw-profile');
        for (var p = 0; p < profiles.length; p++) {
            profiles[p].setAttribute('aria-pressed', String(state.profile === profiles[p].dataset.profile));
        }

        ui.stepValue.textContent = Math.round(TEXT_SCALES[state.textScale] * 100) + '%';
        ui.stepDown.disabled = state.textScale === 0;
        ui.stepUp.disabled = state.textScale === TEXT_SCALES.length - 1;
        ui.badge.hidden = !isActive();
        ui.toggle.setAttribute('aria-label', isActive()
            ? 'Open accessibility menu (adjustments active)'
            : 'Open accessibility menu');
    }

    function announce(message) {
        if (!ui.live || !message) {
            return;
        }
        ui.live.textContent = '';
        window.setTimeout(function () { ui.live.textContent = message; }, 40);
    }

    function openPanel() {
        ui.panel.hidden = false;
        ui.wrap.classList.add('is-open');
        ui.toggle.setAttribute('aria-expanded', 'true');
        var first = ui.panel.querySelector('.a11yw-profile');
        if (first) {
            first.focus({ preventScroll: true });
        }
    }

    function closePanel(returnFocus) {
        if (ui.panel.hidden) {
            return;
        }
        ui.panel.hidden = true;
        ui.wrap.classList.remove('is-open');
        ui.toggle.setAttribute('aria-expanded', 'false');
        if (returnFocus) {
            ui.toggle.focus({ preventScroll: true });
        }
    }

    function onKeyDown(e) {
        if (e.altKey && !e.ctrlKey && !e.metaKey && (e.code === 'KeyA' || String(e.key).toLowerCase() === 'a')) {
            e.preventDefault();
            if (ui.panel.hidden) {
                openPanel();
            } else {
                closePanel(true);
            }
            return;
        }
        if (e.key === 'Escape') {
            if (!ui.panel.hidden && ui.wrap.contains(document.activeElement)) {
                closePanel(true);
            } else if (state.tts) {
                stopSpeaking();
            }
        }
    }

    function onDocumentPointerDown(e) {
        if (!ui.panel.hidden && !ui.wrap.contains(e.target)) {
            closePanel(false);
        }
    }

    /* ------------------------------------------------------------------ */
    /* Dynamic content                                                    */
    /* ------------------------------------------------------------------ */

    var refreshTimer = null;

    function watchDom() {
        if (!('MutationObserver' in window)) {
            return;
        }
        var observer = new MutationObserver(function (mutations) {
            var relevant = mutations.some(function (m) {
                return m.addedNodes.length && !isWidgetNode(m.target) && !(m.target.hasAttribute && m.target.hasAttribute('data-a11yw-ui'));
            });
            if (!relevant || (state.textScale === 0 && !state.mute && !state.noMotion)) {
                return;
            }
            window.clearTimeout(refreshTimer);
            refreshTimer = window.setTimeout(function () {
                applyTextScale();
                applyMedia();
            }, 350);
        });
        observer.observe(document.body, { childList: true, subtree: true });
    }

    // The panel uses Font Awesome icons; load the bundled copy if the page doesn't.
    function ensureIcons() {
        if (!scriptSrc || document.querySelector('link[href*="fontawesome"], link[href*="font-awesome"]')) {
            return;
        }
        var link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = scriptSrc.replace(/\/assets\/js\/accessibility-widget\.js.*$/, '/assets/vendor/fontawesome/css/all.min.css');
        document.head.appendChild(link);
    }

    function init() {
        if (!document.body || document.querySelector('.a11yw')) {
            return;
        }
        ensureIcons();
        buildWidget();
        applyAll();
        watchDom();

        document.addEventListener('keydown', onKeyDown);
        document.addEventListener('pointerdown', onDocumentPointerDown, true);
        document.addEventListener('pointermove', onPointerMove, { passive: true });
        document.addEventListener('click', onDocumentClick, true);
        document.addEventListener('focusin', onDocumentFocus);
        window.addEventListener('scroll', function () {
            if (state.magnifier) {
                ui.magnifier.hidden = true;
            }
        }, { passive: true });
        document.addEventListener('pointerleave', function () {
            if (state.magnifier) {
                ui.magnifier.hidden = true;
            }
        });
        window.addEventListener('pagehide', stopSpeaking);
    }
}());
