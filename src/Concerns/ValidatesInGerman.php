<?php

namespace Platform\AssetManager\Concerns;

use Platform\AssetManager\Support\GermanValidation;

/**
 * Deutsche Validierungsmeldungen für eine Livewire-Komponente (#983) — Livewire liest `messages()`
 * bei jedem `validate()`/`validateOnly()`, solange der Aufruf keine eigenen Meldungen mitgibt.
 * Guardrail: jede Komponente unter src/Livewire, die validiert, nutzt diesen Trait.
 */
trait ValidatesInGerman
{
    protected function messages(): array
    {
        return GermanValidation::messages();
    }
}
