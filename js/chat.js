// Chat functionality for both tenant and owner dashboards
function initializeChat({
  threadId,
  counterparty,
  bodyEl,
  msgsEl,
  inputEl,
  formEl,
  sendBtn,
  sentinel,
  counterpartyEl,
  threadSelect,
  clearBtn,
  onThreadChange = null,
  role = 'tenant'
}) {
  let loading = false;
  let beforeId = null;
  let reachedTop = false;
  let cooldown = false;
  let channel = null;
  let pusher = null;
  let userCleared = false;
  let lastTimestamp = 0;
  const seenIds = new Set();

  const PUSHER_KEY = 'c9a924289093535f51f9';
  const PUSHER_CLUSTER = 'ap1';

  function setSentinel(text) {
    if (sentinel) sentinel.textContent = text;
  }

  function setInputEnabled(enabled) {
    if (inputEl) inputEl.disabled = !enabled;
    if (sendBtn) sendBtn.disabled = !enabled;
  }

  function formatTime(datetime) {
    if (!datetime) return '';
    const d = datetime instanceof Date ? datetime : new Date(datetime);
    if (Number.isNaN(d.getTime())) return '';

    const now = new Date();
    const diffMs = now - d;
    const diffMins = Math.floor(diffMs / 60000);
    const diffHours = Math.floor(diffMs / 3600000);
    const diffDays = Math.floor(diffMs / 86400000);

    if (diffMins < 1) return 'Just now';
    if (diffMins < 60) return `${diffMins}m ago`;
    if (diffHours < 24) return `${diffHours}h ago`;
    if (diffDays < 7) return `${diffDays}d ago`;

    return d.toLocaleString('en-US', {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
      hour: '2-digit',
      minute: '2-digit'
    });
  }

  function scrollToBottom() {
    if (bodyEl) bodyEl.scrollTop = bodyEl.scrollHeight;
  }

  function addMsg(msg, { prepend = false } = {}) {
    if (!msg || typeof msg !== 'object') return;

    const messageId = String(msg.id ?? msg.message_id ?? `temp-${Date.now()}`);

    let rawBody = msg.body;
    if (rawBody == null || rawBody === '') rawBody = msg.content;
    if (rawBody == null || rawBody === '') rawBody = msg.message;
    const messageBody = typeof rawBody === 'string' ? rawBody : '';

    const createdAt = msg.created_at ?? msg.createdAt ?? msg.time ?? msg.timestamp ?? null;
    const createdDate = createdAt ? new Date(createdAt) : null;
    const createdTimestamp = createdDate && !Number.isNaN(createdDate.getTime())
      ? createdDate.getTime()
      : Date.now();

    const isMine = String(msg.sender_id ?? msg.senderId ?? '') === String(window.HB_CURRENT_USER_ID ?? '');

    if (!msgsEl) return;
    if (seenIds.has(messageId)) {
      const existingDup = Array.from(msgsEl.children || []).find(child => child.dataset.msgId === messageId);
      if (existingDup) {
        const timeDup = existingDup.querySelector('.hb-msg-time');
        if (timeDup) timeDup.textContent = formatTime(createdDate ?? createdTimestamp);
      }
      if (window.console && console.debug) console.debug('chat:dedupe-cache', messageId, msg);
      return;
    }
    const existing = Array.from(msgsEl.children || []).find(child => child.dataset.msgId === messageId);
    if (existing) {
      if (window.console && console.debug) {
        console.debug('chat:dedupe', messageId, msg);
      }
      const timeEl = existing.querySelector('.hb-msg-time');
      if (timeEl) timeEl.textContent = formatTime(createdDate ?? createdTimestamp);
      seenIds.add(messageId);
      return;
    }

    const el = document.createElement('div');
    el.className = `hb-msg ${isMine ? 'mine' : 'their'}`;
    el.dataset.msgId = messageId;
    el.dataset.timestamp = createdTimestamp;

    if (!isMine) {
      const from = document.createElement('div');
      from.className = 'hb-from';
      
      // Handle auto-replies (sender_id = 0) - they should be attributed to the owner
      if (msg.sender_id === 0) {
        // For auto-replies, show as coming from the owner (opposite of current user's role)
        const currentRole = window.HB_CURRENT_USER_ROLE || 'tenant';
        if (currentRole === 'tenant') {
          from.textContent = counterparty; // Owner's name
        } else {
          // If owner is viewing, auto-replies should show as coming from the owner themselves
          from.textContent = 'You'; // Owner sees auto-replies as their own
        }
      } else if (counterparty) {
        from.textContent = counterparty;
      }
      
      el.appendChild(from);
    }

    const contentEl = document.createElement('div');
    contentEl.className = 'hb-msg-content';
    contentEl.innerHTML = escapeHtml(messageBody);
    el.appendChild(contentEl);

    const timeEl = document.createElement('span');
    timeEl.className = 'hb-msg-time';
    timeEl.textContent = formatTime(createdDate ?? createdTimestamp);
    el.appendChild(timeEl);

    function escapeHtml(text) {
      const div = document.createElement('div');
      div.textContent = text;
      return div.innerHTML;
    }

    // Find the correct position to insert the message based on timestamp
    const msgTimestamp = createdTimestamp;
    if (window.console && console.debug) {
      console.debug('chat:add', messageId, msgTimestamp, msg);
    }
    seenIds.add(messageId);

    let inserted = false;
    if (!prepend && msgTimestamp > lastTimestamp) {
      let nextEl = null;
      for (const child of msgsEl.children) {
        const childTimestamp = parseInt(child.dataset.timestamp, 10);
        if (childTimestamp > msgTimestamp) {
          nextEl = child;
          break;
        }
      }

      if (nextEl) {
        msgsEl.insertBefore(el, nextEl);
      } else {
        msgsEl.appendChild(el);
        scrollToBottom();
      }
      inserted = true;
      lastTimestamp = msgTimestamp;
    }

    if (!inserted) {
      if (prepend) {
        msgsEl.insertBefore(el, msgsEl.firstChild);
      } else {
        msgsEl.appendChild(el);
        scrollToBottom();
      }
    }
  }

  async function loadMore() {
    if (loading || reachedTop || userCleared) return;
    loading = true;
    setSentinel('Loading earlier messages…');

    try {
      const firstMsg = msgsEl.firstElementChild;
      const beforeParam = firstMsg ? `&before_id=${firstMsg.dataset.msgId}` : '';
      const res = await fetch(`/api/chat/fetch_messages.php?thread_id=${threadId}${beforeParam}`, {
        credentials: 'include'
      });
      const data = await res.json();

      if (Array.isArray(data.messages)) {
        if (data.messages.length === 0) {
          reachedTop = true;
          setSentinel('Start of conversation');
        } else {
          setSentinel('');
          data.messages.reverse().forEach(m => addMsg(m, { prepend: true }));
        }
      }
    } catch (err) {
      console.error('Failed to load more messages:', err);
      setSentinel('Failed to load messages');
    } finally {
      loading = false;
    }
  }

  async function refreshMessages() {
    if (!threadId || loading || userCleared) return;

    try {
      const res = await fetch(`/api/chat/fetch_messages.php?thread_id=${threadId}`, {
        credentials: 'include'
      });
      const data = await res.json();

      if (Array.isArray(data.messages) && data.messages.length) {
        // Add new messages while maintaining order
        data.messages.forEach(m => addMsg(m, { prepend: false }));
      }
    } catch (err) {
      console.error('Failed to refresh messages:', err);
    }
  }

  function subscribeRealtime() {
    if (!threadId) return;
    if (!pusher) {
      pusher = new Pusher(PUSHER_KEY, {
        cluster: PUSHER_CLUSTER,
        forceTLS: true
      });
    }
    if (channel) {
      pusher.unsubscribe(channel.name);
      channel = null;
    }
    channel = pusher.subscribe(`thread-${threadId}`);
    channel.bind('new-message', msg => {
      addMsg(msg);
      // Expand chat when new message arrives (if function exists)
      if (typeof window.expandChatOnNewMessage === 'function') {
        window.expandChatOnNewMessage();
      }
    });
  }

  function switchThread(newThreadId, newCounterparty) {
    threadId = newThreadId;
    counterparty = newCounterparty;
    window.COUNTERPARTY = counterparty;
    beforeId = null;
    reachedTop = false;
    lastTimestamp = 0;
    userCleared = false;
    seenIds.clear();

    // Update UI
    msgsEl.innerHTML = '';
    if (counterpartyEl) counterpartyEl.textContent = counterparty;
    setSentinel(threadId ? 'Loading…' : 'Select a conversation to view messages');
    setInputEnabled(!!threadId);

    // Load messages and setup realtime
    if (threadId) {
      loadMore();
      subscribeRealtime();
      sessionStorage.removeItem(`chat_cleared_${threadId}`);
    }

    if (onThreadChange) onThreadChange(threadId, counterparty);
  }

  // Set up event listeners
  if (bodyEl) {
    bodyEl.addEventListener('scroll', () => {
      if (bodyEl.scrollTop < 40) {
        if (userCleared) {
          userCleared = false;
          sessionStorage.removeItem(`chat_cleared_${threadId}`);
        }
        loadMore();
      }
    });
  }

  if (formEl) {
    formEl.addEventListener('submit', async (e) => {
      e.preventDefault();
      if (!threadId || cooldown) return;

      const content = inputEl.value.trim();
      if (!content) return;

      cooldown = true;
      setTimeout(() => { cooldown = false; }, 500);

      try {
        const res = await fetch('/api/chat/post_message.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: `thread_id=${threadId}&body=${encodeURIComponent(content)}`,
          credentials: 'include'
        });

        const text = await res.text();
        let data = null;
        try {
          data = JSON.parse(text);
        } catch {
          console.error('chat: unexpected response', text);
        }

        if (data && data.ok) {
          inputEl.value = '';
          inputEl.style.height = 'auto';
          scrollToBottom();
        } else {
          const errMsg = (data && data.error) ? data.error : 'Failed to send message';
          const errorCode = (data && data.code) ? data.code : '';
          
          // Handle specific error codes
          if (errorCode === 'THREAD_NOT_FOUND') {
            alert('This conversation is no longer available. Please refresh the page or start a new conversation.');
          } else if (errorCode === 'NOT_PARTICIPANT') {
            alert('You are not authorized to send messages in this conversation. Please refresh the page.');
          } else {
            throw new Error(errMsg);
          }
        }
      } catch (err) {
        console.error('Failed to send message:', err);
        alert('Failed to send message. Please try again.');
      }
    });
  }

  if (threadSelect) {
    threadSelect.addEventListener('change', () => {
      const threadId = parseInt(threadSelect.value, 10) || 0;
      const option = threadSelect.selectedOptions[0];
      const counterparty = option ? option.dataset.name || 'User' : '';
      switchThread(threadId, counterparty);
    });
  }

  if (clearBtn) {
    clearBtn.addEventListener('click', () => {
      if (!threadId) return;
      msgsEl.innerHTML = '';
      setSentinel('Chat cleared. Send a message or scroll up to reload.');
      userCleared = true;
      sessionStorage.setItem(`chat_cleared_${threadId}`, '1');
    });
  }

  if (inputEl) {
    inputEl.addEventListener('input', () => {
      inputEl.style.height = 'auto';
      inputEl.style.height = Math.min(inputEl.scrollHeight, 140) + 'px';
    });
  }

  // Auto-refresh when page becomes visible
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') {
      refreshMessages();
    }
  });

  // Initial setup
  if (threadId) {
    if (counterpartyEl) counterpartyEl.textContent = counterparty;
    window.COUNTERPARTY = counterparty;
    userCleared = !!sessionStorage.getItem(`chat_cleared_${threadId}`);
    
    if (!userCleared) {
      loadMore();
      subscribeRealtime();
    } else {
      setSentinel('Chat cleared. Send a message or scroll up to reload.');
    }
  }
}
