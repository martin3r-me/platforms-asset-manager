<?php

namespace Platform\AssetManager\Services;

use Illuminate\Support\Facades\Log;
use Platform\AssetManager\Models\AssetBillingProfile;
use Platform\AssetManager\Models\AssetBillingRun;
use Throwable;

/**
 * Ermittelt den Stückpreis eines Abrechnungsprofils — bevorzugt aus dem Commerce-Artikelstamm,
 * sonst aus dem am Profil hinterlegten Rückfallwert.
 *
 * **Warum nicht einfach ein Preisfeld am Profil?** Weil der Preis dort eine zweite Wahrheit wäre.
 * Der Artikel („Mitarbeiter Servicepauschale", SKU `IT-5001`) ist bereits gepflegt, samt Erlöskonto;
 * ihn abzuschreiben hieße, bei der nächsten Preisanpassung zwei Stellen zu ändern und eine zu
 * vergessen.
 *
 * **Warum trotzdem ein Rückfallwert?** Weil Commerce ein Fremdmodul ist, das in einem Team fehlen
 * oder in dem die SKU verschwinden kann. Ohne Rückfall stünde der Rechnungslauf dann still — und
 * zwar an dem Tag im Monat, an dem er gebraucht wird. Der Bezug läuft deshalb bewusst über die SKU
 * als Zeichenkette und nicht über einen Fremdschlüssel (dasselbe Muster wie docs/adr/0015).
 */
class BillingUnitPriceResolver
{
    /**
     * Zur Laufzeit aufgelöst, nicht importiert: das Modul darf ohne Commerce lauffähig bleiben.
     */
    protected const COMMERCE_ARTICLE = '\Platform\Commerce\Models\CommerceArticle';

    /**
     * @return array{cents: int|null, source: string|null, label: string|null, number: string|null,
     *     booking_account: string|null, vat_percent: float|null, unit: string|null, note: string|null}
     */
    public function resolve(AssetBillingProfile $profile): array
    {
        if ($profile->commerce_sku) {
            $fromCommerce = $this->fromCommerce($profile);

            if ($fromCommerce !== null) {
                return $fromCommerce;
            }
        }

        if ($profile->fallback_unit_price_cents !== null) {
            return [
                'cents'           => (int) $profile->fallback_unit_price_cents,
                'source'          => AssetBillingRun::PRICE_SOURCE_FALLBACK,
                'label'           => $profile->name,
                // Ohne Artikel keine Artikelnummer: der Suchbegriff aus dem Profil waere hier eine
                // Nummer, zu der es nachweislich keinen Artikel gibt.
                'number'          => null,
                'booking_account' => null,
                'vat_percent'     => null,
                'unit'            => null,
                'note'            => $profile->commerce_sku
                    ? 'Artikel ' . $profile->commerce_sku . ' nicht gefunden — Rückfallpreis verwendet.'
                    : null,
            ];
        }

        return [
            'cents'           => null,
            'source'          => null,
            'label'           => null,
            'number'          => null,
            'booking_account' => null,
            'vat_percent'     => null,
            'unit'            => null,
            'note'            => 'Kein Preis ermittelbar: weder ein auffindbarer Commerce-Artikel noch ein Rückfallpreis.',
        ];
    }

    /**
     * @return array{cents: int, source: string, label: string|null, number: string|null,
     *     booking_account: string|null, vat_percent: float|null, unit: string|null, note: string|null}|null
     */
    protected function fromCommerce(AssetBillingProfile $profile): ?array
    {
        $model = self::COMMERCE_ARTICLE;

        if (! class_exists($model)) {
            return null;
        }

        try {
            $article = $model::query()
                ->where('team_id', $profile->team_id)
                ->where('sku', $profile->commerce_sku)
                ->first();
        } catch (Throwable $e) {
            // Ein Fehler im Fremdmodul darf den Rechnungslauf nicht mitreißen — er fällt auf den
            // Rückfallpreis zurück. Protokolliert wird trotzdem, sonst bleibt eine kaputte
            // Commerce-Anbindung unbemerkt, solange der Rückfallwert plausibel aussieht.
            Log::warning('[asset-manager] Commerce-Preis nicht lesbar', [
                'sku'   => $profile->commerce_sku,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $article || $article->price === null) {
            return null;
        }

        // Der Artikelpreis ist ein Nettopreis in Euro; easybill erwartet Integer-Cent. Gerundet wird
        // genau hier, einmal — nicht an der Schnittstelle, wo ein Rundungsfehler erst auf der
        // Rechnung des Kunden auffiele.
        $cents = (int) round(((float) $article->price) * 100);

        if ($cents <= 0) {
            return null;
        }

        return [
            'cents'  => $cents,
            'source' => AssetBillingRun::PRICE_SOURCE_COMMERCE,
            'label'  => $article->name ?: $profile->name,
            // Die Artikelnummer kommt aus dem **gefundenen Artikel**, nicht aus dem Profilfeld.
            // Beides sieht gleich aus, ist es aber nicht: das Profilfeld ist der Suchbegriff, die
            // hier zurückgegebene Nummer die des Treffers. Stünde auf dem Beleg der Suchbegriff,
            // wäre eine Rechnung mit einer Artikelnummer möglich, die es so nicht gibt.
            'number' => $article->sku ?: null,
            // Erlöskonto des Artikels — bewusst `effective_…`, das die Vererbung von der
            // Steuerkategorie einschließt. Das rohe `revenue_account` ist am Artikel oft leer,
            // obwohl über die Kategorie eines gilt; der Umsatz landete dann auf dem Sammelkonto.
            'booking_account' => $article->effective_revenue_account ?: ($article->revenue_account ?: null),
            // Steuersatz aus der Steuerkategorie des Artikels. Nur wenn dort einer hinterlegt ist —
            // sonst bleibt der Wert am Profil maßgeblich.
            'vat_percent' => $article->taxCategory?->default_rate !== null
                ? (float) $article->taxCategory->default_rate
                : null,
            // Mengeneinheit („Pauschale", „Stück") — steht in easybill neben der Menge.
            'unit' => $article->base_price_unit ?: null,
            'note' => null,
        ];
    }
}
