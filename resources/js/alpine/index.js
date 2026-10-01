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
import './admin-modal.js';
import './confirm-dialog.js';
import './page-enter.js';
