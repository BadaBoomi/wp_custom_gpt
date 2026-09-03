(function () {
    var root = document.getElementById('wpcgpt-app');
    if (!root || typeof WPCGPT_CONFIG === 'undefined') {
        return;
    }

    var roomList = document.getElementById('wpcgpt-room-list');
    var chatList = document.getElementById('wpcgpt-chat-list');
    var messageList = document.getElementById('wpcgpt-message-list');
    var statusEl = document.getElementById('wpcgpt-status');
    var createBtn = document.getElementById('wpcgpt-create-room');
    var createChatBtn = document.getElementById('wpcgpt-create-chat');
    var sendMessageBtn = document.getElementById('wpcgpt-send-message');
    var refreshBtn = document.getElementById('wpcgpt-refresh');
    var nameInput = document.getElementById('wpcgpt-room-name');
    var chatTitleInput = document.getElementById('wpcgpt-chat-title');
    var messageInput = document.getElementById('wpcgpt-message-input');
    var selectedRoomId = null;
    var selectedChatId = null;

    function setStatus(message, isError) {
        statusEl.textContent = message;
        statusEl.style.color = isError ? '#b00020' : '#2d6a4f';
    }

    function request(path, options) {
        var requestOptions = Object.assign(
            {
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': WPCGPT_CONFIG.nonce,
                },
            },
            options || {}
        );

        return fetch(WPCGPT_CONFIG.restBase + path, requestOptions).then(function (response) {
            return response.json().then(function (body) {
                if (!response.ok) {
                    var message = (body && body.message) || 'Anfrage fehlgeschlagen.';
                    throw new Error(message);
                }
                return body;
            });
        });
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
            li.textContent = room.name + ' (ID: ' + room.id + ')';

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

            var openBtn = document.createElement('button');
            openBtn.type = 'button';
            openBtn.textContent = 'Oeffnen';
            openBtn.style.marginLeft = '8px';
            openBtn.addEventListener('click', function () {
                selectedRoomId = room.id;
                setStatus('Ausgewaehlter Raum: ' + room.name, false);
                loadChats(room.id);
            });

            li.appendChild(openBtn);
            roomList.appendChild(li);
        });
    }

    function renderChats(chats) {
        chatList.innerHTML = '';

        if (!chats.length) {
            var empty = document.createElement('li');
            empty.textContent = 'Keine Chats im ausgewaehlten Raum.';
            chatList.appendChild(empty);
            selectedChatId = null;
            messageList.innerHTML = '';
            return;
        }

        chats.forEach(function (chat) {
            var li = document.createElement('li');
            li.textContent = chat.title + ' (ID: ' + chat.id + ')';

            var openBtn = document.createElement('button');
            openBtn.type = 'button';
            openBtn.textContent = 'Chat oeffnen';
            openBtn.style.marginLeft = '8px';
            openBtn.addEventListener('click', function () {
                selectedChatId = chat.id;
                setStatus('Ausgewaehlter Chat: ' + chat.title, false);
                loadMessages(chat.id);
            });

            li.appendChild(openBtn);
            chatList.appendChild(li);
        });
    }

    function renderMessages(messages) {
        messageList.innerHTML = '';

        if (!messages.length) {
            var empty = document.createElement('li');
            empty.textContent = 'Noch keine Nachrichten.';
            messageList.appendChild(empty);
            return;
        }

        messages.forEach(function (message) {
            var li = document.createElement('li');
            li.textContent = message.role + ': ' + message.content;
            messageList.appendChild(li);
        });
    }

    function loadMessages(chatId) {
        request('/chats/' + chatId + '/messages', { method: 'GET' })
            .then(function (messages) {
                renderMessages(messages);
            })
            .catch(function (error) {
                setStatus(error.message, true);
            });
    }

    function loadChats(roomId) {
        request('/rooms/' + roomId + '/chats', { method: 'GET' })
            .then(function (chats) {
                renderChats(chats);
            })
            .catch(function (error) {
                setStatus(error.message, true);
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

    createChatBtn.addEventListener('click', function () {
        if (!selectedRoomId) {
            setStatus('Bitte zuerst einen Raum waehlen.', true);
            return;
        }

        var title = (chatTitleInput.value || '').trim() || 'Neuer Chat';

        request('/rooms/' + selectedRoomId + '/chats', {
            method: 'POST',
            body: JSON.stringify({ title: title }),
        })
            .then(function () {
                chatTitleInput.value = '';
                setStatus('Chat erstellt.', false);
                loadChats(selectedRoomId);
            })
            .catch(function (error) {
                setStatus(error.message, true);
            });
    });

    sendMessageBtn.addEventListener('click', function () {
        if (!selectedChatId) {
            setStatus('Bitte zuerst einen Chat waehlen.', true);
            return;
        }

        var message = (messageInput.value || '').trim();
        if (!message) {
            setStatus('Bitte eine Nachricht eingeben.', true);
            return;
        }

        setStatus('Nachricht wird gesendet...', false);

        request('/chats/' + selectedChatId + '/send', {
            method: 'POST',
            body: JSON.stringify({ message: message }),
        })
            .then(function () {
                messageInput.value = '';
                setStatus('Antwort wurde gespeichert.', false);
                loadMessages(selectedChatId);
            })
            .catch(function (error) {
                setStatus(error.message, true);
            });
    });

    loadRooms();
})();
