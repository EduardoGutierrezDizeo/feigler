<?php

namespace App\Livewire\Admin\HomePage;

use App\Enums\HomePageTab;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The page where the administrator shapes what the home page shows.
 *
 * It is a shell: it owns the URL of the tab, the title of the page and the tab
 * bar, and the tab that is being read is the child that does the work. Only one
 * tab exists today, so the bar stays hidden; the moment a second tab is added
 * to the enum, the bar appears on its own.
 */
#[Layout('layouts::admin')]
#[Title('Vista principal')]
class Index extends Component
{
    /**
     * The tab being managed, as it travels in the URL.
     *
     * `#[Url]` writes it into the query string before `mount()` runs, so the
     * fallback below is applied to whatever the visitor arrived with, not to
     * the default.
     */
    #[Url(as: 'tab')]
    public string $tab = HomePageTab::Categorias->value;

    /**
     * The page this component renders sits behind `role:admin`, but a Livewire
     * request is not that page's request: `/livewire/update` reopens the
     * component on its own, so the rule of the route is asked again here, on
     * the mount and on every request after it.
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
     * A tab that does not exist falls back to `categorias` instead of leaving
     * the panel with nothing to read.
     */
    public function setTab(string $tab): void
    {
        $this->tab = HomePageTab::tryFrom($tab)?->value ?? HomePageTab::Categorias->value;
    }

    public function render()
    {
        return view('livewire.admin.home-page.index', [
            'activeTab' => $this->activeTab(),
        ]);
    }

    /**
     * The tab being managed, resolved from the URL value.
     */
    private function activeTab(): HomePageTab
    {
        return HomePageTab::tryFrom($this->tab) ?? HomePageTab::Categorias;
    }
}
