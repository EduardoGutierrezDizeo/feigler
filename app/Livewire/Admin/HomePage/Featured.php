<?php

namespace App\Livewire\Admin\HomePage;

use App\Actions\HomePage\AddHomeFeaturedProduct;
use App\Actions\HomePage\MoveHomeFeaturedProduct;
use App\Actions\HomePage\RemoveHomeFeaturedProduct;
use App\Exceptions\HomeFeaturedProductException;
use App\Livewire\Concerns\Notifies;
use App\Models\HomeFeaturedProduct;
use App\Models\Product;
use App\Services\Storefront\HomeNewProducts;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Livewire\Component;

/**
 * The tab «Lo más nuevo» of the home page panel.
 *
 * It lets the administrator choose, by hand, the products of the «Novedades»
 * section, in the order they appear, and take them out again. What the home
 * page shows today — and why — comes from the same class the storefront reads,
 * HomeNewProducts, so the screen never describes a block the root does not draw.
 *
 * A visible list keeps its length no matter how many choices stop being shown:
 * the storefront does not fill the gaps of a manual list, and the screen says
 * so in the state header instead of inventing rows.
 */
class Featured extends Component
{
    use Notifies;

    /**
     * The free text of the product search. Reaches the database from the second
     * character on, so a keystroke alone cannot fire a query.
     */
    public string $search = '';

    public const BUSQUEDA_MINIMA = 2;

    public const BUSQUEDA_LIMITE = 8;

    /**
     * A Livewire request is not the panel page's request: `/livewire/update`
     * reopens the component on its own, so every action here is reachable by
     * anyone who reaches that endpoint. The `role:admin` rule of the route is
     * asked again here, on the mount and on every request after it, which is the
     * only place the guard can still stop the call.
     */
    public function booted(): void
    {
        abort_unless(auth()->user()?->hasRole('admin'), 403);
    }

    /**
     * Highlight a product the search found.
     *
     * The decision of whether to honour the add lives in the action, and its
     * exceptions already carry the message the administrator reads: a product
     * the store does not show refuses to be highlighted, one that is already in
     * the list is not put in twice and a full list stays full. After a
     * successful add the search goes back to blank, ready for the next one.
     */
    public function add(int $productId): void
    {
        try {
            (new AddHomeFeaturedProduct)(Product::query()->findOrFail($productId));

            $this->notifySuccess('Producto añadido a las novedades.');
            $this->search = '';
        } catch (HomeFeaturedProductException $exception) {
            $this->notifyError($exception->getMessage());
        }
    }

    /**
     * Stop highlighting a product, and put the list back in one piece.
     *
     * Removing too a row reindexes the whole list to `0..n-1`, the same rule the
     * home page reads by, so a position is never left empty in the middle.
     */
    public function remove(int $featuredId): void
    {
        $featured = HomeFeaturedProduct::query()->findOrFail($featuredId);

        (new RemoveHomeFeaturedProduct)($featured);

        $this->notifySuccess('Producto quitado de las novedades.');
    }

    public function moveUp(int $featuredId): void
    {
        $this->move($featuredId, -1);
    }

    public function moveDown(int $featuredId): void
    {
        $this->move($featuredId, 1);
    }

    public function render()
    {
        $rows = HomeFeaturedProduct::query()
            ->orderBy('order')
            ->orderBy('id')
            ->get();

        $products = Product::query()
            ->with(['category', 'images'])
            ->whereIn('id', $rows->pluck('product_id'))
            ->get()
            ->keyBy('id');

        $visibleIds = Product::query()
            ->visible()
            ->whereIn('id', $rows->pluck('product_id'))
            ->pluck('id')
            ->all();

        $novelties = app(HomeNewProducts::class)->effective();

        return view('livewire.admin.home-page.featured', [
            'rows' => $rows,
            'products' => $products,
            'visibleIds' => $visibleIds,
            'novelties' => $novelties['products'],
            'state' => $novelties['state'],
            'searching' => mb_strlen($this->search) >= self::BUSQUEDA_MINIMA,
            'candidates' => $this->candidates($rows),
        ]);
    }

    /**
     * The visible products the search matches, that are not already chosen.
     *
     * The free text is escaped as a literal pattern, so `%` and `_` are searched
     * as the characters they are and not as the wildcards of `LIKE`. Only from
     * the second character on, and only while the list is not full, does the
     * search reach the database: before that there is nothing to answer.
     *
     * @return SupportCollection<int, Product>
     */
    private function candidates(Collection $rows): SupportCollection
    {
        if (mb_strlen($this->search) < self::BUSQUEDA_MINIMA) {
            return new SupportCollection;
        }

        if ($rows->count() >= HomeFeaturedProduct::MAX) {
            return new SupportCollection;
        }

        $chosenIds = $rows->pluck('product_id')->all();

        $query = Product::query()
            ->visible()
            ->with(['images'])
            ->alphabetically()
            ->limit(self::BUSQUEDA_LIMITE);

        if ($chosenIds !== []) {
            $query->whereNotIn('id', $chosenIds);
        }

        $pattern = '%'.addcslashes($this->search, '%_\\').'%';

        return $query
            ->where(function (Builder $q) use ($pattern): void {
                $q->whereRaw('LOWER(name) LIKE ?', [$pattern])
                    ->orWhereRaw('LOWER(reference) LIKE ?', [$pattern]);
            })
            ->get();
    }

    private function move(int $featuredId, int $offset): void
    {
        $featured = HomeFeaturedProduct::query()->findOrFail($featuredId);

        (new MoveHomeFeaturedProduct)($featured, $offset);
    }
}
