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

    const loadButton = document.getElementById("wpcgpt-flow-load");
    const listButton = document.getElementById("wpcgpt-flow-list");
    const templateButton = document.getElementById("wpcgpt-flow-template");
    const validateButton = document.getElementById("wpcgpt-flow-validate");
    const saveButton = document.getElementById("wpcgpt-flow-save");
    const deactivateButton = document.getElementById("wpcgpt-flow-deactivate");
    const fileUploadButton = document.getElementById("wpcgpt-flow-file-upload");
    const fileRefreshButton = document.getElementById("wpcgpt-flow-file-refresh");
    const fileDeleteButton = document.getElementById("wpcgpt-flow-file-delete");

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
            throw new Error("REST base URL is missing.");
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
            const message = data && data.message ? data.message : "Request failed.";
            throw new Error(message);
        }

        return data;
    }

    async function uploadFile(path, file) {
        if (!restBase) {
            throw new Error("REST base URL is missing.");
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
            const message = data && data.message ? data.message : "Upload failed.";
            throw new Error(message);
        }

        return data;
    }

    async function listFlows() {
        const data = await request("/flows", "GET");
        if (!Array.isArray(data)) {
            listOutput.textContent = "No flow list available.";
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
            throw new Error("Flow type is required.");
        }

        const data = await request("/flows/" + encodeURIComponent(flowType), "GET");
        setCode(typeof data.code_php === "string" ? data.code_php : "");
        setStatus("Flow loaded: " + flowType + " (v" + String(data.version || "?") + ")", false);
    }

    async function validateFlow() {
        const code = getCode();
        await request("/flows/validate", "POST", { code_php: code });
        setStatus("Flow code is valid.", false);
    }

    async function saveFlow() {
        const flowType = getFlowType();
        if (!flowType) {
            throw new Error("Flow type is required.");
        }

        const code = getCode();
        const data = await request("/flows/" + encodeURIComponent(flowType), "POST", {
            code_php: code,
        });

        setStatus("Flow saved: " + flowType + " (v" + String(data.version || "?") + ")", false);
    }

    async function deactivateFlow() {
        const flowType = getFlowType();
        if (!flowType) {
            throw new Error("Flow type is required.");
        }

        await request("/flows/" + encodeURIComponent(flowType), "DELETE");
        setStatus("Flow deactivated: " + flowType, false);
    }

    async function refreshFlowFiles() {
        const flowType = getFlowType();
        if (!flowType) {
            throw new Error("Flow type is required.");
        }

        const data = await request("/flows/" + encodeURIComponent(flowType) + "/files", "GET");
        if (!filesOutput) {
            return;
        }

        if (!Array.isArray(data) || data.length === 0) {
            filesOutput.textContent = "No files uploaded for this flow.";
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
            throw new Error("Flow type is required.");
        }

        if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
            throw new Error("Please select a file to upload.");
        }

        await uploadFile("/flows/" + encodeURIComponent(flowType) + "/files", fileInput.files[0]);
        fileInput.value = "";
        setStatus("File uploaded.", false);
        await refreshFlowFiles();
    }

    async function deleteFlowFile() {
        const flowType = getFlowType();
        if (!flowType) {
            throw new Error("Flow type is required.");
        }

        if (!fileDeleteIdInput) {
            throw new Error("Delete input is unavailable.");
        }

        const fileId = parseInt(String(fileDeleteIdInput.value || "0"), 10);
        if (!fileId || fileId <= 0) {
            throw new Error("Please provide a valid file id.");
        }

        await request("/flows/" + encodeURIComponent(flowType) + "/files/" + String(fileId), "DELETE");
        setStatus("File deleted: #" + String(fileId), false);
        await refreshFlowFiles();
    }

    function bind(button, action) {
        if (!button) {
            return;
        }

        button.addEventListener("click", async function () {
            setStatus("Working...", false);
            try {
                await action();
            } catch (error) {
                setStatus(error instanceof Error ? error.message : "Unknown error", true);
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

    if (templateButton) {
        templateButton.addEventListener("click", function () {
            setCode(typeof config.defaultFlowCode === "string" ? config.defaultFlowCode : "");
            setStatus("Template inserted.", false);
        });
    }
})();
