(function ($) {

    var AbandonedPlugin = {
        widgetContainer: null,
        modalContainer: null,

        start: function () {
            this.widgetContainer = $('#abandonedcalls');
            this.modalContainer = $('#abandonedCallsContainer'); // Para el modal grande

            if (this.widgetContainer.length === 0) {
                console.log("Widget de llamadas abandonadas no encontrado. Abortando.");
                return;
            }

            console.log("Plugin de Llamadas Abandonadas Iniciado.");

            this.refreshWidget();

            setInterval(() => this.refreshWidget(), 5000);

            $('#abandonedCallsModal').on('show.bs.modal', () => {
                this.refreshModal();
            });

            $(document).on('click', '.btn-recover-abandoned', function (e) {
                e.preventDefault();
                var uniqueid = $(this).data('uniqueid');
                AbandonedPlugin.recover(uniqueid);
            });
        },

        refreshWidget: function () {
            this.fetchAndRender(this.widgetContainer, false);
        },

        refreshModal: function () {
            this.fetchAndRender(this.modalContainer, true);
        },

        fetchAndRender: function (targetContainer, isModal) {
            $.ajax({
                url: 'plugins/abandonedcalls/get_abandoned.php',
                type: 'GET',
                dataType: 'json',
                cache: false,
                success: (data) => {
                    var html = this.generateTableHtml(data, isModal);
                    targetContainer.html(html);
                },
                error: (jqXHR, textStatus, errorThrown) => {
                    console.error('Error cargando llamadas abandonadas:', textStatus, errorThrown);
                    targetContainer.html('<div class="alert alert-warning">Error al cargar los datos.</div>');
                }
            });
        },

        // Función para generar el HTML de la tabla.
        generateTableHtml: function (data, isModal) {
            if (data.length === 0) {
                return '<p style="text-align:center; padding: 15px;">No hay llamadas abandonadas.</p>';
            }

            var header = isModal ? '<th>Desde</th><th>Cola</th><th>Fecha</th><th>Acción</th>' : '<th>Desde</th><th>Cola</th><th>Hora</th><th>Acción</th>';

            const options = {
                timeZone: 'Europe/Madrid',
                year: 'numeric',
                month: '2-digit',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit',
                hour12: false
            };

            let table = `<table class="table table-striped table-condensed"><thead><tr>${header}</tr></thead><tbody>`;
            data.forEach(call => {
                var button = `<button class="btn btn-success btn-xs btn-recover-abandoned" data-uniqueid="${call.uniqueid}"><i class="fa fa-check"></i> Visto</button>`;

                var from_number = call.from;
                var queue_name = call.queue;

                const callDate = new Date(call.time * 1000);
                var call_datetime = callDate.toLocaleString('es-ES', options);

                if (isModal) {
                    table += `<tr><td>${from_number}</td><td>${queue_name}</td><td>${call_datetime}</td><td>${button}</td></tr>`;
                }
                else {
                    // Extraemos la parte de la hora.
                    var call_time_only = call_datetime.split(', ')[1];
                    table += `<tr><td>${from_number}</td><td>${queue_name}</td><td>${call_time_only}</td><td>${button}</td></tr>`;
                }
            });
            table += '</tbody></table>';
            return table;
        },

        // Función para marcar una llamada como vista.
        recover: function (uniqueid) {
            $.ajax({
                url: 'plugins/abandonedcalls/recover.php',
                type: 'GET',
                data: { uniqueid: uniqueid },
                success: () => {
                    console.log(`Llamada ${uniqueid} marcada como vista.`);
                    this.refreshWidget();
                    if ($('#abandonedCallsModal').is(':visible')) {
                        this.refreshModal();
                    }
                }
            });
        }
    };

    // La forma correcta de iniciar nuestro plugin en este entorno.
    // Se ejecuta cuando el DOM está listo y jQuery está disponible.
    $(document).ready(function () {
        AbandonedPlugin.start();
    });

})(jQuery);