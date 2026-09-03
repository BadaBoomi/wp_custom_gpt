(function () {
    var root = document.getElementById('wpcgpt-chats-app');
    if (!root || typeof WPCGPT_CHATS_CONFIG === 'undefined') {
        return;
    }

    var chatList = document.getElementById('wpcgpt-chat-list');
    var statusEl = document.getElementById('wpcgpt-status');
    var roomLabelEl = document.getElementById('wpcgpt-room-label');
    var createChatBtn = document.getElementById('wpcgpt-create-chat');
    var refreshChatsBtn = document.getElementById('wpcgpt-refresh-chats');
    var backRoomsLink = document.getElementById('wpcgpt-back-rooms');
    var chatTitleInput = document.getElementById('wpcgpt-chat-title');

    var roomId = parseInt(root.getAttribute('data-room-id') || '0', 10);
    var chatPage = root.getAttribute('data-chat-page') || '';
    var roomsPage = root.getAttribute('data-rooms-page') || '';

    function setStatus(message, isError) {
        statusEl.textContent = message;
        statusEl.style.color = isError ? '#b00020' : '#2d6a4f';
    }

    function request(path, options) {
        var requestOptions = Object.assign(
            {
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': WPCGPT_CHATS_CONFIG.nonce,
                },
            },
            options || {}
        );

        return fetch(WPCGPT_CHATS_CONFIG.restBase + path, requestOptions).then(function (response) {
            return response.json().then(function (body) {
                if (!response.ok) {
                    throw new Error((body && body.message) || 'Anfrage fehlgeschlagen.');
                }
                return body;
            });
        });
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

    function buildChatUrl(chatId) {
        if (!chatPage) {
            return '';
        }

        var separator = chatPage.indexOf('?') >= 0 ? '&' : '?';
        return (
            chatPage +
            separator +
            'room_id=' + encodeURIComponent(String(roomId)) +
            '&chat_id=' + encodeURIComponent(String(chatId))
        );
    }

    function renderChats(chats) {
        chatList.innerHTML = '';

        if (!chats.length) {
            var empty = document.createElement('li');
            empty.textContent = 'Noch keine Chats in diesem Raum.';
            chatList.appendChild(empty);
            return;
        }

        chats.forEach(function (chat) {
            var li = document.createElement('li');
            li.textContent = chat.title + ' (ID: ' + chat.id + ')';

            var continueBtn = document.createElement('button');
            continueBtn.type = 'button';
            continueBtn.textContent = 'Fortsetzen';
            continueBtn.style.marginLeft = '8px';
            continueBtn.addEventListener('click', function () {
                var targetUrl = buildChatUrl(chat.id);
                if (!targetUrl) {
                    setStatus('chat_page fehlt im Shortcode.', true);
                    return;
                }
                window.location.href = targetUrl;
            });

            li.appendChild(continueBtn);
            chatList.appendChild(li);
        });
    }

    function loadChats() {
        setStatus('Chats werden geladen...', false);
        request('/rooms/' + roomId + '/chats', { method: 'GET' })
            .then(function (chats) {
                renderChats(chats);
                setStatus('Chats geladen.', false);
            })
            .catch(function (error) {
                setStatus(error.message, true);
            });
    }

    function goBackToRooms(event) {
        event.preventDefault();
        if (!roomsPage) {
            setStatus('rooms_page fehlt im Shortcode.', true);
            return;
        }
        window.location.href = roomsPage;
    }

    function loadRoomLabel() {
        request('/rooms', { method: 'GET' })
            .then(function (rooms) {
                if (!Array.isArray(rooms)) {
                    roomLabelEl.textContent = 'Raum: #' + roomId;
                    return;
                }

                var room = rooms.find(function (entry) {
                    return Number(entry && entry.id) === roomId;
                });

                if (!room) {
                    roomLabelEl.textContent = 'Raum: #' + roomId;
                    return;
                }

                roomLabelEl.textContent = 'Raum: ' + formatRoomDisplayName(room);
            })
            .catch(function () {
                roomLabelEl.textContent = 'Raum: #' + roomId;
            });
    }

    createChatBtn.addEventListener('click', function () {
        var title = (chatTitleInput.value || '').trim() || 'Neuer Chat';

        request('/rooms/' + roomId + '/chats', {
            method: 'POST',
            body: JSON.stringify({ title: title }),
        })
            .then(function (chat) {
                chatTitleInput.value = '';
                var targetUrl = buildChatUrl(chat.id);
                if (!targetUrl) {
                    setStatus('Chat erstellt. Konfigurieren Sie chat_page zum Fortsetzen.', false);
                    loadChats();
                    return;
                }
                window.location.href = targetUrl;
            })
            .catch(function (error) {
                setStatus(error.message, true);
            });
    });

    refreshChatsBtn.addEventListener('click', loadChats);
    backRoomsLink.addEventListener('click', goBackToRooms);

    loadRoomLabel();
    loadChats();
})();
