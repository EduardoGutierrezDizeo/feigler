<?php

namespace App\Support;

/**
 * Datos falsos con los que se previsualizan las tres vistas maquetadas de la
 * tienda (inicio, producto y sección) mientras la tienda no está conectada a la
 * base de datos.
 *
 * Cada método devuelve exactamente el arreglo que pide el contrato escrito en
 * la cabecera de su vista, así que si un método cambia, cambia también el
 * contrato que hay que cumplir al conectar los datos reales.
 *
 * Solo lo usan las rutas de `routes/storefront-preview.php`, que a su vez solo
 * se cargan en el entorno local.
 *
 * @phpstan-type card array{id: int, name: string, url: string, price: int, image: string|null, badge: string|null, colors: list<array{name: string, hex: string}>}
 */
class StorefrontPreview
{
    /**
     * Tallas de la categoría, en el orden en que se ofrecen.
     *
     * @var list<string>
     */
    private const SIZES = ['XS', 'S', 'M', 'L', 'XL', 'XXL', 'XXXL'];

    /**
     * Paleta de la colección: el mismo juego de colores para tarjetas, filtros
     * y variantes, para que la vista previa se vea coherente de punta a punta.
     *
     * @var list<array{name: string, hex: string}>
     */
    private const PALETTE = [
        ['name' => 'Verde oliva', 'hex' => '#6b7c3a'],
        ['name' => 'Terracota', 'hex' => '#a9552b'],
        ['name' => 'Tinta', 'hex' => '#1e1b18'],
        ['name' => 'Crudo', 'hex' => '#efe6d5'],
        ['name' => 'Latón', 'hex' => '#b8944a'],
        ['name' => 'Vino', 'hex' => '#6d2233'],
    ];

    /**
     * Stock de las variantes de producto, girado por color para que cada color
     * tenga un reparto distinto. Hay 0 (agotada) y 2 (pocas unidades) entre
     * los valores, que es lo que la vista necesita para mostrar los dos estados.
     *
     * @var list<int>
     */
    private const STOCKS = [8, 0, 2, 5, 1, 0, 4];

    /**
     * Contrato de resources/views/storefront/home.blade.php.
     *
     * @return array{
     *     sections: list<array{
     *         key: string,
     *         label: string,
     *         url: string,
     *         categories: list<array{name: string, count: int, image: string|null, url: string}>
     *     }>,
     *     newProducts: list<card>,
     *     store: array{hours: string, mapUrl: string, image: string|null},
     *     cartCount: int
     * }
     */
    public static function home(): array
    {
        return [
            'sections' => [
                ['key' => 'hombre', 'label' => 'Hombre', 'url' => '/hombre', 'categories' => [
                    self::category('hombre', 'Camisas', 12),
                    self::category('hombre', 'Pantalones', 9),
                    self::category('hombre', 'Chaquetas', 7),
                    self::category('hombre', 'Tejidos', 6),
                    self::category('hombre', 'Accesorios', 14),
                ]],
                ['key' => 'mujer', 'label' => 'Mujer', 'url' => '/mujer', 'categories' => [
                    self::category('mujer', 'Vestidos', 18),
                    self::category('mujer', 'Blusas', 11),
                    self::category('mujer', 'Faldas', 8),
                    self::category('mujer', 'Tejidos', 9),
                    self::category('mujer', 'Abrigos', 6),
                    self::category('mujer', 'Accesorios', 12),
                ]],
                ['key' => 'ninos', 'label' => 'Niños', 'url' => '/ninos', 'categories' => [
                    self::category('ninos', 'Camisas', 7),
                    self::category('ninos', 'Pantalones', 8),
                    self::category('ninos', 'Vestidos', 5),
                    self::category('ninos', 'Tejidos', 4),
                    self::category('ninos', 'Accesorios', 6),
                ]],
            ],
            'newProducts' => [
                self::card(101, 'Chaqueta Llanera', 189000, 'nuevo', 3),
                self::card(102, 'Vestido Selva', 142000, null, 4),
                self::card(103, 'Suéter Cordillera', 96000, 'agotado', 2),
                self::card(104, 'Pantalón Sabanero', 118000, 'nuevo', 5),
            ],
            'store' => [
                'hours' => 'Lunes a sábado de 10:00 a 19:00. Domingos cerrado.',
                'mapUrl' => 'https://maps.google.com/?q=Calle+2+Santander',
                'image' => null,
            ],
            'cartCount' => 2,
        ];
    }

    /**
     * Contrato de resources/views/storefront/product.blade.php.
     *
     * @return array{
     *     product: array{
     *         name: string,
     *         reference: string,
     *         section_label: string,
     *         category_label: string,
     *         price: int,
     *         summary: string|null,
     *         description: string|null,
     *         materials: list<array{name: string, percentage: int}>,
     *         colors: list<array{id: int, name: string, hex: string, images: list<array{url: string|null, thumb: string|null}>}>,
     *         sizes: list<string>,
     *         variants: list<array{id: int, color: int, size: string, stock: int}>,
     *         breadcrumb: list<array{label: string, url: string}>
     *     },
     *     related: array{title: string, products: list<card>},
     *     cartCount: int
     * }
     */
    public static function product(): array
    {
        $colors = [];

        foreach (self::PALETTE as $index => $swatch) {
            $colors[] = [
                'id' => $index + 1,
                'name' => $swatch['name'],
                'hex' => $swatch['hex'],
                'images' => array_fill(0, ($index % 4) + 1, ['url' => null, 'thumb' => null]),
            ];
        }

        $variants = [];

        foreach ($colors as $color) {
            foreach (self::SIZES as $index => $size) {
                $variants[] = [
                    'id' => ($color['id'] * 100) + $index,
                    'color' => $color['id'],
                    'size' => $size,
                    'stock' => self::STOCKS[($color['id'] + $index) % count(self::STOCKS)],
                ];
            }
        }

        return [
            'product' => [
                'name' => 'Vestido Cordillera',
                'reference' => 'REF-V-1042',
                'section_label' => 'Mujer',
                'category_label' => 'Vestidos',
                'price' => 118000,
                'summary' => 'Lana peinada con forro de algodón, cortado para los días fríos de la cordillera.',
                'description' => 'Un vestido de caída recta que abriga sin pesar. Se cose en taller propio con lana de la zona y forro de algodón peinado; la bolsa interior va cosida a mano.',
                'materials' => [
                    ['name' => 'Lana', 'percentage' => 70],
                    ['name' => 'Poliéster', 'percentage' => 25],
                    ['name' => 'Elastano', 'percentage' => 5],
                ],
                'colors' => $colors,
                'sizes' => self::SIZES,
                'variants' => $variants,
                'breadcrumb' => [
                    ['label' => 'Inicio', 'url' => '/'],
                    ['label' => 'Mujer', 'url' => '/mujer'],
                    ['label' => 'Vestido Cordillera', 'url' => '/mujer/vestido-cordillera'],
                ],
            ],
            'related' => [
                'title' => 'esta selección',
                'products' => [
                    self::card(201, 'Vestido Selva', 142000, 'nuevo', 3),
                    self::card(202, 'Blusa Crema', 68000, null, 2),
                    self::card(203, 'Falda Ladrillo', 89000, null, 4),
                    self::card(204, 'Suéter Tejido', 96000, 'agotado', 2),
                ],
            ],
            'cartCount' => 2,
        ];
    }

    /**
     * Contrato de resources/views/storefront/section.blade.php.
     *
     * @return array{
     *     section: array{key: string, label: string, url: string},
     *     total: int,
     *     shown: int,
     *     products: list<card>,
     *     nextUrl: string|null,
     *     clearUrl: string,
     *     sort: array{value: string, options: list<array{value: string, label: string}>},
     *     active: list<array{label: string, removeUrl: string}>,
     *     filters: array{
     *         categories: list<array{value: string, label: string, count: int, checked: bool}>,
     *         sizes: list<array{value: string, label: string, checked: bool}>,
     *         colors: list<array{value: string, label: string, hex: string, checked: bool}>,
     *         materials: list<array{value: string, label: string, count: int, checked: bool}>,
     *         price: array{min: int, max: int, from: int|null, to: int|null, step: int},
     *         stock: array{count: int, checked: bool}
     *     },
     *     cartCount: int
     * }
     */
    public static function section(): array
    {
        $colors = [];

        foreach (self::PALETTE as $index => $swatch) {
            $colors[] = [
                'value' => str()->slug($swatch['name']),
                'label' => $swatch['name'],
                'hex' => $swatch['hex'],
                'checked' => $index === 1,
            ];
        }

        $sizes = [];

        foreach (self::SIZES as $size) {
            $sizes[] = [
                'value' => $size,
                'label' => $size,
                'checked' => in_array($size, ['M', 'L'], true),
            ];
        }

        return [
            'section' => ['key' => 'mujer', 'label' => 'Mujer', 'url' => '/mujer'],
            'total' => 118,
            'shown' => 9,
            'products' => [
                self::card(301, 'Vestido Selva', 142000, 'nuevo', 3),
                self::card(302, 'Blusa Crema', 68000, null, 2),
                self::card(303, 'Falda Ladrillo', 89000, null, 4),
                self::card(304, 'Suéter Tejido', 96000, null, 2),
                self::card(305, 'Vestido Tinta', 118000, 'agotado', 3),
                self::card(306, 'Camisa Lino', 74000, 'nuevo', 4),
                self::card(307, 'Falda Arena', 62000, null, 2),
                self::card(308, 'Blusa Vino', 71000, null, 3),
                self::card(309, 'Abrigo Lana', 120000, null, 5),
            ],
            'nextUrl' => '/mujer?categoria%5B%5D=vestidos&categoria%5B%5D=faldas&talla%5B%5D=M&talla%5B%5D=L&stock=1&orden=novedades&page=2',
            'clearUrl' => '/mujer',
            'sort' => [
                'value' => 'novedades',
                'options' => [
                    ['value' => 'novedades', 'label' => 'Novedades'],
                    ['value' => 'relevancia', 'label' => 'Relevancia'],
                    ['value' => 'precio-asc', 'label' => 'Precio: menor a mayor'],
                    ['value' => 'precio-desc', 'label' => 'Precio: mayor a menor'],
                ],
            ],
            'active' => [
                ['label' => 'Vestidos', 'removeUrl' => '/mujer?categoria%5B%5D=faldas&stock=1'],
                ['label' => 'Talla M', 'removeUrl' => '/mujer?categoria%5B%5D=vestidos&categoria%5B%5D=faldas&talla%5B%5D=L&stock=1'],
                ['label' => 'Solo en stock', 'removeUrl' => '/mujer?categoria%5B%5D=vestidos&categoria%5B%5D=faldas'],
            ],
            'filters' => [
                'categories' => [
                    ['value' => 'vestidos', 'label' => 'Vestidos', 'count' => 32, 'checked' => true],
                    ['value' => 'blusas', 'label' => 'Blusas', 'count' => 28, 'checked' => false],
                    ['value' => 'faldas', 'label' => 'Faldas', 'count' => 19, 'checked' => true],
                    ['value' => 'tejidos', 'label' => 'Tejidos', 'count' => 21, 'checked' => false],
                    ['value' => 'abrigos', 'label' => 'Abrigos', 'count' => 18, 'checked' => false],
                ],
                'sizes' => $sizes,
                'colors' => $colors,
                'materials' => [
                    ['value' => 'algodon', 'label' => 'Algodón', 'count' => 41, 'checked' => true],
                    ['value' => 'lino', 'label' => 'Lino', 'count' => 22, 'checked' => false],
                    ['value' => 'lana', 'label' => 'Lana', 'count' => 16, 'checked' => true],
                    ['value' => 'sintetico', 'label' => 'Sintético', 'count' => 11, 'checked' => false],
                ],
                'price' => ['min' => 30000, 'max' => 120000, 'from' => null, 'to' => null, 'step' => 1000],
                'stock' => ['count' => 64, 'checked' => true],
            ],
            'cartCount' => 2,
        ];
    }

    /**
     * Categoría de la portada: sin imagen todavía, así la vista previa se ve
     * igual que en producción antes de subir las fotos.
     *
     * @return array{name: string, count: int, image: string|null, url: string}
     */
    private static function category(string $section, string $name, int $count): array
    {
        return [
            'name' => $name,
            'count' => $count,
            'image' => null,
            'url' => '/'.$section.'/'.str()->slug($name),
        ];
    }

    /**
     * Tarjeta de producto, con el número de colores y el distintivo que se
     * pidan para que la lista salga variada.
     *
     * @return array{
     *     id: int,
     *     name: string,
     *     url: string,
     *     price: int,
     *     image: string|null,
     *     badge: string|null,
     *     colors: list<array{name: string, hex: string}>
     * }
     */
    private static function card(int $id, string $name, int $price, ?string $badge, int $colorCount): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'url' => '/producto/'.$id,
            'price' => $price,
            'image' => null,
            'badge' => $badge,
            'colors' => array_slice(self::PALETTE, 0, $colorCount),
        ];
    }
}
