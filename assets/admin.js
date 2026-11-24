(function () {
    "use strict";

    const body = document.body;
    const apiUrl = body.dataset.apiUrl || "admin-api.php";
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || "";
    const tabs = [...document.querySelectorAll("[data-tab]")];
    const panels = [...document.querySelectorAll("[data-tab-panel]")];
    let currentTab = "overview";

    function request(action, options) {
        const config = options || {};
        const method = (config.method || "GET").toUpperCase();
        const url = new URL(apiUrl, window.location.href);
        url.searchParams.set("action", action);
        const headers = { Accept: "application/json", ...(config.headers || {}) };
        let requestBody = config.body;

        if (method !== "GET" && method !== "HEAD") {
            headers["X-CSRF-TOKEN"] = csrfToken;
            if (!(requestBody instanceof FormData) && !(requestBody instanceof URLSearchParams)) {
                headers["Content-Type"] = "application/json";
                requestBody = JSON.stringify(requestBody || {});
            }
        }

        return fetch(url.toString(), { method, headers, body: requestBody, credentials: "same-origin" })
            .then(async (response) => {
                const data = await response.json().catch(() => ({}));
                if (!response.ok) {
                    const error = new Error(data.message || `Request failed (${response.status})`);
                    error.status = response.status;
                    throw error;
                }
                return data;
            })
            .catch((error) => {
                if (error.status === 401 || error.status === 403) {
                    window.location.href = "index.php";
                }
                throw error;
            });
    }

    function showToast(message, isError) {
        const toast = document.getElementById("admin-toast");
        toast.textContent = message;
        toast.classList.toggle("error", Boolean(isError));
        toast.hidden = false;
        window.clearTimeout(showToast.timer);
        showToast.timer = window.setTimeout(() => { toast.hidden = true; }, 3200);
    }

    function text(value) {
        return value === null || value === undefined || value === "" ? "—" : String(value);
    }

    function formatDate(value) {
        if (!value) return "Never";
        const normalized = String(value).includes(" ") ? String(value).replace(" ", "T") + "Z" : String(value);
        const date = new Date(normalized);
        return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleString([], { dateStyle: "medium", timeStyle: "short" });
    }

    function avatarUrl(filename) {
        const value = String(filename || "");
        if (!value || value === "default.png") return "assets/default-avatar.png";
        return `uploads/${encodeURIComponent(value.split(/[\\/]/).pop())}`;
    }

    function setText(id, value) {
        const element = document.getElementById(id);
        if (element) element.textContent = text(value);
    }

    function badge(value, className) {
        const element = document.createElement("span");
        element.className = `pill ${className || ""}`.trim();
        element.textContent = text(value);
        return element;
    }

    function clearAndMessage(element, columns, message) {
        element.replaceChildren();
        const row = document.createElement("tr");
        const cell = document.createElement("td");
        cell.colSpan = columns;
        cell.className = "loading-row";
        cell.textContent = message;
        row.appendChild(cell);
        element.appendChild(row);
    }

    function renderOverview(data) {
        setText("stat-total-users", data.total_users);
        setText("stat-online-users", data.online_users);
        setText("stat-active-users", data.active_users);
        setText("stat-total-messages", data.total_messages);
        setText("stat-messages-day", data.messages_last_24h);
        setText("stat-pending-reports", data.pending_reports);
        const badgeElement = document.getElementById("nav-report-count");
        badgeElement.textContent = String(data.pending_reports || 0);
        badgeElement.hidden = !Number(data.pending_reports);

        const total = Math.max(1, Number(data.total_users || 0));
        const rows = [
            ["Active", data.active_users, "green"],
            ["Suspended", data.suspended_users, "orange"],
            ["Banned", data.banned_users, "red"],
        ];
        const bars = document.getElementById("status-bars");
        bars.replaceChildren();
        rows.forEach(([label, value, color]) => {
            const row = document.createElement("div");
            row.className = "status-row";
            const name = document.createElement("span");
            name.textContent = label;
            const track = document.createElement("span");
            track.className = "status-track";
            const fill = document.createElement("span");
            fill.className = `status-fill ${color}`;
            fill.style.width = `${Math.min(100, (Number(value || 0) / total) * 100)}%`;
            track.appendChild(fill);
            const count = document.createElement("strong");
            count.textContent = String(value || 0);
            row.append(name, track, count);
            bars.appendChild(row);
        });
    }

    function renderHealth(data) {
        const list = document.getElementById("overview-health");
        list.replaceChildren();
        [
            ["Database", data.database_ok, data.database_ok ? "Connected" : "Unavailable"],
            ["WebSocket", data.websocket_ok, data.websocket_ok ? `Port ${data.websocket_port}` : "Offline"],
            ["Storage", data.storage_writable && data.uploads_writable, data.storage_writable && data.uploads_writable ? "Writable" : "Check permissions"],
        ].forEach(([label, ok, value]) => {
            const row = document.createElement("div");
            row.className = "health-item";
            const name = document.createElement("span");
            const dot = document.createElement("i");
            dot.className = `health-dot ${ok ? "" : "bad"}`;
            name.append(dot, document.createTextNode(label));
            const state = document.createElement("strong");
            state.className = "health-value";
            state.textContent = value;
            row.append(name, state);
            list.appendChild(row);
        });
    }

    function renderSystem(data) {
        const container = document.getElementById("system-grid");
        container.replaceChildren();
        const cards = [
            { title: "Services", icon: "fa-server", rows: [["Database", data.database_ok ? "Connected" : "Unavailable", data.database_ok], ["WebSocket", data.websocket_ok ? `Listening on ${data.websocket_port}` : "Offline", data.websocket_ok], ["Checked", data.checked_at, true]] },
            { title: "Runtime", icon: "fa-microchip", rows: [["PHP", data.php_version, true], ["Server", data.server_software, true], ["Memory", `${Math.round(Number(data.memory_usage || 0) / 1024 / 1024)} MB`, true]] },
            { title: "Storage", icon: "fa-hdd", rows: [["Database", data.database_name, true], ["App storage", data.storage_writable ? "Writable" : "Not writable", data.storage_writable], ["Uploads", data.uploads_writable ? "Writable" : "Not writable", data.uploads_writable], ["Free disk", data.disk_free === null ? "Unknown" : `${(Number(data.disk_free) / 1024 / 1024 / 1024).toFixed(1)} GB`, data.disk_free !== null]] },
        ];
        cards.forEach((card) => {
            const article = document.createElement("article");
            article.className = "system-card";
            const heading = document.createElement("h2");
            const icon = document.createElement("i");
            icon.className = `fas ${card.icon}`;
            heading.append(icon, document.createTextNode(card.title));
            article.appendChild(heading);
            card.rows.forEach(([label, value, ok]) => {
                const line = document.createElement("div");
                line.className = "system-line";
                const name = document.createElement("span");
                name.textContent = label;
                const state = document.createElement("strong");
                state.textContent = text(value);
                if (typeof ok === "boolean") state.className = ok ? "system-ok" : "system-bad";
                line.append(name, state);
                article.appendChild(line);
            });
            container.appendChild(article);
        });
    }

    async function loadOverview() {
        const response = await request("overview");
        renderOverview(response.data || {});
        await loadSystem(true);
    }

    async function loadSystem(overviewOnly) {
        const response = await request("system");
        if (overviewOnly) renderHealth(response.data || {});
        else renderSystem(response.data || {});
    }

    function renderUsers(users) {
        const table = document.getElementById("users-table");
        table.replaceChildren();
        if (!users.length) { clearAndMessage(table, 6, "No users found."); return; }
        users.forEach((user) => {
            const row = document.createElement("tr");
            const userCell = document.createElement("td");
            const userWrap = document.createElement("div");
            userWrap.className = "user-cell";
            const image = document.createElement("img"); image.src = avatarUrl(user.profile_picture); image.alt = "";
            const details = document.createElement("span");
            const name = document.createElement("strong"); name.textContent = text(user.full_name);
            const email = document.createElement("small"); email.textContent = `${user.username} · ${user.email}`;
            details.append(name, email); userWrap.append(image, details); userCell.appendChild(userWrap);
            const roleCell = document.createElement("td"); roleCell.appendChild(badge(user.role, user.role === "admin" ? "pill-admin" : "pill-user"));
            const statusCell = document.createElement("td"); statusCell.appendChild(badge(user.status, `pill-${user.status}`));
            const seenCell = document.createElement("td"); seenCell.textContent = formatDate(user.last_seen_at);
            const createdCell = document.createElement("td"); createdCell.textContent = formatDate(user.created_at);
            const actionsCell = document.createElement("td"); actionsCell.className = "align-right";
            const actions = document.createElement("span"); actions.className = "row-actions";
            if (user.role !== "admin") actions.appendChild(actionButton("Make admin", "role", user.id, "admin"));
            else actions.appendChild(actionButton("Make user", "role", user.id, "user"));
            if (user.status === "active") actions.appendChild(actionButton("Suspend", "status", user.id, "suspended"));
            else actions.appendChild(actionButton("Activate", "status", user.id, "active"));
            if (user.status !== "banned") actions.appendChild(actionButton("Ban", "status", user.id, "banned", true));
            actionsCell.appendChild(actions);
            row.append(userCell, roleCell, statusCell, seenCell, createdCell, actionsCell);
            table.appendChild(row);
        });
    }

    function actionButton(label, action, id, value, danger) {
        const button = document.createElement("button");
        button.type = "button"; button.className = `action-button ${danger ? "danger" : ""}`.trim(); button.textContent = label;
        button.dataset.userAction = action; button.dataset.userId = String(id); button.dataset.value = value;
        return button;
    }

    async function loadUsers() {
        const url = new URL(apiUrl, window.location.href);
        url.searchParams.set("action", "users");
        url.searchParams.set("search", document.getElementById("user-search").value.trim());
        url.searchParams.set("status", document.getElementById("user-status").value);
        url.searchParams.set("role", document.getElementById("user-role").value);
        const response = await fetch(url, { headers: { Accept: "application/json" }, credentials: "same-origin" }).then(async (res) => {
            const data = await res.json(); if (!res.ok) throw new Error(data.message || "Unable to load users."); return data;
        });
        renderUsers(response.data || []);
    }

    function renderReports(reports) {
        const table = document.getElementById("reports-table"); table.replaceChildren();
        if (!reports.length) { clearAndMessage(table, 4, "No reports in this queue."); return; }
        reports.forEach((report) => {
            const row = document.createElement("tr");
            const reportCell = document.createElement("td");
            const reason = document.createElement("strong"); reason.className = "report-reason"; reason.textContent = report.reason;
            const meta = document.createElement("small"); meta.className = "report-meta"; meta.textContent = `${report.reporter_name || "Unknown"} → ${report.reported_name || "Unknown"}`;
            reportCell.append(reason, meta);
            const messageCell = document.createElement("td"); const content = document.createElement("span"); content.className = "compact-message"; content.textContent = report.message_content || "Message was removed"; messageCell.appendChild(content);
            const dateCell = document.createElement("td"); dateCell.textContent = formatDate(report.created_at);
            const actionCell = document.createElement("td"); actionCell.className = "align-right"; const actions = document.createElement("span"); actions.className = "row-actions";
            if (report.status === "pending") { actions.append(actionButton("Resolve", "report", report.id, "resolved"), actionButton("Dismiss", "report", report.id, "dismissed")); }
            else { actions.appendChild(actionButton("Reopen", "report", report.id, "pending")); }
            actionCell.appendChild(actions); row.append(reportCell, messageCell, dateCell, actionCell); table.appendChild(row);
        });
    }

    function renderMessages(messages) {
        const table = document.getElementById("messages-table"); table.replaceChildren();
        if (!messages.length) { clearAndMessage(table, 4, "No messages found."); return; }
        messages.forEach((message) => {
            const row = document.createElement("tr");
            const conversation = document.createElement("td"); conversation.textContent = `${message.sender_name || "Unknown"} → ${message.receiver_name || "Unknown"}`;
            const content = document.createElement("td"); const excerpt = document.createElement("span"); excerpt.className = "compact-message"; excerpt.textContent = message.content; content.appendChild(excerpt);
            const date = document.createElement("td"); date.textContent = formatDate(message.created_at);
            const actions = document.createElement("td"); actions.className = "align-right"; const button = document.createElement("button"); button.type = "button"; button.className = "action-button danger"; button.dataset.deleteMessage = String(message.id); button.textContent = "Remove"; actions.appendChild(button);
            row.append(conversation, content, date, actions); table.appendChild(row);
        });
    }

    async function loadModeration() {
        const status = document.getElementById("report-status").value;
        const reportsUrl = new URL(apiUrl, window.location.href); reportsUrl.searchParams.set("action", "reports"); reportsUrl.searchParams.set("status", status);
        const [reports, messages] = await Promise.all([
            fetch(reportsUrl, { headers: { Accept: "application/json" }, credentials: "same-origin" }).then((res) => res.json()),
            request("messages"),
        ]);
        renderReports(reports.data || []); renderMessages(messages.data || []);
    }

    async function changeUser(button) {
        const id = Number(button.dataset.userId); const action = button.dataset.userAction; const value = button.dataset.value;
        if (action === "status" && value === "banned" && !window.confirm("Ban this user?")) return;
        if (action === "role" && value === "admin" && !window.confirm("Give this user administrator access?")) return;
        const data = { user_id: id }; data[action] = value;
        await request("update-user", { method: "POST", body: data });
        showToast("User updated."); await Promise.all([loadUsers(), loadOverview()]);
    }

    async function changeReport(button) {
        await request("update-report", { method: "POST", body: { report_id: Number(button.dataset.userId), status: button.dataset.value } });
        showToast("Report updated."); await Promise.all([loadModeration(), loadOverview()]);
    }

    async function deleteMessage(button) {
        if (!window.confirm("Remove this message?")) return;
        await request("delete-message", { method: "POST", body: { message_id: Number(button.dataset.deleteMessage) } });
        showToast("Message removed."); await Promise.all([loadModeration(), loadOverview()]);
    }

    async function loadTab(tab) {
        try {
            if (tab === "overview") await loadOverview();
            if (tab === "users") await loadUsers();
            if (tab === "moderation") await loadModeration();
            if (tab === "system") await loadSystem(false);
        } catch (error) { showToast(error.message, true); }
    }

    tabs.forEach((tabButton) => tabButton.addEventListener("click", () => {
        currentTab = tabButton.dataset.tab;
        tabs.forEach((button) => button.classList.toggle("active", button === tabButton));
        panels.forEach((panel) => panel.classList.toggle("active", panel.dataset.tabPanel === currentTab));
        loadTab(currentTab);
    }));

    document.querySelectorAll("[data-tab-link]").forEach((button) => button.addEventListener("click", () => document.querySelector(`[data-tab="${button.dataset.tabLink}"]`)?.click()));
    document.querySelectorAll("[data-refresh]").forEach((button) => button.addEventListener("click", () => loadTab(button.dataset.refresh)));
    document.getElementById("user-search").addEventListener("input", (() => { let timer; return () => { clearTimeout(timer); timer = setTimeout(loadUsers, 250); }; })());
    document.getElementById("user-status").addEventListener("change", loadUsers);
    document.getElementById("user-role").addEventListener("change", loadUsers);
    document.getElementById("report-status").addEventListener("change", loadModeration);
    document.getElementById("users-table").addEventListener("click", (event) => { const button = event.target.closest("[data-user-action]"); if (button) changeUser(button).catch((error) => showToast(error.message, true)); });
    document.getElementById("reports-table").addEventListener("click", (event) => { const button = event.target.closest("[data-user-action='report']"); if (button) changeReport(button).catch((error) => showToast(error.message, true)); });
    document.getElementById("messages-table").addEventListener("click", (event) => { const button = event.target.closest("[data-delete-message]"); if (button) deleteMessage(button).catch((error) => showToast(error.message, true)); });
    document.getElementById("admin-logout").addEventListener("click", async () => {
        try {
            const logoutUrl = new URL("api.php", window.location.href);
            logoutUrl.searchParams.set("action", "logout");
            await fetch(logoutUrl.toString(), {
                method: "POST",
                headers: { Accept: "application/json", "Content-Type": "application/json", "X-CSRF-TOKEN": csrfToken },
                body: "{}",
                credentials: "same-origin",
            });
        } catch (error) {
            // Navigation still clears the admin view if the server is already offline.
        } finally {
            window.location.href = "index.php?page=login";
        }
    });

    loadTab("overview");
}());
