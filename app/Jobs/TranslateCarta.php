<?php

namespace App\Jobs;

use App\Models\Carta;
use App\Services\Assistant\MenuTranslator;
use App\Services\Catalog\MenuDocument;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Once a carta is written in Catalan/Spanish, translate it to the other printing languages
 * in the background, so printing later is instant.
 */
class TranslateCarta implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public int $cartaId) {}

    public function handle(MenuTranslator $translator): void
    {
        $carta = Carta::query()->find($this->cartaId);

        if ($carta !== null) {
            $translator->ensure($carta, MenuDocument::LOCALES);
        }
    }
}
