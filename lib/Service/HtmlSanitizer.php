<?php

declare(strict_types=1);

namespace OCA\NoticeBoard\Service;

use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Zengin metin duyuru gövdesini güvenli HTML'e indirger.
 *
 * NEDEN VAR: duyuru gövdesi arayüzde `v-html` ile basılıyor. Temizlenmemiş HTML
 * yazabilen biri (yayıncı) bunu okuyan HERKESİN tarayıcısında script çalıştırabilir —
 * yani depolanmış XSS. Yayıncılar "güvenilir" sayılsa bile, hesabı ele geçirilen tek
 * bir yayıncı tüm kuruma ulaşır. Bu yüzden temizleme YAZMA anında, sunucuda yapılır;
 * arayüze asla temizlenmemiş içerik ulaşmaz.
 *
 * Yaklaşım: izin listesi (allowlist). Listede olmayan etiket ve öznitelik atılır.
 * Kara liste yaklaşımı (şunları engelle) kullanılmaz — her zaman bir tane unutulur.
 */
class HtmlSanitizer {

    /** İçeriği korunarak tutulan etiketler. */
    private const ALLOWED_TAGS = [
        'p', 'br', 'hr',
        'strong', 'b', 'em', 'i', 'u', 's',
        'ul', 'ol', 'li',
        'blockquote', 'pre', 'code',
        'h1', 'h2', 'h3', 'h4',
        'a', 'span',
        'img',
    ];

    /**
     * Etiket başına izin verilen öznitelikler.
     *
     * `img` için `src` BİLEREK yok: gövdedeki görsel yalnız bir dosya kimliği
     * taşır (`data-nb-file`), adresi arayüz üretir ve dosya FileController'dan
     * duyuru görünürlüğüyle verilir. `src` serbest olsaydı dış adresli görsel
     * okuyucunun IP'sini/okuma anını üçüncü tarafa sızdırırdı (izleme pikseli).
     * `data-nb-owner` da burada SİLİNİR; sahibi istemci değil InlineImages yazar.
     *
     * Görsel yerleşimi (27 Eki 2026) `style` ile DEĞİL, değeri sabit listeden
     * gelen iki öznitelikle taşınır: `data-nb-align` (left/center/right) ve
     * `data-nb-width` (10–100, 5'in katı, yüzde). Biçimi CSS verir
     * (src/richBody.css); serbest `style` açılsaydı `url()`/konumlandırma
     * hileleri yeniden kapı bulurdu.
     */
    private const ALLOWED_ATTRIBUTES = [
        'a' => ['href'],
        'span' => ['style'],
        'p' => ['style'],
        'img' => ['data-nb-file', 'data-nb-align', 'data-nb-width', 'alt'],
    ];

    /** Satır içi görselin dosya kimliği: yalnız rakam. */
    private const FILE_ID = '/^[1-9][0-9]{0,18}$/';

    /** Görsel yerleşimi — src/inlineImageTools.js IMAGE_ALIGNS ile AYNI. */
    private const IMAGE_ALIGNS = ['left', 'center', 'right'];

    /** Görsel genişliği (yüzde): 10, 15, … 100 — CSS her biri için kural taşır. */
    private const IMAGE_WIDTH = '/^(1[05]|[2-9][05]|100)$/';

    /** Görsel açıklamasının (alt) en fazla uzunluğu — MAX_IMAGE_ALT ile AYNI. */
    private const MAX_IMAGE_ALT = 200;

    /** href için izin verilen şemalar. `javascript:` ve `data:` bilerek dışarıda. */
    private const ALLOWED_SCHEMES = ['http', 'https', 'mailto'];

    /**
     * style özniteliğinde yalnız renk tanımlarına izin verilir; `expression()`,
     * `url()` gibi her şey elenir. Değer sadece #hex, rgb()/rgba() ya da düz
     * renk adı olabilir.
     */
    private const ALLOWED_STYLE_PROPS = ['color', 'background-color'];
    private const SAFE_COLOR = '/^(#[0-9a-f]{3,8}|rgba?\([0-9,.\s%]+\)|[a-z]+)$/i';

    public function sanitize(string $html): string {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        $doc = new DOMDocument();

        // İçerik parçası; <html>/<body> sarmalayıcısı istemiyoruz ama DOMDocument
        // UTF-8'i ancak bir meta ya da XML bildirimiyle doğru okuyor.
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML(
            '<?xml encoding="UTF-8"?><div id="nb-root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $doc->getElementById('nb-root');
        if ($root === null) {
            return '';
        }

        $this->cleanChildren($root);

        $result = '';
        foreach ($root->childNodes as $child) {
            $result .= $doc->saveHTML($child);
        }

        return trim($result);
    }

    /**
     * Metni HTML'den arındırır — bildirim ve e-posta özetleri için.
     */
    public function toPlainText(string $html): string {
        $text = strip_tags(preg_replace('/<(br|img|\/p|\/li|\/h[1-4])[^>]*>/i', ' ', $html) ?? $html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string)preg_replace('/\s+/u', ' ', $text));
    }

    private function cleanChildren(DOMNode $node): void {
        // Canlı NodeList üzerinde dolaşırken düğüm silmek atlamaya yol açar;
        // bu yüzden önce kopya alınır.
        $children = [];
        foreach ($node->childNodes as $child) {
            $children[] = $child;
        }

        foreach ($children as $child) {
            if ($child instanceof DOMElement) {
                $this->cleanElement($child);
            } elseif ($child->nodeType === XML_COMMENT_NODE) {
                // Yorumlar koşullu yorum hilelerine açık; tamamen atılır.
                $child->parentNode?->removeChild($child);
            }
        }
    }

    private function cleanElement(DOMElement $element): void {
        $tag = strtolower($element->tagName);

        if (!in_array($tag, self::ALLOWED_TAGS, true)) {
            // <script>/<style> içeriği METİN olarak bile kalmamalı; diğer
            // bilinmeyen etiketlerde ise içerik korunup etiket açılır (unwrap).
            if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed'], true)) {
                $element->parentNode?->removeChild($element);
                return;
            }

            $this->cleanChildren($element);
            $this->unwrap($element);
            return;
        }

        // Dosya kimliği olmayan görsel (dış `src`, `onerror` hilesi...) gösterilemez;
        // içeriği de olmadığı için tamamen atılır.
        if ($tag === 'img' && preg_match(self::FILE_ID, $element->getAttribute('data-nb-file')) !== 1) {
            $element->parentNode?->removeChild($element);
            return;
        }

        $allowedAttributes = self::ALLOWED_ATTRIBUTES[$tag] ?? [];

        $attributes = [];
        foreach ($element->attributes as $attribute) {
            $attributes[] = $attribute;
        }

        foreach ($attributes as $attribute) {
            /** @var DOMAttr $attribute */
            $name = strtolower($attribute->name);

            if (!in_array($name, $allowedAttributes, true)) {
                $element->removeAttribute($attribute->name);
                continue;
            }

            if ($name === 'href' && !$this->isSafeUrl($attribute->value)) {
                $element->removeAttribute($attribute->name);
                continue;
            }

            if ($name === 'style') {
                $safeStyle = $this->sanitizeStyle($attribute->value);
                if ($safeStyle === '') {
                    $element->removeAttribute($attribute->name);
                } else {
                    $element->setAttribute('style', $safeStyle);
                }
            }

            if ($tag === 'img' && $name !== 'data-nb-file') {
                $value = $this->sanitizeImageAttribute($name, $attribute->value);
                if ($value === null) {
                    $element->removeAttribute($attribute->name);
                } else {
                    $element->setAttribute($name, $value);
                }
            }
        }

        // Dış bağlantılar yeni sekmede ve opener sızdırmadan açılsın.
        if ($tag === 'a' && $element->hasAttribute('href')) {
            $element->setAttribute('target', '_blank');
            $element->setAttribute('rel', 'noopener noreferrer');
        }

        $this->cleanChildren($element);
    }

    /** Etiketi kaldırır, çocuklarını yerinde bırakır. */
    private function unwrap(DOMElement $element): void {
        $parent = $element->parentNode;
        if ($parent === null) {
            return;
        }

        while ($element->firstChild !== null) {
            $parent->insertBefore($element->firstChild, $element);
        }

        $parent->removeChild($element);
    }

    private function isSafeUrl(string $url): bool {
        $url = trim($url);

        // Göreli bağlantılar (aynı sunucu içi) serbest.
        if ($url === '' || str_starts_with($url, '/') || str_starts_with($url, '#')) {
            return $url !== '';
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (!is_string($scheme)) {
            return false;
        }

        return in_array(strtolower($scheme), self::ALLOWED_SCHEMES, true);
    }

    /**
     * Görsel yerleşim/açıklama değeri: geçerliyse temiz hali, değilse null (silinir).
     * Yerleşim ve genişlik yalnız sabit listeden; açıklama düz metin (çıktıda
     * DOMDocument kaçışlar), kontrol karakterleri boşluk olur, uzunluk sınırlı.
     */
    private function sanitizeImageAttribute(string $name, string $value): ?string {
        $value = trim($value);

        return match ($name) {
            'data-nb-align' => in_array($value, self::IMAGE_ALIGNS, true) ? $value : null,
            'data-nb-width' => preg_match(self::IMAGE_WIDTH, $value) === 1 ? $value : null,
            'alt' => $this->imageAlt($value),
            default => null,
        };
    }

    private function imageAlt(string $value): ?string {
        $value = trim((string)preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $value));
        if ($value === '') {
            return null;
        }

        return mb_substr($value, 0, self::MAX_IMAGE_ALT);
    }

    private function sanitizeStyle(string $style): string {
        $safe = [];

        foreach (explode(';', $style) as $declaration) {
            $parts = explode(':', $declaration, 2);
            if (count($parts) !== 2) {
                continue;
            }

            $property = strtolower(trim($parts[0]));
            $value = trim($parts[1]);

            if (!in_array($property, self::ALLOWED_STYLE_PROPS, true)) {
                continue;
            }

            if (preg_match(self::SAFE_COLOR, $value) !== 1) {
                continue;
            }

            $safe[] = "$property: $value";
        }

        return implode('; ', $safe);
    }
}
