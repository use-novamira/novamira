declare const novamiraVisualWorkspaceData: {
	adminUrl: string;
	siteUrl: string;
	siteName: string;
	serverName?: string;
	restUrl: string;
	workspaceUrl: string;
	mcpbUrl: string;
	authCheckUrl: string;
	loginUrl: string;
	visualVersion: string;
	abilitiesEnabled: boolean;
	/** wp_localize_script sends true as "1". */
	readMode?: boolean | string;
	readModeMessage?: string;
	/** Who decides whether a page action waits, and the attention from which it asks. */
	approvals?: { mode?: string; cutoff?: number | string; instructions?: string };
	/** The words of the AI Activity cards, so both say the same in every language. */
	cardStrings?: Partial<Record<CardString, string>>;
	/** Printed only in the workspace page: the pages it shows cannot decide an approval. */
	decisionNonce?: string;
	nonce: string;
};

export const workspaceData = novamiraVisualWorkspaceData;

export type CardString = "heading" | "says" | "whyAsks" | "aiAsks" | "showCode" | "showAction" | "approveRun" | "reject" | "rejectNote";

const CARD_STRINGS: Record<CardString, string> = {
	heading: "Approval needed",
	says: "The AI says it will",
	whyAsks: "Why it asks",
	aiAsks: "The AI judged it needs a look",
	showCode: "Show the code",
	showAction: "Show the action",
	approveRun: "Approve and run",
	reject: "Reject",
	rejectNote: "Reject: nothing runs, and the AI is told you said no.",
};

/** A word of the approval card, as the site translates it. */
export function cardString(key: CardString): string {
	const value = workspaceData.cardStrings?.[key];
	return typeof value === "string" && value !== "" ? value : CARD_STRINGS[key];
}

export const LAST_BRIDGE_PORT_KEY = "visual:lastBridgePort";
export const BRIDGE_PORTS_KEY = "visual:bridgePorts";
export const WORKSPACE_TABS_KEY = "visual:workspaceTabs";
export const AUTO_RECONNECT_INTERVAL_MS = 3000;
export const MAX_SAVED_BRIDGE_PORTS = 8;
export const WORKSPACE_EVAL_ENABLED = true;
export const WORKSPACE_PROTOCOL_NAME = "novamira-visual-workspace";
export const WORKSPACE_PROTOCOL_VERSION = 1;
export const WORKSPACE_PROTOCOL_CAPABILITIES = ["workspace-request-v1"] as const;
export const WORKSPACE_MCP_PACKAGE_VERSION = "latest";
export const WORKSPACE_MCP_NPM_PACKAGE = `novamira-visual-mcp@${WORKSPACE_MCP_PACKAGE_VERSION}`;
