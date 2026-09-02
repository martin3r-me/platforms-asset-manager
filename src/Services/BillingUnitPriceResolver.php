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
     * @return array{cents: int|null, source: string|null, label: string|null, booking_account: string|null, note: string|null}
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
                'booking_account' => null,
                'note'            => $profile->commerce_sku
                    ? 'Artikel ' . $profile->commerce_sku . ' nicht gefunden — Rückfallpreis verwendet.'
                    : null,
            ];
        }

        return [
            'cents'           => null,
            'source'          => null,
            'label'           => null,
            'booking_account' => null,
            'note'            => 'Kein Preis ermittelbar: weder ein auffindbarer Commerce-Artikel noch ein Rückfallpreis.',
        ];
    }

    /**
     * @return array{cents: int, source: string, label: string|null, booking_account: string|null, note: string|null}|null
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
            // Erlöskonto des Artikels. easybill erwartet es an der Position (`booking_account`) und
            // schreibt es in den DATEV-Export; ohne diesen Durchstich landet der Umsatz auf dem
            // Sammelkonto und muss in der Buchhaltung von Hand umgesetzt werden.
            'booking_account' => $article->revenue_account ?: null,
            'note'            => null,
        ];
    }
}
