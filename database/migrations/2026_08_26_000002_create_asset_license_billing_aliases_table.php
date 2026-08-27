<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gelernte Zuordnung Rechnungsposition → Graph-SKU.
 *
 * Der Wiederverkäufer benennt seine Artikel anders als Microsoft Graph: „NCE Exchange Online
 * Archiving for Exchange Online - Addon" gegen `EXCHANGEARCHIVE_ADDON`, „Power Automate per user
 * with attended RPA plan" gegen `POWERAUTOMATE_ATTENDED_RPA`. Der Automatik-Match kommt weit, aber
 * nicht überall hin — und er darf nicht raten, weil ein falsch zugeordneter Preis über alle Seats
 * dieser SKU multipliziert wird.
 *
 * Deshalb: was der Automatik-Match nicht sicher trifft, ordnet ein Mensch **einmal** zu, und das
 * bleibt. Ein Alias mit `sku_id = null` heißt bewusst „gehört zu keiner Lizenz" (etwa ein
 * kontobezogener Posten) — das ist eine Entscheidung und keine offene Lücke, sonst erscheint die
 * Position bei jedem Import wieder als ungeklärt.
 *
 * Der Anker ist `edition_key`, nicht der Rohname: der Anbieter hat seine Artikel zwischen Mai und
 * Juni 2026 um „- P1Y" erweitert. Über den Rohnamen wäre daraus ein neues Produkt geworden.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('asset_license_billing_aliases')) {
            return;
        }

        Schema::create('asset_license_billing_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained('asset_tenants')->cascadeOnDelete();

            $table->string('edition_key');
            // Graph-SKU-GUID; null = bewusst keiner Lizenz zugeordnet (siehe oben).
            $table->string('sku_id')->nullable();
            $table->string('note')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique(['team_id', 'tenant_id', 'edition_key'], 'asset_license_billing_aliases_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_license_billing_aliases');
    }
};
