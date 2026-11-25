import { cardString } from "./workspace-config";
import type {
	ConfirmableModelAction,
	ConfirmationDecision,
	ConfirmationRequest,
	PendingConfirmation,
	WorkspaceAction,
	WorkspaceActionStatus,
} from "./workspace-types";
import { cloneSerializable, decodeJsonEscaped } from "./workspace-utils";

/** How long one wait for the person's decision lasts, and how many waits the AI makes before it ends its turn. */
const WAIT_MS = 60000;
const WAIT_LIMIT = 10;

interface WorkspaceConfirmationHost {
	log: (message: string) => void;
}

/**
 * The actions waiting for the person in front of Visual. Each card is one decision, Approve or Deny: whether an
 * action waits at all is decided by the approvals, as for MCP and Novamira Chat, never by an earlier answer.
 */
export class WorkspaceConfirmations {
	private nextActionId = 1;
	private nextConfirmationId = 1;
	private actions = new Map<string, WorkspaceAction>();
	private actionWaiters = new Map<string, Set<() => void>>();
	private pendingConfirmations = new Map<number, PendingConfirmation>();

	constructor(
		private confirmationsEl: HTMLDivElement,
		private host: WorkspaceConfirmationHost,
	) {}

	enqueueToolConfirmation(
		action: ConfirmableModelAction,
		request: ConfirmationRequest,
		run: () => Promise<unknown>,
		onDeny?: () => void,
	): unknown {
		return this.enqueueConfirmableAction(action, { ...request, says: decodeJsonEscaped(request.says) }, run, onDeny);
	}

	enqueueConfirmableAction(
		action: ConfirmableModelAction,
		request: ConfirmationRequest,
		run: () => Promise<unknown>,
		onDeny?: () => void,
	): unknown {
		const actionId = `action-${this.nextActionId++}`;
		const now = Date.now();
		const workspaceAction: WorkspaceAction = {
			id: actionId,
			action,
			title: request.title,
			status: "waiting_for_confirmation",
			createdAt: now,
			updatedAt: now,
			run,
			onDeny,
		};
		this.actions.set(actionId, workspaceAction);

		const id = this.nextConfirmationId++;
		this.pendingConfirmations.set(id, {
			id,
			actionId,
			action,
			...request,
		});
		this.renderConfirmations();
		this.host.log(`Waiting for user approval: ${request.title} (${actionId}).`);

		return this.serializeAction(workspaceAction);
	}

	failPendingConfirmations(message: string): void {
		if (this.pendingConfirmations.size === 0) return;
		for (const confirmation of this.pendingConfirmations.values()) {
			this.setActionError(confirmation.actionId, "failed", message);
		}
		this.pendingConfirmations.clear();
		this.renderConfirmations();
	}

	getActionStatus(actionId: string): unknown {
		return this.serializeAction(this.getAction(actionId));
	}

	async waitForAction(actionId: string, timeoutMs: number): Promise<unknown> {
		const action = this.getAction(actionId);
		if (action.status === "waiting_for_confirmation") action.waits = (action.waits ?? 0) + 1;
		if (this.isTerminalActionStatus(action.status) || timeoutMs === 0) {
			return this.serializeAction(action);
		}

		return new Promise((resolve) => {
			let timer: number | null = null;
			const finish = (): void => {
				if (timer !== null) {
					window.clearTimeout(timer);
					timer = null;
				}
				const waiters = this.actionWaiters.get(actionId);
				if (waiters) {
					waiters.delete(check);
					if (waiters.size === 0) this.actionWaiters.delete(actionId);
				}
				resolve(this.serializeAction(this.getAction(actionId)));
			};

			const check = (): void => {
				if (this.isTerminalActionStatus(this.getAction(actionId).status)) {
					finish();
				}
			};

			let waiters = this.actionWaiters.get(actionId);
			if (!waiters) {
				waiters = new Set();
				this.actionWaiters.set(actionId, waiters);
			}
			waiters.add(check);
			timer = window.setTimeout(finish, timeoutMs);
		});
	}

	private renderConfirmations(): void {
		this.confirmationsEl.innerHTML = "";
		const confirmations = Array.from(this.pendingConfirmations.values());
		this.confirmationsEl.hidden = confirmations.length === 0;

		for (const confirmation of confirmations) {
			this.confirmationsEl.appendChild(this.drawCard(confirmation));
		}
	}

	/** One card, as AI Activity draws it: what the AI says it will do, why it asks, and the exact action closed. */
	private drawCard(confirmation: PendingConfirmation): HTMLElement {
		const card = document.createElement("section");
		card.className = "novamira-visual-confirmation-card";
		card.setAttribute("aria-label", confirmation.says || confirmation.title);

		card.appendChild(this.element("p", cardString("heading"), "novamira-visual-confirmation-heading"));
		card.appendChild(this.element("p", cardString("says"), "novamira-visual-confirmation-label"));
		card.appendChild(this.element("h2", confirmation.says || confirmation.title));
		if (confirmation.where !== "") {
			card.appendChild(this.element("p", confirmation.where, "novamira-visual-confirmation-where"));
		}

		// Why it asks, only when Novamira found something: otherwise the AI's rating decided, as the line above shows.
		if (confirmation.why.length > 0) {
			const facts = document.createElement("dl");
			facts.className = "novamira-visual-confirmation-details";
			facts.appendChild(this.element("dt", cardString("whyAsks")));
			const reasons = this.element("dd", "", "novamira-visual-confirmation-found");
			for (const reason of confirmation.why) {
				reasons.appendChild(this.element("span", reason, "novamira-visual-confirmation-chip"));
			}
			facts.appendChild(reasons);
			card.appendChild(facts);
		}

		if (confirmation.code !== "") {
			const panel = document.createElement("details");
			panel.className = "novamira-visual-confirmation-code";
			panel.appendChild(this.element("summary", confirmation.codeLabel));
			panel.appendChild(this.element("pre", confirmation.code));
			card.appendChild(panel);
		}

		const actions = document.createElement("div");
		actions.className = "novamira-visual-confirmation-actions";

		const approveBtn = document.createElement("button");
		approveBtn.type = "button";
		approveBtn.className = "button button-primary novamira-visual-confirmation-button novamira-visual-confirmation-approve";
		approveBtn.textContent = cardString("approveRun");
		approveBtn.addEventListener("click", () => this.decideConfirmation(confirmation.id, "approve"));
		actions.appendChild(approveBtn);

		const denyBtn = document.createElement("button");
		denyBtn.type = "button";
		denyBtn.className = "button novamira-visual-confirmation-button novamira-visual-confirmation-deny";
		denyBtn.textContent = cardString("reject");
		denyBtn.addEventListener("click", () => this.decideConfirmation(confirmation.id, "deny"));
		actions.appendChild(denyBtn);

		card.appendChild(actions);
		card.appendChild(this.element("p", cardString("rejectNote"), "novamira-visual-confirmation-note"));
		return card;
	}

	private element<K extends keyof HTMLElementTagNameMap>(tag: K, text: string, className = ""): HTMLElementTagNameMap[K] {
		const node = document.createElement(tag);
		if (className !== "") node.className = className;
		if (text !== "") node.textContent = text;
		return node;
	}

	private decideConfirmation(id: number, decision: ConfirmationDecision): void {
		const confirmation = this.pendingConfirmations.get(id);
		if (!confirmation) return;

		this.pendingConfirmations.delete(id);
		if (decision === "approve") {
			this.startConfirmedAction(confirmation.actionId);
		} else {
			this.setActionError(confirmation.actionId, "denied", `User denied action: ${confirmation.title}`);
		}
		this.host.log(decision === "approve" ? `Approved: ${confirmation.title}.` : `Denied: ${confirmation.title}.`);
		this.renderConfirmations();
	}

	private startConfirmedAction(actionId: string): void {
		const action = this.actions.get(actionId);
		if (!action || action.status !== "waiting_for_confirmation") return;

		action.status = "running";
		action.updatedAt = Date.now();
		this.notifyActionWaiters(actionId);

		void action
			.run()
			.then((result) => {
				action.status = "succeeded";
				action.result = cloneSerializable(result);
				action.updatedAt = Date.now();
				this.host.log(`Completed confirmed action ${actionId}.`);
			})
			.catch((err: unknown) => {
				action.status = "failed";
				action.error = err instanceof Error ? err.message : String(err);
				action.updatedAt = Date.now();
				this.host.log(`Confirmed action ${actionId} failed: ${action.error}`);
			})
			.finally(() => this.notifyActionWaiters(actionId));
	}

	private setActionError(actionId: string, status: "failed" | "denied", error: string): void {
		const action = this.actions.get(actionId);
		if (!action) return;
		if (action.status === "waiting_for_confirmation") action.onDeny?.();
		action.status = status;
		action.error = error;
		action.updatedAt = Date.now();
		this.notifyActionWaiters(actionId);
	}

	private notifyActionWaiters(actionId: string): void {
		const waiters = this.actionWaiters.get(actionId);
		if (!waiters) return;
		for (const waiter of Array.from(waiters)) {
			waiter();
		}
	}

	private getAction(actionId: string): WorkspaceAction {
		const action = this.actions.get(actionId);
		if (!action) {
			throw new Error(`Unknown action: ${actionId}`);
		}
		return action;
	}

	private serializeAction(action: WorkspaceAction): unknown {
		const terminal = this.isTerminalActionStatus(action.status);
		const awaitingApproval = action.status === "waiting_for_confirmation";
		const running = action.status === "running";
		// While the person decides, the AI waits for the answer rather than ending its turn, up to a limit.
		const keepWaiting = running || (awaitingApproval && (action.waits ?? 0) < WAIT_LIMIT);
		return {
			actionId: action.id,
			action: action.action,
			title: action.title,
			status: action.status,
			terminal,
			requiresUserConfirmation: awaitingApproval,
			message:
				awaitingApproval
					? "Nothing has run: this action waits for the user's approval in Novamira Visual."
					: running
						? "The approved action is running."
					: null,
			agentInstruction: awaitingApproval
				? keepWaiting
					? `Tell the user in one line that it waits for their approval in Novamira Visual, then call workspace_request with method 'workspace_wait_action' and params containing this actionId and timeoutMs ${WAIT_MS}: it returns as soon as they decide. If it still waits, call it again, at most ${WAIT_LIMIT} times in all.`
					: "The user has not decided yet. Tell them what waits for their approval in Novamira Visual and end your turn. When they write again, check it with workspace_get_action_status."
				: running
				? `Call workspace_request with method 'workspace_wait_action' and params containing this actionId and timeoutMs ${WAIT_MS}. If it is still not terminal, call it again.`
				: null,
			nextTool: keepWaiting
				? {
						name: "workspace_request",
						args: { method: "workspace_wait_action", params: { actionId: action.id, timeoutMs: WAIT_MS } },
					}
				: null,
			createdAt: action.createdAt,
			updatedAt: action.updatedAt,
			result: action.result ?? null,
			error: action.error ?? null,
		};
	}

	private isTerminalActionStatus(status: WorkspaceActionStatus): boolean {
		return status === "succeeded" || status === "failed" || status === "denied";
	}
}
