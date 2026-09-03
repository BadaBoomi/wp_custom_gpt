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
                    throw new Error((body && body.message) || 'Request failed.');
                }
                return body;
            });
        });
    }

    function addQuery(url, key, value) {
        var separator = url.indexOf('?') >= 0 ? '&' : '?';
        return url + separator + encodeURIComponent(key) + '=' + encodeURIComponent(String(value));
    }

    function renderRooms(rooms) {
        roomList.innerHTML = '';

        if (!rooms.length) {
            var empty = document.createElement('li');
            empty.textContent = 'No rooms yet.';
            roomList.appendChild(empty);
            return;
        }

        rooms.forEach(function (room) {
            var li = document.createElement('li');
            li.textContent = room.name + ' (ID: ' + room.id + ')';

            var deleteBtn = document.createElement('button');
            deleteBtn.type = 'button';
            deleteBtn.textContent = 'Delete';
            deleteBtn.style.marginLeft = '8px';
            deleteBtn.addEventListener('click', function () {
                request('/rooms/' + room.id, { method: 'DELETE' })
                    .then(function () {
                        setStatus('Room deleted.', false);
                        loadRooms();
                    })
                    .catch(function (error) {
                        setStatus(error.message, true);
                    });
            });
            li.appendChild(deleteBtn);

            var enterBtn = document.createElement('button');
            enterBtn.type = 'button';
            enterBtn.textContent = 'Enter';
            enterBtn.style.marginLeft = '8px';
            enterBtn.addEventListener('click', function () {
                if (!chatsPage) {
                    setStatus('Missing chats_page in shortcode.', true);
                    return;
                }

                window.location.href = addQuery(chatsPage, 'room_id', room.id);
            });
            li.appendChild(enterBtn);

            roomList.appendChild(li);
        });
    }

    function loadRooms() {
        setStatus('Loading rooms...', false);
        request('/rooms', { method: 'GET' })
            .then(function (rooms) {
                renderRooms(rooms);
                setStatus('Rooms loaded.', false);
            })
            .catch(function (error) {
                setStatus(error.message, true);
            });
    }

    createBtn.addEventListener('click', function () {
        var name = (nameInput.value || '').trim();
        if (!name) {
            setStatus('Please enter a room name.', true);
            return;
        }

        request('/rooms', {
            method: 'POST',
            body: JSON.stringify({ name: name }),
        })
            .then(function () {
                nameInput.value = '';
                setStatus('Room created.', false);
                loadRooms();
            })
            .catch(function (error) {
                setStatus(error.message, true);
            });
    });

    refreshBtn.addEventListener('click', loadRooms);

    loadRooms();
})();
