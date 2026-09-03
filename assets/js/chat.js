(function () {
    var root = document.getElementById('wpcgpt-chat-app');
    if (!root || typeof WPCGPT_CHAT_CONFIG === 'undefined') {
        return;
    }

    var messageList = document.getElementById('wpcgpt-message-list');
    var statusEl = document.getElementById('wpcgpt-status');
    var sendMessageBtn = document.getElementById('wpcgpt-send-message');
    var refreshMessagesBtn = document.getElementById('wpcgpt-refresh-messages');
    var backChatsLink = document.getElementById('wpcgpt-back-chats');
    var messageInput = document.getElementById('wpcgpt-message-input');

    var chatId = parseInt(root.getAttribute('data-chat-id') || '0', 10);
    var roomId = parseInt(root.getAttribute('data-room-id') || '0', 10);
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
                    'X-WP-Nonce': WPCGPT_CHAT_CONFIG.nonce,
                },
            },
            options || {}
        );

        return fetch(WPCGPT_CHAT_CONFIG.restBase + path, requestOptions).then(function (response) {
            return response.json().then(function (body) {
                if (!response.ok) {
                    throw new Error((body && body.message) || 'Request failed.');
                }
                return body;
            });
        });
    }

    function renderMessages(messages) {
        messageList.innerHTML = '';

        if (!messages.length) {
            var empty = document.createElement('li');
            empty.textContent = 'No messages yet.';
            messageList.appendChild(empty);
            return;
        }

        messages.forEach(function (message) {
            var li = document.createElement('li');
            li.textContent = message.role + ': ' + message.content;
            messageList.appendChild(li);
        });
    }

    function loadMessages() {
        setStatus('Loading messages...', false);
        request('/chats/' + chatId + '/messages', { method: 'GET' })
            .then(function (messages) {
                renderMessages(messages);
                setStatus('Messages loaded.', false);
            })
            .catch(function (error) {
                setStatus(error.message, true);
            });
    }

    function goBackToChats(event) {
        event.preventDefault();

        if (!chatsPage) {
            setStatus('Missing chats_page in shortcode.', true);
            return;
        }

        var separator = chatsPage.indexOf('?') >= 0 ? '&' : '?';
        window.location.href = chatsPage + separator + 'room_id=' + encodeURIComponent(String(roomId));
    }

    sendMessageBtn.addEventListener('click', function () {
        var message = (messageInput.value || '').trim();
        if (!message) {
            setStatus('Please enter a message.', true);
            return;
        }

        setStatus('Sending message to OpenAI...', false);

        request('/chats/' + chatId + '/send', {
            method: 'POST',
            body: JSON.stringify({ message: message }),
        })
            .then(function () {
                messageInput.value = '';
                setStatus('Assistant response saved.', false);
                loadMessages();
            })
            .catch(function (error) {
                setStatus(error.message, true);
            });
    });

    refreshMessagesBtn.addEventListener('click', loadMessages);
    backChatsLink.addEventListener('click', goBackToChats);

    loadMessages();
})();
