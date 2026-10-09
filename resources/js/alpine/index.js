/**
 * Barrel de componentes Alpine.data del proyecto.
 *
 * Aquí se registra todo componente `Alpine.data(...)` del proyecto, y este
 * archivo lo importan los dos bundles de entrada que declara vite.config.js:
 *
 *     resources/js/app.js    → welcome, layouts/guest, layouts/app
 *     resources/js/admin.js  → layouts/admin (todo /admin/* y /staff)
 *
 * Importar el barrel desde ambos bundles garantiza, por construcción, que un
 * componente Alpine nuevo esté disponible en toda la aplicación sin depender de
 * recordar en cuál bundle agregarlo. El fallo anterior —registrar
 * `confirmDialog` solo en app.js, que layouts/admin no carga— hacía que
 * `x-data="confirmDialog"` no encontrara su proveedor y Alpine resolviera
 * `x-show="open"` contra el global `window.open`, abriendo el diálogo al
 * cargar. tests/Unit/AlpineComponentRegistrationTest.php cubre esa clase de
 * error.
 *
 * Todos los módulos usan el listener `alpine:init`, que es idempotente:
 * cargarlo dos veces en la misma página no duplica nada.
 */

// Plugins de directivas de Alpine.
import './plugins.js';

// Componentes Alpine.data.
import accessModal from './access-modal.js';
import addressModal from './address-modal.js';
import './admin-modal.js';
import categoryCarousel from './category-carousel.js';
import './color-hex-input.js';
import './confirm-dialog.js';
import './home-image-upload-preview.js';
import loadMore from './load-more.js';
import './page-enter.js';
import './price-input.js';
import priceRange from './price-range.js';
import productPurchase from './product-purchase.js';
import './select-input.js';

// La tienda pública: los módulos solo exportan la fábrica
// (`export default function categoryCarousel(config)`), así que aquí es donde
// se registran, con el mismo patrón que el resto y dentro de `alpine:init`. Los
// que NO exportan la fábrica (admin-modal, confirm-dialog...) se registran ellos
// mismos con un listener propio, por eso se importan sin nombre para que sus
// efectos ocurran.
document.addEventListener('alpine:init', () => {
    Alpine.data('accessModal', accessModal);
    Alpine.data('addressModal', addressModal);
    Alpine.data('categoryCarousel', categoryCarousel);
    Alpine.data('loadMore', loadMore);
    Alpine.data('priceRange', priceRange);
    Alpine.data('productPurchase', productPurchase);
});
