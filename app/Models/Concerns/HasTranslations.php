<?php

namespace App\Models\Concerns;

use App\Support\Translation;

trait HasTranslations
{
    public function translated(string $attribute = 'name', ?string $locale = null): string
    {
        /** @var array<string, string|null>|null $value */
        $value = $this->getAttribute($attribute);

        return Translation::pick($value, $locale);
    }
}
