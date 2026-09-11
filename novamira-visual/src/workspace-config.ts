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
	looksProduction: boolean;
	nonce: string;
};

export const workspaceData = novamiraVisualWorkspaceData;

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
