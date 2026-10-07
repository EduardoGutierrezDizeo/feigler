/**
 * Componente Alpine de la vista previa de la foto de portada de una categoría.
 *
 * Se registra vía `Alpine.data('homeImageUploadPreview', ...)` desde el barrel
 * `resources/js/alpine/index.js` y se usa en Blade como
 * `x-data="homeImageUploadPreview($wire, 'imageUpload')"`, así que el proveedor
 * recibe el proxy `$wire` y el nombre de la propiedad que guarda el archivo en
 * el componente Livewire.
 *
 * La vista previa es un `URL.createObjectURL` del archivo elegido que se destruye
 * cuando la foto deja de estar pendiente: cuando el administrador elige otro
 * archivo, cuando se guarda (el componente vacía `imageUpload`) o cuando se
 * cancela. Si el objeto no se revocara, cada previsualización filtraría una
 * copia del archivo en la memoria del navegador.
 */
document.addEventListener('alpine:init', () => {
    Alpine.data('homeImageUploadPreview', (wire, property) => ({
        previewUrl: null,
        objectUrl: null,

        init() {
            this.$watch('previewUrl', (value) => {
                if (value === null && this.objectUrl) {
                    URL.revokeObjectURL(this.objectUrl)
                    this.objectUrl = null
                }
            })

            // Cuando el servidor borra el archivo (guardado o cancelado), la
            // vista previa vuelve a lo que la categoría ya mostraba.
            wire.$watch(property, (value) => {
                if (value === null) {
                    this.previewUrl = null
                }
            })
        },

        preview(input) {
            const file = input.files?.[0]

            if (this.objectUrl) {
                URL.revokeObjectURL(this.objectUrl)
                this.objectUrl = null
            }

            if (!file) {
                this.previewUrl = null

                return
            }

            this.objectUrl = URL.createObjectURL(file)
            this.previewUrl = this.objectUrl
        },
    }))
})