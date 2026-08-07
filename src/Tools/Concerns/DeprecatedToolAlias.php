<?php

namespace Platform\AssetManager\Tools\Concerns;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;

/**
 * Basis für **Alias-Tools unter einem alten Namen** (ADR 0017 / Dev-Modul #760).
 *
 * Tool-Namen sind über den Live-Connector nach außen sichtbar. Ein Rename ohne Alias bricht bestehende
 * LLM-Aufrufe mit „unbekanntes Tool" — also mit einer Fehlermeldung, die nicht sagt, wo die Fähigkeit
 * jetzt lebt. Ein Alias antwortet dagegen normal und trägt in der Antwort `deprecated` +
 * `use_instead`, sodass ein Modell den Umstieg selbst findet.
 *
 * Der Alias **delegiert**, er kopiert nicht: Schema und Ausführung kommen vom Ziel-Tool. So können die
 * beiden nicht auseinanderlaufen — der klassische Fehler bei handgeschriebenen Alias-Kopien.
 *
 * Erben, {@see target()}/{@see getName()} setzen, registrieren. Entfernen, sobald der Connector eine
 * Weile keine Aufrufe mehr auf den alten Namen zeigt.
 */
abstract class DeprecatedToolAlias implements ToolContract, ToolMetadataContract
{
    /** Das Tool, an das dieser Alias delegiert. */
    abstract protected function target(): ToolContract;

    /** Name des Nachfolgers — erscheint in der Antwort als `use_instead`. */
    protected function replacementName(): string
    {
        return $this->target()->getName();
    }

    /**
     * Umbenannte Antwort-Schlüssel des Nachfolgers → alte Namen, die dieser Alias **zusätzlich**
     * liefert (`neu => alt`).
     *
     * Nur hier, nicht in den neuen Tools: dort ist der neue Name der Punkt der Umbenennung — ein
     * mitgeliefertes `employees` würde die Fehldeutung, die ADR 0017 abstellen soll, verlängern.
     * Wer den alten Tool-Namen ruft, bekommt dagegen auch die alte Antwortform und muss nicht in
     * einem Schritt beides umstellen.
     *
     * @return array<string, string>
     */
    protected function legacyKeyMap(): array
    {
        return [
            'holders' => 'employees',
            'holder'  => 'employee',
        ];
    }

    public function getDescription(): string
    {
        return sprintf(
            'DEPRECATED (nutze %s) — %s',
            $this->replacementName(),
            $this->target()->getDescription(),
        );
    }

    public function getSchema(): array
    {
        return $this->target()->getSchema();
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $result = $this->target()->execute($arguments, $context);

        // Deprecation-Hinweis nur an erfolgreiche Antworten hängen: bei einem Fehler ist die
        // Fehlermeldung die wichtigere Information und soll nicht verwässert werden.
        // `success`/`data` sind readonly Properties von ToolResult (keine Getter).
        if (! $result->success || ! is_array($result->data)) {
            return $result;
        }

        $data = $result->data;

        // Alte Antwort-Schlüssel zusätzlich spiegeln (nicht ersetzen), damit ein Aufrufer Tool-Name
        // und Antwortform nicht gleichzeitig umstellen muss.
        foreach ($this->legacyKeyMap() as $new => $legacy) {
            if (array_key_exists($new, $data) && ! array_key_exists($legacy, $data)) {
                $data[$legacy] = $data[$new];
            }
        }

        return ToolResult::success(array_merge($data, [
            'deprecated'  => true,
            'use_instead' => $this->replacementName(),
            'note'        => sprintf(
                'Dieses Tool heißt jetzt %s. „Mitarbeiter" wurde modulweit zu „Asset-Träger" — die '
                . 'Entität umfasst auch Externe, Admin-Accounts und Funktionskonten (ADR 0017).',
                $this->replacementName(),
            ),
        ]));
    }

    public function getMetadata(): array
    {
        $meta = $this->target() instanceof ToolMetadataContract
            ? $this->target()->getMetadata()
            : [];

        return array_merge($meta, [
            'deprecated' => true,
            'tags'       => array_values(array_unique(array_merge($meta['tags'] ?? [], ['deprecated']))),
        ]);
    }
}
