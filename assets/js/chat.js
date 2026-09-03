(function () {
    var root = document.getElementById('wpcgpt-chat-app');
    if (!root || typeof WPCGPT_CHAT_CONFIG === 'undefined') {
        return;
    }

    var messageOutput = document.getElementById('wpcgpt-message-output');
    var statusEl = document.getElementById('wpcgpt-status');
    var actionButtonsEl = document.getElementById('wpcgpt-action-buttons');
    var sendMessageBtn = document.getElementById('wpcgpt-send-message');
    var refreshMessagesBtn = document.getElementById('wpcgpt-refresh-messages');
    var backChatsLink = document.getElementById('wpcgpt-back-chats');
    var messageInput = document.getElementById('wpcgpt-message-input');
    var roomLabelEl = document.getElementById('wpcgpt-room-label');

    var chatId = parseInt(root.getAttribute('data-chat-id') || '0', 10);
    var roomId = parseInt(root.getAttribute('data-room-id') || '0', 10);
    var chatsPage = root.getAttribute('data-chats-page') || '';
    var configurationEntriesRaw = root.getAttribute('data-configuration-entries') || '[]';
    var configurationEntries = [];
    var lastMessageCount = 0;
    var shouldStickToBottom = true;
    var selectedConfiguration = null;

    try {
        configurationEntries = JSON.parse(configurationEntriesRaw);
        if (!Array.isArray(configurationEntries)) {
            configurationEntries = [];
        }
    } catch (error) {
        configurationEntries = [];
    }

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

    function extractInlineResponseButtons(text) {
        var pattern = /\[\[buttons:\s*([\s\S]*?)\]\](?!\])/gi;
        var match;
        var lastBody = '';

        while ((match = pattern.exec(text)) !== null) {
            lastBody = match[1] || '';
        }

        if (!lastBody) {
            return {
                cleanedText: text,
                buttons: [],
            };
        }

        var entryPattern = /\[\s*([^|\]]+?)\s*\|\s*([^\]]*?)\s*\]/g;
        var buttons = [];
        var entry;
        while ((entry = entryPattern.exec(lastBody)) !== null) {
            var label = (entry[1] || '').trim();
            var content = (entry[2] || '').trim();
            if (label && content) {
                buttons.push({ label: label, content: content });
            }
        }

        var cleanedText = text.replace(pattern, '').replace(/\n{3,}/g, '\n\n').trim();

        return {
            cleanedText: cleanedText,
            buttons: buttons,
        };
    }

    function renderActionButtons(buttons, mode) {
        if (!actionButtonsEl) {
            return;
        }

        actionButtonsEl.innerHTML = '';

        if (!buttons.length) {
            return;
        }

        buttons.forEach(function (button) {
            var el = document.createElement('button');
            el.type = 'button';
            el.textContent = button.label;
            el.style.border = '1px solid #0a58ca';
            el.style.background = '#eef4ff';
            el.style.color = '#0a58ca';
            el.style.borderRadius = '14px';
            el.style.padding = '4px 10px';
            el.style.cursor = 'pointer';
            el.addEventListener('click', function () {
                messageInput.value = button.content;
                if (mode === 'configuration') {
                    selectedConfiguration = {
                        label: button.label,
                        promptId: button.promptId || '',
                    };
                    setStatus('Selected configuration: ' + button.label, false);
                }
                messageInput.focus();
            });
            actionButtonsEl.appendChild(el);
        });
    }

    function isNearBottom() {
        var threshold = 16;
        return messageOutput.scrollHeight - messageOutput.scrollTop - messageOutput.clientHeight <= threshold;
    }

    function getConfigurationButtons() {
        return configurationEntries
            .map(function (entry) {
                var label = (entry && entry.label ? String(entry.label) : '').trim();
                var content = (entry && entry.prompt ? String(entry.prompt) : '').trim();
                var promptId = (entry && entry.promptId ? String(entry.promptId) : '').trim();

                if (!label || !content) {
                    return null;
                }

                return {
                    label: label,
                    content: content,
                    promptId: promptId,
                };
            })
            .filter(function (item) {
                return item !== null;
            });
    }

    function renderMessages(messages) {
        var hasNewMessages = messages.length > lastMessageCount;
        var shouldAutoScroll = hasNewMessages && shouldStickToBottom;
        var latestAssistantButtons = [];

        messageOutput.innerHTML = '';

        if (!messages.length) {
            var empty = document.createElement('div');
            empty.textContent = 'No messages yet.';
            empty.style.color = '#6a737d';
            messageOutput.appendChild(empty);
            renderActionButtons(getConfigurationButtons(), 'configuration');
            lastMessageCount = 0;
            shouldStickToBottom = true;
            return;
        }

        messages.forEach(function (message) {
            var contentText = message.content || '';
            if (message.role === 'assistant') {
                var extracted = extractInlineResponseButtons(contentText);
                contentText = extracted.cleanedText;
                if (extracted.buttons.length > 0) {
                    latestAssistantButtons = extracted.buttons.map(function (item) {
                        return {
                            label: item.label,
                            content: item.content,
                            promptId: '',
                        };
                    });
                }
            }

            if (!contentText.trim()) {
                return;
            }

            var wrapper = document.createElement('div');
            wrapper.style.marginBottom = '10px';

            var role = document.createElement('div');
            role.textContent = String(message.role || '').toUpperCase();
            role.style.fontWeight = '600';
            role.style.fontSize = '12px';
            role.style.color = message.role === 'assistant' ? '#0a58ca' : '#1f2328';

            var content = document.createElement('div');
            content.style.whiteSpace = 'pre-wrap';
            content.style.lineHeight = '1.45';
            content.style.padding = '6px 0';
            content.textContent = contentText;

            wrapper.appendChild(role);
            wrapper.appendChild(content);
            messageOutput.appendChild(wrapper);
        });

        if (latestAssistantButtons.length > 0) {
            renderActionButtons(latestAssistantButtons, 'response');
        } else {
            renderActionButtons(getConfigurationButtons(), 'configuration');
        }

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

    function loadRoomLabel() {
        if (!roomLabelEl || !roomId) {
            return;
        }

        request('/rooms', { method: 'GET' })
            .then(function (rooms) {
                if (!Array.isArray(rooms)) {
                    roomLabelEl.textContent = 'Room: #' + String(roomId);
                    return;
                }

                var room = rooms.find(function (entry) {
                    return Number(entry && entry.id) === roomId;
                });

                if (room && room.name) {
                    roomLabelEl.textContent = 'Room: ' + String(room.name);
                    return;
                }

                roomLabelEl.textContent = 'Room: #' + String(roomId);
            })
            .catch(function () {
                roomLabelEl.textContent = 'Room: #' + String(roomId);
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
            body: JSON.stringify({
                message: message,
                prompt_id: selectedConfiguration && selectedConfiguration.promptId ? selectedConfiguration.promptId : '',
            }),
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

    loadRoomLabel();
    loadMessages();
})();
