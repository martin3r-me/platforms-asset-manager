<?php

namespace Platform\AssetManager\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Platform\AssetManager\Concerns\TenantScopable;

/**
 * Verlauf am Asset-Träger (#897) — nur die geldwirksamen Felder: Kostenstelle und
 * Abrechnungs-Ausnahme (ADR 0024). Muster wie {@see AssetDeviceEvent}.
 *
 * **Geschrieben wird aus den Model-Events des {@see AssetHolder}**, nicht an jeder Aufrufstelle:
 * Detailseite, Sammelaktionen, MCP-Tools, BillingExclusionService und Kostenstellen-Bulk schreiben
 * alle über `save()`. Ein Verlauf, den jeder Schreibweg selbst mitführen muss, hätte beim ersten
 * vergessenen Weg eine Lücke — genau die Stelle, nach der später gefragt wird.
 *
 * Der Urheber kommt aus {@see actingAs()} (MCP-Tools: der Tool-Kontext kennt den User, der Auth-Guard
 * nicht zwingend), sonst aus `Auth::id()` (UI), sonst null = System/Sync.
 */
class AssetHolderEvent extends Model
{
    use TenantScopable;

    public const TYPE_COST_CENTER     = 'cost_center_changed';
    public const TYPE_EXCLUDED        = 'billing_excluded';
    public const TYPE_REASON_CHANGED  = 'billing_reason_changed';
    public const TYPE_INCLUDED        = 'billing_included';

    /** Ereignisse, deren Werte Freitext (den Ausnahmegrund) tragen — Anonymisierung leert sie (ADR 0005). */
    public const BILLING_TYPES = [self::TYPE_EXCLUDED, self::TYPE_REASON_CHANGED, self::TYPE_INCLUDED];

    protected $table = 'asset_holder_events';

    protected $fillable = [
        'team_id',
        'tenant_id',
        'holder_id',
        'user_id',
        'event_type',
        'old_value',
        'new_value',
        'description',
    ];

    /** Gesetzter Urheber (actingAs) — `$actorSet` unterscheidet „bewusst null" von „nicht gesetzt". */
    protected static bool $actorSet = false;
    protected static ?int $actor = null;
    protected static bool $muted = false;
    protected static ?bool $tableExists = null;

    /**
     * Callback mit ausdrücklichem Urheber ausführen (MCP-Tools).
     *
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    public static function actingAs(?int $userId, callable $callback): mixed
    {
        $previous = [self::$actorSet, self::$actor];
        self::$actorSet = true;
        self::$actor    = $userId;

        try {
            return $callback();
        } finally {
            [self::$actorSet, self::$actor] = $previous;
        }
    }

    /**
     * Callback ohne Verlauf ausführen — nur für die Anonymisierung, die den Ausnahmegrund leert und
     * dabei keinen neuen Eintrag mit dem alten (personenbezogenen) Grund erzeugen darf.
     *
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withoutRecording(callable $callback): mixed
    {
        $previous    = self::$muted;
        self::$muted = true;

        try {
            return $callback();
        } finally {
            self::$muted = $previous;
        }
    }

    /**
     * Änderungen eines eben gespeicherten Trägers in den Verlauf schreiben.
     *
     * Aufgerufen aus `AssetHolder::booted()` (created/updated). Verlauf ist Beiwerk: ein Fehler darf
     * den auslösenden Schreibpfad nie scheitern lassen (Muster {@see AssetDeviceEvent::record()}).
     */
    public static function recordChanges(AssetHolder $holder, bool $created = false): void
    {
        if (self::$muted || ! self::tableReady()) {
            return;
        }

        try {
            foreach (self::diff($holder, $created) as $event) {
                static::query()->create($event + [
                    'team_id'   => $holder->team_id,
                    'tenant_id' => $holder->tenant_id,
                    'holder_id' => $holder->id,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('AssetManager: Träger-Ereignis nicht geschrieben', [
                'holder_id' => $holder->id,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    /**
     * Die zu schreibenden Ereignisse aus dem Vorher/Nachher eines Trägers.
     *
     * Läuft im `created`/`updated`-Hook: `getOriginal()` hält dort noch den alten Stand.
     *
     * @return list<array{event_type: string, old_value: ?string, new_value: ?string, description: string, user_id: ?int}>
     */
    public static function diff(AssetHolder $holder, bool $created = false): array
    {
        $old = fn (string $field) => $created ? null : $holder->getOriginal($field);
        $events = [];
        $actor  = self::currentActor();

        // --- Kostenstelle: Code ODER Fremdschlüssel geändert --------------------------------
        $oldCode = self::text($old('cost_center'));
        $newCode = self::text($holder->cost_center);
        $oldId   = $old('cost_center_id');
        $newId   = $holder->cost_center_id;

        if ($oldCode !== $newCode || (string) $oldId !== (string) $newId) {
            $events[] = [
                'event_type'  => self::TYPE_COST_CENTER,
                'old_value'   => $oldCode,
                'new_value'   => $newCode,
                'description' => 'Kostenstelle: ' . ($oldCode ?? '—') . ' → ' . ($newCode ?? '—'),
                'user_id'     => $actor,
            ];
        }

        // --- Abrechnungs-Ausnahme (ADR 0024) ------------------------------------------------
        $wasExcluded = $old('billing_excluded_at') !== null;
        $isExcluded  = $holder->billing_excluded_at !== null;
        $oldReason   = self::text($old('billing_excluded_reason'));
        $newReason   = self::text($holder->billing_excluded_reason);

        if (! $wasExcluded && $isExcluded) {
            $events[] = [
                'event_type'  => self::TYPE_EXCLUDED,
                'old_value'   => null,
                'new_value'   => $newReason,
                'description' => 'Von der Weiterberechnung ausgenommen',
                // Der Service hält den Urheber am Träger fest — er ist die bessere Auskunft.
                'user_id'     => $holder->billing_excluded_by !== null ? (int) $holder->billing_excluded_by : $actor,
            ];
        } elseif ($wasExcluded && ! $isExcluded) {
            $events[] = [
                'event_type'  => self::TYPE_INCLUDED,
                'old_value'   => $oldReason,
                'new_value'   => null,
                'description' => 'Ausnahme aufgehoben — wieder in der Weiterberechnung',
                'user_id'     => $actor,
            ];
        } elseif ($isExcluded && $oldReason !== $newReason) {
            $events[] = [
                'event_type'  => self::TYPE_REASON_CHANGED,
                'old_value'   => $oldReason,
                'new_value'   => $newReason,
                'description' => 'Grund der Ausnahme geändert',
                'user_id'     => $holder->billing_excluded_by !== null ? (int) $holder->billing_excluded_by : $actor,
            ];
        }

        return $events;
    }

    /** Urheber dieses Schreibvorgangs: actingAs() vor Auth, sonst null (System/Sync). */
    public static function currentActor(): ?int
    {
        if (self::$actorSet) {
            return self::$actor;
        }

        $id = Auth::id();

        return $id !== null ? (int) $id : null;
    }

    /** Nur für Tests: Tabellen-Memo verwerfen. */
    public static function forget(): void
    {
        self::$tableExists = null;
    }

    /**
     * Gibt es die Tabelle schon? Vor der Migration (Deploy-Reihenfolge) darf ein Träger-Update nicht
     * an einem fehlenden Verlauf scheitern. Je Prozess einmal gefragt.
     */
    protected static function tableReady(): bool
    {
        return self::$tableExists ??= Schema::hasTable('asset_holder_events');
    }

    /** Leerstring und null gleich behandeln — sonst wäre „'' → null" eine Änderung. */
    protected static function text(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, 255);
    }

    public function holder(): BelongsTo
    {
        return $this->belongsTo(AssetHolder::class, 'holder_id');
    }

    /** Akteur der Änderung (null bei System/Sync). */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(\Platform\Core\Models\User::class, 'user_id');
    }

    public function eventLabel(): string
    {
        return match ($this->event_type) {
            self::TYPE_COST_CENTER    => 'Kostenstelle geändert',
            self::TYPE_EXCLUDED       => 'Ausgenommen',
            self::TYPE_REASON_CHANGED => 'Grund geändert',
            self::TYPE_INCLUDED       => 'Ausnahme aufgehoben',
            default                   => $this->description ?: (string) $this->event_type,
        };
    }

    /** Nur Farbfamilien, die im Modul bereits als Klassen vorkommen (Tailwind-Build). */
    public function eventColor(): string
    {
        return match ($this->event_type) {
            self::TYPE_COST_CENTER    => 'indigo',
            self::TYPE_EXCLUDED       => 'slate',
            self::TYPE_REASON_CHANGED => 'amber',
            self::TYPE_INCLUDED       => 'emerald',
            default                   => 'gray',
        };
    }
}
