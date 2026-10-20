<?php

/**
 * Deutsche Texte trotz englischer Host-Locale (#983).
 *
 *  - Validierungsmeldungen über ValidatesInGerman (vorher „The … field must be greater than 0.")
 *  - Import-Protokoll: Zeitraum als 15.08.2026–14.09.2026 statt Rohwert mit Uhrzeit
 *  - relative Zeitangaben mit ->locale('de')
 *
 * Aufruf: php tests/local/german-texts.php   (siehe tests/local/bootstrap.php)
 */

require __DIR__ . '/bootstrap.php';

use Illuminate\Support\Carbon;
use Platform\AssetManager\Support\ImportRunRecorder;

// Live läuft die Host-App mit locale=en — hier nachstellen (die lokale .env kann abweichen).
app()->setLocale('en');

$form = new class extends Livewire\Component {
    use Platform\AssetManager\Concerns\ValidatesInGerman;

    public $price = 0;
    public $vat = '19.5';
    public $name = '';
    public $note = 'x';

    public function save(): void
    {
        $this->validate([
            'price' => 'required|numeric|gt:0',
            'vat'   => 'required|integer',
            'name'  => 'required|string|max:255',
            'note'  => 'nullable|string|min:3',
        ]);
    }

    public function render() { return ''; }
};

$errors = new Illuminate\Support\MessageBag();
try {
    $form->save();
} catch (Illuminate\Validation\ValidationException $e) {
    // Außerhalb des Livewire-Lebenszyklus landet der Fehler nur in der Exception.
    $errors = $e->validator->errors();
}
check('gt:0 deutsch', 'Muss größer als 0 sein.', $errors->first('price'));
check('integer deutsch', 'Bitte eine ganze Zahl eingeben.', $errors->first('vat'));
check('required deutsch', 'Pflichtfeld.', $errors->first('name'));
check('min.string deutsch', 'Muss mindestens 3 Zeichen lang sein.', $errors->first('note'));

$recorder = new ImportRunRecorder();
$m = new ReflectionMethod($recorder, 'fromVodafone');
$m->setAccessible(true);
[$metrics] = $m->invoke($recorder, ['period' => ['from' => '2026-08-15 00:00:00', 'to' => '2026-09-14 00:00:00', 'invoice' => '1'], 'stats' => []]);
check('Import-Protokoll: Zeitraum deutsch formatiert', '15.08.2026–14.09.2026', $metrics['Zeitraum']);
[$metrics] = $m->invoke($recorder, ['period' => [], 'stats' => []]);
check('Import-Protokoll: ohne Zeitraum ein Strich', '—', $metrics['Zeitraum']);

check('relative Zeit deutsch', 'vor 51 Minuten', Carbon::now()->subMinutes(51)->locale('de')->diffForHumans());

check_summary();
