<?php

namespace Platform\AssetManager\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

class AssetConnectorConfig extends Model
{
    protected $table = 'asset_connector_configs';

    protected $fillable = [
        'team_id',
        'client_id',
        'tenant_id',
        'object_id',
        'key_id',
        'client_secret',
        'enabled',
        'last_sync_at',
        'sync_status',
        'sync_error',
    ];

    protected $casts = [
        'enabled'      => 'boolean',
        'last_sync_at' => 'datetime',
    ];

    /** Defense-in-depth: Secrets nie in toArray()/toJson() (Logs, API-Responses, Events) durchreichen. */
    protected $hidden = ['client_id', 'tenant_id', 'object_id', 'key_id', 'client_secret'];

    // Verschlüsselte Felder — Accessors/Mutators nach MicrosoftOAuthToken-Muster

    public function setClientIdAttribute(?string $value): void
    {
        $this->attributes['client_id'] = $value ? Crypt::encryptString($value) : null;
    }

    public function getClientIdAttribute(?string $value): ?string
    {
        return $this->decryptField($value, 'client_id');
    }

    public function setTenantIdAttribute(?string $value): void
    {
        $this->attributes['tenant_id'] = $value ? Crypt::encryptString($value) : null;
    }

    public function getTenantIdAttribute(?string $value): ?string
    {
        return $this->decryptField($value, 'tenant_id');
    }

    public function setObjectIdAttribute(?string $value): void
    {
        $this->attributes['object_id'] = $value ? Crypt::encryptString($value) : null;
    }

    public function getObjectIdAttribute(?string $value): ?string
    {
        return $this->decryptField($value, 'object_id');
    }

    public function setKeyIdAttribute(?string $value): void
    {
        $this->attributes['key_id'] = $value ? Crypt::encryptString($value) : null;
    }

    public function getKeyIdAttribute(?string $value): ?string
    {
        return $this->decryptField($value, 'key_id');
    }

    public function setClientSecretAttribute(?string $value): void
    {
        $this->attributes['client_secret'] = $value ? Crypt::encryptString($value) : null;
    }

    public function getClientSecretAttribute(?string $value): ?string
    {
        return $this->decryptField($value, 'client_secret');
    }

    /**
     * Entschlüsselt ein Secret-Feld. Fällt bei rotiertem APP_KEY o. ä. weiterhin safe auf null (kein 500),
     * loggt den Fehler aber jetzt sichtbar (statt still) — damit die UI „Schlüssel rotiert, Anmeldedaten
     * neu eingeben" zeigen kann statt „nicht konfiguriert". Siehe hasUndecryptableSecrets().
     */
    protected function decryptField(?string $value, string $field): ?string
    {
        if (!$value) return null;
        try {
            return Crypt::decryptString($value);
        } catch (\Throwable $e) {
            Log::warning('AssetManager: Connector-Secret nicht entschlüsselbar (APP_KEY rotiert?)', [
                'team_id' => $this->attributes['team_id'] ?? null,
                'field'   => $field,
            ]);
            return null;
        }
    }

    /**
     * True, wenn mindestens ein verschlüsseltes Feld zwar gesetzt, aber nicht mehr entschlüsselbar ist
     * (z. B. nach APP_KEY-Rotation). Lässt die UI „neu eingeben" anzeigen statt „fehlt/nicht konfiguriert".
     */
    public function hasUndecryptableSecrets(): bool
    {
        foreach (['client_id', 'tenant_id', 'object_id', 'key_id', 'client_secret'] as $field) {
            $raw = $this->attributes[$field] ?? null;
            if (!$raw) continue;
            try {
                Crypt::decryptString($raw);
            } catch (\Throwable) {
                return true;
            }
        }
        return false;
    }

    public function isConfigured(): bool
    {
        return !empty($this->client_id)
            && !empty($this->tenant_id)
            && !empty($this->client_secret);
    }

    public function team()
    {
        return $this->belongsTo(\Platform\Core\Models\Team::class);
    }
}
