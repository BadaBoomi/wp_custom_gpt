(function () {
    const config = window.WPCGPT_VOLUME_ADMIN_CONFIG || {};
    const restBase = typeof config.restBase === "string" ? config.restBase : "";
    const nonce = typeof config.nonce === "string" ? config.nonce : "";

    const tabs = Array.from(document.querySelectorAll(".nav-tab"));
    const panels = Array.from(document.querySelectorAll(".nav-tab-panel"));
    const modal = document.getElementById("wpcgpt-volume-role-modal");
    const modalRoleLabel = document.getElementById("wpcgpt-volume-role-modal-role");
    const modalInput = document.getElementById("wpcgpt-volume-role-modal-input");
    const modalCancelButton = document.getElementById("wpcgpt-volume-role-modal-cancel");
    const modalSaveButton = document.getElementById("wpcgpt-volume-role-modal-save");
    const userRoleModal = document.getElementById("wpcgpt-volume-user-role-modal");
    const userRoleModalUser = document.getElementById("wpcgpt-volume-user-role-modal-user");
    const userRoleModalSelect = document.getElementById("wpcgpt-volume-user-role-modal-select");
    const userRoleModalCancelButton = document.getElementById("wpcgpt-volume-user-role-modal-cancel");
    const userRoleModalSaveButton = document.getElementById("wpcgpt-volume-user-role-modal-save");
    let activeRoleEdit = null;
    let activeUserRoleEdit = null;

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

    function openRoleModal(row) {
        if (!modal || !modalRoleLabel || !modalInput) {
            return;
        }

        const role = row && row.dataset.role ? row.dataset.role : "";
        const roleLabel = row && row.dataset.roleLabel ? row.dataset.roleLabel : role;
        const quota = row && row.dataset.roleQuota ? row.dataset.roleQuota : "0";

        activeRoleEdit = role;
        modalRoleLabel.textContent = roleLabel + " (" + role + ")";
        modalInput.value = quota;
        modal.style.display = "flex";
    }

    function closeRoleModal() {
        if (!modal) {
            return;
        }

        activeRoleEdit = null;
        modal.style.display = "none";
    }

    function openUserRoleModal(userId, userLabel, options, selectedRole) {
        if (!userRoleModal || !userRoleModalUser || !userRoleModalSelect) {
            return;
        }

        userRoleModalUser.textContent = userLabel;
        userRoleModalSelect.innerHTML = options;
        userRoleModalSelect.value = selectedRole;
        activeUserRoleEdit = userId;
        userRoleModal.style.display = "flex";
    }

    function closeUserRoleModal() {
        if (!userRoleModal) {
            return;
        }

        activeUserRoleEdit = null;
        userRoleModal.style.display = "none";
    }

    document.querySelectorAll(".js-volume-role-edit").forEach(function (button) {
        button.addEventListener("click", function () {
            const row = button.closest("tr");
            if (!row) {
                return;
            }

            openRoleModal(row);
        });
    });

    if (modalCancelButton) {
        modalCancelButton.addEventListener("click", closeRoleModal);
    }

    if (modalSaveButton) {
        modalSaveButton.addEventListener("click", function () {
            if (!activeRoleEdit) {
                return;
            }

            const value = Number(modalInput.value);
            request("/volume/roles", "POST", {
                role: activeRoleEdit,
                monthly_tokens_millions: Number.isFinite(value) ? value : 0,
            })
                .then(function () {
                    closeRoleModal();
                    window.location.reload();
                })
                .catch(function (error) {
                    window.alert(error.message || "Speichern fehlgeschlagen.");
                });
        });
    }

    document.querySelectorAll(".js-volume-user-edit").forEach(function (button) {
        button.disabled = false;
        button.addEventListener("click", function () {
            const userId = button.dataset.userId;
            const assignedRole = button.dataset.userRole || "";
            const userLabel = button.closest("tr") ? button.closest("tr").children[0].textContent.trim() : "Benutzer";
            if (!userId) {
                return;
            }

            const availableRoles = Array.from(document.querySelectorAll("[data-role]"));
            const roleOptions = availableRoles
                .filter(function (row) {
                    const role = row.dataset.role || "";
                    const quota = Number(row.dataset.roleQuota || "0");
                    return role && Number.isFinite(quota) && quota > 0;
                })
                .map(function (row) {
                    const role = row.dataset.role || "";
                    const label = row.dataset.roleLabel || role;
                    const selected = role === assignedRole ? " selected" : "";
                    return '<option value="' + role + '"' + selected + '>' + label + '</option>';
                })
                .join("");
            if (!roleOptions) {
                window.alert("Es gibt keine Rolle mit Volumeninformationen zum Zuweisen.");
                return;
            }

            openUserRoleModal(userId, userLabel, roleOptions, assignedRole);
        });
    });

    if (userRoleModalCancelButton) {
        userRoleModalCancelButton.addEventListener("click", closeUserRoleModal);
    }

    if (userRoleModalSaveButton) {
        userRoleModalSaveButton.addEventListener("click", function () {
            if (!activeUserRoleEdit || !userRoleModalSelect) {
                return;
            }

            const chosenRole = userRoleModalSelect.value;
            if (!chosenRole) {
                window.alert("Bitte wählen Sie eine gültige Rolle aus.");
                return;
            }

            request("/volume/users/" + encodeURIComponent(activeUserRoleEdit), "POST", { role: chosenRole })
                .then(function () {
                    closeUserRoleModal();
                    window.location.reload();
                })
                .catch(function (error) {
                    window.alert(error.message || "Speichern fehlgeschlagen.");
                });
        });
    }
})();
