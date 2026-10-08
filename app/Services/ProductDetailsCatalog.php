<?php

namespace App\Services;

use App\Enums\StoreSection;
use App\Exceptions\InactiveProductMaterialException;
use App\Exceptions\InactiveVariantColorException;
use App\Exceptions\InactiveVariantSizeException;
use App\Exceptions\UnknownColorException;
use App\Exceptions\UnknownMaterialException;
use App\Exceptions\UnknownSizeException;
use App\Models\Category;
use App\Models\Color;
use App\Models\Material;
use App\Models\Size;
use App\Services\Storefront\ListingScope;
use App\Support\Concerns\NormalizesNames;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * The one place that turns the names a person writes into the rows of the catalog, and
 * that says which options a shopper can be offered.
 *
 * It exists because three of those lists were being read in three different ways by the
 * people who write them. The panel offers a `<select>` of what the database holds, so a
 * name typed there cannot be wrong; a spreadsheet has one cell per material with a
 * person behind the keyboard, so `algodon`, `Algodón ` and `ALGODÓN` are all the same
 * material and all three have to land on the same row. This service is the reading half
 * of that: it resolves the names, and it decides which names are worth offering as a
 * filter at all.
 *
 * Nothing here writes. A resolved material, color or size is handed back for the caller
 * to use, and a composition is handed back as the list `SyncProductMaterials` takes,
 * with the percentages still as text so that the action that writes them stays the one
 * that says whether a composition adds up.
 *
 * **On names.** The comparison is the one `NormalizesNames` already holds for the
 * panel: lowercased, without accents and without the spaces that were typed twice. It
 * is a shared criterion rather than a new one, so `ÚNICA` in the panel and `unica` in a
 * file are the same size, and the comparison happens in PHP because MySQL ignores case
 * in its collations and SQLite — which is what the tests run on — does not, so leaving
 * it to the database would make the rule hold on one driver and not on the other.
 *
 * A name is cleaned before it is keyed, never after: `nameKey()` folds case and accents
 * and nothing else, so the double space of `Poliéster  Pettal` survives it and would
 * stop the name from finding the row that is stored as `Poliéster Pettal`.
 *
 * **On which products count.** A filter may only offer what somebody can actually be
 * shown, so the four lists at the end are held back by a single definition of a visible
 * product, written once as `variantsOfAVisibleProduct()`. Stock is not part of it: a
 * size that ran out is still a size the store sells, and hiding it would tell the
 * shopper the store has stopped selling it. Whether an option without stock should be
 * offered anyway is a decision about the shop, not about the catalog.
 */
class ProductDetailsCatalog
{
    use NormalizesNames;

    /**
     * The size of a category under the name that was written.
     *
     * Only the category asked about is looked at, because a size only means something
     * inside its category: a `42` the trousers carry is not a size of the shirts, and
     * answering with it would be answering about a garment nobody asked for.
     *
     * The two failures are kept apart. A name the category does not carry is a typo or
     * a size the store has to add, and it says so; a name the category carries but has
     * turned off is a size that is there and only needs turning back on, which is what
     * `InactiveVariantSizeException` has been saying for the panel all along.
     *
     * @param  bool  $onlyActive  Whether a size that is turned off counts as found.
     */
    public function resolveSize(Category $category, string $name, bool $onlyActive = true): Size
    {
        $written = $this->cleanName($name);
        $sizes = Size::listedForCategory($category->getKey());

        $match = $this->firstNamed($sizes, $this->nameKey($written));

        if (! $match instanceof Size) {
            throw UnknownSizeException::forSize(
                $written,
                $category,
                $sizes
                    ->filter(fn (Size $size): bool => $size->is_active)
                    ->map(fn (Size $size): string => $size->name)
                    ->values()
                    ->all(),
            );
        }

        if ($onlyActive && ! $match->is_active) {
            throw InactiveVariantSizeException::forSize($match);
        }

        return $match;
    }

    /**
     * The color of the store under the name that was written.
     *
     * @param  bool  $onlyActive  Whether a color that is turned off counts as found.
     */
    public function resolveColor(string $name, bool $onlyActive = true): Color
    {
        $written = $this->cleanName($name);
        $match = $this->firstNamed(Color::listed(), $this->nameKey($written));

        if (! $match instanceof Color) {
            throw UnknownColorException::forColor($written);
        }

        if ($onlyActive && ! $match->is_active) {
            throw InactiveVariantColorException::forColor($match);
        }

        return $match;
    }

    /**
     * The material of the store under the name that was written.
     *
     * @param  bool  $onlyActive  Whether a material that is turned off counts as found.
     */
    public function resolveMaterial(string $name, bool $onlyActive = true): Material
    {
        $written = $this->cleanName($name);
        $match = $this->firstNamed(Material::listed(), $this->nameKey($written));

        if (! $match instanceof Material) {
            throw UnknownMaterialException::forMaterial($written);
        }

        if ($onlyActive && ! $match->is_active) {
            throw InactiveProductMaterialException::forMaterial($match);
        }

        return $match;
    }

    /**
     * Read a composition of materials out of the text of a cell.
     *
     * The text is what a person types into a spreadsheet, so it is read the way a person
     * writes it: `Algodón 80 | Poliéster 20`, `Algodón 80% / Poliéster 20%`, and
     * `Algodón` on its own. Materials are separated by `|` or `/` and not by a comma,
     * because a comma is what a CSV in Spanish uses to close a field and would be read
     * as part of it. A material with no number is the whole garment, and separators
     * left hanging at either end are ignored instead of being an empty element that has
     * to be explained.
     *
     * **What this does not do.** It does not check that the composition adds up, that a
     * material is not listed twice, or that a share is a whole number. The percentages
     * come back as the text that was written for exactly that reason: `SyncProductMaterials`
     * is where those rules live, and a cell that says `50,5` has to arrive there as
     * `50,5` so the message names the value that is wrong. A material named twice comes
     * back as two lines, and the action refuses the composition as the repeat it is.
     *
     * The materials are resolved as active ones and only active ones: a composition is
     * being written now, and a material the store has turned off cannot go into a new
     * one. Both refusals name the material, so a cell of ten materials says which of the
     * ten is the one to fix.
     *
     * @return list<array{id: int, percentage: string}> The percentages are uncast text, for the action to judge.
     */
    public function resolveComposition(string $text): array
    {
        $elements = preg_split('/\s*[|\/]\s*/u', $text) ?: [];

        $composition = [];

        foreach ($elements as $element) {
            $element = $this->cleanName($element);

            if ($element === '') {
                continue;
            }

            [$name, $percentage] = $this->splitShare($element);

            $composition[] = [
                'id' => $this->resolveMaterial($name)->getKey(),
                'percentage' => $percentage,
            ];
        }

        return $composition;
    }

    /**
     * The sizes of a category a shopper can be offered, as a filter inside it.
     *
     * A size only reaches the list when a garment in the category is actually sold in it,
     * so a category does not offer a `XXL` that nothing in the store carries.
     *
     * @return Collection<int, Size>
     */
    public function sizesForFilter(Category $category): Collection
    {
        return Size::query()
            ->active()
            ->where('category_id', $category->getKey())
            ->whereHas('variants', $this->variantsOfAVisibleProduct())
            ->ordered()
            ->get();
    }

    /**
     * The sizes a shopper can be offered without naming a category, gathered by name.
     *
     * Every category has its own rows, so the store has three `M` that mean the same
     * thing to whoever is shopping. They come back as one entry carrying the ids of all
     * of them, which is what lets a global filter on `M` reach the `M` of the trousers
     * as well as the `M` of the shirts.
     *
     * **How the groups are ordered.** By the `order` of the smallest of their sizes,
     * and then by name. The sizes arrive already sorted by `order` and then by id, so
     * the first one a group sees is its smallest, and that is both the position the
     * group takes and the name it is shown under: a category that spells it `ÚNICA` and
     * another that spells it `Unica` produce one group, labelled with whichever came
     * first in catalog order.
     *
     * The section narrows the list to one part of the store and is not required, because
     * a catalog that shows every size is a legitimate thing to want. A scope narrows it
     * to the sections it covers, which is how a listing of several sections asks.
     *
     * @param  StoreSection|ListingScope|null  $section  One section, the sections of a scope, or the whole store.
     * @return Collection<int, array{name: string, ids: list<int>}>
     */
    public function sizeNamesForFilter(StoreSection|ListingScope|null $section = null): Collection
    {
        $sizes = Size::query()
            ->active()
            ->whereHas('variants', $this->variantsOfAVisibleProduct($section))
            ->ordered()
            ->get();

        return new Collection($this->groupSizesByName($sizes));
    }

    /**
     * The colors a shopper can be offered, whole store or one section.
     *
     * The section narrows the list to the garments a catalog of that section would
     * show, and is not required because a catalog that offers every color is a
     * legitimate thing to want. A scope narrows it to the sections it covers.
     *
     * @param  StoreSection|ListingScope|null  $section  One section, the sections of a scope, or the whole store.
     * @return Collection<int, Color>
     */
    public function colorsForFilter(StoreSection|ListingScope|null $section = null): Collection
    {
        return Color::query()
            ->active()
            ->whereHas('variants', $this->variantsOfAVisibleProduct($section))
            ->ordered()
            ->get();
    }

    /**
     * The materials a shopper can be offered, whole store or one section.
     *
     * A garment is made of a material whether or not it has been given a variant, so
     * unlike the sizes and the colors there is no variant in the middle of this one: a
     * garment that is in the catalog with its composition says the material is used.
     *
     * A material of a section is one that a visible garment of an active category of
     * that section is made of, which is the same definition the section listing uses;
     * a scope asks the same for the sections it covers.
     *
     * @param  StoreSection|ListingScope|null  $section  One section, the sections of a scope, or the whole store.
     * @return Collection<int, Material>
     */
    public function materialsForFilter(StoreSection|ListingScope|null $section = null): Collection
    {
        return Material::query()
            ->active()
            ->whereHas('products', fn (Builder $products): Builder => $this->visibleGarment($section, $products))
            ->ordered()
            ->get();
    }

    /**
     * The variants that put a size or a color in a garment a shopper can be shown.
     *
     * A product is visible when it is not turned off, which is the first thing
     * `Product::displayStatus()` decides: `inactive` is read off the stored status, and
     * the other three states are worked out from the variants and their stock. The check
     * stops there on purpose. `no_variants`, `out_of_stock` and `active` all mean the
     * product is in the catalog, and deciding that a size has to be hidden because its
     * units ran out is a question about the shop rather than about the catalog.
     *
     * The section — or the sections a scope covers — narrows the garment to the catalog
     * it lists, and such a garment has to sit in a category that is on: turning a
     * category off hides its garments from the listing, so an option they alone carry
     * is not offered.
     *
     * It is written once and passed to the three queries that need it, so the definition
     * of a visible product cannot drift apart between the sizes and the colors.
     *
     * @return Closure(Builder<\App\Models\ProductVariant>): Builder<\App\Models\ProductVariant>>
     */
    private function variantsOfAVisibleProduct(StoreSection|ListingScope|null $section = null): Closure
    {
        return function (Builder $variants) use ($section): Builder {
            $variants
                ->where('is_active', true)
                ->whereHas('product', fn (Builder $products): Builder => $this->visibleGarment($section, $products));

            return $variants;
        };
    }

    /**
     * One garment a filter list may count: turned on, and — when a section or a scope
     * narrows the store — inside an active category of it.
     *
     * A section is read as the scope that covers it alone, so `ListingScope::applyTo()`
     * is the one place that says what a garment of a section (or of several sections)
     * is, and these lists cannot drift apart from the listing itself.
     *
     * @param  Builder<Product>  $products
     * @return Builder<Product>
     */
    private function visibleGarment(StoreSection|ListingScope|null $section, Builder $products): Builder
    {
        if ($section === null) {
            return $products->where('status', '!=', 'inactive');
        }

        return ($section instanceof ListingScope ? $section : ListingScope::section($section))->applyTo($products);
    }

    /**
     * The first row of a list whose name is the one being looked for.
     *
     * The lists a name is looked for in are the sizes of one category or the colors and
     * the materials of the whole store, which are small enough to bring in and compare
     * here rather than leave to the collation of the driver.
     *
     * @template TModel of Model
     *
     * @param  Collection<int, TModel>  $candidates
     * @return TModel|null
     */
    private function firstNamed(Collection $candidates, string $key): ?Model
    {
        foreach ($candidates as $candidate) {
            if ($this->nameKey((string) $candidate->getAttribute('name')) === $key) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Split one element of a composition into the name of the material and its share.
     *
     * The share is whatever was written after the name, whole or not, and the name is
     * everything before it, so a material whose name has a space in it — `Poliéster
     * Peinado` — is not cut in half. An element of one word is a material with no
     * number, which is the whole garment and not a name ending in digits.
     *
     * The percent sign is taken off before the split, so `80%` and `80 %` are the same
     * thing, and nothing is cast: a `50,5` reaches the action as the `50,5` that was
     * written, for the message to name.
     *
     * The name is cleaned again after the sign is off, because taking it out of `80 %`
     * leaves a space where the sign was, and that space would become the last word of the
     * element and swallow the share into the name.
     *
     * @return array{0: string, 1: string}
     */
    private function splitShare(string $element): array
    {
        $words = explode(' ', $this->cleanName(str_replace('%', '', $element)));

        if (count($words) === 1) {
            return [$words[0], '100'];
        }

        $share = array_pop($words);

        return [implode(' ', $words), $share];
    }

    /**
     * Bring a list of sizes together by the name they are written the same.
     *
     * The sizes have to arrive active and sorted by `order` and then by id, which is
     * what makes the first one a group sees its smallest.
     *
     * @param  Collection<int, Size>  $sizes
     * @return list<array{name: string, ids: list<int>}>
     */
    private function groupSizesByName(Collection $sizes): array
    {
        /** @var array<string, array{name: string, ids: list<int>, order: int}> $groups */
        $groups = [];

        foreach ($sizes as $size) {
            $key = $this->nameKey($size->name);

            if (! isset($groups[$key])) {
                $groups[$key] = ['name' => $size->name, 'ids' => [], 'order' => $size->order];
            }

            $groups[$key]['ids'][] = $size->getKey();
        }

        usort($groups, fn (array $a, array $b): int => [$a['order'], $a['name']] <=> [$b['order'], $b['name']]);

        return array_map(
            fn (array $group): array => ['name' => $group['name'], 'ids' => $group['ids']],
            $groups,
        );
    }
}
