(function () {
    const config = window.WPCGPT_FLOW_ADMIN_CONFIG || {};
    const restBase = typeof config.restBase === "string" ? config.restBase : "";
    const nonce = typeof config.nonce === "string" ? config.nonce : "";

    const typeInput = document.getElementById("wpcgpt-flow-type");
    const codeInput = document.getElementById("wpcgpt-flow-code");
    const statusEl = document.getElementById("wpcgpt-flow-status");
    const listOutput = document.getElementById("wpcgpt-flow-list-output");

    const loadButton = document.getElementById("wpcgpt-flow-load");
    const listButton = document.getElementById("wpcgpt-flow-list");
    const templateButton = document.getElementById("wpcgpt-flow-template");
    const validateButton = document.getElementById("wpcgpt-flow-validate");
    const saveButton = document.getElementById("wpcgpt-flow-save");
    const deactivateButton = document.getElementById("wpcgpt-flow-deactivate");

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

    if (templateButton) {
        templateButton.addEventListener("click", function () {
            setCode(typeof config.defaultFlowCode === "string" ? config.defaultFlowCode : "");
            setStatus("Template inserted.", false);
        });
    }
})();
