(function () {
    var root = document.getElementById('wpcgpt-chat-app');
    if (!root || typeof WPCGPT_CHAT_CONFIG === 'undefined') {
        return;
    }

    var messageOutput = document.getElementById('wpcgpt-message-output');
    var statusEl = document.getElementById('wpcgpt-status');
    var sendMessageBtn = document.getElementById('wpcgpt-send-message');
    var refreshMessagesBtn = document.getElementById('wpcgpt-refresh-messages');
    var backChatsLink = document.getElementById('wpcgpt-back-chats');
    var messageInput = document.getElementById('wpcgpt-message-input');

    var chatId = parseInt(root.getAttribute('data-chat-id') || '0', 10);
    var roomId = parseInt(root.getAttribute('data-room-id') || '0', 10);
    var chatsPage = root.getAttribute('data-chats-page') || '';
    var lastMessageCount = 0;
    var shouldStickToBottom = true;

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

    function isNearBottom() {
        var threshold = 16;
        return messageOutput.scrollHeight - messageOutput.scrollTop - messageOutput.clientHeight <= threshold;
    }

    function renderMessages(messages) {
        var hasNewMessages = messages.length > lastMessageCount;
        var shouldAutoScroll = hasNewMessages && shouldStickToBottom;

        messageOutput.innerHTML = '';

        if (!messages.length) {
            var empty = document.createElement('div');
            empty.textContent = 'No messages yet.';
            empty.style.color = '#6a737d';
            messageOutput.appendChild(empty);
            lastMessageCount = 0;
            shouldStickToBottom = true;
            return;
        }

        messages.forEach(function (message) {
            var wrapper = document.createElement('div');
            wrapper.style.marginBottom = '10px';

            var role = document.createElement('div');
            role.textContent = String(message.role || '').toUpperCase();
            role.style.fontWeight = '600';
            role.style.fontSize = '12px';
            role.style.color = message.role === 'assistant' ? '#0a58ca' : '#1f2328';

            var content = document.createElement('div');
            content.textContent = message.content || '';
            content.style.whiteSpace = 'pre-wrap';
            content.style.lineHeight = '1.45';
            content.style.padding = '6px 0';

            wrapper.appendChild(role);
            wrapper.appendChild(content);
            messageOutput.appendChild(wrapper);
        });

        if (shouldAutoScroll) {
            messageOutput.scrollTop = messageOutput.scrollHeight;
        }

        lastMessageCount = messages.length;
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
    messageOutput.addEventListener('scroll', function () {
        shouldStickToBottom = isNearBottom();
    });

    loadMessages();
})();
