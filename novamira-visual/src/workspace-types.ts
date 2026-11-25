export type PageStatus = "loading" | "ready" | "error";
export type PageInteractionName = "click" | "fill" | "type" | "press" | "select" | "check" | "uncheck" | "scroll";
export type ConfirmableModelAction = "workspace_eval" | `backend_tool:${string}` | `page_tool:${string}` | `page_interaction:${PageInteractionName}`;
export type ConfirmationDecision = "approve" | "deny";
export type WorkspaceActionStatus = "waiting_for_confirmation" | "running" | "succeeded" | "failed" | "denied";

export interface PageElementRef {
	ref: string;
	element: Element;
	url: string;
	role: string;
	name: string;
	selector: string;
}

export interface PageElementRefStore {
	createdAt: number;
	url: string;
	refs: Map<string, PageElementRef>;
}

export interface PageSnapshotItem {
	ref: string;
	role: string;
	tag: string;
	name: string;
	selector: string;
	depth: number;
	attributes: Record<string, string | boolean | number>;
}

/** What the card shows, laid out as the cards of AI Activity: the AI's words first, the exact action closed. */
export interface ConfirmationRequest {
	/** What the action is, for the log and the action's status. */
	title: string;
	/** The AI's own words for what it will do. */
	says: string;
	/** Why it asks: what Novamira's check found. Empty when the AI's attention alone reached the cutoff. */
	why: string[];
	/** Where it acts: the page or the backend tool. */
	where: string;
	/** The label of the closed panel, and the exact action inside it. */
	codeLabel: string;
	code: string;
}

export interface PendingConfirmation extends ConfirmationRequest {
	id: number;
	actionId: string;
	action: ConfirmableModelAction;
}

export interface WorkspaceAction {
	id: string;
	action: ConfirmableModelAction;
	title: string;
	status: WorkspaceActionStatus;
	createdAt: number;
	updatedAt: number;
	result?: unknown;
	error?: string;
	run: () => Promise<unknown>;
	/** Tells whoever holds the request that it was denied or dropped. */
	onDeny?: () => void;
	/** How many times the AI waited for the person's decision. */
	waits?: number;
}

export interface WorkspacePage {
	id: string;
	title: string;
	url: string;
	status: PageStatus;
	openedAt: number;
	iframe: HTMLIFrameElement;
}

export interface PageToolDefinition {
	name: string;
	label?: string;
	description?: string;
	parameters?: unknown;
}

export interface BridgeRequest {
	type: "request";
	id: string | number;
	method: string;
	params?: unknown;
}

export interface BridgeResponse {
	type: "response";
	id: string | number;
	ok: boolean;
	result?: unknown;
	error?: string;
}

export interface BridgeConnection {
	port: number;
	socket: WebSocket;
}

export interface WorkspaceProtocolInfo {
	name: string;
	version: number;
	capabilities: string[];
	compatibleMcpPackage: string;
	visualVersion: string;
}

export interface WorkspaceState {
	connected: boolean;
	protocol: WorkspaceProtocolInfo;
	activePageId: string | null;
	requestGuide: string;
	activePageToolCallGuide?: string | null;
	activePageSuggestedNextCall?: unknown;
	methods: string[];
	pages: Array<{
		id: string;
		title: string;
		url: string;
		status: PageStatus;
		openedAt: number;
		tools: string[];
	}>;
}

export interface StoredWorkspaceTabs {
	activePageId: string | null;
	pages: Array<{
		id: string;
		title: string;
		url: string;
		openedAt: number;
	}>;
}
