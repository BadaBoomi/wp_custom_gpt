(function () {
    var root = document.getElementById('wpcgpt-rooms-app');
    if (!root || typeof WPCGPT_ROOMS_CONFIG === 'undefined') {
        return;
    }

    var roomList = document.getElementById('wpcgpt-room-list');
    var statusEl = document.getElementById('wpcgpt-status');
    var createBtn = document.getElementById('wpcgpt-create-room');
    var nameInput = document.getElementById('wpcgpt-room-name');
    var chatsPage = root.getAttribute('data-chats-page') || '';

    function setStatus(message, isError) {
        statusEl.textContent = message;
        statusEl.style.color = isError ? 'var(--wpcgpt-color-error)' : 'var(--wpcgpt-color-success)';
    }

    function request(path, options) {
        var requestOptions = Object.assign(
            {
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': WPCGPT_ROOMS_CONFIG.nonce,
                },
            },
            options || {}
        );

        return fetch(WPCGPT_ROOMS_CONFIG.restBase + path, requestOptions).then(function (response) {
            return response.json().then(function (body) {
                if (!response.ok) {
                    throw new Error((body && body.message) || 'Anfrage fehlgeschlagen.');
                }
                return body;
            });
        });
    }

    function addQuery(url, key, value) {
        var separator = url.indexOf('?') >= 0 ? '&' : '?';
        return url + separator + encodeURIComponent(key) + '=' + encodeURIComponent(String(value));
    }

    function parseCustomAttributes(rawValue) {
        if (!rawValue) {
            return {};
        }

        if (typeof rawValue === 'object') {
            return rawValue;
        }

        if (typeof rawValue !== 'string') {
            return {};
        }

        try {
            var parsed = JSON.parse(rawValue);
            return parsed && typeof parsed === 'object' ? parsed : {};
        } catch (error) {
            return {};
        }
    }

    function getConfiguredRoomAttributes() {
        var raw = [];
        if (Array.isArray(WPCGPT_ROOMS_CONFIG && WPCGPT_ROOMS_CONFIG.roomAttributes)) {
            raw = WPCGPT_ROOMS_CONFIG.roomAttributes;
        }

        try {
            var rootValue = JSON.parse(root.getAttribute('data-room-attributes') || '[]');
            if (Array.isArray(rootValue) && rootValue.length) {
                raw = rootValue;
            }
        } catch (error) {
            raw = raw || [];
        }

        var result = [];
        var seen = {};
        (raw || []).forEach(function (entry) {
            var name = String(entry || '').trim().toLowerCase();
            name = name.replace(/[^a-z0-9 _\-]/g, '').trim();
            if (!name || seen[name]) {
                return;
            }
            seen[name] = true;
            result.push(name);
        });

        return result;
    }

    function formatRoomDisplayName(room) {
        var baseName = String((room && room.name) || '').trim();
        var attrs = parseCustomAttributes(room && room.custom_attributes);
        var configured = getConfiguredRoomAttributes();
        var keyMap = {};
        Object.keys(attrs).forEach(function (key) {
            var cleanKey = String(key || '').trim();
            if (!cleanKey) {
                return;
            }
            keyMap[cleanKey.toLowerCase()] = { key: cleanKey, value: String(attrs[key] || '').trim() };
        });

        var attrItems = configured
            .map(function (name) {
                var match = keyMap[name.toLowerCase()];
                if (!match || !match.value) {
                    return '';
                }
                return match.key + ': ' + match.value;
            })
            .filter(function (entry) {
                return entry !== '';
            });

        if (attrItems.length === 0) {
            return baseName;
        }

        return baseName + ' (' + attrItems.join(', ') + ')';
    }

    function renderRooms(rooms) {
        roomList.innerHTML = '';

        if (!rooms.length) {
            var empty = document.createElement('li');
            empty.textContent = 'Noch keine Raeume.';
            roomList.appendChild(empty);
            return;
        }

        rooms.forEach(function (room) {
            var li = document.createElement('li');
            li.style.display = 'flex';
            li.style.justifyContent = 'space-between';
            li.style.alignItems = 'center';
            li.style.gap = '12px';

            var title = document.createElement('div');
            title.textContent = formatRoomDisplayName(room) + ' (ID: ' + room.id + ')';
            title.style.flex = '1';
            title.style.minWidth = '0';
            li.appendChild(title);

            var actions = document.createElement('div');
            actions.style.display = 'flex';
            actions.style.justifyContent = 'flex-end';
            actions.style.gap = '8px';
            actions.style.flexWrap = 'wrap';

            var deleteBtn = document.createElement('button');
            deleteBtn.type = 'button';
            deleteBtn.textContent = 'Loeschen';
            deleteBtn.addEventListener('click', function () {
                if (!window.confirm('Sind Sie sicher? Der Raum wird mit allen Inhalten gelöscht, diese Aktion kann nicht rückgängig gemacht werden')) {
                    return;
                }

                request('/rooms/' + room.id, { method: 'DELETE' })
                    .then(function () {
                        setStatus('Raum geloescht.', false);
                        loadRooms();
                    })
                    .catch(function (error) {
                        setStatus(error.message, true);
                    });
            });
            actions.appendChild(deleteBtn);

            var enterBtn = document.createElement('button');
            enterBtn.type = 'button';
            enterBtn.textContent = 'Oeffnen';
            enterBtn.addEventListener('click', function () {
                if (!chatsPage) {
                    setStatus('chats_page fehlt im Shortcode.', true);
                    return;
                }

                window.location.href = addQuery(chatsPage, 'room_id', room.id);
            });
            actions.appendChild(enterBtn);

            li.appendChild(actions);
            roomList.appendChild(li);
        });
    }

    function loadRooms() {
        setStatus('Raeume werden geladen...', false);
        request('/rooms', { method: 'GET' })
            .then(function (rooms) {
                renderRooms(rooms);
                setStatus('Raeume geladen.', false);
            })
            .catch(function (error) {
                setStatus(error.message, true);
            });
    }

    createBtn.addEventListener('click', function () {
        var name = (nameInput.value || '').trim();
        if (!name) {
            setStatus('Bitte einen Raumnamen eingeben.', true);
            return;
        }

        request('/rooms', {
            method: 'POST',
            body: JSON.stringify({ name: name }),
        })
            .then(function () {
                nameInput.value = '';
                setStatus('Raum erstellt.', false);
                loadRooms();
            })
            .catch(function (error) {
                setStatus(error.message, true);
            });
    });

    loadRooms();
})();
