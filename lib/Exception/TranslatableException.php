<?php

declare(strict_types=1);

namespace OCA\NoticeBoard\Exception;

use OCP\IL10N;

/**
 * Mesajı kullanıcıya gösterilen istisnaların tabanı.
 *
 * NEDEN: servis katmanı kullanıcının dilini bilmez, ama mesajı arayüzde
 * (oluşturma ekranı, yorumlar) doğrudan gösteriliyor. Eskiden mesaj hazır
 * Türkçe metin olarak taşınıyordu; İngilizce kullanıcı "Başlık boş olamaz."
 * görüyordu. Artık istisna ÇEVRİLMEMİŞ şablonu + parametreleri taşır, dili
 * bilen controller `translate()` ile çevirir.
 *
 * ⚠️ Şablon, kodda DÜZ METİN olarak yazılmalı (`new XException('...')`):
 * `build-l10n.py` onu oradan toplar. Birleştirerek (`'En fazla ' . $n`)
 * yazılan şablon çeviri sözlüğüne hiç girmez — `%s` parametresi kullan.
 */
abstract class TranslatableException extends \RuntimeException {

    /**
     * @param string $template Türkçe kaynak metin; yer tutucular `%s`
     * @param array<int, string|int> $parameters
     */
    public function __construct(
        private string $template,
        private array $parameters = [],
    ) {
        // getMessage() okunur kalır (günlük ve testler için) — Türkçe kaynak dil.
        parent::__construct(vsprintf($template, $parameters));
    }

    public function getTemplate(): string {
        return $this->template;
    }

    /** @return array<int, string|int> */
    public function getParameters(): array {
        return $this->parameters;
    }

    /** Kullanıcının dilinde mesaj. */
    public function translate(IL10N $l): string {
        return $l->t($this->template, $this->parameters);
    }
}
