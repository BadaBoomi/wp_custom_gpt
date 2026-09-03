(function () {
    var root = document.getElementById('wpcgpt-settings-app');
    if (!root || typeof WPCGPT_SETTINGS_CONFIG === 'undefined') {
        return;
    }

    var form = document.getElementById('wpcgpt-settings-form');
    var statusEl = document.getElementById('wpcgpt-settings-status');
    var apiKeyInput = document.getElementById('wpcgpt-api-key');
    var promptIdInput = document.getElementById('wpcgpt-prompt-id');
    var vectorStoreIdsInput = document.getElementById('wpcgpt-vector-store-ids');
    var userEmailInput = document.getElementById('wpcgpt-user-email');
    var startersInput = document.getElementById('wpcgpt-starters');
    var reloadConfigurationBtn = document.getElementById('wpcgpt-reload-configuration');
    var apiKeyCurrentEl = document.getElementById('wpcgpt-api-key-current');

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
        promptIdInput.value = data.prompt_id || '';
        vectorStoreIdsInput.value = data.vector_store_ids || '';
        userEmailInput.value = data.user_email || '';
        startersInput.value = data.starters || '';

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
            prompt_id: promptIdInput.value.trim(),
            vector_store_ids: vectorStoreIdsInput.value.trim(),
            user_email: userEmailInput.value.trim(),
            starters: startersInput.value,
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

    if (reloadConfigurationBtn) {
        reloadConfigurationBtn.addEventListener('click', function () {
            setStatus('Konfiguration aus GET_CONFIGURATION wird neu geladen...', false);
            request('POST', '/settings/reload-configuration')
                .then(function (data) {
                    fillForm(data);
                    setStatus('Konfiguration neu geladen und gespeichert.', false);
                })
                .catch(function (error) {
                    setStatus(error.message, true);
                });
        });
    }

    loadSettings();
})();
