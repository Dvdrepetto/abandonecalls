(function ($) {

    var AbandonedPlugin = {
        abandonedWidgetContainer: null,
        recoveredWidgetContainer: null,
        currentUser: 'unknown',
        isInitialized: false,

        start: function () {
            this.abandonedWidgetContainer = $('#abandonedcalls');
            this.recoveredWidgetContainer = $('#recoveredcalls');
            this.initializeAndFetchAgent();
        },

        initializeAndFetchAgent: function () {
            var self = this;
            console.log("Intentando verificar agente desde el servidor...");
            $.ajax({
                url: 'plugins/abandonedcalls/whoami.php',
                type: 'GET',
                dataType: 'json',
                cache: false,
                success: function (data) {
                    if (data && data.agent && data.agent !== 'unknown') {
                        self.currentUser = data.agent;
                        console.log(`Agente verificado desde API: ${self.currentUser}`);
                    } else {
                        console.warn("No se pudo verificar el agente desde la API. Se usará 'unknown'.", data);
                        self.currentUser = 'unknown';
                    }
                },
                error: function (xhr) {
                    console.error("Fallo en whoami.php. Usando 'unknown'.", xhr.responseText);
                    self.currentUser = 'unknown';
                },
                complete: function () {
                    if (!self.isInitialized) {
                        self.isInitialized = true;
                        self.setupEventHandlers();
                        self.startRefreshTimers();
                        self.refreshAllWidgets();
                    }
                }
            });
        },

        setupEventHandlers: function () {
            var self = this;
            $(document).off('click.abandoned').on('click.abandoned', '.btn-recover-abandoned, .btn-finalize-abandoned, .btn-copy-number, .btn-show-note, .btn-history', function (e) {
                e.preventDefault();
                var $button = $(this);

                if ($button.hasClass('btn-recover-abandoned')) {
                    var dialString = $button.data('dial-string');
                    if (dialString) {
                        navigator.clipboard.writeText(dialString).then(() => {
                            $button.css('opacity', 0.5).animate({ opacity: 1 }, 400);
                        });
                    }
                    self.recover($button.data('uniqueid'), self.currentUser);
                }
                else if ($button.hasClass('btn-finalize-abandoned')) {
                    self.finalize($button.data('uniqueid'));
                }
                else if ($button.hasClass('btn-copy-number')) {
                    var dialString = $button.data('dial-string');
                    navigator.clipboard.writeText(dialString).then(() => { $button.fadeOut(100).fadeIn(100); });
                }
                else if ($button.hasClass('btn-show-note')) {
                    var encodedNote = $button.data('note-content');
                    var decodedNote = decodeURIComponent(encodedNote || '');
                    alert("Nota de la gestión:\n\n" + decodedNote);
                }
                else if ($button.hasClass('btn-history')) {
                    var callerId = $button.data('caller-id');
                    var contactName = $button.data('contact-name');
                    self.showHistory(callerId, contactName);
                }
            });
            this.setupFilters();
            var typingTimeout;
            $('#abandoned_filter').on('keyup', function () {
                clearTimeout(typingTimeout);
                typingTimeout = setTimeout(function () {
                    self.refreshAbandonedWidget();
                }, 400);
            });
            $('#recovered_filter').on('keyup', function () {
                clearTimeout(typingTimeout);
                typingTimeout = setTimeout(function () {
                    self.refreshRecoveredWidget();
                }, 400);
            });
            $('#abandoned_sort').on('change', function () {
                self.refreshAbandonedWidget();
            });
        },

        setupFilters: function () {
            var self = this;
            var typingTimeout;
            function handleTyping(elementId, refreshFunction) {
                $('#' + elementId).on('keyup', function () {
                    clearTimeout(typingTimeout);
                    typingTimeout = setTimeout(() => {
                        refreshFunction.call(self);
                    }, 400);
                });
            }
            handleTyping('abandoned_filter', this.refreshAbandonedWidget);
            handleTyping('recovered_filter', this.refreshRecoveredWidget);
        },

        startRefreshTimers: function () {
            setInterval(() => this.refreshAbandonedWidget(), 30000);
            setInterval(() => this.refreshRecoveredWidget(), 60000);
        },

        refreshAllWidgets: function () {
            this.refreshAbandonedWidget();
            this.refreshRecoveredWidget();
        },

        refreshAbandonedWidget: function () {
            if (this.abandonedWidgetContainer.length === 0) return;
            var filterValue = $('#abandoned_filter').val() || '';
            var sortValue = $('#abandoned_sort').val() || 'call_time_desc';
            $.ajax({
                url: 'plugins/abandonedcalls/get_abandoned.php',
                type: 'GET',
                data: {
                    filter: filterValue,
                    sort: sortValue
                },
                dataType: 'json', cache: false,
                success: (data) => {
                    $('#abandonedcallstag').text(`Llamadas Pendientes (${data.length})`);
                    var html = this.generateAbandonedTableHtml(data);
                    this.abandonedWidgetContainer.html(html);
                }
            });
        },

        refreshRecoveredWidget: function () {
            if (this.recoveredWidgetContainer.length === 0) return;
            var filterValue = $('#recovered_filter').val() || '';
            $.ajax({
                url: 'plugins/abandonedcalls/get_recovered.php',
                type: 'GET',
                data: { filter: filterValue },
                dataType: 'json', cache: false,
                success: (data) => {
                    $('#recoveredcallstag').text(`Llamadas Gestionadas (${data.length})`);
                    var html = this.generateRecoveredTableHtml(data);
                    this.recoveredWidgetContainer.html(html);
                }
            });
        },

        generateAbandonedTableHtml: function (data) {
            if (!data || data.length === 0) return '<p style="text-align:center; padding: 15px;">No hay llamadas pendientes.</p>';
            var header = '<th>Llamante</th><th>Cola</th><th>Info</th><th>Acción</th>';
            let table = `<table class="table table-striped table-condensed"><thead><tr>${header}</tr></thead><tbody>`;
            data.forEach(call => {
                let from_text = (call.contact_name || call.caller_id) + (call.company_name ? ` <small>(${call.company_name})</small>` : '');
                let history_button = `<a href="#" class="btn-history" data-caller-id="${call.caller_id}" data-contact-name="${call.contact_name || ''}" title="Ver historial"><i class="fa fa-history" style="margin-left: 5px;"></i></a>`;
                let from_display = from_text + history_button;
                const waitTimeInt = parseInt(call.wait_time, 10) || 0;
                let info_block = `<small>Hora: ${new Date(call.time * 1000).toLocaleTimeString('es-ES', { timeZone: 'Europe/Madrid' })}<br>Espera: <b>${waitTimeInt}s</b> (Pos: ${call.abandon_position})</small>`;
                let actions_block = '';
                let row_class = '';

                if (call.status === 'processing') {
                    row_class = 'info';
                    if (call.agent_id == this.currentUser) {
                        actions_block = `<button class="btn btn-primary btn-xs btn-finalize-abandoned" data-uniqueid="${call.uniqueid}" title="Finalizar"><i class="fa fa-flag-checkered"></i> Finalizar</button>` + ` <button class="btn btn-warning btn-xs btn-requeue-abandoned" data-uniqueid="${call.uniqueid}" title="Devolver a la cola (sin respuesta)"><i class="fa fa-undo"></i> Devolver</button>`;
                    } else {
                        actions_block = `<span class="label label-default" title="Gestionada por ${call.agent_id}"><i class="fa fa-user-circle"></i> ${call.agent_id}</span>`;
                    }
                } else if (call.status === 'abandoned') {
                    if (waitTimeInt > 40) row_class = 'danger';
                    else if (waitTimeInt > 20) row_class = 'warning';
                    actions_block = `<button class="btn btn-success btn-xs btn-recover-abandoned" data-uniqueid="${call.uniqueid}" data-dial-string="${call.dial_string}" title="Tomar y Copiar (${call.dial_string})"><i class="fa fa-check"></i> Tomar y Copiar</button>`;
                }
                table += `<tr class="${row_class}"><td>${from_display}</td><td>${call.queue_human_name}</td><td>${info_block}</td><td>${actions_block}</td></tr>`;
            });
            table += '</tbody></table>';
            return table;
        },

        generateRecoveredTableHtml: function (data) {
            if (!data || data.length === 0) return '<p style="text-align:center; padding: 15px;">No hay llamadas gestionadas.</p>';
            var header = '<th>Llamante</th><th>Agente</th><th>Hora</th><th>Resultado</th><th>Acción</th>';
            let table = `<table class="table table-striped table-condensed"><thead><tr>${header}</tr></thead><tbody>`;
            data.forEach(call => {
                let from_text = (call.contact_name || call.caller_id) + (call.company_name ? ` <small>(${call.company_name})</small>` : '');
                let history_button = `<a href="#" class="btn-history" data-caller-id="${call.caller_id}" data-contact-name="${call.contact_name || ''}" title="Ver historial"><i class="fa fa-history" style="margin-left: 5px;"></i></a>`;
                let from_display = from_text + history_button;
                let recovery_time_only = call.recovery_time_formatted ? call.recovery_time_formatted.split(' ')[1] : '';
                let note_html = '-';
                if (call.notes && call.notes.trim() !== '') {
                    note_html = `<button class="btn btn-default btn-xs btn-show-note" data-note-content="${encodeURIComponent(call.notes)}" title="Ver nota"><i class="fa fa-comment-o"></i></button>`;
                }
                let copy_button = `<button class="btn btn-default btn-xs btn-copy-number" data-dial-string="${call.dial_string}" title="Copiar: ${call.dial_string}"><i class="fa fa-clipboard"></i></button>`;
                table += `<tr><td>${from_display}</td><td>${call.agent_id}</td><td>${recovery_time_only}</td><td>${note_html}</td><td>${copy_button}</td></tr>`;
            });
            table += '</tbody></table>';
            return table;
        },

        recover: function (uniqueid, agentId) {
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
                }
            });
        },

        showHistory: function (callerId, contactName) {
            $.ajax({
                url: 'plugins/abandonedcalls/get_history.php',
                type: 'GET', data: { caller_id: callerId }, dataType: 'json', cache: false,
                success: function (history) {
                    let modalTitle = `Historial para <strong>${contactName || callerId}</strong>` + (contactName ? ` (${callerId})` : '');
                    $('#historyModalTitle').html(modalTitle);
                    let historyHtml = '';
                    if (history.length === 0) {
                        historyHtml = '<p>No hay historial reciente para este número.</p>';
                    } else {
                        historyHtml = '<ul class="list-group">';
                        history.forEach(entry => {
                            let item_class = '', icon = '', text = '';
                            if (entry.status === 'abandoned' || entry.status === 'processing') {
                                item_class = 'list-group-item-warning'; icon = 'fa-exclamation-triangle'; text = `<strong>Abandonada</strong> el ${entry.event_time}`;
                            } else if (entry.status === 'recovered') {
                                item_class = 'list-group-item-success'; icon = 'fa-check-circle'; text = `<strong>Gestionada</strong> por ${entry.agent_id} el ${entry.recovery_time_f}`;
                                if (entry.notes) text += `<br><small style="padding-left: 20px;"><em>Nota: ${entry.notes}</em></small>`;
                            }
                            historyHtml += `<li class="list-group-item ${item_class}"><i class="fa ${icon}"></i> ${text}</li>`;
                        });
                        historyHtml += '</ul>';
                    }
                    $('#historyModalContent').html(historyHtml);
                    $('#historyModal').modal('show');
                }
            });
        },

        finalize: function (uniqueid) {
            var self = this;
            var note = prompt("Por favor, introduce una nota para la gestión.", "");
            if (note === null) return;
            $.ajax({
                url: 'plugins/abandonedcalls/finalize.php',
                data: { uniqueid: uniqueid, notes: note, agent: this.currentUser },
                success: () => {
                    self.refreshAbandonedWidget();
                    self.refreshRecoveredWidget();
                },
                error: () => { alert("Error al finalizar la gestión."); }
            });
        },

    };

    $(document).ready(function () {
        AbandonedPlugin.start();
    });

})(jQuery);