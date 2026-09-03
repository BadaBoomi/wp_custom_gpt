(function () {
    const config = window.WPCGPT_FLOW_ADMIN_CONFIG || {};
    const restBase = typeof config.restBase === "string" ? config.restBase : "";
    const nonce = typeof config.nonce === "string" ? config.nonce : "";

    const typeInput = document.getElementById("wpcgpt-flow-type");
    const codeInput = document.getElementById("wpcgpt-flow-code");
    const statusEl = document.getElementById("wpcgpt-flow-status");
    const listOutput = document.getElementById("wpcgpt-flow-list-output");
    const filesOutput = document.getElementById("wpcgpt-flow-files-output");
    const fileInput = document.getElementById("wpcgpt-flow-file-input");
    const fileDeleteIdInput = document.getElementById("wpcgpt-flow-file-delete-id");
    const openAiDebugEnabledInput = document.getElementById("wpcgpt-openai-debug-enabled");
    const openAiDebugOutput = document.getElementById("wpcgpt-openai-debug-output");
    const tableIntegrityOutput = document.getElementById("wpcgpt-table-integrity-output");

    const loadButton = document.getElementById("wpcgpt-flow-load");
    const listButton = document.getElementById("wpcgpt-flow-list");
    const templateButton = document.getElementById("wpcgpt-flow-template");
    const validateButton = document.getElementById("wpcgpt-flow-validate");
    const saveButton = document.getElementById("wpcgpt-flow-save");
    const deactivateButton = document.getElementById("wpcgpt-flow-deactivate");
    const fileUploadButton = document.getElementById("wpcgpt-flow-file-upload");
    const fileRefreshButton = document.getElementById("wpcgpt-flow-file-refresh");
    const fileDeleteButton = document.getElementById("wpcgpt-flow-file-delete");
    const openAiDebugSaveButton = document.getElementById("wpcgpt-openai-debug-save");
    const openAiDebugLoadButton = document.getElementById("wpcgpt-openai-debug-load");
    const tableIntegrityVerifyButton = document.getElementById("wpcgpt-table-integrity-verify");
    const tableIntegrityCheckButton = document.getElementById("wpcgpt-table-integrity-check");

    function setStatus(message, isError) {
        if (!statusEl) {
            return;
        }

        statusEl.textContent = message;
        statusEl.style.color = isError ? "#b42318" : "#1f6f43";
    }

    function getFlowType() {
        if (!typeInput) {
            return "";
        }

        return String(typeInput.value || "").trim();
    }

    function setCode(value) {
        if (!codeInput) {
            return;
        }

        codeInput.value = value;
    }

    function getCode() {
        if (!codeInput) {
            return "";
        }

        return String(codeInput.value || "");
    }

    async function request(path, method, body) {
        if (!restBase) {
            throw new Error("REST-Basis-URL fehlt.");
        }

        const response = await fetch(restBase + path, {
            method: method,
            headers: {
                "Content-Type": "application/json",
                "X-WP-Nonce": nonce,
            },
            body: body ? JSON.stringify(body) : undefined,
            credentials: "same-origin",
        });

        const data = await response.json().catch(function () {
            return {};
        });

        if (!response.ok) {
            const message = data && data.message ? data.message : "Anfrage fehlgeschlagen.";
            throw new Error(message);
        }

        return data;
    }

    async function uploadFile(path, file) {
        if (!restBase) {
            throw new Error("REST-Basis-URL fehlt.");
        }

        const formData = new FormData();
        formData.append("file", file);

        const response = await fetch(restBase + path, {
            method: "POST",
            headers: {
                "X-WP-Nonce": nonce,
            },
            body: formData,
            credentials: "same-origin",
        });

        const data = await response.json().catch(function () {
            return {};
        });

        if (!response.ok) {
            const message = data && data.message ? data.message : "Upload fehlgeschlagen.";
            throw new Error(message);
        }

        return data;
    }

    async function listFlows() {
        const data = await request("/flows", "GET");
        if (!Array.isArray(data)) {
            listOutput.textContent = "Keine Flow-Liste verfuegbar.";
            return;
        }

        listOutput.textContent = JSON.stringify(
            data.map(function (entry) {
                return {
                    flow_type: entry.flow_type,
                    version: entry.version,
                    is_active: entry.is_active,
                    updated_at: entry.updated_at,
                };
            }),
            null,
            2
        );
    }

    async function loadFlow() {
        const flowType = getFlowType();
        if (!flowType) {
            throw new Error("Flow-Typ ist erforderlich.");
        }

        const data = await request("/flows/" + encodeURIComponent(flowType), "GET");
        setCode(typeof data.code_php === "string" ? data.code_php : "");
        setStatus("Flow geladen: " + flowType + " (v" + String(data.version || "?") + ")", false);
    }

    async function validateFlow() {
        const code = getCode();
        await request("/flows/validate", "POST", { code_php: code });
        setStatus("Flow-Code ist gueltig.", false);
    }

    async function saveFlow() {
        const flowType = getFlowType();
        if (!flowType) {
            throw new Error("Flow-Typ ist erforderlich.");
        }

        const code = getCode();
        const data = await request("/flows/" + encodeURIComponent(flowType), "POST", {
            code_php: code,
        });

        setStatus("Flow gespeichert: " + flowType + " (v" + String(data.version || "?") + ")", false);
    }

    async function deactivateFlow() {
        const flowType = getFlowType();
        if (!flowType) {
            throw new Error("Flow-Typ ist erforderlich.");
        }

        await request("/flows/" + encodeURIComponent(flowType), "DELETE");
        setStatus("Flow deaktiviert: " + flowType, false);
    }

    async function refreshFlowFiles() {
        const flowType = getFlowType();
        if (!flowType) {
            throw new Error("Flow-Typ ist erforderlich.");
        }

        const data = await request("/flows/" + encodeURIComponent(flowType) + "/files", "GET");
        if (!filesOutput) {
            return;
        }

        if (!Array.isArray(data) || data.length === 0) {
            filesOutput.textContent = "Fuer diesen Flow wurden noch keine Dateien hochgeladen.";
            return;
        }

        filesOutput.textContent = JSON.stringify(
            data.map(function (entry) {
                return {
                    id: entry.id,
                    original_name: entry.original_name,
                    mime_type: entry.mime_type,
                    size_bytes: entry.size_bytes,
                    updated_at: entry.updated_at,
                    relative_path: entry.relative_path,
                };
            }),
            null,
            2
        );
    }

    async function uploadFlowFile() {
        const flowType = getFlowType();
        if (!flowType) {
            throw new Error("Flow-Typ ist erforderlich.");
        }

        if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
            throw new Error("Bitte waehlen Sie eine Datei zum Hochladen aus.");
        }

        await uploadFile("/flows/" + encodeURIComponent(flowType) + "/files", fileInput.files[0]);
        fileInput.value = "";
        setStatus("Datei hochgeladen.", false);
        await refreshFlowFiles();
    }

    async function deleteFlowFile() {
        const flowType = getFlowType();
        if (!flowType) {
            throw new Error("Flow-Typ ist erforderlich.");
        }

        if (!fileDeleteIdInput) {
            throw new Error("Eingabe fuer Loeschen ist nicht verfuegbar.");
        }

        const fileId = parseInt(String(fileDeleteIdInput.value || "0"), 10);
        if (!fileId || fileId <= 0) {
            throw new Error("Bitte eine gueltige Datei-ID eingeben.");
        }

        await request("/flows/" + encodeURIComponent(flowType) + "/files/" + String(fileId), "DELETE");
        setStatus("Datei geloescht: #" + String(fileId), false);
        await refreshFlowFiles();
    }

    async function loadDebugSetting() {
        if (!openAiDebugEnabledInput) {
            return;
        }

        const data = await request("/settings", "GET");
        openAiDebugEnabledInput.checked = !!(data && data.openai_debug_enabled);
    }

    async function saveDebugSetting() {
        if (!openAiDebugEnabledInput) {
            throw new Error("Debug-Schalter ist nicht verfuegbar.");
        }

        await request("/settings", "POST", {
            openai_debug_enabled: openAiDebugEnabledInput.checked,
        });

        setStatus(
            openAiDebugEnabledInput.checked
                ? "OpenAI-Debug-Protokoll wurde aktiviert."
                : "OpenAI-Debug-Protokoll wurde deaktiviert.",
            false
        );
    }

    async function loadDebugLog() {
        if (!openAiDebugOutput) {
            throw new Error("Log-Ausgabe ist nicht verfuegbar.");
        }

        const data = await request("/settings/openai-debug-log?limit=300", "GET");
        const lines = Array.isArray(data && data.lines) ? data.lines : [];
        const path = data && data.path ? String(data.path) : "";

        if (lines.length === 0) {
            openAiDebugOutput.textContent = data && data.message
                ? String(data.message)
                : "Keine OpenAI-Debug-Eintraege gefunden.";
            setStatus("Keine OpenAI-Debug-Eintraege gefunden.", false);
            return;
        }

        const header = path ? "Log-Datei: " + path + "\n\n" : "";
        openAiDebugOutput.textContent = header + lines.join("\n");
        setStatus("OpenAI-Debug-Log geladen (" + String(lines.length) + " Zeilen).", false);
    }

    function renderTableIntegrityResult(data) {
        const checkedTables = Number(data && data.checked_tables ? data.checked_tables : 0);
        const repaired = !!(data && data.repaired);
        const ok = !!(data && data.ok);
        const missingBefore = Array.isArray(data && data.missing_before) ? data.missing_before : [];
        const missingAfter = Array.isArray(data && data.missing_after) ? data.missing_after : [];

        if (tableIntegrityOutput) {
            tableIntegrityOutput.textContent = JSON.stringify(
                {
                    ok: ok,
                    repaired: repaired,
                    checked_tables: checkedTables,
                    missing_before: missingBefore,
                    missing_after: missingAfter,
                    schema_version_expected: data && data.schema_version_expected ? data.schema_version_expected : "",
                    schema_version_stored: data && data.schema_version_stored ? data.schema_version_stored : "",
                },
                null,
                2
            );
        }

        return {
            checkedTables: checkedTables,
            repaired: repaired,
            ok: ok,
            missingBefore: missingBefore,
            missingAfter: missingAfter,
        };
    }

    async function runTableIntegrityCheck() {
        const data = await request("/settings/table-integrity-check", "POST");
        const result = renderTableIntegrityResult(data);

        if (result.ok) {
            setStatus(
                result.repaired
                    ? "Integritaetscheck abgeschlossen. Fehlende Tabellen wurden repariert."
                    : "Integritaetscheck abgeschlossen. Alle Tabellen sind vorhanden.",
                false
            );
            return;
        }

        setStatus("Integritaetscheck abgeschlossen, aber es fehlen weiterhin Tabellen. Details siehe Ausgabe.", true);
    }

    async function runTableIntegrityVerify() {
        const data = await request("/settings/table-integrity-verify", "POST");
        const result = renderTableIntegrityResult(data);

        if (result.ok) {
            setStatus("Tabellenpruefung abgeschlossen. Alle Tabellen sind vorhanden.", false);
            return;
        }

        setStatus("Tabellenpruefung abgeschlossen. Es fehlen Tabellen (keine Reparatur ausgefuehrt).", true);
    }

    function bind(button, action) {
        if (!button) {
            return;
        }

        button.addEventListener("click", async function () {
            setStatus("Wird bearbeitet...", false);
            try {
                await action();
            } catch (error) {
                setStatus(error instanceof Error ? error.message : "Unbekannter Fehler", true);
            }
        });
    }

    bind(listButton, listFlows);
    bind(loadButton, loadFlow);
    bind(validateButton, validateFlow);
    bind(saveButton, saveFlow);
    bind(deactivateButton, deactivateFlow);
    bind(fileUploadButton, uploadFlowFile);
    bind(fileRefreshButton, refreshFlowFiles);
    bind(fileDeleteButton, deleteFlowFile);
    bind(openAiDebugSaveButton, saveDebugSetting);
    bind(openAiDebugLoadButton, loadDebugLog);
    bind(tableIntegrityVerifyButton, runTableIntegrityVerify);
    bind(tableIntegrityCheckButton, runTableIntegrityCheck);

    if (templateButton) {
        templateButton.addEventListener("click", function () {
            setCode(typeof config.defaultFlowCode === "string" ? config.defaultFlowCode : "");
            setStatus("Vorlage eingefuegt.", false);
        });
    }

    loadDebugSetting().catch(function (error) {
        setStatus(error instanceof Error ? error.message : "Debug-Einstellung konnte nicht geladen werden.", true);
    });
})();
