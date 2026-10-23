(function () {
    var root = document.getElementById('wpcgpt-settings-app');
    if (!root || typeof WPCGPT_SETTINGS_CONFIG === 'undefined') {
        return;
    }

    var form = document.getElementById('wpcgpt-settings-form');
    var statusEl = document.getElementById('wpcgpt-settings-status');
    var apiKeyInput = document.getElementById('wpcgpt-api-key');
    var transientModeEnabledInput = document.getElementById('wpcgpt-transient-mode-enabled');
    var apiKeyCurrentEl = document.getElementById('wpcgpt-api-key-current');
    var configurationRowsEl = document.getElementById('wpcgpt-configuration-rows');
    var configurationAddBtn = document.getElementById('wpcgpt-configuration-add');
    var roomAttributeRowsEl = document.getElementById('wpcgpt-room-attribute-rows');
    var roomAttributeAddBtn = document.getElementById('wpcgpt-room-attribute-add');
    var assistantIconIdInput = document.getElementById('wpcgpt-assistant-icon-id');
    var assistantIconPreview = document.getElementById('wpcgpt-assistant-icon-preview');
    var assistantIconUploadBtn = document.getElementById('wpcgpt-assistant-icon-upload');
    var assistantIconRemoveBtn = document.getElementById('wpcgpt-assistant-icon-remove');
    var tabButtons = root.querySelectorAll('.wpcgpt-tab-button');
    var tabPanels = root.querySelectorAll('.wpcgpt-settings-tab-panel');

    var THEME_FIELDS = [
        'font_family',
        'font_size',
        'color_text',
        'color_muted',
        'color_background',
        'color_surface',
        'color_border',
        'color_accent',
        'color_accent_text',
        'color_success',
        'color_error',
        'color_chat_window',
        'color_user_bubble',
        'color_assistant_bubble',
        'color_assistant_avatar_bg',
    ];

    var CONFIGURATION_FIELDS = [
        { key: 'label', type: 'text', placeholder: 'Zweck' },
        { key: 'prompt', type: 'textarea', placeholder: 'Prompt / Begruessung' },
        { key: 'maxPerRoom', type: 'number', placeholder: '0', min: '0' },
        { key: 'promptId', type: 'text', placeholder: 'pmpt_...' },
    ];

    function createRoomAttributeRow(name) {
        var row = document.createElement('div');
        row.style.display = 'flex';
        row.style.alignItems = 'center';
        row.style.gap = '8px';
        row.style.marginBottom = '6px';

        var input = document.createElement('input');
        input.type = 'text';
        input.value = String(name || '');
        input.placeholder = 'z.B. alias';
        input.style.flex = '1';
        input.style.minWidth = '120px';

        var upBtn = document.createElement('button');
        upBtn.type = 'button';
        upBtn.textContent = '↑';
        upBtn.title = 'nach oben';
        upBtn.addEventListener('click', function () {
            if (!roomAttributeRowsEl) {
                return;
            }

            var siblings = Array.prototype.slice.call(roomAttributeRowsEl.children);
            var index = siblings.indexOf(row);
            if (index > 0) {
                roomAttributeRowsEl.insertBefore(row, siblings[index - 1]);
            }
        });

        var downBtn = document.createElement('button');
        downBtn.type = 'button';
        downBtn.textContent = '↓';
        downBtn.title = 'nach unten';
        downBtn.addEventListener('click', function () {
            if (!roomAttributeRowsEl) {
                return;
            }

            var siblings = Array.prototype.slice.call(roomAttributeRowsEl.children);
            var index = siblings.indexOf(row);
            if (index >= 0 && index < siblings.length - 1) {
                roomAttributeRowsEl.insertBefore(row, siblings[index + 1].nextSibling || null);
            }
        });

        var removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.textContent = 'Entfernen';
        removeBtn.addEventListener('click', function () {
            if (row.parentNode) {
                row.parentNode.removeChild(row);
            }
        });

        row.appendChild(input);
        row.appendChild(upBtn);
        row.appendChild(downBtn);
        row.appendChild(removeBtn);
        return row;
    }

    function renderRoomAttributeRows(values) {
        if (!roomAttributeRowsEl) {
            return;
        }

        roomAttributeRowsEl.innerHTML = '';
        (values || []).forEach(function (value) {
            roomAttributeRowsEl.appendChild(createRoomAttributeRow(value));
        });
    }

    function collectRoomAttributes() {
        if (!roomAttributeRowsEl) {
            return [];
        }

        var result = [];
        var seen = {};

        Array.prototype.forEach.call(roomAttributeRowsEl.children, function (row) {
            var input = row.querySelector('input');
            if (!input) {
                return;
            }

            var name = String(input.value || '').trim().toLowerCase();
            name = name.replace(/[^a-z0-9 _\-]/g, '').trim();
            if (!name || seen[name]) {
                return;
            }

            seen[name] = true;
            result.push(name);
        });

        return result;
    }

    function renderIconPreview(container, iconUrl) {
        if (!container) {
            return;
        }

        container.innerHTML = '';

        var cleanUrl = String(iconUrl || '').trim();
        if (!cleanUrl) {
            var empty = document.createElement('span');
            empty.className = 'wpcgpt-icon-preview-empty';
            empty.textContent = 'Kein Icon ausgewaehlt';
            container.appendChild(empty);
            return;
        }

        var image = document.createElement('img');
        image.className = 'wpcgpt-icon-preview-image';
        image.src = cleanUrl;
        image.alt = '';
        container.appendChild(image);
    }

    function openImagePicker(onSelected) {
        if (typeof wp === 'undefined' || !wp.media) {
            setStatus('WordPress Media-Uploader ist nicht verfuegbar.', true);
            return;
        }

        var frame = wp.media({
            title: 'Icon wählen',
            button: {
                text: 'Icon verwenden',
            },
            library: {
                type: 'image',
            },
            multiple: false,
        });

        frame.on('select', function () {
            var selection = frame.state().get('selection');
            if (!selection || selection.length === 0) {
                return;
            }

            var attachment = selection.first().toJSON();
            if (!attachment || !attachment.id) {
                return;
            }

            onSelected({
                id: parseInt(attachment.id, 10) || 0,
                url: String(attachment.url || '').trim(),
            });
        });

        frame.open();
    }

    function createConfigurationRow(entry) {
        var row = document.createElement('tr');
        row.className = 'wpcgpt-configuration-row';
        row.setAttribute('data-config-id', String((entry && entry.id) ? parseInt(entry.id, 10) : 0));

        var idCell = document.createElement('td');
        idCell.style.padding = '4px 6px 4px 0';
        idCell.className = 'wpcgpt-config-id-cell';
        var idValue = parseInt(row.getAttribute('data-config-id') || '0', 10);
        idCell.textContent = idValue > 0 ? String(idValue) : 'Neu';
        row.appendChild(idCell);

        CONFIGURATION_FIELDS.forEach(function (field) {
            var cell = document.createElement('td');
            cell.style.padding = '4px 6px 4px 0';

            var input;
            if (field.type === 'textarea') {
                input = document.createElement('textarea');
                input.rows = 2;
            } else {
                input = document.createElement('input');
                input.type = field.type || 'text';
                if (field.min !== undefined) {
                    input.min = String(field.min);
                }
            }

            input.style.width = '100%';
            input.placeholder = field.placeholder || '';
            input.setAttribute('data-field', field.key);
            var rawValue = entry && entry[field.key] !== undefined && entry[field.key] !== null ? String(entry[field.key]) : '';
            input.value = rawValue;

            cell.appendChild(input);
            row.appendChild(cell);
        });

        var iconCell = document.createElement('td');
        iconCell.style.padding = '4px 6px 4px 0';

        var iconIdInput = document.createElement('input');
        iconIdInput.type = 'hidden';
        iconIdInput.setAttribute('data-field', 'iconId');
        iconIdInput.value = String((entry && entry.iconId) ? parseInt(entry.iconId, 10) : 0);

        var iconPreview = document.createElement('div');
        iconPreview.className = 'wpcgpt-icon-preview';
        renderIconPreview(iconPreview, entry && entry.iconUrl ? entry.iconUrl : '');

        var iconUploadBtn = document.createElement('button');
        iconUploadBtn.type = 'button';
        iconUploadBtn.textContent = 'Icon wählen';
        iconUploadBtn.addEventListener('click', function () {
            openImagePicker(function (selected) {
                iconIdInput.value = String(selected.id || 0);
                renderIconPreview(iconPreview, selected.url || '');
            });
        });

        var iconRemoveBtn = document.createElement('button');
        iconRemoveBtn.type = 'button';
        iconRemoveBtn.textContent = 'Entfernen';
        iconRemoveBtn.style.marginLeft = '6px';
        iconRemoveBtn.addEventListener('click', function () {
            iconIdInput.value = '0';
            renderIconPreview(iconPreview, '');
        });

        iconCell.appendChild(iconIdInput);
        iconCell.appendChild(iconPreview);
        iconCell.appendChild(iconUploadBtn);
        iconCell.appendChild(iconRemoveBtn);
        row.appendChild(iconCell);

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

            var idValue = parseInt(row.getAttribute('data-config-id') || '0', 10);
            entry.id = isNaN(idValue) ? 0 : Math.max(0, idValue);

            CONFIGURATION_FIELDS.forEach(function (field) {
                var input = row.querySelector('[data-field="' + field.key + '"]');
                var value = input ? input.value.trim() : '';
                if (field.type === 'number') {
                    entry[field.key] = parseInt(value || '0', 10) || 0;
                    if (entry[field.key] > 0) {
                        hasValue = true;
                    }
                    return;
                }

                entry[field.key] = value;
                if (value) {
                    hasValue = true;
                }
            });

            var iconInput = row.querySelector('[data-field="iconId"]');
            var iconId = iconInput ? parseInt(iconInput.value || '0', 10) : 0;
            entry.iconId = isNaN(iconId) ? 0 : Math.max(0, iconId);
            if (entry.iconId > 0) {
                hasValue = true;
            }

            if (hasValue) {
                entries.push(entry);
            }
        });

        return entries;
    }

    function setStatus(message, isError) {
        statusEl.textContent = message;
        statusEl.style.color = isError ? 'var(--wpcgpt-color-error)' : 'var(--wpcgpt-color-success)';
    }

    function toThemeInputId(key) {
        return 'wpcgpt-theme-' + key.replace(/_/g, '-');
    }

    function setActiveTab(tabId) {
        Array.prototype.forEach.call(tabButtons, function (button) {
            var isActive = button.getAttribute('data-tab') === tabId;
            button.classList.toggle('is-active', isActive);
            button.setAttribute('aria-selected', isActive ? 'true' : 'false');
        });

        Array.prototype.forEach.call(tabPanels, function (panel) {
            var isActive = panel.getAttribute('data-tab-panel') === tabId;
            panel.classList.toggle('is-active', isActive);
            if (isActive) {
                panel.removeAttribute('hidden');
            } else {
                panel.setAttribute('hidden', 'hidden');
            }
        });
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
        renderConfigurationRows(data.configuration_entries || []);
        renderRoomAttributeRows(data.room_attributes || []);

        if (transientModeEnabledInput) {
            transientModeEnabledInput.checked = !!(data && data.transient_mode_enabled);
        }

        if (assistantIconIdInput) {
            assistantIconIdInput.value = String(parseInt((data && data.assistant_icon_id) || 0, 10) || 0);
        }
        renderIconPreview(assistantIconPreview, (data && data.assistant_icon_url) || '');

        var theme = (data && data.ui_theme && typeof data.ui_theme === 'object') ? data.ui_theme : {};
        THEME_FIELDS.forEach(function (key) {
            var input = document.getElementById(toThemeInputId(key));
            if (!input) {
                return;
            }

            input.value = String(theme[key] || '');
        });

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
            room_attributes: collectRoomAttributes(),
            assistant_icon_id: assistantIconIdInput ? parseInt(assistantIconIdInput.value || '0', 10) || 0 : 0,
            transient_mode_enabled: transientModeEnabledInput ? !!transientModeEnabledInput.checked : false,
            ui_theme: {},
        };

        THEME_FIELDS.forEach(function (key) {
            var input = document.getElementById(toThemeInputId(key));
            payload.ui_theme[key] = input ? input.value.trim() : '';
        });

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

    if (roomAttributeAddBtn && roomAttributeRowsEl) {
        roomAttributeAddBtn.addEventListener('click', function () {
            roomAttributeRowsEl.appendChild(createRoomAttributeRow(''));
        });
    }

    if (assistantIconUploadBtn && assistantIconIdInput) {
        assistantIconUploadBtn.addEventListener('click', function () {
            openImagePicker(function (selected) {
                assistantIconIdInput.value = String(selected.id || 0);
                renderIconPreview(assistantIconPreview, selected.url || '');
            });
        });
    }

    if (assistantIconRemoveBtn && assistantIconIdInput) {
        assistantIconRemoveBtn.addEventListener('click', function () {
            assistantIconIdInput.value = '0';
            renderIconPreview(assistantIconPreview, '');
        });
    }

    Array.prototype.forEach.call(tabButtons, function (button) {
        button.addEventListener('click', function () {
            var tabId = button.getAttribute('data-tab');
            if (!tabId) {
                return;
            }

            setActiveTab(tabId);
        });
    });

    setActiveTab('general');

    loadSettings();
})();
