<?php

namespace Platform\AssetManager\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

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

    // Verschlüsselte Felder — Accessors/Mutators nach MicrosoftOAuthToken-Muster

    public function setClientIdAttribute(?string $value): void
    {
        $this->attributes['client_id'] = $value ? Crypt::encryptString($value) : null;
    }

    public function getClientIdAttribute(?string $value): ?string
    {
        if (!$value) return null;
        try { return Crypt::decryptString($value); } catch (\Exception) { return null; }
    }

    public function setTenantIdAttribute(?string $value): void
    {
        $this->attributes['tenant_id'] = $value ? Crypt::encryptString($value) : null;
    }

    public function getTenantIdAttribute(?string $value): ?string
    {
        if (!$value) return null;
        try { return Crypt::decryptString($value); } catch (\Exception) { return null; }
    }

    public function setObjectIdAttribute(?string $value): void
    {
        $this->attributes['object_id'] = $value ? Crypt::encryptString($value) : null;
    }

    public function getObjectIdAttribute(?string $value): ?string
    {
        if (!$value) return null;
        try { return Crypt::decryptString($value); } catch (\Exception) { return null; }
    }

    public function setKeyIdAttribute(?string $value): void
    {
        $this->attributes['key_id'] = $value ? Crypt::encryptString($value) : null;
    }

    public function getKeyIdAttribute(?string $value): ?string
    {
        if (!$value) return null;
        try { return Crypt::decryptString($value); } catch (\Exception) { return null; }
    }

    public function setClientSecretAttribute(?string $value): void
    {
        $this->attributes['client_secret'] = $value ? Crypt::encryptString($value) : null;
    }

    public function getClientSecretAttribute(?string $value): ?string
    {
        if (!$value) return null;
        try { return Crypt::decryptString($value); } catch (\Exception) { return null; }
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
