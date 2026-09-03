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
                    throw new Error((payload && payload.message) || 'Request failed.');
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
            apiKeyCurrentEl.textContent = 'Current API key: ' + (data.api_key_masked || '(hidden)');
        } else {
            apiKeyCurrentEl.textContent = 'No API key is stored yet.';
        }
    }

    function loadSettings() {
        setStatus('Loading settings...', false);
        request('GET', '/settings')
            .then(function (data) {
                fillForm(data);
                setStatus('Settings loaded.', false);
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

        setStatus('Saving settings...', false);

        request('POST', '/settings', payload)
            .then(function (data) {
                apiKeyInput.value = '';
                fillForm(data);
                setStatus('Settings saved.', false);
            })
            .catch(function (error) {
                setStatus(error.message, true);
            });
    });

    if (reloadConfigurationBtn) {
        reloadConfigurationBtn.addEventListener('click', function () {
            setStatus('Reloading configuration from GET_CONFIGURATION...', false);
            request('POST', '/settings/reload-configuration')
                .then(function (data) {
                    fillForm(data);
                    setStatus('Configuration reloaded and saved.', false);
                })
                .catch(function (error) {
                    setStatus(error.message, true);
                });
        });
    }

    loadSettings();
})();
