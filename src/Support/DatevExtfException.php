<?php

namespace Platform\AssetManager\Support;

/**
 * Ein EXTF-Stapel verstößt gegen die Checkliste der Buchhaltung — es wird **keine** Datei erzeugt.
 * Trägt alle Verstöße auf einmal, damit man sie in einem Durchgang beheben kann.
 */
final class DatevExtfException extends \RuntimeException
{
    /** @param list<string> $violations */
    public function __construct(public readonly array $violations)
    {
        parent::__construct("DATEV-EXTF-Stapel nicht erzeugt:\n- " . implode("\n- ", $violations));
    }
}
