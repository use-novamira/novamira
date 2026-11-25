import { cardString, workspaceData } from "./workspace-config";
import type { ConfirmableModelAction, ConfirmationRequest } from "./workspace-types";
import { cloneSerializable } from "./workspace-utils";

interface WorkspaceBackendToolHost {
	log: (message: string) => void;
	confirmToolAction: (
		action: ConfirmableModelAction,
		request: ConfirmationRequest,
		run: () => Promise<unknown>,
		onDeny: () => void,
	) => unknown;
}

interface PendingApproval {
	id: string;
	why: string;
	/** The PHP of an execute-php call as the card shows it: laid out again when it was sent on one line. */
	code: string;
}

/**
 * Backend tools run on the site, which applies the approvals: a call that needs a person comes back waiting, and only
 * the person's decision, sent from the card, runs it.
 */
export class WorkspaceBackendTools {
	constructor(private host: WorkspaceBackendToolHost) {}

	async discoverBackendTools(params: Record<string, unknown>): Promise<unknown> {
		return cloneSerializable(await this.backendAjax("novamira_visual_safe_backend_tools_discover", params));
	}

	async callBackendTool(
		name: string,
		args: Record<string, unknown>,
		reason: string,
		attention: unknown,
		agentAction = false,
	): Promise<unknown> {
		if (agentAction) this.host.log(`Calling backend tool ${name}.`);
		const result = await this.backendAjax("novamira_visual_safe_backend_tools_call", { name, args, attention, reason });
		const pending = this.pendingApproval(result);
		if (!pending) return cloneSerializable(result);

		return this.host.confirmToolAction(`backend_tool:${name}` as ConfirmableModelAction, {
			title: `Backend tool: ${name}`,
			says: reason,
			why: pending.why.trim() === "" ? [] : [pending.why],
			where: name,
			codeLabel: cardString("showCode"),
			code: this.cardCode(args, pending.code),
		}, () => this.decide(pending.id, "approve"), () => {
			this.decide(pending.id, "deny").catch(() => undefined);
		});
	}

	private async decide(approvalId: string, decision: "approve" | "deny"): Promise<unknown> {
		const result = await this.backendAjax("novamira_visual_safe_backend_tools_decide", {
			approval_id: approvalId,
			decision,
			decision_nonce: workspaceData.decisionNonce ?? "",
		});
		return cloneSerializable(result);
	}

	private pendingApproval(result: unknown): PendingApproval | null {
		if (!result || typeof result !== "object") return null;
		const { status, approval_id: id, why, code } = result as { status?: unknown; approval_id?: unknown; why?: unknown; code?: unknown };
		if (status !== "pending_approval" || typeof id !== "string" || id === "") return null;
		return { id, why: typeof why === "string" ? why : "", code: typeof code === "string" ? code : "" };
	}

	/** The code on its own lines, then the other arguments as JSON. */
	private cardCode(args: Record<string, unknown>, code: string): string {
		const rest = { ...args };
		if (code !== "") delete rest.code;
		const parts = code !== "" ? [code] : [];
		if (Object.keys(rest).length > 0) parts.push(JSON.stringify(rest, null, 2));
		return parts.join("\n\n");
	}

	private async backendAjax(action: string, body: Record<string, unknown>): Promise<unknown> {
		const url = new URL("admin-ajax.php", workspaceData.adminUrl);
		url.searchParams.set("action", action);
		url.searchParams.set("nonce", workspaceData.nonce);
		const response = await window.fetch(url, {
			method: "POST",
			credentials: "same-origin",
			headers: {
				"Content-Type": "application/json",
				"X-WP-Nonce": workspaceData.nonce,
			},
			body: JSON.stringify(body),
		});
		const data = await response.json().catch(() => null) as unknown;
		const payload = data && typeof data === "object" ? data as { success?: unknown; data?: unknown } : null;
		if (!response.ok || payload?.success === false) {
			const detail = payload?.data && typeof payload.data === "object" ? payload.data as { message?: unknown } : null;
			const message = typeof detail?.message === "string"
				? detail.message
				: `Backend tool request failed with HTTP ${response.status}.`;
			throw new Error(message);
		}

		return payload && "data" in payload ? payload.data : data;
	}
}
