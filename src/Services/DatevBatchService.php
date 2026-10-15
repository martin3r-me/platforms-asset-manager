<?php

namespace Platform\AssetManager\Services;

use Illuminate\Support\Facades\DB;
use Platform\AssetManager\Models\AssetDatevBatch;
use Platform\AssetManager\Models\AssetInvoiceSnapshot;
use Platform\AssetManager\Support\DatevBookingText;
use Platform\AssetManager\Support\DatevExtfException;
use Platform\AssetManager\Support\DatevExtfWriter;

/**
 * DATEV-Stapel-Protokoll (Baustein 4, Discussion #26): erzeugt einen EXTF-Buchungsstapel aus den offenen
 * Monatsständen eines Buchungsmonats, sichert die Datei und sperrt die umgebuchten Monatsstände.
 *
 * Alles oder nichts, in einer Transaktion: Verstöße des Rechners ({@see DatevReallocationService}) oder
 * des Writers ({@see DatevExtfWriter}) erzeugen keinen Stapel und sperren nichts. Die Monatsstände werden
 * beim Sperren erneut gelesen und gesperrt (`lockForUpdate`) — zwei gleichzeitige Läufe können denselben
 * Stand nicht beide buchen.
 *
 * Ein zweiter Stapel desselben Monats nimmt nur, was noch offen ist (z. B. eine nachgereichte Rechnung),
 * und heißt „… Teil 2". Verwerfen gibt die Stände wieder frei — für einen Stapel, der nicht oder
 * fehlerhaft in DATEV importiert wurde. Ein bereits importierter Stapel muss in DATEV selbst storniert
 * werden; das kann dieser Dienst nicht wissen, deshalb ist der Grund Pflicht.
 */
class DatevBatchService
{
    public function __construct(protected DatevReallocationService $reallocation = new DatevReallocationService()) {}

    /**
     * @param array{consultant: string|int, client: string|int, fiscal_year_start: \DateTimeInterface,
     *              account_length: int, initials: string} $meta DATEV-Kopf des Mandanten
     * @param ?list<string> $knownCostCenters DATEV-KST-Liste; null = nicht prüfen
     *
     * @throws DatevExtfException bei Verstößen gegen Umbuchungsregeln oder Checkliste
     * @throws \RuntimeException  wenn es nichts umzubuchen gibt oder ein Stand inzwischen gebucht ist
     */
    public function create(
        int $teamId,
        int $tenantId,
        \DateTimeInterface $month,
        array $meta,
        ?int $userId,
        ?array $knownCostCenters = null,
        string $sourceCostCenter = '1520',
    ): AssetDatevBatch {
        $from = \DateTimeImmutable::createFromInterface($month)->modify('first day of this month')->setTime(0, 0);
        $to   = $from->modify('last day of this month');

        return DB::transaction(function () use ($teamId, $tenantId, $from, $to, $meta, $userId, $knownCostCenters, $sourceCostCenter) {
            $plan = $this->reallocation->prepare($teamId, $tenantId, $from, (string) $meta['client'], $knownCostCenters, $sourceCostCenter);

            if ($plan['violations'] !== []) {
                throw new DatevExtfException($plan['violations']);
            }
            if ($plan['bookings'] === []) {
                throw new \RuntimeException('Für ' . DatevBookingText::month($from) . ' gibt es keine offenen Monatsstände mit DATEV-Sachkonto — kein Stapel erzeugt.');
            }

            $ids    = array_column($plan['snapshots'], 'id');
            $locked = AssetInvoiceSnapshot::withoutTenantScope()
                ->whereIn('id', $ids)
                ->whereNull('datev_batch_id')
                ->lockForUpdate()
                ->pluck('id');
            if ($locked->count() !== count($ids)) {
                throw new \RuntimeException('Ein Monatsstand wurde inzwischen in einem anderen Stapel gebucht — bitte neu laden.');
            }

            $label = $this->label($teamId, $tenantId, $from);
            $bytes = DatevExtfWriter::fromTemplate()->write([
                'consultant'        => (string) $meta['consultant'],
                'client'            => (string) $meta['client'],
                'fiscal_year_start' => $meta['fiscal_year_start'],
                'account_length'    => (int) $meta['account_length'],
                'period_from'       => $from,
                'period_to'         => $to,
                'label'             => $label,
                'initials'          => (string) $meta['initials'],
                'created_at'        => new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin')),
            ], $plan['bookings']);

            $batch = AssetDatevBatch::withoutTenantScope()->create([
                'team_id'        => $teamId,
                'tenant_id'      => $tenantId,
                'booking_month'  => $from->format('Y-m-d'),
                'client'         => (string) $meta['client'],
                'consultant'     => (string) $meta['consultant'],
                'label'          => $label,
                'file_name'      => DatevExtfWriter::filename($label),
                'file_base64'    => base64_encode($bytes),
                'file_sha256'    => hash('sha256', $bytes),
                'bookings_count' => count($plan['bookings']),
                'total_cents'    => array_sum(array_map(fn ($b) => abs($b['amount_cents']), $plan['summary'])),
                'summary'        => [
                    'blocks'    => $plan['summary'],
                    'snapshots' => $plan['snapshots'],
                    'skipped'   => $plan['skipped'],
                    'findings'  => $plan['findings'],
                ],
                'user_id'        => $userId,
            ]);

            AssetInvoiceSnapshot::withoutTenantScope()->whereIn('id', $ids)->update(['datev_batch_id' => $batch->id]);

            return $batch;
        });
    }

    /** Stapel verwerfen und seine Monatsstände wieder freigeben. Die Datei bleibt erhalten. */
    public function void(AssetDatevBatch $batch, ?int $userId, string $reason): AssetDatevBatch
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new \InvalidArgumentException('Verwerfen braucht einen Grund (z. B. „Import in DATEV abgebrochen").');
        }

        return DB::transaction(function () use ($batch, $userId, $reason) {
            $fresh = AssetDatevBatch::withoutTenantScope()->lockForUpdate()->findOrFail($batch->id);
            if ($fresh->isVoided()) {
                throw new \RuntimeException("Stapel „{$fresh->label}“ ist bereits verworfen.");
            }

            $fresh->update(['voided_at' => now(), 'voided_by' => $userId, 'void_reason' => mb_substr($reason, 0, 255)]);
            AssetInvoiceSnapshot::withoutTenantScope()->where('datev_batch_id', $fresh->id)->update(['datev_batch_id' => null]);

            return $fresh;
        });
    }

    /**
     * „CTRL Umb IT Sep 2026", ab dem zweiten Stapel des Monats „… Teil n" — gezählt werden auch
     * verworfene, damit Bezeichnung und Dateiname nie doppelt vorkommen.
     */
    protected function label(int $teamId, int $tenantId, \DateTimeImmutable $month): string
    {
        $base  = 'CTRL Umb IT ' . mb_substr(DatevBookingText::month($month), 0, 3) . ' ' . $month->format('Y');
        $count = AssetDatevBatch::withoutTenantScope()
            ->where('team_id', $teamId)->where('tenant_id', $tenantId)
            ->whereDate('booking_month', $month->format('Y-m-d'))
            ->count();

        return $count === 0 ? $base : $base . ' Teil ' . ($count + 1);
    }
}
