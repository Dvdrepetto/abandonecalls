(function ($) {

    var AbandonedPlugin = {
        abandonedWidgetContainer: null,
        recoveredWidgetContainer: null,
        currentUser: 'unknown',

        start: function () {
            // Asigna los contenedores de los DOS widgets basándose en sus IDs
            this.abandonedWidgetContainer = $('#abandonedcalls');
            this.recoveredWidgetContainer = $('#recoveredcalls');

            // Verifica si los contenedores existen en la página
            if (this.abandonedWidgetContainer.length === 0) console.warn("Widget de abandonadas (#abandonedcalls) no encontrado.");
            if (this.recoveredWidgetContainer.length === 0) console.warn("Widget de recuperadas (#recoveredcalls) no encontrado.");

            // Obtiene el agente actual de FOP2
            if (typeof fop2 !== 'undefined' && fop2.MYEXTEN) {
                this.currentUser = fop2.MYEXTEN;
            }
            console.log(`Plugin de 2 Widgets Iniciado. Agente: ${this.currentUser}`);

            // Refresca ambos widgets al iniciar la página
            this.refreshAbandonedWidget();
            this.refreshRecoveredWidget();

            // Configura un refresco periódico e independiente para cada widget
            setInterval(() => this.refreshAbandonedWidget(), 10000); // Abandonadas cada 10 seg
            setInterval(() => this.refreshRecoveredWidget(), 15000); // Recuperadas cada 15 seg

            // Manejador para el botón "Visto"
            $(document).on('click', '.btn-recover-abandoned', (e) => {
                e.preventDefault();
                var uniqueid = $(e.currentTarget).data('uniqueid');
                this.recover(uniqueid, this.currentUser);
            });
        },

        // Pide los datos de abandonadas y los renderiza en su widget
        refreshAbandonedWidget: function () {
            if (this.abandonedWidgetContainer.length === 0) return;
            $.ajax({
                url: 'plugins/abandonedcalls/get_abandoned.php',
                type: 'GET',
                dataType: 'json',
                cache: false,
                success: (data) => {
                    var html = this.generateAbandonedTableHtml(data);
                    this.abandonedWidgetContainer.html(html);
                }
            });
        },

        // Pide los datos de recuperadas y los renderiza en su widget
        refreshRecoveredWidget: function () {
            if (this.recoveredWidgetContainer.length === 0) return;
            $.ajax({
                url: 'plugins/abandonedcalls/get_recovered.php',
                type: 'GET',
                dataType: 'json',
                cache: false,
                success: (data) => {
                    var html = this.generateRecoveredTableHtml(data);
                    this.recoveredWidgetContainer.html(html);
                }
            });
        },

        // Genera el HTML para la tabla de abandonadas
        generateAbandonedTableHtml: function (data) {
            if (!data || data.length === 0) return '<p style="text-align:center; padding: 15px;">No hay llamadas abandonadas.</p>';
            var header = '<th>Llamante</th><th>Cola</th><th>Hora</th><th>Acción</th>';
            let table = `<table class="table table-striped table-condensed"><thead><tr>${header}</tr></thead><tbody>`;
            const optionsTime = { timeZone: 'Europe/Madrid', hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false };
            data.forEach(call => {
                let button = `<button class="btn btn-success btn-xs btn-recover-abandoned" data-uniqueid="${call.uniqueid}" title="Marcar como Gestionada"><i class="fa fa-check"></i></button>`;
                let from_display = call.from;
                let queue_name = call.queue;
                let call_time_only = new Date(call.time * 1000).toLocaleString('es-ES', optionsTime);
                table += `<tr><td>${from_display}</td><td>${queue_name}</td><td>${call_time_only}</td><td>${button}</td></tr>`;
            });
            table += '</tbody></table>';
            return table;
        },

        // Genera el HTML para la tabla de recuperadas
        generateRecoveredTableHtml: function (data) {
            if (!data || data.length === 0) return '<p style="text-align:center; padding: 15px;">No hay llamadas gestionadas.</p>';
            var header = '<th>Llamante</th><th>Agente</th><th>Hora Gestión</th>';
            let table = `<table class="table table-striped table-condensed"><thead><tr>${header}</tr></thead><tbody>`;
            data.forEach(call => {
                let from_display = call.contact_name || call.from;
                // Extraemos solo la hora de la fecha de recuperación que viene del PHP
                let recovery_time_only = (call.recovery_time && call.recovery_time.split(' ')[1]) ? call.recovery_time.split(' ')[1] : '';
                table += `<tr><td>${from_display}</td><td>${call.agent_id}</td><td>${recovery_time_only}</td></tr>`;
            });
            table += '</tbody></table>';
            return table;
        },

        // Marca una llamada como recuperada y fuerza el refresco de ambos widgets
        recover: function (uniqueid, agentId) {
            $.ajax({
                url: 'plugins/abandonedcalls/recover.php',
                type: 'GET',
                data: { uniqueid: uniqueid, agent: agentId },
                success: () => {
                    console.log(`Llamada ${uniqueid} asignada a ${agentId}. Refrescando widgets...`);
                    this.refreshAbandonedWidget();
                    this.refreshRecoveredWidget();
                }
            });
        }
    };

    $(document).ready(function () { AbandonedPlugin.start(); });
})(jQuery);