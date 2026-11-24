(function () {
    "use strict";

    const body = document.body;
    const page = body.dataset.page;
    const apiUrl = body.dataset.apiUrl || "api.php";
    const websocketEnabled = body.dataset.websocketEnabled !== "false";
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || "";

    function apiRequest(action, options) {
        const requestOptions = options || {};
        const method = (requestOptions.method || "GET").toUpperCase();
        const headers = {
            Accept: "application/json",
            ...(requestOptions.headers || {}),
        };
        let requestBody = requestOptions.body;

        if (method !== "GET" && method !== "HEAD") {
            headers["X-CSRF-TOKEN"] = csrfToken;
        }

        if (requestBody && !(requestBody instanceof FormData) && !(requestBody instanceof URLSearchParams)) {
            headers["Content-Type"] = headers["Content-Type"] || "application/json";
            if (headers["Content-Type"].includes("application/json") && typeof requestBody !== "string") {
                requestBody = JSON.stringify(requestBody);
            }
        }

        const url = new URL(apiUrl, window.location.href);
        url.searchParams.set("action", action);

        return fetch(url.toString(), {
            method,
            headers,
            body: requestBody,
            credentials: "same-origin",
        }).then(async (response) => {
            const raw = await response.text();
            let data = {};

            try {
                data = raw ? JSON.parse(raw) : {};
            } catch (error) {
                data = { message: raw || "The server returned an invalid response." };
            }

            if (!response.ok) {
                const requestError = new Error(data.message || `Request failed (${response.status})`);
                requestError.status = response.status;
                requestError.data = data;
                throw requestError;
            }

            return data;
        });
    }

    function errorMessage(error) {
        if (!error) return "Something went wrong. Please try again.";
        const errors = error.data?.errors;
        if (errors && typeof errors === "object") {
            const messages = Object.values(errors).flat().filter(Boolean);
            if (messages.length) return messages.join(" ");
        }
        return error.message || "Something went wrong. Please try again.";
    }

    function handleUnauthorized(error) {
        if (error?.status === 401) {
            window.location.href = "index.php?page=login";
            return true;
        }
        return false;
    }

    function profilePictureUrl(filename) {
        const value = String(filename || "");
        if (!value || value === "default.png") return "assets/default-avatar.png";
        const cleanName = value.split(/[\\/]/).pop();
        return `uploads/${encodeURIComponent(cleanName)}`;
    }

    function initAuth() {
        const form = document.getElementById("auth-form");
        const errorBox = document.getElementById("form-error");
        if (!form) return;

        form.addEventListener("submit", async (event) => {
            event.preventDefault();
            errorBox.hidden = true;
            errorBox.textContent = "";

            const button = form.querySelector("button[type=submit]");
            const action = page === "register" ? "signup" : "login";
            const data = Object.fromEntries(new FormData(form).entries());

            if (page === "register" && data.password !== data.password_confirmation) {
                errorBox.textContent = "Passwords do not match.";
                errorBox.hidden = false;
                return;
            }

            button.disabled = true;
            button.textContent = page === "register" ? "Creating account..." : "Signing in...";

            try {
                const response = await apiRequest(action, { method: "POST", body: data });
                window.location.href = page !== "register" && response.data?.role === "admin"
                    ? "admin.php"
                    : "index.php";
            } catch (error) {
                errorBox.textContent = errorMessage(error);
                errorBox.hidden = false;
                button.disabled = false;
                button.textContent = page === "register" ? "Register" : "Sign in";
            }
        });
    }

    function initChat() {
        const currentUserId = Number(body.dataset.userId || 0);
        const contactsElement = document.getElementById("contacts");
        const searchElement = document.getElementById("contact-search");
        const toggleUsersButton = document.getElementById("toggle-users");
        const chatPanel = document.getElementById("chat-panel");
        const logoutButton = document.getElementById("logout-button");
        const profileTrigger = document.getElementById("profile-trigger");
        const profileOverlay = document.getElementById("profile-overlay");
        const profileClose = document.getElementById("profile-close");
        const profileForm = document.getElementById("profile-form");
        const profileMessage = document.getElementById("profile-message");
        const profilePreview = document.getElementById("profile-preview");
        const currentAvatar = document.getElementById("current-avatar");
        const profilePicture = document.getElementById("profile-picture");
        const fileName = document.getElementById("file-name");

        const state = {
            activeContact: null,
            contacts: [],
            messages: [],
            showAllUsers: false,
            search: "",
            socket: null,
            reconnectTimer: null,
            messagePollTimer: null,
            reconnectDelay: 1500,
            shouldScroll: true,
            sending: false,
            pendingClientId: null,
            chatError: "",
        };

        function contactId(contact) {
            return Number(contact?.user_id ?? contact?.id ?? 0);
        }

        function setSocketStatus(label, className) {
            const status = document.getElementById("socket-status");
            if (!status) return;
            status.textContent = label;
            status.className = `sr-only socket-status ${className || ""}`.trim();
        }

        function renderContacts() {
            contactsElement.replaceChildren();

            if (!state.contacts.length) {
                const empty = document.createElement("p");
                empty.className = "no-contacts";
                empty.textContent = state.showAllUsers ? "No users found" : "No contacts found";
                contactsElement.appendChild(empty);
                return;
            }

            state.contacts.forEach((contact) => {
                const button = document.createElement("button");
                const id = contactId(contact);
                button.type = "button";
                button.className = `contact ${contactId(state.activeContact) === id ? "active" : ""}`;

                const image = document.createElement("img");
                image.src = profilePictureUrl(contact.profile_picture);
                image.alt = contact.full_name || "Contact";

                const meta = document.createElement("span");
                meta.className = "meta";
                const name = document.createElement("span");
                name.className = "name";
                name.textContent = contact.full_name || "Unnamed user";
                const username = document.createElement("span");
                username.className = "small";
                username.textContent = `@${contact.username || "user"}`;
                meta.append(name, username);
                button.append(image, meta);

                const unread = Number(contact.unread_count || contact.unread_messages || 0);
                if (unread > 0) {
                    const badge = document.createElement("span");
                    badge.className = "badge";
                    badge.textContent = String(unread);
                    button.appendChild(badge);
                }

                button.addEventListener("click", () => openConversation(contact));
                contactsElement.appendChild(button);
            });
        }

        async function loadContacts() {
            try {
                const action = state.showAllUsers ? "users" : "contacts";
                const query = state.search.trim();
                const url = new URL(apiUrl, window.location.href);
                url.searchParams.set("action", action);
                if (query) url.searchParams.set("search", query);

                const response = await fetch(url.toString(), {
                    headers: { Accept: "application/json" },
                    credentials: "same-origin",
                });
                const data = await response.json();
                if (!response.ok) {
                    const requestError = new Error(data.message || "Unable to load contacts.");
                    requestError.status = response.status;
                    throw requestError;
                }

                state.contacts = Array.isArray(data.data) ? data.data : [];
                renderContacts();
            } catch (error) {
                if (!handleUnauthorized(error)) {
                    contactsElement.replaceChildren();
                    const message = document.createElement("p");
                    message.className = "no-contacts";
                    message.textContent = errorMessage(error);
                    contactsElement.appendChild(message);
                }
            }
        }

        function renderChatShell(contact) {
            chatPanel.className = "chat";
            chatPanel.replaceChildren();

            const header = document.createElement("header");
            header.className = "chat-header";
            const title = document.createElement("strong");
            title.textContent = contact.full_name || "Contact";
            const username = document.createElement("span");
            username.className = "small";
            username.textContent = `@${contact.username || "user"}`;
            const status = document.createElement("span");
            status.id = "socket-status";
            status.className = "sr-only";
            status.textContent = websocketEnabled && state.socket?.readyState === 1 ? "Live" : "HTTP polling";
            header.append(title, username, status);

            const messages = document.createElement("div");
            messages.id = "messages";
            messages.className = "messages";

            const composer = document.createElement("form");
            composer.id = "composer";
            composer.className = "composer";
            const textarea = document.createElement("textarea");
            textarea.id = "message-input";
            textarea.maxLength = 5000;
            textarea.placeholder = "Type your message...";
            textarea.setAttribute("aria-label", "Message");
            const sendButton = document.createElement("button");
            sendButton.type = "submit";
            sendButton.id = "send-button";
            sendButton.disabled = true;
            sendButton.textContent = "Send";
            composer.append(textarea, sendButton);

            composer.addEventListener("submit", (event) => {
                event.preventDefault();
                sendMessage(textarea.value, textarea, sendButton);
            });
            textarea.addEventListener("keydown", (event) => {
                if (event.key === "Enter" && !event.shiftKey) {
                    event.preventDefault();
                    composer.requestSubmit();
                }
            });
            textarea.addEventListener("input", () => {
                if (!state.sending) {
                    sendButton.disabled = !textarea.value.trim();
                }
            });

            chatPanel.append(header, messages, composer);
            renderMessages();
        }

        function messageBelongsToActive(message) {
            const activeId = contactId(state.activeContact);
            return activeId > 0 && ((Number(message.sender_id) === currentUserId && Number(message.receiver_id) === activeId)
                || (Number(message.sender_id) === activeId && Number(message.receiver_id) === currentUserId));
        }

        function formatTime(value) {
            if (!value) return "";
            const normalized = String(value).includes(" ") ? String(value).replace(" ", "T") + "Z" : String(value);
            const date = new Date(normalized);
            if (Number.isNaN(date.getTime())) return String(value);
            return date.toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" });
        }

        function renderMessages() {
            const messagesElement = document.getElementById("messages");
            if (!messagesElement) return;
            const previousScroll = messagesElement.scrollHeight - messagesElement.scrollTop - messagesElement.clientHeight;
            messagesElement.replaceChildren();

            if (state.chatError) {
                const error = document.createElement("p");
                error.className = "chat-error";
                error.textContent = state.chatError;
                messagesElement.appendChild(error);
            }

            if (!state.messages.length) {
                const empty = document.createElement("p");
                empty.className = "no-messages";
                empty.textContent = "No messages yet";
                messagesElement.appendChild(empty);
            } else {
                state.messages.forEach((message) => {
                    const item = document.createElement("div");
                    item.className = `msg ${Number(message.sender_id) === currentUserId ? "from-me" : "from-them"}`;
                    const text = document.createElement("div");
                    text.className = "text";
                    text.textContent = message.content || "";
                    const time = document.createElement("div");
                    time.className = "time";
                    time.textContent = formatTime(message.created_at);
                    item.append(text, time);
                    if (Number(message.sender_id) !== currentUserId && message.id) {
                        const report = document.createElement("button");
                        report.type = "button";
                        report.className = "message-report";
                        report.textContent = "Report";
                        report.title = "Report this message";
                        report.addEventListener("click", async () => {
                            const reason = window.prompt("Why are you reporting this message?", "Inappropriate content");
                            if (!reason) return;
                            report.disabled = true;
                            try {
                                await apiRequest("report-message", {
                                    method: "POST",
                                    body: { message_id: message.id, reason },
                                });
                                report.textContent = "Reported";
                            } catch (error) {
                                report.disabled = false;
                                if (!handleUnauthorized(error)) {
                                    state.chatError = errorMessage(error);
                                    renderMessages();
                                }
                            }
                        });
                        item.appendChild(report);
                    }
                    messagesElement.appendChild(item);
                });
            }

            if (state.shouldScroll) {
                messagesElement.scrollTop = messagesElement.scrollHeight;
                state.shouldScroll = false;
            } else {
                messagesElement.scrollTop = Math.max(0, messagesElement.scrollHeight - messagesElement.clientHeight - previousScroll);
            }
        }

        async function loadMessages(contact, options) {
            const id = contactId(contact);
            if (!id) return;
            const initialLoad = Boolean(options?.initial);

            if (initialLoad) {
                state.messages = [];
                state.chatError = "";
                state.shouldScroll = true;
                renderMessages();
            }

            try {
                const response = await apiRequest("messages", {
                    method: "POST",
                    headers: { "Content-Type": "application/x-www-form-urlencoded;charset=UTF-8" },
                    body: new URLSearchParams({ id: String(id) }),
                });
                if (contactId(state.activeContact) !== id) return;
                const nextMessages = Array.isArray(response.data) ? response.data : [];
                const changed = nextMessages.length !== state.messages.length
                    || nextMessages.some((message, index) => {
                        const previous = state.messages[index];
                        return !previous
                            || Number(previous.id) !== Number(message.id)
                            || previous.content !== message.content
                            || previous.status !== message.status;
                    });
                const messagesElement = document.getElementById("messages");
                const nearBottom = !messagesElement
                    || messagesElement.scrollHeight - messagesElement.scrollTop - messagesElement.clientHeight < 80;

                state.messages = nextMessages;
                state.chatError = "";
                if (initialLoad || changed) {
                    state.shouldScroll = initialLoad || nearBottom;
                    renderMessages();
                }
                sendRead(id);
                if (initialLoad || changed) loadContacts();
            } catch (error) {
                if (!handleUnauthorized(error) && contactId(state.activeContact) === id) {
                    state.chatError = errorMessage(error);
                    renderMessages();
                }
            }
        }

        function openConversation(contact) {
            if (state.messagePollTimer) {
                window.clearInterval(state.messagePollTimer);
                state.messagePollTimer = null;
            }
            state.activeContact = contact;
            state.contacts = state.contacts.map((item) => contactId(item) === contactId(contact)
                ? { ...item, unread_count: 0 }
                : item);
            renderContacts();
            renderChatShell(contact);
            loadMessages(contact, { initial: true });
            state.messagePollTimer = window.setInterval(() => {
                if (contactId(state.activeContact) === contactId(contact)) {
                    loadMessages(state.activeContact);
                }
            }, 2000);
            sendRead(contactId(contact));
        }

        function updateSendState(sending, textarea, button) {
            state.sending = sending;
            if (textarea) textarea.disabled = sending;
            if (button) {
                button.disabled = sending || !textarea?.value.trim();
                button.textContent = sending ? "Sending..." : "Send";
            }
        }

        function addMessage(message) {
            if (!message || state.messages.some((item) => Number(item.id) === Number(message.id))) return;
            state.messages.push(message);
            state.messages.sort((left, right) => {
                const leftTime = `${left.created_at || ""}-${left.id || ""}`;
                const rightTime = `${right.created_at || ""}-${right.id || ""}`;
                return leftTime.localeCompare(rightTime);
            });
            state.shouldScroll = true;
            renderMessages();
        }

        function sendRead(userId) {
            if (!userId || state.socket?.readyState !== WebSocket.OPEN) return;
            state.socket.send(JSON.stringify({ type: "mark_read", user_id: userId }));
        }

        async function sendMessage(rawContent, textarea, button) {
            const content = String(rawContent || "").trim();
            const receiverId = contactId(state.activeContact);
            if (!content || !receiverId || state.sending) return;

            state.chatError = "";
            updateSendState(true, textarea, button);

            if (state.socket?.readyState === WebSocket.OPEN) {
                state.pendingClientId = `${Date.now()}-${Math.random().toString(16).slice(2)}`;
                state.socket.send(JSON.stringify({
                    type: "send_message",
                    receiver_id: receiverId,
                    content,
                    client_id: state.pendingClientId,
                }));
                textarea.value = "";
                updateSendState(false, textarea, button);
                return;
            }

            try {
                const response = await apiRequest("send-message", {
                    method: "POST",
                    body: { receiver_id: receiverId, content },
                });
                addMessage(response.data);
                textarea.value = "";
                loadContacts();
            } catch (error) {
                if (!handleUnauthorized(error)) {
                    state.chatError = errorMessage(error);
                    renderMessages();
                }
            } finally {
                updateSendState(false, textarea, button);
            }
        }

        function scheduleReconnect() {
            if (state.reconnectTimer || !document.body.isConnected) return;
            state.reconnectTimer = window.setTimeout(() => {
                state.reconnectTimer = null;
                connectSocket();
            }, state.reconnectDelay);
            state.reconnectDelay = Math.min(10000, state.reconnectDelay * 1.5);
        }

        function connectSocket() {
            if (!websocketEnabled) {
                setSocketStatus("HTTP polling", "offline");
                return;
            }

            if (!window.WebSocket) {
                setSocketStatus("HTTP fallback", "offline");
                return;
            }

            const socketPort = body.dataset.websocketPort || "8080";
            const socketScheme = window.location.protocol === "https:" ? "wss" : "ws";
            const socketUrl = `${socketScheme}://${window.location.hostname}:${socketPort}`;
            if (!socketUrl) return;

            try {
                const socket = new WebSocket(socketUrl);
                state.socket = socket;
                setSocketStatus("Connecting", "");

                socket.addEventListener("open", () => {
                    state.reconnectDelay = 1500;
                    setSocketStatus("Live", "live");
                    socket.send(JSON.stringify({ type: "hello" }));
                });

                socket.addEventListener("message", (event) => {
                    let packet;
                    try {
                        packet = JSON.parse(event.data);
                    } catch (error) {
                        return;
                    }

                    if (packet.type === "ready") {
                        setSocketStatus("Live", "live");
                        return;
                    }
                    if (packet.type === "error") {
                        state.chatError = packet.message || "The message could not be sent.";
                        renderMessages();
                        return;
                    }
                    if (packet.type !== "message" || !packet.data) return;

                    const message = packet.data;
                    if (messageBelongsToActive(message)) {
                        addMessage(message);
                        if (Number(message.receiver_id) === currentUserId) {
                            sendRead(Number(message.sender_id));
                        }
                    }
                    loadContacts();
                });

                socket.addEventListener("error", () => {
                    setSocketStatus("Offline", "offline");
                });

                socket.addEventListener("close", () => {
                    if (state.socket === socket) {
                        setSocketStatus("Reconnecting", "offline");
                        scheduleReconnect();
                    }
                });
            } catch (error) {
                setSocketStatus("HTTP fallback", "offline");
                scheduleReconnect();
            }
        }

        function showProfileMessage(message, isError) {
            profileMessage.textContent = message;
            profileMessage.classList.toggle("error", Boolean(isError));
            profileMessage.hidden = !message;
        }

        profileTrigger?.addEventListener("click", async () => {
            profileOverlay.hidden = false;
            showProfileMessage("", false);
            try {
                const response = await apiRequest("profile");
                const profile = response.data || {};
                document.getElementById("profile-name").value = profile.full_name || "";
                const image = profilePictureUrl(profile.profile_picture);
                profilePreview.src = image;
                currentAvatar.src = image;
            } catch (error) {
                if (!handleUnauthorized(error)) showProfileMessage(errorMessage(error), true);
            }
        });

        function closeProfile() {
            profileOverlay.hidden = true;
        }

        profileClose?.addEventListener("click", closeProfile);
        profileOverlay?.addEventListener("click", (event) => {
            if (event.target === profileOverlay) closeProfile();
        });

        profilePicture?.addEventListener("change", () => {
            const selected = profilePicture.files?.[0];
                if (fileName) fileName.textContent = selected ? selected.name : "Choose a profile picture";
        });

        profileForm?.addEventListener("submit", async (event) => {
            event.preventDefault();
            const button = profileForm.querySelector("button[type=submit]");
            button.disabled = true;
            showProfileMessage("", false);

            try {
                const response = await apiRequest("profile", {
                    method: "POST",
                    body: new FormData(profileForm),
                });
                const profile = response.data || {};
                const image = profilePictureUrl(profile.profile_picture);
                currentAvatar.src = image;
                profilePreview.src = image;
                document.getElementById("profile-name").value = profile.full_name || "";
                document.getElementById("profile-password").value = "";
                profilePicture.value = "";
                if (fileName) fileName.textContent = "Choose a profile picture";
                showProfileMessage(response.message || "Profile updated successfully.", false);
            } catch (error) {
                if (!handleUnauthorized(error)) showProfileMessage(errorMessage(error), true);
            } finally {
                button.disabled = false;
            }
        });

        logoutButton?.addEventListener("click", async () => {
            logoutButton.disabled = true;
            try {
                await apiRequest("logout", { method: "POST", body: {} });
            } finally {
                window.location.href = "index.php?page=login";
            }
        });

        let searchTimer;
        searchElement?.addEventListener("input", () => {
            window.clearTimeout(searchTimer);
            state.search = searchElement.value;
            searchTimer = window.setTimeout(loadContacts, 220);
        });

        toggleUsersButton?.addEventListener("click", () => {
            state.showAllUsers = !state.showAllUsers;
            toggleUsersButton.textContent = state.showAllUsers ? "−" : "+";
            toggleUsersButton.title = state.showAllUsers ? "Show conversations" : "Find users";
            toggleUsersButton.setAttribute("aria-label", toggleUsersButton.title);
            loadContacts();
        });

        loadContacts();
        window.setInterval(loadContacts, 10000);
        if (websocketEnabled) {
            connectSocket();
        } else {
            setSocketStatus("HTTP polling", "offline");
        }
    }

    if (page === "chat") {
        initChat();
    } else {
        initAuth();
    }
}());
