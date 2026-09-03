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
                    throw new Error((body && body.message) || 'Request failed.');
                }
                return body;
            });
        });
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
            empty.textContent = 'No chats in this room yet.';
            chatList.appendChild(empty);
            return;
        }

        chats.forEach(function (chat) {
            var li = document.createElement('li');
            li.textContent = chat.title + ' (ID: ' + chat.id + ')';

            var continueBtn = document.createElement('button');
            continueBtn.type = 'button';
            continueBtn.textContent = 'Continue';
            continueBtn.style.marginLeft = '8px';
            continueBtn.addEventListener('click', function () {
                var targetUrl = buildChatUrl(chat.id);
                if (!targetUrl) {
                    setStatus('Missing chat_page in shortcode.', true);
                    return;
                }
                window.location.href = targetUrl;
            });

            li.appendChild(continueBtn);
            chatList.appendChild(li);
        });
    }

    function loadChats() {
        setStatus('Loading chats...', false);
        request('/rooms/' + roomId + '/chats', { method: 'GET' })
            .then(function (chats) {
                renderChats(chats);
                setStatus('Chats loaded.', false);
            })
            .catch(function (error) {
                setStatus(error.message, true);
            });
    }

    function goBackToRooms(event) {
        event.preventDefault();
        if (!roomsPage) {
            setStatus('Missing rooms_page in shortcode.', true);
            return;
        }
        window.location.href = roomsPage;
    }

    createChatBtn.addEventListener('click', function () {
        var title = (chatTitleInput.value || '').trim() || 'New Chat';

        request('/rooms/' + roomId + '/chats', {
            method: 'POST',
            body: JSON.stringify({ title: title }),
        })
            .then(function (chat) {
                chatTitleInput.value = '';
                var targetUrl = buildChatUrl(chat.id);
                if (!targetUrl) {
                    setStatus('Chat created. Configure chat_page to continue.', false);
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

    roomLabelEl.textContent = 'Selected room ID: ' + roomId;
    loadChats();
})();
