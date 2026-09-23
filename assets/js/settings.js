(function () {
    var root = document.getElementById('wpcgpt-settings-app');
    if (!root || typeof WPCGPT_SETTINGS_CONFIG === 'undefined') {
        return;
    }

    var form = document.getElementById('wpcgpt-settings-form');
    var statusEl = document.getElementById('wpcgpt-settings-status');
    var apiKeyInput = document.getElementById('wpcgpt-api-key');
    var startersInput = document.getElementById('wpcgpt-starters');
    var apiKeyCurrentEl = document.getElementById('wpcgpt-api-key-current');
    var configurationRowsEl = document.getElementById('wpcgpt-configuration-rows');
    var configurationAddBtn = document.getElementById('wpcgpt-configuration-add');

    var CONFIGURATION_FIELDS = [
        { key: 'label', type: 'text', placeholder: 'Zweck' },
        { key: 'prompt', type: 'textarea', placeholder: 'Prompt' },
        { key: 'promptId', type: 'text', placeholder: 'pmpt_...' },
    ];

    function createConfigurationRow(entry) {
        var row = document.createElement('tr');
        row.className = 'wpcgpt-configuration-row';

        CONFIGURATION_FIELDS.forEach(function (field) {
            var cell = document.createElement('td');
            cell.style.padding = '4px 6px 4px 0';

            var input;
            if (field.type === 'textarea') {
                input = document.createElement('textarea');
                input.rows = 2;
            } else {
                input = document.createElement('input');
                input.type = 'text';
            }

            input.style.width = '100%';
            input.placeholder = field.placeholder;
            input.setAttribute('data-field', field.key);
            input.value = (entry && entry[field.key]) ? String(entry[field.key]) : '';

            cell.appendChild(input);
            row.appendChild(cell);
        });

        var actionCell = document.createElement('td');
        actionCell.style.padding = '4px 0';

        var removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.textContent = 'Entfernen';
        removeBtn.addEventListener('click', function () {
            row.parentNode.removeChild(row);
        });

        actionCell.appendChild(removeBtn);
        row.appendChild(actionCell);

        return row;
    }

    function renderConfigurationRows(entries) {
        if (!configurationRowsEl) {
            return;
        }

        configurationRowsEl.innerHTML = '';
        (entries || []).forEach(function (entry) {
            configurationRowsEl.appendChild(createConfigurationRow(entry));
        });
    }

    function collectConfigurationEntries() {
        if (!configurationRowsEl) {
            return [];
        }

        var rows = configurationRowsEl.querySelectorAll('.wpcgpt-configuration-row');
        var entries = [];

        Array.prototype.forEach.call(rows, function (row) {
            var entry = {};
            var hasValue = false;

            CONFIGURATION_FIELDS.forEach(function (field) {
                var input = row.querySelector('[data-field="' + field.key + '"]');
                var value = input ? input.value.trim() : '';
                entry[field.key] = value;
                if (value) {
                    hasValue = true;
                }
            });

            if (hasValue) {
                entries.push(entry);
            }
        });

        return entries;
    }

    function setStatus(message, isError) {
        statusEl.textContent = message;
        statusEl.style.color = isError ? '#b00020' : '#2d6a4f';
    }

    function request(method, path, body) {
        var options = {
            method: method,
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': WPCGPT_SETTINGS_CONFIG.nonce,
            },
        };

        if (body) {
            options.body = JSON.stringify(body);
        }

        return fetch(WPCGPT_SETTINGS_CONFIG.restBase + path, options).then(function (response) {
            return response.json().then(function (payload) {
                if (!response.ok) {
                    throw new Error((payload && payload.message) || 'Anfrage fehlgeschlagen.');
                }
                return payload;
            });
        });
    }

    function fillForm(data) {
        startersInput.value = data.starters || '';
        renderConfigurationRows(data.configuration_entries || []);

        if (data.has_api_key) {
            apiKeyCurrentEl.textContent = 'Aktueller API-Key: ' + (data.api_key_masked || '(versteckt)');
        } else {
            apiKeyCurrentEl.textContent = 'Es ist noch kein API-Key gespeichert.';
        }
    }

    function loadSettings() {
        setStatus('Einstellungen werden geladen...', false);
        request('GET', '/settings')
            .then(function (data) {
                fillForm(data);
                setStatus('Einstellungen geladen.', false);
            })
            .catch(function (error) {
                setStatus(error.message, true);
            });
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();

        var payload = {
            configuration_entries: collectConfigurationEntries(),
        };

        var apiKeyValue = apiKeyInput.value.trim();
        if (apiKeyValue) {
            payload.api_key = apiKeyValue;
        }

        setStatus('Einstellungen werden gespeichert...', false);

        request('POST', '/settings', payload)
            .then(function (data) {
                apiKeyInput.value = '';
                fillForm(data);
                setStatus('Einstellungen gespeichert.', false);
            })
            .catch(function (error) {
                setStatus(error.message, true);
            });
    });

    if (configurationAddBtn) {
        configurationAddBtn.addEventListener('click', function () {
            if (configurationRowsEl) {
                configurationRowsEl.appendChild(createConfigurationRow(null));
            }
        });
    }

    loadSettings();
})();
