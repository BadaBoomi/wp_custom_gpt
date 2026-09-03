(function () {
    var root = document.getElementById('wpcgpt-rooms-app');
    if (!root || typeof WPCGPT_ROOMS_CONFIG === 'undefined') {
        return;
    }

    var roomList = document.getElementById('wpcgpt-room-list');
    var statusEl = document.getElementById('wpcgpt-status');
    var createBtn = document.getElementById('wpcgpt-create-room');
    var refreshBtn = document.getElementById('wpcgpt-refresh');
    var nameInput = document.getElementById('wpcgpt-room-name');
    var chatsPage = root.getAttribute('data-chats-page') || '';

    function setStatus(message, isError) {
        statusEl.textContent = message;
        statusEl.style.color = isError ? '#b00020' : '#2d6a4f';
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

    function formatRoomDisplayName(room) {
        var baseName = String((room && room.name) || '').trim();
        var attrs = parseCustomAttributes(room && room.custom_attributes);
        var orderedKeys = Object.keys(attrs).sort(function (a, b) {
            var aKey = String(a || '').trim();
            var bKey = String(b || '').trim();
            var aAlias = aKey.toLowerCase() === 'alias';
            var bAlias = bKey.toLowerCase() === 'alias';

            if (aAlias && !bAlias) {
                return -1;
            }
            if (!aAlias && bAlias) {
                return 1;
            }

            return aKey.localeCompare(bKey, 'de', { sensitivity: 'base' });
        });

        var attrItems = orderedKeys
            .map(function (key) {
                var cleanKey = String(key || '').trim();
                var cleanValue = String(attrs[key] || '').trim();
                if (!cleanKey || !cleanValue) {
                    return '';
                }
                return cleanKey + ': ' + cleanValue;
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
            li.textContent = formatRoomDisplayName(room) + ' (ID: ' + room.id + ')';

            var deleteBtn = document.createElement('button');
            deleteBtn.type = 'button';
            deleteBtn.textContent = 'Loeschen';
            deleteBtn.style.marginLeft = '8px';
            deleteBtn.addEventListener('click', function () {
                request('/rooms/' + room.id, { method: 'DELETE' })
                    .then(function () {
                        setStatus('Raum geloescht.', false);
                        loadRooms();
                    })
                    .catch(function (error) {
                        setStatus(error.message, true);
                    });
            });
            li.appendChild(deleteBtn);

            var enterBtn = document.createElement('button');
            enterBtn.type = 'button';
            enterBtn.textContent = 'Oeffnen';
            enterBtn.style.marginLeft = '8px';
            enterBtn.addEventListener('click', function () {
                if (!chatsPage) {
                    setStatus('chats_page fehlt im Shortcode.', true);
                    return;
                }

                window.location.href = addQuery(chatsPage, 'room_id', room.id);
            });
            li.appendChild(enterBtn);

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

    refreshBtn.addEventListener('click', loadRooms);

    loadRooms();
})();
