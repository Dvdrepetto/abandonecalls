(function($) {

    var AbandonedPlugin = {

        abandonedWidgetContainer: null,
        recoveredWidgetContainer: null,
        currentUser: 'unknown',

	        start: function() {
            // Asignamos los contenedores primero
            this.abandonedWidgetContainer = $('#abandonedcalls');
            this.recoveredWidgetContainer = $('#recoveredcalls');

            // Verificamos si existen
            if (this.abandonedWidgetContainer.length === 0) console.warn("Widget #abandonedcalls no encontrado.");
            if (this.recoveredWidgetContainer.length === 0) console.warn("Widget #recoveredcalls no encontrado.");

            // Llamamos a la función que se encargará de todo lo demás
            this.initializeUserAndPlugins();
        },

        // --- NUEVA FUNCIÓN DE INICIALIZACIÓN ---
        initializeUserAndPlugins: function() {
            var self = this;

            // Hacemos la llamada para saber quién es el agente
            $.ajax({
                url: 'plugins/abandonedcalls/whoami.php',
                type: 'GET',
                dataType: 'json',
                cache: false,
                success: function(data) {
                    // --- ESTE BLOQUE SOLO SE EJECUTA CUANDO TENEMOS LA RESPUESTA ---
                    if (data && data.agent && data.agent !== 'unknown') {
                        self.currentUser = data.agent;
                        console.log(`Plugin Iniciado. Agente verificado desde servidor: ${self.currentUser}`);
                    } else {
                        self.currentUser = 'unknown_session';
                        console.warn("No se pudo verificar el agente desde la sesión del servidor.");
                    }

                    // AHORA que sabemos quién es el usuario, iniciamos el resto.
                    self.setupEventHandlers();
                    self.startRefreshTimers();
                    
                    // Hacemos un refresco inicial
                    self.refreshAbandonedWidget();
                    self.refreshRecoveredWidget();
                },
                error: function() {
                    console.error("Fallo CRÍTICO en la llamada a whoami.php. El plugin no funcionará correctamente.");
                    self.currentUser = 'unknown_ajax_fail';
                }
            });
        },

        // --- NUEVA FUNCIÓN PARA LOS MANEJADORES DE EVENTOS ---
        setupEventHandlers: function() {
            var self = this;

            $(document).on('click', '.btn-recover-abandoned', (e) => {
                e.preventDefault();
                var uniqueid = $(e.currentTarget).data('uniqueid');
                // Ahora this.currentUser SIEMPRE tiene el valor correcto
                self.recover(uniqueid, self.currentUser);

            });
            $(document).on('click', '.btn-finalize-abandoned', (e) => {
                e.preventDefault();
                var uniqueid = $(e.currentTarget).data('uniqueid');
                self.finalize(uniqueid);
            });
            $(document).on('click', '.btn-copy-number', function(e) {
                e.preventDefault();
                var dialString = $(this).data('dial-string');
                navigator.clipboard.writeText(dialString).then(() => {
                    $(this).fadeOut(100).fadeIn(100);
                });
            });
        },

        // --- NUEVA FUNCIÓN PARA LOS TEMPORIZADORES ---
        startRefreshTimers: function() {
            setInterval(() => this.refreshAbandonedWidget(), 10000);
            setInterval(() => this.refreshRecoveredWidget(), 15000);
        },

        refreshAbandonedWidget: function() {
            if (this.abandonedWidgetContainer.length === 0) return;
            $.ajax({
                url: 'plugins/abandonedcalls/get_abandoned.php',
                success: (data) => {
                    var html = this.generateAbandonedTableHtml(data);
                    this.abandonedWidgetContainer.html(html);
                }
            });
        },

        refreshRecoveredWidget: function() {
            if (this.recoveredWidgetContainer.length === 0) return;
            $.ajax({
                url: 'plugins/abandonedcalls/get_recovered.php',
                success: (data) => {
                    var html = this.generateRecoveredTableHtml(data);
                    this.recoveredWidgetContainer.html(html);
                }
            });
        },

        // --- ESTA ES LA FUNCIÓN CLAVE A REVISAR ---
        generateAbandonedTableHtml: function(data) {
            if (!data || data.length === 0) return '<p style="text-align:center; padding: 15px;">No hay llamadas pendientes.</p>';
            var header = '<th>Llamante</th><th>Cola</th><th>Info</th><th>Acciones/Agente</th>';
            let table = `<table class="table table-striped table-condensed"><thead><tr>${header}</tr></thead><tbody>`;
            
            data.forEach(call => {
                // Usamos el nombre del contacto si existe, si no, el número
                let from_display = call.contact_name || call.from;
                
                // Creamos el bloque de información con los nuevos datos
                let info_block = `<small>Hora: ${new Date(call.time * 1000).toLocaleTimeString('es-ES', { timeZone: 'Europe/Madrid' })}<br>Espera: <b>${call.wait_time}s</b> (Pos: ${call.abandon_position})</small>`;
                
                let actions_block = '';
                let row_class = '';

                if (call.status === 'processing') {
                    row_class = 'info'; // Pinta la fila de azul
                    if (call.agent_id == this.currentUser) {
                        actions_block = `<button class="btn btn-primary btn-xs btn-finalize-abandoned" data-uniqueid="${call.uniqueid}" title="Finalizar gestión"><i class="fa fa-flag-checkered"></i> Finalizar</button>`;
                    } else {
                        actions_block = `<span class="label label-default"><i class="fa fa-user-circle"></i> ${call.agent_id}</span>`;
                    }
                } else { // status === 'abandoned'
                    actions_block = `<div class="btn-group"><button class="btn btn-success btn-xs btn-recover-abandoned" data-uniqueid="${call.uniqueid}" title="Tomar llamada"><i class="fa fa-check"></i></button><button class="btn btn-info btn-xs btn-copy-number" data-dial-string="${call.dial_string}" title="Copiar: ${call.dial_string}"><i class="fa fa-clipboard"></i></button></div>`;
                }
                
                table += `<tr class="${row_class}"><td>${from_display}</td><td>${call.queue}</td><td>${info_block}</td><td>${actions_block}</td></tr>`;
            });
            table += '</tbody></table>';
            return table;
        },

        generateRecoveredTableHtml: function(data) {
             if (!data || data.length === 0) return '<p style="text-align:center; padding: 15px;">No hay llamadas gestionadas.</p>';
            var header = '<th>Llamante</th><th>Agente</th><th>Hora Gestión</th><th>Acción</th>';
            let table = `<table class="table table-striped table-condensed"><thead><tr>${header}</tr></thead><tbody>`;
            data.forEach(call => {
                let from_display = call.contact_name || call.from;
                let recovery_time_only = call.recovery_time ? call.recovery_time.split(' ')[1] : '';
                let copy_button = `<button class="btn btn-default btn-xs btn-copy-number" data-dial-string="${call.dial_string}" title="Copiar: ${call.dial_string}"><i class="fa fa-clipboard"></i></button>`;
                table += `<tr><td>${from_display}</td><td>${call.agent_id}</td><td>${recovery_time_only}</td><td>${copy_button}</td></tr>`;
            });
            table += '</tbody></table>';
            return table;
        },

        recover: function(uniqueid, agentId) {
            var row = $(`.btn-recover-abandoned[data-uniqueid="${uniqueid}"]`).closest('tr');
            $.ajax({
                url: 'plugins/abandonedcalls/recover.php',
                type: 'GET',
                data: { uniqueid: uniqueid, agent: agentId },
                success: (response) => {
                    if (response === 'ALREADY_TAKEN') {
                        alert('Esta llamada ya ha sido tomada por otro agente.');
                        this.refreshAbandonedWidget();
                        return;
                    }
                    row.addClass('info');
                    var actionCell = row.find('td').last();
                    var newActionHtml = `<button class="btn btn-primary btn-xs btn-finalize-abandoned" data-uniqueid="${uniqueid}" title="Finalizar gestión"><i class="fa fa-flag-checkered"></i> Finalizar</button>`;
                    actionCell.html(newActionHtml);
                }
            });
        },

        finalize: function(uniqueid) {
            $.ajax({
                url: 'plugins/abandonedcalls/finalize.php',
                type: 'GET',
                data: { uniqueid: uniqueid },
                success: () => {
                    this.refreshAbandonedWidget();
                    this.refreshRecoveredWidget();
                }
            });
        }
    };

    $(document).ready(function() {
        AbandonedPlugin.start();
    });

})(jQuery);