<?php

declare(strict_types=1);

namespace OCA\NoticeBoard\Service;

use DOMDocument;
use DOMElement;
use OCA\NoticeBoard\Exception\ValidationException;

/**
 * Duyuru metnine gömülü görseller (20 Eki 2026, kullanıcı isteği).
 *
 * Gövdede görsel `<img data-nb-file="42" data-nb-owner="ali">` olarak durur:
 * adres YOK, yalnız dosya kimliği ve dosyanın kimin alanında olduğu. Dosya
 * FileController::image'dan, kapak ve eklerle aynı kuralla — dosya izni değil
 * duyuruyu görebilmek — verilir. Ayrı tablo yok: hangi görselin bu duyuruya ait
 * olduğunun kaydı gövdenin kendisidir.
 *
 * 🔐 Sahip KAYIT anında burada yazılır, istemciden alınmaz (HtmlSanitizer
 * `data-nb-owner`'ı siler). Aksi halde duyuruyu düzenleyebilen biri başkasının
 * özel bir dosyasının kimliğini gövdeye yazar, dosya tüm okuyuculara servis
 * edilirdi — kapak/ekte 7 Eki'de kapatılan açığın aynısı (UserFileService).
 */
class InlineImages {

    /** Tek duyuruda en fazla görsel; detay sayfası her biri için ayrı istek atar. */
    public const MAX_IMAGES = 50;

    public function __construct(
        private UserFileService $userFiles,
    ) {
    }

    /**
     * Temizlenmiş gövdedeki görsellere sahip yazar; yazılamayan görsel varsa reddeder.
     *
     * - Önceki gövdede zaten olan görsel önceki sahibini korur (yönetici yazım
     *   hatası düzeltince yazarın görseli yöneticinin alanında aranmasın).
     * - Yeni görsel, kaydedenin alanında bir görsel olmalı.
     *
     * @throws ValidationException
     */
    public function bind(string $html, string $previousHtml, string $actorUid, string $authorUid): string {
        if (stripos($html, '<img') === false) {
            return $html;
        }

        $known = $this->owners($previousHtml, $authorUid);
        [$doc, $root] = $this->load($html);

        $images = [];
        foreach ($root->getElementsByTagName('img') as $img) {
            $images[] = $img;
        }

        if (count($images) > self::MAX_IMAGES) {
            throw new ValidationException('Duyuru metnine en fazla %s görsel eklenebilir.', [self::MAX_IMAGES]);
        }

        foreach ($images as $img) {
            /** @var DOMElement $img */
            $fileId = (int)$img->getAttribute('data-nb-file');

            if (isset($known[$fileId])) {
                $owner = $known[$fileId];
            } elseif ($this->userFiles->isUserImage($actorUid, $fileId)) {
                $owner = $actorUid;
            } else {
                throw new ValidationException('Metindeki görsellerden biri dosyalarınızda bulunamadı ya da bir görsel değil.');
            }

            $img->setAttribute('data-nb-owner', $owner);
        }

        $result = '';
        foreach ($root->childNodes as $child) {
            $result .= $doc->saveHTML($child);
        }

        return trim($result);
    }

    /**
     * Kayıtlı gövdedeki görseller: dosya kimliği => sahibin kimliği.
     * Sahibi yazılmamış görsel yazarındır.
     *
     * @return array<int, string>
     */
    public function owners(string $html, string $authorUid): array {
        if (stripos($html, '<img') === false) {
            return [];
        }

        [, $root] = $this->load($html);

        $owners = [];
        foreach ($root->getElementsByTagName('img') as $img) {
            $fileId = (int)$img->getAttribute('data-nb-file');
            if ($fileId <= 0) {
                continue;
            }
            $owner = $img->getAttribute('data-nb-owner');
            $owners[$fileId] ??= $owner !== '' ? $owner : $authorUid;
        }

        return $owners;
    }

    /** @return array{0: DOMDocument, 1: DOMElement} */
    private function load(string $html): array {
        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        // HtmlSanitizer ile aynı yükleme: UTF-8 bildirimi + sarmalayıcı kök.
        $doc->loadHTML(
            '<?xml encoding="UTF-8"?><div id="nb-root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $doc->getElementById('nb-root') ?? $doc->createElement('div');

        return [$doc, $root];
    }
}
