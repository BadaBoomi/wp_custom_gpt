(function () {
    var root = document.getElementById('wpcgpt-chat-app');
    if (!root || typeof WPCGPT_CHAT_CONFIG === 'undefined') {
        return;
    }

    var USER_AVATAR_SVG =
        '<svg viewBox="0 0 40 40" width="40" height="40" focusable="false">' +
        '<circle cx="20" cy="15" r="6.5" fill="#7d8ea6"></circle>' +
        '<path d="M8 33c0-6.2 5.4-10.5 12-10.5S32 26.8 32 33z" fill="#7d8ea6"></path>' +
        '</svg>';

    var ASSISTANT_AVATAR_SVG =
        '<svg viewBox="0 0 40 40" width="40" height="40" focusable="false">' +
        '<rect x="9" y="11" width="22" height="19" rx="5" fill="#33404f"></rect>' +
        '<circle cx="15.5" cy="19" r="3.2" fill="#ffffff"></circle>' +
        '<circle cx="24.5" cy="19" r="3.2" fill="#ffffff"></circle>' +
        '<rect x="14" y="25" width="12" height="2.4" rx="1.2" fill="#ffffff"></rect>' +
        '</svg>';

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

    function stripSetDirectives(text) {
        var cleaned = text.replace(/\[set\|[^|\]\r\n]+\|[^\]\r\n]*\]/gi, '');
        return cleaned.replace(/\n{3,}/g, '\n\n').trim();
    }

    function normalizeEscapedLineBreaks(text) {
        if (typeof text !== 'string') {
            return '';
        }

        return text
            .replace(/\\r\\n/g, '\n')
            .replace(/\\n/g, '\n')
            .replace(/\\r/g, '\n')
            .replace(/W&amp;W/g, 'W&W');
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
                        vectorStoreId: button.vectorStoreId || '',
                    };
                    setStatus('Konfiguration ausgewaehlt: ' + button.label, false);
                }
                messageInput.focus();
            });
            actionButtonsEl.appendChild(el);
        });
    }

    function formatMessageTime(rawValue) {
        var value = String(rawValue || '').trim();
        if (!value) {
            return '';
        }

        var normalized = value.replace(' ', 'T');
        if (!/(Z|[+-]\d{2}:?\d{2})$/.test(normalized)) {
            normalized += 'Z';
        }

        var date = new Date(normalized);
        if (isNaN(date.getTime())) {
            return '';
        }

        var hours = String(date.getHours()).padStart(2, '0');
        var minutes = String(date.getMinutes()).padStart(2, '0');

        return hours + ':' + minutes;
    }

    function createMessageRow(role, contentText, timeText, isPending) {
        var row = document.createElement('div');
        row.className = 'wpcgpt-chat-row wpcgpt-chat-row--' + (role === 'assistant' ? 'assistant' : 'user');
        if (isPending) {
            row.className += ' wpcgpt-chat-row--pending';
        }

        var avatar = document.createElement('div');
        avatar.className = 'wpcgpt-chat-avatar';
        avatar.setAttribute('aria-hidden', 'true');
        avatar.innerHTML = role === 'assistant' ? ASSISTANT_AVATAR_SVG : USER_AVATAR_SVG;

        var bubble = document.createElement('div');
        bubble.className = 'wpcgpt-chat-bubble';

        var text = document.createElement('div');
        text.className = 'wpcgpt-chat-text';
        text.textContent = contentText;
        bubble.appendChild(text);

        if (timeText) {
            var time = document.createElement('div');
            time.className = 'wpcgpt-chat-time';
            time.textContent = timeText;
            bubble.appendChild(time);
        }

        row.appendChild(avatar);
        row.appendChild(bubble);

        return row;
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
                var vectorStoreId = (entry && entry.vectorStoreId ? String(entry.vectorStoreId) : '').trim();

                if (!label || !content) {
                    return null;
                }

                return {
                    label: label,
                    content: content,
                    promptId: promptId,
                    vectorStoreId: vectorStoreId,
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
            empty.className = 'wpcgpt-chat-empty';
            empty.textContent = 'Noch keine Nachrichten.';
            messageOutput.appendChild(empty);
            renderActionButtons(getConfigurationButtons(), 'configuration');
            lastMessageCount = 0;
            shouldStickToBottom = true;
            return;
        }

        messages.forEach(function (message) {
            var contentText = message.content || '';
            if (message.role === 'assistant') {
                contentText = normalizeEscapedLineBreaks(contentText);
                var extracted = extractInlineResponseButtons(contentText);
                contentText = extracted.cleanedText;
                if (extracted.buttons.length > 0) {
                    latestAssistantButtons = extracted.buttons.map(function (item) {
                        return {
                            label: item.label,
                            content: item.content,
                            promptId: '',
                            vectorStoreId: '',
                        };
                    });
                }
            }

            contentText = stripSetDirectives(contentText);

            if (!contentText.trim()) {
                return;
            }

            messageOutput.appendChild(
                createMessageRow(message.role, contentText, formatMessageTime(message.created_at), false)
            );
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

    function appendPendingUserMessage(contentText) {
        if (!messageOutput) {
            return;
        }

        var row = createMessageRow('user', contentText, formatMessageTime(new Date().toISOString()), true);
        row.setAttribute('data-pending-user-message', '1');
        messageOutput.appendChild(row);
        messageOutput.scrollTop = messageOutput.scrollHeight;
    }

    function loadMessages() {
        setStatus('Nachrichten werden geladen...', false);
        request('/chats/' + chatId + '/messages?limit=150', { method: 'GET' })
            .then(function (messages) {
                renderMessages(messages);
                setStatus('Nachrichten geladen.', false);
            })
            .catch(function (error) {
                setStatus(error.message, true);
            });
    }

    function loadRoomLabel() {
        if (!roomLabelEl || !roomId) {
            return;
        }

        request('/rooms/' + roomId, { method: 'GET' })
            .then(function (room) {
                if (room && room.name) {
                    roomLabelEl.textContent = 'Raum: ' + formatRoomDisplayName(room);
                    return;
                }

                roomLabelEl.textContent = 'Raum: #' + String(roomId);
            })
            .catch(function () {
                roomLabelEl.textContent = 'Raum: #' + String(roomId);
            });
    }

    function goBackToChats(event) {
        event.preventDefault();

        if (!chatsPage) {
            setStatus('chats_page fehlt im Shortcode.', true);
            return;
        }

        var separator = chatsPage.indexOf('?') >= 0 ? '&' : '?';
        window.location.href = chatsPage + separator + 'room_id=' + encodeURIComponent(String(roomId));
    }

    sendMessageBtn.addEventListener('click', function () {
        var message = (messageInput.value || '').trim();
        if (!message) {
            setStatus('Bitte eine Nachricht eingeben.', true);
            return;
        }

        if (sendMessageBtn) {
            sendMessageBtn.disabled = true;
        }

        appendPendingUserMessage(message);
        setStatus('Nachricht wird gesendet...', false);

        request('/chats/' + chatId + '/send', {
            method: 'POST',
            body: JSON.stringify({
                message: message,
                prompt_id: selectedConfiguration && selectedConfiguration.promptId ? selectedConfiguration.promptId : '',
                vector_store_ids: selectedConfiguration && selectedConfiguration.vectorStoreId ? selectedConfiguration.vectorStoreId : '',
            }),
        })
            .then(function () {
                messageInput.value = '';
                setStatus('Antwort wurde gespeichert.', false);
                loadMessages();
            })
            .catch(function (error) {
                setStatus(error.message, true);
                loadMessages();
            })
            .finally(function () {
                if (sendMessageBtn) {
                    sendMessageBtn.disabled = false;
                }
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
