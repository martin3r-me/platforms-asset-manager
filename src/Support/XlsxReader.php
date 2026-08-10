<?php

namespace Platform\AssetManager\Support;

use Illuminate\Support\Str;

/**
 * Schlanker .xlsx-Leser (ZipArchive + SimpleXML) — ohne Fremd-Bibliothek, ohne ext-gd.
 *
 * Liest immer den **gecachten** Zellwert (`<v>`), also auch das Ergebnis von Formeln. Das ist der
 * entscheidende Punkt bei Kunden-Arbeitsmappen: dort stehen Beträge häufig als Formel, und ein
 * Reader, der nur Literale sieht, liefert stillschweigend Nullen.
 *
 * Herkunft: extrahiert aus {@see \Platform\AssetManager\Services\CostExcelImportService}, als der
 * Vodafone-Rechnungsimport denselben Parser brauchte. Zwei Kopien desselben XML-Geknobels wären die
 * schlechtere Antwort gewesen — Formate driften, und dann driftet nur eine der Kopien mit.
 */
class XlsxReader
{
    /**
     * Arbeitsmappe lesen.
     *
     * @return array<string, array<int, array<string, string|float|int>>>
     *         normalisierter Blattname => [Zeilennummer (1-basiert) => [Spaltenbuchstabe => Wert]]
     */
    public function read(string $path): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \RuntimeException('Excel-Datei konnte nicht geöffnet werden.');
        }

        // Shared Strings
        $shared = [];
        if (($ss = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
            $xml = simplexml_load_string($ss);
            if ($xml !== false) {
                foreach ($xml->si as $si) {
                    $shared[] = $this->siText($si);
                }
            }
        }

        // Relationship-Map (r:id → Target)
        $relMap = [];
        if (($relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels')) !== false) {
            $rels = simplexml_load_string($relsXml);
            if ($rels !== false) {
                foreach ($rels->Relationship as $rel) {
                    $relMap[(string) $rel['Id']] = (string) $rel['Target'];
                }
            }
        }

        $sheets = [];
        $wb = simplexml_load_string($zip->getFromName('xl/workbook.xml') ?: '');
        if ($wb !== false && isset($wb->sheets)) {
            foreach ($wb->sheets->sheet as $sheet) {
                $name = (string) $sheet['name'];
                $rid  = '';
                foreach ($sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships') as $k => $v) {
                    if ($k === 'id') $rid = (string) $v;
                }
                $target = $relMap[$rid] ?? null;
                if (!$target) continue;
                if (!str_starts_with($target, 'xl/')) {
                    $target = 'xl/' . ltrim($target, '/');
                }
                $data = $zip->getFromName($target);
                if ($data === false) continue;
                $sheets[$this->normName($name)] = $this->parseSheet($data, $shared);
            }
        }
        $zip->close();

        return $sheets;
    }

    /**
     * Blatt anhand mehrerer Namenskandidaten finden (normalisiert, dann als Teilstring).
     *
     * @param  array<string, array<int, array<string, mixed>>>  $sheets
     * @param  list<string>  $candidates
     */
    public function findSheet(array $sheets, array $candidates): ?array
    {
        foreach ($candidates as $c) {
            $key = $this->normName($c);
            if (isset($sheets[$key])) return $sheets[$key];
        }
        // Teilstring-Suche als Fallback
        foreach ($sheets as $key => $rows) {
            foreach ($candidates as $c) {
                if (Str::contains($key, $this->normName($c))) return $rows;
            }
        }

        return null;
    }

    /** Blatt-/Spaltennamen vergleichbar machen: klein, ohne Umlaute, ohne Leerzeichen und Punkte. */
    public function normName(?string $s): string
    {
        $s = mb_strtolower(trim((string) $s));

        return str_replace(['ä', 'ö', 'ü', 'ß', ' ', '.'], ['a', 'o', 'u', 'ss', '', ''], $s);
    }

    /** Text aus <si> (inkl. Rich-Text-Runs <r><t>). */
    protected function siText(\SimpleXMLElement $si): string
    {
        $text = '';
        if (isset($si->t)) $text .= (string) $si->t;
        foreach ($si->r as $r) {
            if (isset($r->t)) $text .= (string) $r->t;
        }

        return $text;
    }

    /**
     * Eine Worksheet-XML → [zeilennr => ['A'=>wert, …]] mit gecachten Werten.
     *
     * @param  list<string>  $shared
     * @return array<int, array<string, string|float|int>>
     */
    protected function parseSheet(string $xml, array $shared): array
    {
        $sx = simplexml_load_string($xml);
        $rows = [];
        if ($sx === false || !isset($sx->sheetData)) return $rows;

        foreach ($sx->sheetData->row as $row) {
            $rn    = (int) $row['r'];
            $assoc = [];
            foreach ($row->c as $c) {
                $ref = (string) $c['r'];
                if (!preg_match('/^([A-Z]+)/', $ref, $m)) continue;
                $col = $m[1];
                $t   = (string) $c['t'];
                $val = null;

                if ($t === 's') {
                    $val = isset($c->v) ? ($shared[(int) $c->v] ?? null) : null;
                } elseif ($t === 'inlineStr') {
                    $val = isset($c->is) ? $this->siText($c->is) : null;
                } elseif (isset($c->v)) {
                    // numerisch / boolean / gecachtes Formelergebnis (t='' | 'n' | 'str' | 'b')
                    $raw = (string) $c->v;
                    $val = is_numeric($raw) ? $raw + 0 : $raw;
                }

                if ($val !== null && $val !== '') {
                    $assoc[$col] = $val;
                }
            }
            if ($assoc) $rows[$rn] = $assoc;
        }

        return $rows;
    }
}
