(function () {
    const config = window.WPCGPT_VOLUME_ADMIN_CONFIG || {};
    const restBase = typeof config.restBase === "string" ? config.restBase : "";
    const nonce = typeof config.nonce === "string" ? config.nonce : "";

    const tabs = Array.from(document.querySelectorAll(".nav-tab"));
    const panels = Array.from(document.querySelectorAll(".nav-tab-panel"));

    function activateTab(targetId) {
        tabs.forEach(function (tab) {
            const isActive = tab.getAttribute("href") === "#" + targetId;
            tab.classList.toggle("nav-tab-active", isActive);
            tab.setAttribute("aria-selected", isActive ? "true" : "false");
        });

        panels.forEach(function (panel) {
            const shouldShow = panel.id === targetId;
            panel.style.display = shouldShow ? "block" : "none";
        });
    }

    function request(path, method, body) {
        if (!restBase) {
            throw new Error("REST-Basis-URL fehlt.");
        }

        return fetch(restBase + path, {
            method: method,
            headers: {
                "Content-Type": "application/json",
                "X-WP-Nonce": nonce,
            },
            body: body ? JSON.stringify(body) : undefined,
            credentials: "same-origin",
        }).then(function (response) {
            return response.json().then(function (data) {
                if (!response.ok) {
                    throw new Error(data && data.message ? data.message : "Anfrage fehlgeschlagen.");
                }
                return data;
            });
        });
    }

    if (tabs.length && panels.length) {
        tabs.forEach(function (tab) {
            tab.addEventListener("click", function (event) {
                event.preventDefault();
                const href = tab.getAttribute("href") || "";
                if (!href || href.charAt(0) !== "#") {
                    return;
                }
                activateTab(href.slice(1));
            });
        });

        const activeHash = window.location.hash && window.location.hash.startsWith("#")
            ? window.location.hash.slice(1)
            : "volumes";

        activateTab(activeHash);
    }

    document.querySelectorAll(".js-volume-role-edit").forEach(function (button) {
        button.addEventListener("click", function () {
            const row = button.closest("tr");
            const input = row ? row.querySelector("input[data-role-quota]") : null;
            const role = button.dataset.role;

            if (!input || !role) {
                return;
            }

            const value = Number(input.value);
            request("/volume/roles", "POST", {
                role: role,
                monthly_tokens_millions: Number.isFinite(value) ? value : 0,
            })
                .then(function () {
                    window.location.reload();
                })
                .catch(function (error) {
                    window.alert(error.message || "Speichern fehlgeschlagen.");
                });
        });
    });

    document.querySelectorAll(".js-volume-user-edit").forEach(function (button) {
        button.disabled = false;
        button.addEventListener("click", function () {
            const row = button.closest("tr");
            if (!row) {
                return;
            }

            const userId = button.dataset.userId;
            const roleSelect = row.querySelector("select[data-user-role]");
            if (!userId || !roleSelect) {
                return;
            }

            const role = roleSelect.value;
            request("/volume/users/" + encodeURIComponent(userId), "POST", { role: role })
                .then(function () {
                    window.location.reload();
                })
                .catch(function (error) {
                    window.alert(error.message || "Speichern fehlgeschlagen.");
                });
        });
    });
})();
