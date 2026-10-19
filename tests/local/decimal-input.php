<?php

/**
 * Betragseingaben (#958): {@see \Platform\AssetManager\Support\DecimalInput}.
 *
 * Aufruf: php tests/local/decimal-input.php   (siehe tests/local/bootstrap.php)
 */

require __DIR__ . '/bootstrap.php';

use Platform\AssetManager\Support\DecimalInput;

check('deutsches Format mit Tausenderpunkt', 1234.56, DecimalInput::parse('1.234,56'));
check('deutsches Format ohne Tausender', 12.5, DecimalInput::parse('12,50'));
check('Punkt als Dezimalzeichen', 12.5, DecimalInput::parse('12.50'));
check('Ganzzahl', 80.0, DecimalInput::parse('80'));
check('mit Euro-Zeichen und Leerzeichen', 9.99, DecimalInput::parse(' 9,99 € '));
check('negativ bleibt erkennbar', -5.0, DecimalInput::parse('-5'));
check('Text ist keine Zahl', null, DecimalInput::parse('abc'));
check('leer ist keine Zahl', null, DecimalInput::parse('   '));
check('null bleibt null', null, DecimalInput::parse(null));
check('zwei Kommas ist keine Zahl', null, DecimalInput::parse('1,2,3'));

check_summary();
