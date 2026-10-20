<?php

namespace Platform\AssetManager\Support;

/**
 * Deutsche Validierungsmeldungen für die Livewire-Formulare des Moduls (#983).
 *
 * Die Host-App läuft mit `locale = en` und ohne `lang/de` — Laravel lieferte deshalb „The Rückfallpreis
 * field must be greater than 0." Die Sprache der Host-App umzustellen beträfe alle Module; die Meldungen
 * leben deshalb hier und hängen über {@see \Platform\AssetManager\Concerns\ValidatesInGerman} an den
 * Komponenten.
 *
 * Bewusst ohne `:attribute`: die Fehler stehen im Modul immer direkt unter ihrem Feld, und ohne gepflegte
 * Attributnamen setzte Laravel dort den Property-Namen ein („e status").
 */
final class GermanValidation
{
    /**
     * Größen-Regeln (min, max, gt, size …) als Array je Typ — so liest Laravel eigene Meldungen für
     * diese Regeln; der Schlüssel `min.string` würde stillschweigend ignoriert.
     *
     * @return array<string, string|array<string, string>>
     */
    public static function messages(): array
    {
        return [
            'accepted'             => 'Bitte bestätigen.',
            'after_or_equal'       => 'Das Datum darf nicht vor :date liegen.',
            'array'                => 'Ungültige Auswahl.',
            'before_or_equal'      => 'Das Datum darf nicht nach :date liegen.',
            'between' => [
                'numeric' => 'Bitte einen Wert zwischen :min und :max eingeben.',
                'string' => 'Bitte :min bis :max Zeichen eingeben.',
                'array' => 'Bitte :min bis :max Einträge wählen.',
                'file' => 'Die Datei muss zwischen :min und :max KB groß sein.',
            ],
            'boolean'              => 'Ungültiger Wert.',
            'date'                 => 'Bitte ein gültiges Datum eingeben.',
            'date_format'          => 'Bitte das Datum im Format :format eingeben.',
            'decimal'              => 'Bitte eine Zahl mit :decimal Nachkommastellen eingeben.',
            'different'            => 'Muss sich von :other unterscheiden.',
            'digits'               => 'Bitte genau :digits Ziffern eingeben.',
            'email'                => 'Bitte eine gültige E-Mail-Adresse eingeben.',
            'exists'               => 'Der gewählte Eintrag existiert nicht (mehr).',
            'file'                 => 'Bitte eine Datei wählen.',
            'gt' => [
                'numeric' => 'Muss größer als :value sein.',
                'string' => 'Muss länger als :value Zeichen sein.',
                'array' => 'Bitte mehr als :value Einträge wählen.',
                'file' => 'Die Datei muss größer als :value KB sein.',
            ],
            'gte' => [
                'numeric' => 'Muss mindestens :value sein.',
                'string' => 'Muss mindestens :value Zeichen lang sein.',
                'array' => 'Bitte mindestens :value Einträge wählen.',
                'file' => 'Die Datei muss mindestens :value KB groß sein.',
            ],
            'image'                => 'Bitte ein Bild wählen.',
            'in'                   => 'Ungültige Auswahl.',
            'integer'              => 'Bitte eine ganze Zahl eingeben.',
            'lt' => [
                'numeric' => 'Muss kleiner als :value sein.',
                'string' => 'Muss kürzer als :value Zeichen sein.',
                'array' => 'Bitte weniger als :value Einträge wählen.',
                'file' => 'Die Datei muss kleiner als :value KB sein.',
            ],
            'lte' => [
                'numeric' => 'Darf höchstens :value sein.',
                'string' => 'Darf höchstens :value Zeichen lang sein.',
                'array' => 'Bitte höchstens :value Einträge wählen.',
                'file' => 'Die Datei darf höchstens :value KB groß sein.',
            ],
            'max' => [
                'numeric' => 'Darf höchstens :max sein.',
                'string' => 'Darf höchstens :max Zeichen lang sein.',
                'array' => 'Bitte höchstens :max Einträge wählen.',
                'file' => 'Die Datei darf höchstens :max KB groß sein.',
            ],
            'mimes'                => 'Erlaubte Dateitypen: :values.',
            'mimetypes'            => 'Erlaubte Dateitypen: :values.',
            'min' => [
                'numeric' => 'Muss mindestens :min sein.',
                'string' => 'Muss mindestens :min Zeichen lang sein.',
                'array' => 'Bitte mindestens :min Einträge wählen.',
                'file' => 'Die Datei muss mindestens :min KB groß sein.',
            ],
            'not_in'               => 'Ungültige Auswahl.',
            'numeric'              => 'Bitte eine Zahl eingeben.',
            'prohibited'           => 'Dieses Feld muss leer bleiben.',
            'regex'                => 'Das Format ist ungültig.',
            'required'             => 'Pflichtfeld.',
            'required_if'          => 'Pflichtfeld.',
            'required_unless'      => 'Pflichtfeld.',
            'required_with'        => 'Pflichtfeld.',
            'required_without'     => 'Pflichtfeld.',
            'same'                 => 'Muss mit :other übereinstimmen.',
            'size' => [
                'numeric' => 'Muss genau :size sein.',
                'string' => 'Muss genau :size Zeichen lang sein.',
                'array' => 'Bitte genau :size Einträge wählen.',
                'file' => 'Die Datei muss genau :size KB groß sein.',
            ],
            'starts_with'          => 'Muss mit :values beginnen.',
            'string'               => 'Bitte Text eingeben.',
            'unique'               => 'Dieser Wert ist bereits vergeben.',
            'uploaded'             => 'Die Datei konnte nicht hochgeladen werden.',
            'url'                  => 'Bitte eine gültige URL eingeben.',
        ];
    }
}
