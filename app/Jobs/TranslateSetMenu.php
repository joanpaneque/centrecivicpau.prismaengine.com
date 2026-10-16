<?php

namespace App\Jobs;

use App\Models\SetMenu;
use App\Services\Assistant\MenuTranslator;
use App\Services\Catalog\MenuDocument;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Once a menu is written in Catalan/Spanish, translate it to the other printing languages
 * in the background, so printing later is instant.
 */
class TranslateSetMenu implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public int $menuId) {}

    public function handle(MenuTranslator $translator): void
    {
        $menu = SetMenu::query()->find($this->menuId);

        if ($menu !== null) {
            $translator->ensure($menu, MenuDocument::LOCALES);
        }
    }
}
