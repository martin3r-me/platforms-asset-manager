<?php

/**
 * Weiterberechnung (#977): Existenzprüfung des easybill-Belegs in der Vorschau kurz cachen.
 *
 * Die Vorschau läuft bei jedem Livewire-Roundtrip und fragte jedes Mal per HTTP bei easybill nach.
 * Gecacht wird nur in der **Vorschau** und nur ein „vorhanden":
 *
 *  1. `true` wird gemerkt — der zweite Aufruf geht nicht mehr nach draußen.
 *  2. `false` (Beleg gelöscht → Lauf wird verworfen) und `null` (unklar) gehen jedes Mal live durch
 *     und löschen einen gemerkten Wert.
 *  3. Je Team getrennt (verschiedene easybill-Konten).
 *  4. Der Anlegen-Pfad (#940) bleibt ungecacht: der Container liefert den Lauf-Service mit dem rohen
 *     Gateway, nur die Vorschau bekommt die Hülle.
 *
 * Aufruf: php tests/local/billing-preview-cache.php   (siehe tests/local/bootstrap.php)
 */

require __DIR__ . '/bootstrap.php';

use Platform\AssetManager\Livewire\Billing\Index;
use Platform\AssetManager\Services\BillingGateway;
use Platform\AssetManager\Services\BillingRunService;

config(['cache.default' => 'array']);

$inner = new class implements BillingGateway {
    public ?bool $exists = true;
    public int $calls = 0;

    public function isAvailable(): bool { return true; }
    public function unavailableReason(): ?string { return 'grund'; }
    public function createDraftInvoice(array $document): int { return 1; }
    public function documentUrl(int $documentId): ?string { return "u{$documentId}"; }
    public function attachToDocument(int $documentId, string $filename, string $content): ?int { return 9; }
    public function documentExists(int $documentId): ?bool { $this->calls++; return $this->exists; }
    public function findCustomer(string $idOrNumber): ?array { return ['id' => 1, 'name' => 'K', 'number' => null]; }
};

$wrap = function (BillingGateway $g, int $team) {
    $m = new ReflectionMethod(Index::class, 'cachingExistenceGateway');
    $m->setAccessible(true);

    return $m->invoke(null, $g, $team, 300);
};

$g7 = $wrap($inner, 7);

// 1. true wird gemerkt
check('vorhanden: erster Aufruf live', [true, 1], [$g7->documentExists(100), $inner->calls]);
check('vorhanden: zweiter Aufruf aus dem Cache', [true, 1], [$g7->documentExists(100), $inner->calls]);
check('anderer Beleg: wieder live', [true, 2], [$g7->documentExists(101), $inner->calls]);

// 3. je Team getrennt
$g8 = $wrap($inner, 8);
check('anderes Team: eigener Cache', [true, 3], [$g8->documentExists(100), $inner->calls]);

// 2. false/null nie gecacht
$inner->exists = false;
check('gemerkter Beleg bleibt bis Ablauf „vorhanden" (vorsichtige Richtung)', [true, 3], [$g7->documentExists(100), $inner->calls]);
check('ungemerkter Beleg: false live', [false, 4], [$g7->documentExists(102), $inner->calls]);
check('false wird nicht gemerkt', [false, 5], [$g7->documentExists(102), $inner->calls]);
$inner->exists = null;
check('null (unklar) live', [null, 6], [$g7->documentExists(103), $inner->calls]);
check('null wird nicht gemerkt', [null, 7], [$g7->documentExists(103), $inner->calls]);

// Ablauf: nach dem Vergessen geht es wieder live — und ein false löscht nichts Falsches.
Illuminate\Support\Facades\Cache::forget('asset-manager:billing:document-exists:7:100');
$inner->exists = false;
check('nach Ablauf: false live', [false, 8], [$g7->documentExists(100), $inner->calls]);

// Übrige Methoden werden durchgereicht
check('Durchreichen: Grund, URL, Kunde', ['grund', 'u5', 1], [$g7->unavailableReason(), $g7->documentUrl(5), $g7->findCustomer('x')['id']]);

// 4. Container-Service ungecacht, Vorschau-Service mit Hülle
app()->instance(BillingGateway::class, $inner);
$gatewayOf = function (BillingRunService $s) {
    $p = new ReflectionProperty($s, 'gateway');
    $p->setAccessible(true);

    return $p->getValue($s);
};
check('Anlegen-Pfad (Container): rohes Gateway', true, $gatewayOf(app(BillingRunService::class)) === $inner);

$component = new class extends Index {
    public function teamId(): int { return 7; }
};
$m = new ReflectionMethod($component, 'previewRuns');
$m->setAccessible(true);
$preview = $m->invoke($component, $inner);
check('Vorschau-Pfad: gecachte Hülle', true, $gatewayOf($preview) !== $inner && $gatewayOf($preview) instanceof BillingGateway);

check_summary();
