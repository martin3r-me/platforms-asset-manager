<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Platform\AssetManager\Support\MigrationSchema;

/**
 * Tenant-Welle 6/6 — „Alle Tenants" als expliziter Umschalter-Wert (siehe docs/adr/0016).
 *
 * Eigene Spalte statt Sentinel-Wert in `selected_tenant_id`: die Spalte trägt einen FK, ein „-1" wäre
 * dort nicht ablegbar, und NULL bedeutet schon „nichts gewählt" (→ Default). Wichtiger noch:
 * `selected_tenant_id` bleibt so **immer** der letzte konkrete Tenant. Genau das braucht die Regel
 * „‚Alle Tenants‘ nur auf Dashboard/Auswertungen; auf Listen und Detailseiten fällt der Umschalter
 * auf den zuletzt gewählten Tenant zurück" — ohne die Auswahl beim Umschalten zu verlieren.
 */
return new class extends Migration
{
    public function up(): void
    {
        MigrationSchema::addColumn('asset_tenant_selections', 'all_tenants', function (Blueprint $t) {
            $t->boolean('all_tenants')->default(false)->after('selected_tenant_id');
        });
    }

    public function down(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasColumn('asset_tenant_selections', 'all_tenants')) {
            return;
        }

        \Illuminate\Support\Facades\Schema::table(
            'asset_tenant_selections',
            fn (Blueprint $t) => $t->dropColumn('all_tenants'),
        );
    }
};
