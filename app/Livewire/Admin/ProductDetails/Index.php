<?php

namespace App\Livewire\Admin\ProductDetails;

use App\Enums\ProductDetailsTab;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The page that holds the four catalogs a product is built out of.
 *
 * It is a shell: it owns the URL of the tab, the title of the page and the tab bar,
 * and the tab that is being read is the child that does the work. The children are
 * mounted one at a time and each one keys itself with its own tab, so switching tabs
 * hands a fresh component to the new child instead of morphing one into another — the
 * state of a form opened in another tab is not waiting there when it comes back.
 */
#[Layout('layouts::admin')]
#[Title('Detalles de productos')]
class Index extends Component
{
    /**
     * The tab being managed, as it travels in the URL.
     *
     * `#[Url]` writes it into the query string before `mount()` runs, so the fallback
     * below is applied to whatever the visitor arrived with, not to the default.
     */
    #[Url(as: 'tab')]
    public string $tab = ProductDetailsTab::Categorias->value;

    /**
     * The page this component renders sits behind `role:admin`, but a Livewire request
     * is not that page's request: `/livewire/update` reopens the component on its own,
     * so `setTab()` is reachable by anyone who reaches that endpoint. The rule of the
     * route is asked again here, on the mount and on every request after it, which is
     * the only place the guard can still stop the call.
     */
    public function booted(): void
    {
        abort_unless(auth()->user()?->hasRole('admin'), 403);
    }

    public function mount(): void
    {
        $this->tab = $this->activeTab()->value;
    }

    /**
     * Move to another tab from the tab bar.
     *
     * A tab that does not exist falls back to `categorias` instead of leaving the
     * panel with nothing to read, which is what a hand-edited URL would otherwise do.
     */
    public function setTab(string $tab): void
    {
        $this->tab = ProductDetailsTab::tryFrom($tab)?->value ?? ProductDetailsTab::Categorias->value;
    }

    public function render()
    {
        return view('livewire.admin.product-details.index', [
            'activeTab' => $this->activeTab(),
        ]);
    }

    /**
     * The tab being managed, resolved from the URL value.
     */
    private function activeTab(): ProductDetailsTab
    {
        return ProductDetailsTab::tryFrom($this->tab) ?? ProductDetailsTab::Categorias;
    }
}
