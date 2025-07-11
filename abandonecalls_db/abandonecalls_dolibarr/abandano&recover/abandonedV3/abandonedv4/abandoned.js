(function($) {

    var AbandonedPlugin = {
        
        abandonedWidgetContainer: null,
        recoveredWidgetContainer: null,
        currentUser: 'unknown',
        isInitialized: false,

        start: function() {
            this.abandonedWidgetContainer = $('#abandonedcalls');
            this.recoveredWidgetContainer = $('#recoveredcalls');
            this.initializeAndFetchAgent();
        },
        
        initializeAndFetchAgent: function() {
            var self = this;

            console.log("Intentando verificar agente desde el servidor...");
            
            $.ajax({
                url: 'plugins/abandonedcalls/whoami.php',
                type: 'GET',
                dataType: 'json',
                cache: false,
                success: function(data) {
                    if (data && data.agent && data.agent !== 'unknown') {
                        self.currentUser = data.agent;
                        console.log(`Agente verificado desde API: ${self.currentUser}`);
                    } else {
                        console.warn("No se pudo verificar el agente desde la API.", data);
                        self.currentUser = 'unknown';
                    }
                },
                error: function(xhr) {
                    console.error("Fallo en whoami.php. Usando 'unknown'.", xhr.responseText);
                    self.currentUser = 'unknown';
                },
                complete: function() {
                    if (!self.isInitialized) {
                        self.isInitialized = true;
                        self.setupEventHandlers();
                        self.refreshAllWidgets();
                    }
                }
            });
        },

        setupEventHandlers: function() {
            var self = this;
            $(document).off('click.abandoned').on('click.abandoned', '.btn-recover-abandoned, .btn-finalize-abandoned, .btn-copy-number, .btn-show-note', function(e) {
                e.preventDefault();
                var $button = $(this);

                if ($button.hasClass('btn-recover-abandoned')) {
                    self.recover($button.data('uniqueid'), self.currentUser);
                } 
                else if ($button.hasClass('btn-finalize-abandoned')) {
                    self.finalize($button.data('uniqueid'));
                } 
                else if ($button.hasClass('btn-copy-number')) {
                    var dialString = $button.data('dial-string');
                    navigator.clipboard.writeText(dialString).then(() => {
                        $button.fadeOut(100).fadeIn(100);
                    });
                } 
                else if ($button.hasClass('btn-show-note')) {
                    var encodedNote = $button.data('note-content');
                    var decodedNote = decodeURIComponent(encodedNote);
                    alert("Nota de la gestión:\n\n" + decodedNote);
                }
            });
        },

        refreshAllWidgets: function() {
            this.refreshAbandonedWidget();
            this.refreshRecoveredWidget();
        },

        refreshAbandonedWidget: function() {
            if (this.abandonedWidgetContainer.length === 0) return;

            $.ajax({
                url: 'plugins/abandonedcalls/get_abandoned.php',
                success: (data) => {
                    var html = this.generateAbandonedTableHtml(data);
                    this.abandonedWidgetContainer.html(html);
                },
                error: (xhr) => {
                    console.error("Error al refrescar llamadas abandonadas", xhr.responseText);
                },
                complete: () => {
                    setTimeout(() => this.refreshAbandonedWidget(), 10000);
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
                },
                error: (xhr) => {
                    console.error("Error al refrescar llamadas gestionadas", xhr.responseText);
                },
                complete: () => {
                    setTimeout(() => this.refreshRecoveredWidget(), 15000);
                }
            });
        },

        generateAbandonedTableHtml: function(data) {
            if (!data || data.length === 0) return '<p style="text-align:center; padding: 15px;">No hay llamadas pendientes.</p>';
            var header = '<th>Llamante</th><th>Cola</th><th>Info</th><th>Acciones/Agente</th>';
            let table = `<table class="table table-striped table-condensed"><thead><tr>${header}</tr></thead><tbody>`;
            data.forEach(call => {
                let from_display = (call.contact_name || call.caller_id) + (call.company_name ? ` <small>(${call.company_name})</small>` : '');
                let info_block = `<small>Hora: ${new Date(call.time * 1000).toLocaleTimeString('es-ES', { timeZone: 'Europe/Madrid' })}<br>Espera: <b>${call.wait_time}s</b> (Pos: ${call.abandon_position})</small>`;
                let actions_block = '';
                let row_class = '';

                if (call.status === 'processing') {
                    row_class = 'info';
                    if (call.agent_id == this.currentUser) {
                        actions_block = `<button class="btn btn-primary btn-xs btn-finalize-abandoned" data-uniqueid="${call.uniqueid}" title="Finalizar gestión"><i class="fa fa-flag-checkered"></i> Finalizar</button>`;
                    } else {
                        actions_block = `<span class="label label-default" title="Gestionada por ${call.agent_id}"><i class="fa fa-user-circle"></i> ${call.agent_id}</span>`;
                    }
                } else {
                    actions_block = `<div class="btn-group">
                        <button class="btn btn-success btn-xs btn-recover-abandoned" data-uniqueid="${call.uniqueid}" title="Tomar llamada"><i class="fa fa-check"></i></button>
                        <button class="btn btn-info btn-xs btn-copy-number" data-dial-string="${call.dial_string}" title="Copiar: ${call.dial_string}"><i class="fa fa-clipboard"></i></button>
                    </div>`;
                }

                table += `<tr class="${row_class}"><td>${from_display}</td><td>${call.queue_human_name}</td><td>${info_block}</td><td>${actions_block}</td></tr>`;
            });
            table += '</tbody></table>';
            return table;
        },

        generateRecoveredTableHtml: function(data) {
            if (!data || data.length === 0) {
                return '<p style="text-align:center; padding: 15px;">No hay llamadas gestionadas recientemente.</p>';
            }

            var header = '<th>Llamante</th><th>Agente</th><th>Hora Gestión</th><th>Resultado</th><th>Acción</th>';
            let table = `<table class="table table-striped table-condensed"><thead><tr>${header}</tr></thead><tbody>`;

            data.forEach(call => {
                let from_display = (call.contact_name || call.caller_id) + (call.company_name ? ` <small>(${call.company_name})</small>` : '');
                let recovery_time_only = call.recovery_time_formatted ? call.recovery_time_formatted.split(' ')[1] : '';
                let note_html = '-';

                if (call.notes && call.notes.trim() !== '') {
                    note_html = `<button class="btn btn-default btn-xs btn-show-note" data-note-content="${encodeURIComponent(call.notes)}" title="Ver nota">
                                    <i class="fa fa-comment-o"></i>
                                 </button>`;
                }

                let copy_button = `<button class="btn btn-default btn-xs btn-copy-number" data-dial-string="${call.dial_string}" title="Copiar: ${call.dial_string}"><i class="fa fa-clipboard"></i></button>`;

                table += `<tr>
                    <td>${from_display}</td>
                    <td>${call.agent_id}</td>
                    <td>${recovery_time_only}</td>
                    <td>${note_html}</td>
                    <td>${copy_button}</td>
                </tr>`;
            });

            table += '</tbody></table>';
            return table;
        },

        recover: function(uniqueid, agentId) {
            var row = $(`.btn-recover-abandoned[data-uniqueid="${uniqueid}"]`).closest('tr');
            $.ajax({
                url: 'plugins/abandonedcalls/recover.php',
                data: { uniqueid: uniqueid, agent: agentId },
                success: (response) => {
                    if (response === 'ALREADY_TAKEN') {
                        alert('Esta llamada ya ha sido tomada por otro agente.');
                        this.refreshAbandonedWidget();
                        return;
                    }
                    row.addClass('info');
                    var actionCell = row.find('td').last();
                    actionCell.html(`<button class="btn btn-primary btn-xs btn-finalize-abandoned" data-uniqueid="${uniqueid}" title="Finalizar gestión"><i class="fa fa-flag-checkered"></i> Finalizar</button>`);
                },
                error: (xhr) => {
                    alert("Error en el servidor al intentar recuperar la llamada.");
                }
            });
        },

        finalize: function(uniqueid) {
            var note = prompt("Por favor, introduce una nota para la gestión (ej. 'Cliente contactado', 'No contesta').\nDeja en blanco si no hay notas.", "");
            if (note === null) return;
            $.ajax({
                url: 'plugins/abandonedcalls/finalize.php',
                data: { uniqueid: uniqueid, notes: note },
                success: () => {
                    this.refreshAbandonedWidget();
                    this.refreshRecoveredWidget();
                },
                error: () => {
                    alert("Error al finalizar la gestión.");
                }
            });
        }
    };

    $(document).ready(function() {
        AbandonedPlugin.start();
    });

})(jQuery);