import "./workspace.css";

import { WorkspaceAgentGuidance } from "./workspace-agent-guidance";
import { WorkspaceBackendTools } from "./workspace-backend-tools";
import { WorkspaceConfirmations } from "./workspace-confirmations";
import {
	WORKSPACE_EVAL_ENABLED,
	WORKSPACE_MCP_NPM_PACKAGE,
	WORKSPACE_PROTOCOL_CAPABILITIES,
	WORKSPACE_PROTOCOL_NAME,
	WORKSPACE_PROTOCOL_VERSION,
	WORKSPACE_TABS_KEY,
	workspaceData,
} from "./workspace-config";
import { WorkspaceDispatcher } from "./workspace-dispatcher";
import { WorkspacePageTools } from "./workspace-page-tools";
import { WorkspaceTransport } from "./workspace-transport";
import type {
	ConfirmableModelAction,
	PageElementRefStore,
	PageInteractionName,
	PendingConfirmation,
	StoredWorkspaceTabs,
	WorkspacePage,
	WorkspaceState,
} from "./workspace-types";
import { cloneSerializable, optionalReason, truncateText } from "./workspace-utils";

const TITLE_SEPARATORS = "‹«»—–\\-|·";
const AGENT_ACTION_INDICATOR_MS = 8000;

/**
 * Reduce a raw document.title to its distinctive part by stripping the site
 * name and a trailing "WordPress" segment plus any leftover separators.
 * WordPress uses different separators per context (admin "‹ … —", frontend "–"),
 * so we remove known segments rather than splitting on a single delimiter.
 */
function cleanPageTitle(rawTitle: string | null | undefined, fallback: string): string {
	let title = (rawTitle ?? "").replace(/\s+/g, " ").trim();
	if (!title) return fallback;

	title = title.replace(new RegExp(`\\s*[${TITLE_SEPARATORS}]\\s*WordPress\\s*$`, "i"), "");

	const siteName = workspaceData.siteName?.trim();
	if (siteName) {
		const escaped = siteName.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
		title = title.replace(new RegExp(`\\s*[${TITLE_SEPARATORS}]\\s*${escaped}(?=\\s|$)`, "g"), "");
		title = title.replace(new RegExp(`^${escaped}\\s*[${TITLE_SEPARATORS}]\\s*`), "");
		if (title.trim() === siteName) title = "";
	}

	title = title.replace(new RegExp(`^[\\s${TITLE_SEPARATORS}]+|[\\s${TITLE_SEPARATORS}]+$`, "g"), "").trim();
	return title || siteName || fallback;
}

interface WorkspaceAuthCheck {
	authenticated: boolean;
	recoverUrl: string;
}

const WORKSPACE_FRAME_SANDBOX_TOKENS = [
	"allow-downloads",
	"allow-forms",
	"allow-pointer-lock",
	"allow-popups",
	"allow-popups-to-escape-sandbox",
	"allow-same-origin",
	"allow-scripts",
	"allow-top-navigation-by-user-activation",
].join(" ");

const NOVAMIRA_VISUAL_LOGO =
	'<svg viewBox="0 0 112 8.8" fill="currentColor" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Novamira Visual">' +
	'<path d="M0,.2h2.6l3,3.7V.2h2.8v8.4h-2.5l-3.1-3.9v3.9H0V.2Z"/>' +
	'<path d="M9.1,4.4h0C9.1,2,11.1,0,13.6,0s4.6,1.9,4.6,4.3h0c0,2.5-2,4.4-4.6,4.4s-4.6-1.9-4.6-4.3ZM15.4,4.4h0c0-1-.7-1.9-1.8-1.9s-1.7.9-1.7,1.9h0c0,1,.7,1.9,1.8,1.9s1.7-.9,1.7-1.9h0Z"/>' +
	'<path d="M17.7.2h3.1l1.6,4.7L24.1.2h3.1l-3.4,8.5h-2.7L17.7.2Z"/>' +
	'<path d="M28.5.1h2.8l3.5,8.5h-3l-.4-1.1h-2.9l-.4,1.1h-3L28.5.1ZM30.6,5.5l-.8-2-.8,2h1.5Z"/>' +
	'<path d="M35,.2h2.9l1.8,3,1.8-3h2.9v8.4h-2.8v-4.2l-1.9,3h0l-1.9-3v4.2h-2.8V.2h0Z"/>' +
	'<path d="M45.3.2h2.8v8.4h-2.8V.2Z"/>' +
	'<path d="M49,.2h4.1c1.5,0,2.4.4,3,1,.5.5.8,1.1.8,2h0c0,1.3-.6,2.1-1.7,2.6l2,2.8h-3.2l-1.6-2.4h-.6v2.4h-2.8s0-8.4,0-8.4ZM53,4.2c.7,0,1.1-.3,1.1-.8h0c0-.6-.4-.8-1.1-.8h-1.2v1.6h1.2Z"/>' +
	'<path d="M60.1.1h2.8l3.5,8.5h-3l-.4-1.1h-2.9l-.4,1.1h-3L60.1.1ZM62.2,5.5l-.8-2-.8,2h1.5Z"/>' +
	'<path d="M70.1.2h1.1l3,7.2,3-7.2h1l-3.6,8.5h-.8L70.1.2Z"/>' +
	'<path d="M79.3.2h.9v8.4h-.9V.2Z"/>' +
	'<path d="M81.6,7.3l.6-.7c.9.8,1.7,1.2,2.9,1.2s1.9-.6,1.9-1.4h0c0-.8-.4-1.2-2.2-1.6-1.9-.4-2.8-1-2.8-2.4h0c0-1.3,1.2-2.3,2.8-2.3s2.1.3,2.9,1l-.6.7c-.8-.6-1.6-.9-2.4-.9s-1.8.6-1.8,1.4h0c0,.8.4,1.3,2.3,1.7,1.9.4,2.7,1.1,2.7,2.4h0c0,1.5-1.2,2.4-2.9,2.4s-2.4-.4-3.4-1.3h0Z"/>' +
	'<path d="M89.1,5.1V.2h.9v4.8c0,1.8.9,2.8,2.6,2.8s2.5-.9,2.5-2.8V.2h.9v4.8c0,2.5-1.4,3.7-3.5,3.7s-3.5-1.3-3.5-3.7h0Z"/>' +
	'<path d="M100.5.1h.9l3.8,8.5h-1l-1-2.2h-4.6l-1,2.2h-1L100.5.1ZM102.8,5.5l-1.9-4.3-1.9,4.3h3.8Z"/>' +
	'<path d="M106.3.2h.9v7.5h4.7v.9h-5.7V.2h0Z"/>' +
	"</svg>";

class AgentWorkspace {
	private root: HTMLElement;
	private statusText!: HTMLSpanElement;
	private allToolsStatusEl!: HTMLButtonElement;
	private connectionInput!: HTMLInputElement;
	private connectionBtn!: HTMLButtonElement;
	private connectionForm!: HTMLFormElement;
	private reconnectLink!: HTMLButtonElement;
	private connectionOptionsBtn!: HTMLButtonElement;
	private configurationBtn!: HTMLButtonElement;
	private connectionCloseBtn!: HTMLButtonElement;
	private connectionPanel: HTMLDivElement | null = null;
	private connectionTitle: HTMLHeadingElement | null = null;
	private connectionDescription: HTMLParagraphElement | null = null;
	private connectionDetail: HTMLDivElement | null = null;
	private setupSection: HTMLElement | null = null;
	private welcomeSection: HTMLElement | null = null;
	private welcomeMain: HTMLElement | null = null;
	private welcomeDismissed = false;
	private abilitiesConsentSection: HTMLElement | null = null;
	private abilitiesJustEnabled = false;
	private configureViewEl: HTMLElement | null = null;
	private connectViewEl: HTMLElement | null = null;
	private showConnectView: boolean | null = null;
	private tabsEl!: HTMLDivElement;
	private overviewBtn!: HTMLButtonElement;
	private tabbarEl!: HTMLElement;
	private newPageMenu!: HTMLElement;
	private framesEl!: HTMLDivElement;
	private confirmationsEl!: HTMLDivElement;
	private logEl!: HTMLDivElement;
	private pages = new Map<string, WorkspacePage>();
	private activePageId: string | null = null;
	private lastAgentActionPageId: string | null = null;
	private agentActionIndicatorSpinning = false;
	private agentActionIndicatorTimer: number | undefined;
	private overviewMode = false;
	private reconnectFormVisible = false;
	private connectionOptionsVisible = false;
	private connectedConfirmEl!: HTMLElement;
	private showConnectedConfirm = false;
	private connectedConfirmTimer: number | undefined;
	private authRecoveryInProgress = false;
	private nextPageNumber = 1;
	private toolInputsEmittedForPage = new Set<string>();
	private pageElementRefs = new Map<string, PageElementRefStore>();
	private pageTools: WorkspacePageTools;
	private guidance: WorkspaceAgentGuidance;
	private backendTools: WorkspaceBackendTools;
	private confirmations!: WorkspaceConfirmations;
	private dispatcher: WorkspaceDispatcher;
	private transport: WorkspaceTransport;

	constructor(root: HTMLElement) {
		this.root = root;
		this.pageTools = new WorkspacePageTools({
			pageElementRefs: this.pageElementRefs,
			getPage: (pageId) => this.getPage(pageId),
			getPageWindow: (page) => this.getPageWindow(page),
			markAgentAction: (pageId) => this.markAgentAction(pageId),
		});
		this.guidance = new WorkspaceAgentGuidance({
			getPageWindow: (page) => this.getPageWindow(page),
			getPageTools: (page) => this.getPageTools(page),
		});
		this.render();
		this.confirmations = new WorkspaceConfirmations(this.confirmationsEl, {
			log: (message) => this.log(message),
			setAllToolsAllowed: (allowed) => this.setAllToolsAllowed(allowed),
		});
		this.backendTools = new WorkspaceBackendTools({
			log: (message) => this.log(message),
			confirmToolAction: (action, request, run) => this.confirmations.enqueueToolConfirmation(action, request, run),
			isToolAllowed: (action) => this.confirmations.isToolAllowed(action),
		});
		this.dispatcher = new WorkspaceDispatcher({
			getActivePageId: () => this.getActivePageId(),
			getState: () => this.getState(),
			openPage: (params, agentAction) => this.openPage(params, agentAction),
			closePage: (pageId, agentAction) => this.closePage(pageId, agentAction),
			focusPage: (pageId, agentAction) => this.focusPage(pageId, agentAction),
			reloadPage: (pageId, agentAction) => this.reloadPage(pageId, agentAction),
			inspectPage: (pageId, agentAction, includeText, textLimit, includeToolInputs) => this.inspectPage(pageId, agentAction, includeText, textLimit, includeToolInputs),
			confirmPageInteraction: (name, params, agentAction, run) => this.confirmPageInteraction(name, params, agentAction, run),
			evalInPage: (pageId, code, agentAction) => this.evalInPage(pageId, code, agentAction),
			getActionStatus: (actionId) => this.getActionStatus(actionId),
			waitForAction: (actionId, timeoutMs) => this.waitForAction(actionId, timeoutMs),
			discoverBackendTools: (params) => this.discoverBackendTools(params),
			callBackendTool: (name, args, reason, agentAction) => this.callBackendTool(name, args, reason, agentAction),
			callPageTool: (pageId, toolName, args, reason, agentAction) => this.callPageTool(pageId, toolName, args, reason, agentAction),
		}, this.pageTools);
		this.transport = new WorkspaceTransport({
			dispatch: (method, params) => this.dispatcher.dispatch(method, params),
			getState: () => this.getState(),
			log: (message) => this.log(message),
			logError: (err) => this.logError(err),
			setBridgeStatus: (text, connected) => this.setBridgeStatus(text, connected),
			showConnectionDetail: (message) => this.showConnectionDetail(message),
			updateConnectionPanel: () => this.updateConnectionPanel(),
			onBridgeConnected: () => {
				// Show a brief green "Connected" confirmation, then collapse to the
				// workspace. Covers both explicit connects and auto-reconnects.
				this.flashConnectedConfirm();
			},
			failPendingConfirmations: (message) => this.confirmations.failPendingConfirmations(message),
		});
		this.renderTabs();
		this.updateConnectionPanel();
		this.restoreWorkspaceTabs();

		const mcpPort = this.transport.getMcpPortFromUrl();
		if (mcpPort !== null) {
			this.connectionInput.value = `visual:${mcpPort}`;
			this.transport.connectFromMcpPort(mcpPort).catch((err) => {
				this.logError(err);
				this.transport.startAutoReconnect(mcpPort);
			});
			return;
		}

		const savedPorts = this.transport.readSavedBridgePorts();
		if (savedPorts.length > 0) {
			this.connectionInput.value = `visual:${savedPorts[0]}`;
			for (const savedPort of savedPorts) {
				this.transport.startAutoReconnect(savedPort, true);
			}
		}
	}
	private render(): void {
		this.root.className = "novamira-visual-workspace";
		this.root.innerHTML = "";

		const shell = document.createElement("div");
		shell.className = "novamira-visual-workspace-shell";

		const topbar = document.createElement("header");
		topbar.className = "novamira-visual-workspace-topbar";

		const title = document.createElement("div");
		title.className = "novamira-visual-workspace-title";
		title.innerHTML = NOVAMIRA_VISUAL_LOGO;
		topbar.appendChild(title);

		topbar.appendChild(this.buildActivityBar());

		const actions = document.createElement("div");
		actions.className = "novamira-visual-workspace-actions";
		actions.appendChild(this.buildAllToolsStatus());
		actions.appendChild(this.buildBridgeControls());

		this.configurationBtn = document.createElement("button");
		this.configurationBtn.type = "button";
		this.configurationBtn.className = "button";
		this.configurationBtn.textContent = "Set up your AI client";
		this.configurationBtn.addEventListener("click", () => {
			this.connectionOptionsVisible = true;
			this.showConnectView = false;
			this.updateConnectionPanel();
		});
		actions.appendChild(this.configurationBtn);

		topbar.appendChild(actions);
		shell.appendChild(topbar);

		const body = document.createElement("main");
		body.className = "novamira-visual-workspace-main";

		const content = document.createElement("section");
		content.className = "novamira-visual-workspace-content";

		const tabbar = document.createElement("div");
		tabbar.className = "novamira-visual-tabbar";
		this.tabbarEl = tabbar;

		this.tabsEl = document.createElement("div");
		this.tabsEl.className = "novamira-visual-tabs";
		tabbar.appendChild(this.tabsEl);

		this.newPageMenu = this.buildNewPageMenu();
		tabbar.appendChild(this.newPageMenu);

		this.overviewBtn = document.createElement("button");
		this.overviewBtn.type = "button";
		this.overviewBtn.className = "button novamira-visual-overview-toggle";
		this.overviewBtn.title = "Overview";
		this.overviewBtn.setAttribute("aria-label", "Overview");
		this.overviewBtn.innerHTML =
			'<svg viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><rect x="1" y="1" width="4" height="4" rx="1"/><rect x="6" y="1" width="4" height="4" rx="1"/><rect x="11" y="1" width="4" height="4" rx="1"/><rect x="1" y="6" width="4" height="4" rx="1"/><rect x="6" y="6" width="4" height="4" rx="1"/><rect x="11" y="6" width="4" height="4" rx="1"/><rect x="1" y="11" width="4" height="4" rx="1"/><rect x="6" y="11" width="4" height="4" rx="1"/><rect x="11" y="11" width="4" height="4" rx="1"/></svg>';
		this.overviewBtn.addEventListener("click", () => this.setOverviewMode(!this.overviewMode));
		tabbar.appendChild(this.overviewBtn);

		content.appendChild(tabbar);

		this.framesEl = document.createElement("div");
		this.framesEl.className = "novamira-visual-frames";
		this.framesEl.appendChild(this.buildConnectionPanel());
		content.appendChild(this.framesEl);

		body.appendChild(content);

		shell.appendChild(body);

		this.confirmationsEl = document.createElement("div");
		this.confirmationsEl.className = "novamira-visual-confirmations";
		this.confirmationsEl.setAttribute("aria-live", "polite");
		this.confirmationsEl.hidden = true;
		shell.appendChild(this.confirmationsEl);

		this.root.appendChild(shell);
	}

	private buildBridgeControls(): HTMLElement {
		this.connectionOptionsBtn = document.createElement("button");
		this.connectionOptionsBtn.type = "button";
		this.connectionOptionsBtn.className = "button";
		this.connectionOptionsBtn.textContent = "Connect";
		this.connectionOptionsBtn.addEventListener("click", () => {
			this.connectionOptionsVisible = true;
			this.showConnectView = true;
			this.updateConnectionPanel();
		});
		return this.connectionOptionsBtn;
	}

	private buildAllToolsStatus(): HTMLButtonElement {
		this.allToolsStatusEl = document.createElement("button");
		this.allToolsStatusEl.type = "button";
		this.allToolsStatusEl.className = "novamira-visual-all-tools-status";
		this.allToolsStatusEl.hidden = true;
		this.allToolsStatusEl.title = "Allow all is on. Click to turn it off.";
		this.allToolsStatusEl.setAttribute("aria-label", "Allow all is on. Click to turn it off.");
		this.allToolsStatusEl.innerHTML =
			'<svg viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M8 1.8 14.2 13H1.8L8 1.8Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M8 5.7v3.2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><path d="M8 11.4h.01" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg><span>Allow all</span>';
		this.allToolsStatusEl.addEventListener("click", () => this.confirmations.disableAllToolsAllowance());
		return this.allToolsStatusEl;
	}

	private buildActivityBar(): HTMLElement {
		const bar = document.createElement("div");
		bar.className = "novamira-visual-activity-bar";

		const dot = document.createElement("span");
		dot.className = "novamira-visual-status-dot";
		bar.appendChild(dot);

		this.statusText = document.createElement("span");
		this.statusText.className = "novamira-visual-activity-text";
		this.statusText.textContent = "Disconnected";
		bar.appendChild(this.statusText);

		this.logEl = document.createElement("div");
		this.logEl.id = "novamira-visual-bridge-log";
		this.logEl.className = "novamira-visual-activity-pop";
		this.logEl.hidden = true;
		bar.appendChild(this.logEl);

		bar.addEventListener("click", (event) => {
			event.stopPropagation();
			this.logEl.hidden = !this.logEl.hidden;
		});
		this.logEl.addEventListener("click", (event) => event.stopPropagation());
		document.addEventListener("click", () => {
			this.logEl.hidden = true;
		});

		return bar;
	}

	private buildNewPageMenu(): HTMLElement {
		const menu = document.createElement("div");
		menu.className = "novamira-visual-menu novamira-visual-new-page";

		const btn = document.createElement("button");
		btn.type = "button";
		btn.className = "novamira-visual-new-page-btn";
		btn.title = "New page";
		btn.innerHTML = '<span class="novamira-visual-new-page-plus" aria-hidden="true">+</span><span>New Page</span>';

		const pop = document.createElement("div");
		pop.className = "novamira-visual-menu-pop";
		pop.hidden = true;

		const entries: Array<{ label: string; run: () => void }> = [
			{ label: "WordPress Dashboard", run: () => void this.openPage({ url: workspaceData.adminUrl, title: "Dashboard" }).catch((err) => this.logError(err)) },
			{ label: "Site Frontend", run: () => void this.openPage({ url: workspaceData.siteUrl, title: "Frontend" }).catch((err) => this.logError(err)) },
			{
				label: "My Site URL…",
				run: () => {
					const url = window.prompt("Open a page of this site by URL:", `${workspaceData.adminUrl}edit.php?post_type=page`);
					if (url) void this.openPage({ url }).catch((err) => this.logError(err));
				},
			},
		];

		for (const entry of entries) {
			const item = document.createElement("button");
			item.type = "button";
			item.className = "novamira-visual-menu-item";
			item.textContent = entry.label;
			item.addEventListener("click", () => {
				pop.hidden = true;
				entry.run();
			});
			pop.appendChild(item);
		}

		btn.addEventListener("click", (event) => {
			event.stopPropagation();
			pop.hidden = !pop.hidden;
		});
		document.addEventListener("click", () => {
			pop.hidden = true;
		});

		menu.appendChild(btn);
		menu.appendChild(pop);
		return menu;
	}

	private buildConnectionPanel(): HTMLDivElement {
		const panel = document.createElement("div");
		panel.className = "novamira-visual-connection-panel";

		const card = document.createElement("div");
		card.className = "novamira-visual-connection-card";

		this.connectionCloseBtn = document.createElement("button");
		this.connectionCloseBtn.type = "button";
		this.connectionCloseBtn.className = "novamira-visual-connection-close";
		this.connectionCloseBtn.textContent = "Close";
		this.connectionCloseBtn.addEventListener("click", () => {
			this.connectionOptionsVisible = false;
			this.reconnectFormVisible = false;
			this.updateConnectionPanel();
		});
		card.appendChild(this.connectionCloseBtn);

		this.welcomeSection = this.buildWelcomeSection();
		card.appendChild(this.welcomeSection);

		this.abilitiesConsentSection = this.buildAbilitiesConsent();
		card.appendChild(this.abilitiesConsentSection);

		const main = document.createElement("div");
		main.className = "novamira-visual-connection-main";
		this.welcomeMain = main;

		// Configure view — set up the MCP client. No connect field here.
		const configureView = document.createElement("div");
		configureView.className = "novamira-visual-configure-view";
		this.configureViewEl = configureView;

		const cfgTitle = document.createElement("h2");
		cfgTitle.textContent = "Set up your AI client";
		configureView.appendChild(cfgTitle);

		const cfgDesc = document.createElement("p");
		cfgDesc.textContent = "Add Novamira Visual to the AI client you use. You only do this once per client.";
		configureView.appendChild(cfgDesc);

		const doneWrap = document.createElement("div");
		doneWrap.className = "novamira-visual-configure-done";
		doneWrap.hidden = true;
		const doneBtn = document.createElement("button");
		doneBtn.type = "button";
		doneBtn.className = "button button-primary";
		doneBtn.textContent = "Done, connect";
		doneBtn.addEventListener("click", () => {
			this.showConnectView = true;
			this.updateConnectionPanel();
		});
		doneWrap.appendChild(doneBtn);

		this.setupSection = this.buildConfigureSection(() => {
			doneWrap.hidden = false;
		});
		configureView.appendChild(this.setupSection);
		configureView.appendChild(doneWrap);

		main.appendChild(configureView);

		// Connect view — paste the connection code. No configuration here.
		const connectView = document.createElement("div");
		this.connectViewEl = connectView;

		this.connectionTitle = document.createElement("h2");
		connectView.appendChild(this.connectionTitle);

		this.connectionDescription = document.createElement("p");
		connectView.appendChild(this.connectionDescription);

		this.connectionForm = document.createElement("form");
		this.connectionForm.className = "novamira-visual-connection-form";
		this.connectionForm.addEventListener("submit", (event) => {
			event.preventDefault();
			this.connectFromInput();
		});

		this.connectionInput = document.createElement("input");
		this.connectionInput.type = "text";
		this.connectionInput.placeholder = "visual:51234";
		this.connectionInput.autocomplete = "off";
		this.connectionInput.spellcheck = false;
		this.connectionInput.setAttribute("aria-label", "Agent connection code");
		this.connectionForm.appendChild(this.connectionInput);

		this.connectionBtn = document.createElement("button");
		this.connectionBtn.type = "submit";
		this.connectionBtn.className = "button button-primary";
		this.connectionBtn.textContent = "Connect";
		this.connectionForm.appendChild(this.connectionBtn);

		connectView.appendChild(this.connectionForm);

		this.connectionDetail = document.createElement("div");
		this.connectionDetail.className = "novamira-visual-connection-detail";
		connectView.appendChild(this.connectionDetail);

		const connectActions = document.createElement("div");
		connectActions.className = "novamira-visual-connection-actions";

		this.reconnectLink = document.createElement("button");
		this.reconnectLink.type = "button";
		this.reconnectLink.className = "novamira-visual-reconnect-link";
		this.reconnectLink.textContent = "Add another connection code";
		this.reconnectLink.addEventListener("click", () => {
			this.reconnectFormVisible = true;
			this.updateConnectionPanel();
			this.connectionInput.focus();
		});
		connectActions.appendChild(this.reconnectLink);
		connectView.appendChild(connectActions);

		main.appendChild(connectView);

		card.appendChild(main);

		this.connectedConfirmEl = document.createElement("div");
		this.connectedConfirmEl.className = "novamira-visual-connected-confirm";
		this.connectedConfirmEl.hidden = true;
		const confirmIcon = document.createElement("div");
		confirmIcon.className = "novamira-visual-connected-confirm-icon";
		confirmIcon.innerHTML =
			'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7"/></svg>';
		const confirmText = document.createElement("div");
		confirmText.className = "novamira-visual-connected-confirm-text";
		confirmText.textContent = "Connected";
		this.connectedConfirmEl.append(confirmIcon, confirmText);
		card.appendChild(this.connectedConfirmEl);

		panel.appendChild(card);
		this.connectionPanel = panel;

		// Click on the backdrop (outside the card) closes the panel, but only when
		// it is a dismissible overlay (the Close button is available) — never during
		// welcome, abilities or the connected confirmation.
		panel.addEventListener("click", (event) => {
			if (event.target === panel && !this.connectionCloseBtn.hidden) {
				this.connectionOptionsVisible = false;
				this.reconnectFormVisible = false;
				this.updateConnectionPanel();
			}
		});

		return panel;
	}

	private buildWelcomeSection(): HTMLElement {
		const section = document.createElement("div");
		section.className = "novamira-visual-welcome";

		const logo = document.createElement("div");
		logo.className = "novamira-visual-welcome-logo";
		logo.innerHTML = NOVAMIRA_VISUAL_LOGO;
		section.appendChild(logo);

		const eyebrow = document.createElement("div");
		eyebrow.className = "novamira-visual-welcome-eyebrow";
		eyebrow.textContent = "Experimental";
		section.appendChild(eyebrow);

		const title = document.createElement("h2");
		title.className = "novamira-visual-welcome-title";
		title.textContent = "Watch your AI agent work on WordPress";
		section.appendChild(title);

		const lead = document.createElement("p");
		lead.className = "novamira-visual-welcome-lead";
		lead.textContent =
			"Novamira Visual lets you watch your AI agent work on WordPress. Instead of working out of sight, it opens your pages and editors in a browser workspace, right in front of you.";
		section.appendChild(lead);

		section.appendChild(this.buildWelcomeWork(
			"See it work live in the block editor (Gutenberg)",
			"Free",
			true,
			'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" aria-hidden="true"><rect x="3.5" y="4" width="17" height="4.4" rx="1"/><rect x="3.5" y="10.8" width="17" height="4.4" rx="1"/><path d="M3.5 19h9"/></svg>',
		));
		section.appendChild(this.buildWelcomeWork(
			"And in Elementor and Bricks",
			"Novamira Pro",
			false,
			'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3.5" y="4" width="17" height="16" rx="1.5"/><path d="M3.5 9h17M11 9v11"/></svg>',
		));

		const note = document.createElement("p");
		note.className = "novamira-visual-welcome-note";
		note.innerHTML =
			'An experiment for development and staging sites: it may change, or be removed. ' +
			'Whether it grows depends on the community. ' +
			'<a href="https://discord.gg/novamira" target="_blank" rel="noopener">Join us on Discord</a> or ' +
			'<a href="https://facebook.com/groups/novamira" target="_blank" rel="noopener">Facebook</a>.';
		section.appendChild(note);

		const actions = document.createElement("div");
		actions.className = "novamira-visual-welcome-actions";
		const start = document.createElement("button");
		start.type = "button";
		start.className = "button button-primary novamira-visual-welcome-start";
		start.textContent = "Get started";
		start.addEventListener("click", () => {
			this.welcomeDismissed = true;
			this.updateConnectionPanel();
		});
		actions.appendChild(start);
		section.appendChild(actions);

		return section;
	}

	private buildWelcomeWork(label: string, tag: string, free: boolean, glyph: string): HTMLElement {
		const row = document.createElement("div");
		row.className = "novamira-visual-welcome-work";
		const ico = document.createElement("span");
		ico.className = "novamira-visual-welcome-work-ico";
		ico.innerHTML = glyph;
		row.appendChild(ico);
		const text = document.createElement("b");
		text.textContent = label;
		row.appendChild(text);
		const tagEl = document.createElement("span");
		tagEl.className = "novamira-visual-welcome-work-tag" + (free ? " is-free" : "");
		tagEl.textContent = tag;
		row.appendChild(tagEl);
		return row;
	}

	/** Computed server-side so the JSON configs match the .mcpb bundle name. */
	private mcpServerName(): string {
		return workspaceData.serverName || "novamira-visual";
	}

	private copyButton(label: string, getText: () => string): HTMLButtonElement {
		const btn = document.createElement("button");
		btn.type = "button";
		btn.className = "button";
		btn.textContent = label;
		btn.addEventListener("click", () => {
			void navigator.clipboard?.writeText(getText());
			btn.textContent = "Copied";
			window.setTimeout(() => {
				btn.textContent = label;
			}, 1200);
		});
		return btn;
	}

	private buildConfigureSection(onSelect: () => void): HTMLElement {
		const section = document.createElement("div");
		section.className = "novamira-visual-configure";

		const lead = document.createElement("p");
		lead.className = "novamira-visual-configure-lead";
		lead.textContent = "Pick the client you use. Set up as many as you like, they all connect to the same workspace.";
		section.appendChild(lead);

		const serverName = this.mcpServerName();
		const url = workspaceData.workspaceUrl;
		const server = {
			command: "npx",
			args: ["-y", WORKSPACE_MCP_NPM_PACKAGE],
			env: { NOVAMIRA_VISUAL_WORKSPACE_URL: url },
		};
		const mcpJson = JSON.stringify({ mcpServers: { [serverName]: server } }, null, 2);
		const vscodeJson = JSON.stringify({ servers: { [serverName]: server } }, null, 2);
		const zedJson = JSON.stringify(
			{ context_servers: { [serverName]: { source: "custom", enabled: true, ...server } } },
			null,
			2,
		);
		const opencodeJson = JSON.stringify(
			{ mcp: { [serverName]: { type: "local", command: ["npx", "-y", WORKSPACE_MCP_NPM_PACKAGE], environment: { NOVAMIRA_VISUAL_WORKSPACE_URL: url } } } },
			null,
			2,
		);
		const codexToml = [
			`[mcp_servers.${serverName}]`,
			'command = "npx"',
			`args = ["-y", "${WORKSPACE_MCP_NPM_PACKAGE}"]`,
			"",
			`[mcp_servers.${serverName}.env]`,
			`NOVAMIRA_VISUAL_WORKSPACE_URL = "${url}"`,
		].join("\n");
		const claudeCodeCmd = `claude mcp add-json ${serverName} '${JSON.stringify(server)}'`;
		const clients: Array<{ label: string; desktop: boolean; path: string; code: string }> = [
			{ label: "Claude Code", desktop: false, path: "the Claude Code CLI", code: claudeCodeCmd },
			{ label: "Claude Desktop", desktop: true, path: "claude_desktop_config.json", code: mcpJson },
			{ label: "Codex", desktop: false, path: "~/.codex/config.toml", code: codexToml },
			{ label: "Antigravity", desktop: false, path: "~/.gemini/antigravity/mcp_config.json", code: mcpJson },
			{ label: "Cursor", desktop: false, path: "~/.cursor/mcp.json", code: mcpJson },
			{ label: "VS Code", desktop: false, path: ".vscode/mcp.json", code: vscodeJson },
			{ label: "GitHub Copilot", desktop: false, path: ".github/copilot/mcp.json", code: vscodeJson },
			{ label: "Windsurf", desktop: false, path: "~/.codeium/windsurf/mcp_config.json", code: mcpJson },
			{ label: "Cline", desktop: false, path: "cline_mcp_settings.json", code: mcpJson },
			{ label: "Gemini CLI", desktop: false, path: "~/.gemini/settings.json", code: mcpJson },
			{ label: "Roo Code", desktop: false, path: ".roo/mcp.json", code: mcpJson },
			{ label: "Amazon Q", desktop: false, path: "~/.aws/amazonq/mcp.json", code: mcpJson },
			{ label: "Zed", desktop: false, path: "~/.config/zed/settings.json", code: zedJson },
			{ label: "Kilo Code", desktop: false, path: ".kilocode/mcp.json", code: mcpJson },
			{ label: "OpenCode", desktop: false, path: "opencode.json", code: opencodeJson },
		];

		const tabs = document.createElement("div");
		tabs.className = "novamira-visual-configure-tabs";
		section.appendChild(tabs);

		const body = document.createElement("div");
		body.hidden = true;
		section.appendChild(body);

		// .mcpb bundle (Claude Desktop): currently disabled due to newer Claude Desktop compatibility.
		const bundle = document.createElement("div");
		bundle.className = "novamira-visual-bundle";
		bundle.hidden = true;
		const card = document.createElement("div");
		card.className = "novamira-visual-bundle-card";
		const cardText = document.createElement("div");
		cardText.className = "novamira-visual-bundle-text";
		const cardTitle = document.createElement("b");
		cardTitle.textContent = "Download .mcpb bundle";
		const cardSub = document.createElement("span");
		cardSub.textContent = "Currently not working with newer Claude Desktop versions.";
		cardText.append(cardTitle, cardSub);
		const cardBtn = document.createElement("button");
		cardBtn.type = "button";
		cardBtn.className = "button button-secondary";
		cardBtn.textContent = "Download .mcpb";
		cardBtn.disabled = true;
		cardBtn.setAttribute("aria-disabled", "true");
		card.append(cardText, cardBtn);
		bundle.append(card);
		body.appendChild(bundle);

		// setup prompt (other clients)
		const promptWrap = document.createElement("div");
		promptWrap.hidden = true;
		const promptLabel = document.createElement("p");
		promptLabel.className = "novamira-visual-configure-label";
		promptLabel.textContent = "Paste this prompt into your agent";
		const promptArea = document.createElement("textarea");
		promptArea.className = "novamira-visual-setup-prompt";
		promptArea.readOnly = true;
		promptArea.rows = 4;
		promptArea.value = this.buildMcpSetupPrompt(serverName);
		const promptCopyWrap = document.createElement("p");
		promptCopyWrap.style.margin = "10px 0 0";
		promptCopyWrap.appendChild(this.copyButton("Copy setup prompt", () => promptArea.value));
		promptWrap.append(promptLabel, promptArea, promptCopyWrap);
		body.appendChild(promptWrap);

		// JSON config — collapsed, all clients
		const jsonToggle = document.createElement("button");
		jsonToggle.type = "button";
		jsonToggle.className = "novamira-visual-configure-toggle";
		jsonToggle.textContent = "Manual configuration";
		const jsonDetail = document.createElement("div");
		jsonDetail.hidden = true;
		const jsonPre = document.createElement("pre");
		jsonPre.className = "novamira-visual-setup-config";
		const jsonCopyWrap = document.createElement("p");
		jsonCopyWrap.style.margin = "10px 0 0";
		jsonCopyWrap.appendChild(this.copyButton("Copy config", () => jsonPre.textContent ?? ""));
		const jsonPath = document.createElement("p");
		jsonPath.className = "novamira-visual-configure-hint";
		const jsonPathName = document.createElement("span");
		jsonPath.append("Add to ", jsonPathName, ".");
		jsonDetail.append(jsonPre, jsonCopyWrap, jsonPath);
		jsonToggle.addEventListener("click", () => {
			jsonDetail.hidden = !jsonDetail.hidden;
		});
		body.append(jsonToggle, jsonDetail);

		for (const client of clients) {
			const tab = document.createElement("button");
			tab.type = "button";
			tab.className = "button button-small";
			tab.textContent = client.label;
			tab.addEventListener("click", () => {
				for (const other of Array.from(tabs.children)) other.removeAttribute("aria-pressed");
				tab.setAttribute("aria-pressed", "true");
				body.hidden = false;
				bundle.hidden = !client.desktop;
				promptWrap.hidden = client.desktop;
				jsonPre.textContent = client.code;
				jsonPathName.textContent = client.path;
				jsonDetail.hidden = !client.desktop;
				onSelect();
			});
			tabs.appendChild(tab);
		}

		return section;
	}

	private buildMcpSetupPrompt(serverName: string): string {
		return [
			"I want to add Novamira Visual as an MCP server to this AI client.",
			"",
			"Connection details:",
			`- Workspace URL: ${workspaceData.workspaceUrl}`,
			`- Server name to use in the config: ${serverName}`,
			"- Transport: novamira-visual-mcp via npx",
			"",
			"Setup rules:",
			"- Use stdio transport.",
			'- command must be "npx".',
			`- args array must be exactly ["-y", "${WORKSPACE_MCP_NPM_PACKAGE}"].`,
			"- Set NOVAMIRA_VISUAL_WORKSPACE_URL in env to the Workspace URL above.",
			"- Do not add WordPress credentials; Novamira Visual uses the browser session already open in WordPress.",
			"",
			"After writing the config, tell me what to restart, then verify by calling workspace_status.",
		].join("\n");
	}

	private buildAbilitiesConsent(): HTMLElement {
		const section = document.createElement("div");
		section.className = "novamira-visual-abilities-consent";

		const eyebrow = document.createElement("div");
		eyebrow.className = "novamira-visual-welcome-eyebrow";
		eyebrow.textContent = "Required";
		section.appendChild(eyebrow);

		const title = document.createElement("h2");
		title.className = "novamira-visual-welcome-title";
		title.textContent = "Turn on AI Abilities";
		section.appendChild(title);

		const lead = document.createElement("p");
		lead.className = "novamira-visual-welcome-lead";
		lead.textContent =
			"Novamira Visual needs AI Abilities on for this site, and they are off right now. They are a site-wide switch, shared with Novamira everywhere on this site, not just here.";
		section.appendChild(lead);

		const warn = document.createElement("p");
		warn.className = "novamira-visual-abilities-warn";
		warn.textContent =
			"When enabled, AI agents can execute PHP code and perform filesystem operations on this site. For development and staging environments only. Always keep backups.";
		section.appendChild(warn);

		if (workspaceData.looksProduction) {
			const production = document.createElement("p");
			production.className = "novamira-visual-abilities-production";
			production.textContent =
				"This looks like a production site. AI Abilities are not meant for live sites: turn them on only on a development or staging copy.";
			section.appendChild(production);
		}

		const sitewide = document.createElement("p");
		sitewide.className = "novamira-visual-abilities-sitewide";
		sitewide.textContent = "You can turn them off again anytime from Novamira's Configuration page.";
		section.appendChild(sitewide);

		const proceed = document.createElement("button");
		proceed.type = "button";
		proceed.className = "button button-primary novamira-visual-welcome-start";
		proceed.textContent = "Enable & continue";
		proceed.disabled = true;

		const confirmRow = document.createElement("label");
		confirmRow.className = "novamira-visual-abilities-confirm";
		const checkbox = document.createElement("input");
		checkbox.type = "checkbox";
		const confirmText = document.createElement("span");
		confirmText.textContent = workspaceData.looksProduction
			? "I understand this may be a live site, and that AI agents will be able to execute PHP code and access the filesystem on it."
			: "I understand AI agents will be able to execute PHP code and access the filesystem on this site.";
		confirmRow.append(checkbox, confirmText);
		checkbox.addEventListener("change", () => {
			proceed.disabled = !checkbox.checked;
		});
		section.appendChild(confirmRow);

		const enableError = document.createElement("p");
		enableError.className = "novamira-visual-abilities-production";
		enableError.hidden = true;
		section.appendChild(enableError);

		const actions = document.createElement("div");
		actions.className = "novamira-visual-welcome-actions";

		proceed.addEventListener("click", () => {
			if (!checkbox.checked) return;
			proceed.disabled = true;
			enableError.hidden = true;
			this.setAbilities(true)
				.then((nowEnabled) => {
					if (nowEnabled) {
						this.abilitiesJustEnabled = true;
						this.updateConnectionPanel();
						return;
					}
					// The endpoint ran but abilities did not turn on. In a live Visual
					// session this is rare (it means the bundled MCP adapter failed to
					// load); just let the user retry instead of pretending.
					proceed.disabled = false;
					enableError.textContent = "AI Abilities could not be turned on. Please try again.";
					enableError.hidden = false;
				})
				.catch((err) => {
					proceed.disabled = false;
					enableError.textContent = "Could not reach the server to turn on AI Abilities. Try again.";
					enableError.hidden = false;
					this.logError(err);
				});
		});
		actions.appendChild(proceed);

		section.appendChild(actions);
		return section;
	}

	private async setAbilities(enabled: boolean): Promise<boolean> {
		const url = new URL("admin-ajax.php", workspaceData.adminUrl);
		url.searchParams.set("action", "novamira_visual_set_abilities");
		url.searchParams.set("nonce", workspaceData.nonce);
		const response = await window.fetch(url, {
			method: "POST",
			credentials: "same-origin",
			headers: { "Content-Type": "application/json", "X-WP-Nonce": workspaceData.nonce },
			body: JSON.stringify({ enabled }),
		});
		const data = (await response.json().catch(() => null)) as { success?: boolean; data?: { enabled?: boolean } } | null;
		if (!response.ok || data?.success === false) {
			throw new Error("Failed to update AI Abilities.");
		}

		// The server reports the actual persisted state, which can differ from the
		// request (abilities are locked to the site's domain and refuse to enable
		// when the MCP dependency is missing).
		const nowEnabled = data?.data?.enabled === true;
		this.log(nowEnabled ? "AI Abilities enabled for the site." : "AI Abilities are off for the site.");
		return nowEnabled;
	}

	private connectFromInput(): void {
		let port: number;
		try {
			port = this.transport.parseConnectionCode(this.connectionInput.value);
		} catch (err) {
			this.showConnectionDetail(err instanceof Error ? err.message : String(err));
			return;
		}

		this.transport.stopAutoReconnect(port);
		this.reconnectFormVisible = false;
		this.connectionInput.value = `visual:${port}`;
		this.transport.connectFromMcpPort(port).catch((err) => {
			this.logError(err);
			this.transport.startAutoReconnect(port);
		});
	}

	private showConnectionDetail(message: string): void {
		if (!this.connectionDetail) return;
		this.connectionDetail.textContent = message;
	}

	private setBridgeStatus(text: string, connected: boolean): void {
		this.statusText.textContent = text;
		this.root.classList.toggle("novamira-visual-workspace--connected", connected);
		this.updateConnectionPanel();
	}

	private setAllToolsAllowed(allowed: boolean): void {
		this.allToolsStatusEl.hidden = !allowed;
		this.root.classList.toggle("novamira-visual-workspace--all-tools-allowed", allowed);
	}

	private async ensureWorkspaceAuth(): Promise<void> {
		if (this.authRecoveryInProgress) {
			throw new Error("WordPress session expired. Sign in again to continue using Novamira Visual.");
		}

		const auth = await this.checkWorkspaceAuth();
		if (auth?.authenticated === true) return;

		this.recoverExpiredWorkspaceAuth(auth?.recoverUrl);
		throw new Error("WordPress session expired. Sign in again to continue using Novamira Visual.");
	}

	private async checkWorkspaceAuth(): Promise<WorkspaceAuthCheck | null> {
		try {
			const response = await window.fetch(workspaceData.authCheckUrl, {
				method: "GET",
				credentials: "same-origin",
				cache: "no-store",
				headers: { Accept: "application/json" },
			});
			const data = await response.json().catch(() => null) as unknown;
			const payload = data && typeof data === "object" ? data as { success?: unknown; data?: unknown } : null;
			const detail = payload?.data && typeof payload.data === "object" ? payload.data as Record<string, unknown> : null;
			if (!response.ok || payload?.success !== true || !detail) {
				return null;
			}

			return {
				authenticated: detail.authenticated === true,
				recoverUrl: typeof detail.recoverUrl === "string" ? detail.recoverUrl : workspaceData.loginUrl,
			};
		} catch (err) {
			this.log(`Could not verify WordPress session: ${err instanceof Error ? err.message : String(err)}`);
			return null;
		}
	}

	private recoverExpiredWorkspaceAuth(recoverUrl = workspaceData.loginUrl): void {
		if (this.authRecoveryInProgress) return;
		this.authRecoveryInProgress = true;
		this.confirmations.failPendingConfirmations("WordPress session expired. Sign in again to continue using Novamira Visual.");
		this.log("WordPress session expired. Opening sign-in to restore Novamira Visual.");
		window.setTimeout(() => {
			window.location.assign(recoverUrl || workspaceData.loginUrl);
		}, 50);
	}

	private isWordPressLoginUrl(rawUrl: string): boolean {
		try {
			return new URL(rawUrl, workspaceData.siteUrl).pathname.endsWith("/wp-login.php");
		} catch {
			return false;
		}
	}

	private flashConnectedConfirm(): void {
		this.showConnectedConfirm = true;
		if (this.connectedConfirmTimer !== undefined) window.clearTimeout(this.connectedConfirmTimer);
		this.updateConnectionPanel();
		this.connectedConfirmTimer = window.setTimeout(() => {
			this.connectedConfirmTimer = undefined;
			this.showConnectedConfirm = false;
			this.connectionOptionsVisible = false;
			this.updateConnectionPanel();
		}, 1100);
	}

	private updateConnectionPanel(): void {
		this.updateChromeVisibility();
		if (
			!this.connectionPanel ||
			!this.connectionTitle ||
			!this.connectionDescription ||
			!this.connectionDetail
		) return;

		const connectedPorts = this.transport.connectedBridgePorts();
		const connected = connectedPorts.length > 0;
		const retryingPorts = this.transport.retryingBridgePorts();
		const savedPorts = this.transport.readSavedBridgePorts();
		const knownPorts = retryingPorts.length > 0 ? retryingPorts : connectedPorts.length > 0 ? connectedPorts : savedPorts;
		const hasPages = this.pages.size > 0;

		// On a fresh connection, hold a big green confirmation over everything for a
		// moment, then collapse to the workspace. flashConnectedConfirm drives this.
		if (this.showConnectedConfirm && connected) {
			this.connectionPanel.hidden = false;
			this.connectionPanel.classList.add("novamira-visual-connection-panel--connected");
			this.connectionPanel.classList.toggle("novamira-visual-connection-panel--overlay", hasPages);
			this.connectionCloseBtn.hidden = true;
			if (this.welcomeSection) this.welcomeSection.hidden = true;
			if (this.abilitiesConsentSection) this.abilitiesConsentSection.hidden = true;
			if (this.welcomeMain) this.welcomeMain.hidden = true;
			this.connectedConfirmEl.hidden = false;
			return;
		}
		this.connectedConfirmEl.hidden = true;

		this.connectionPanel.hidden = hasPages && !this.connectionOptionsVisible;
		this.connectionPanel.classList.toggle("novamira-visual-connection-panel--connected", connected);
		this.connectionPanel.classList.toggle("novamira-visual-connection-panel--overlay", hasPages);
		this.connectionCloseBtn.hidden = !hasPages;
		const setActiveControl = (active: "connection" | "configuration" | null): void => {
			this.connectionOptionsBtn.classList.toggle("button-primary", active === "connection");
			this.connectionOptionsBtn.setAttribute("aria-pressed", active === "connection" ? "true" : "false");
			this.configurationBtn.classList.toggle("button-primary", active === "configuration");
			this.configurationBtn.setAttribute("aria-pressed", active === "configuration" ? "true" : "false");
		};
		setActiveControl(null);

		const firstTime = knownPorts.length === 0;
		const abilitiesOff = !workspaceData.abilitiesEnabled && !this.abilitiesJustEnabled;
		const showWelcome = !connected && firstTime && !this.welcomeDismissed;
		const showAbilities = !connected && !showWelcome && abilitiesOff;
		const showMain = !showWelcome && !showAbilities;
		if (this.welcomeSection) this.welcomeSection.hidden = !showWelcome;
		if (this.abilitiesConsentSection) this.abilitiesConsentSection.hidden = !showAbilities;
		if (this.welcomeMain) this.welcomeMain.hidden = !showMain;
		if (!showMain) return;

		if (this.showConnectView === null) this.showConnectView = connected || !firstTime;
		const onConnect = this.showConnectView;
		if (!this.connectionPanel.hidden) setActiveControl(onConnect ? "connection" : "configuration");
		if (this.configureViewEl) this.configureViewEl.hidden = onConnect;
		if (this.connectViewEl) this.connectViewEl.hidden = !onConnect;
		if (!onConnect) return;

		if (connected) {
			this.connectionTitle.textContent = connectedPorts.length === 1 ? "Connected to your AI client" : "Connected to your AI clients";
			this.connectionDescription.textContent =
				"Ask any connected AI client to open or edit a WordPress page, or open a page from the tab bar.";
			this.connectionDetail.textContent =
				`Connected on ${connectedPorts.map((port) => `visual:${port}`).join(", ")}. ` +
				"Paste another code to add another.";
			this.connectionForm.hidden = !this.reconnectFormVisible;
			this.reconnectLink.hidden = this.reconnectFormVisible;
			this.connectionBtn.textContent = "Connect Another";
			return;
		}

		this.connectionForm.hidden = false;
		this.reconnectLink.hidden = true;

		this.connectionTitle.textContent = knownPorts.length > 1 ? "Connect your AI clients" : "Connect your AI client";
		this.connectionDescription.textContent =
			`Tell your AI to connect to ${this.mcpServerName()}. It will give you a visual: code to paste below.`;
		this.connectionDetail.textContent = knownPorts.length > 0
			? `Last connection${knownPorts.length === 1 ? "" : "s"}: ${knownPorts
					.map((port) => `visual:${port}`)
					.join(", ")}. Start your agent and this page will reconnect automatically.`
			: "";
		this.connectionBtn.textContent = "Connect";
	}

	private sendEvent(name: string, payload: unknown): void {
		this.transport.sendEvent(name, payload);
	}

	private async openPage(params: Record<string, unknown>, agentAction = false): Promise<unknown> {
		const url = this.normalizeUrl(String(params.url ?? ""));
		const id = String(params.pageId ?? `page-${Date.now().toString(36)}-${this.nextPageNumber++}`);
		const title = String(params.title ?? this.shortTitle(url));
		const openedAt = typeof params.openedAt === "number" && Number.isFinite(params.openedAt) ? params.openedAt : Date.now();
		await this.ensureWorkspaceAuth();

		if (this.pages.has(id)) {
			throw new Error(`Page already exists: ${id}`);
		}

		const frameWrap = document.createElement("section");
		frameWrap.className = "novamira-visual-frame";
		frameWrap.dataset.pageId = id;

		const header = document.createElement("div");
		header.className = "novamira-visual-frame-header";

		const titleEl = document.createElement("button");
		titleEl.type = "button";
		titleEl.className = "novamira-visual-frame-title";
		titleEl.textContent = title;
		titleEl.addEventListener("click", () => this.focusPage(id));
		header.appendChild(titleEl);

		const urlEl = document.createElement("span");
		urlEl.className = "novamira-visual-frame-url";
		urlEl.textContent = url;
		header.appendChild(urlEl);

		const reloadBtn = document.createElement("button");
		reloadBtn.type = "button";
		reloadBtn.className = "button button-small novamira-visual-frame-action";
		reloadBtn.title = "Reload frame";
		reloadBtn.setAttribute("aria-label", "Reload frame");
		reloadBtn.innerHTML =
			'<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M13.6 6.4A5.6 5.6 0 1 0 14 9"/><path d="M13.8 2.6V6.4H10"/></svg>';
		reloadBtn.addEventListener("click", () => {
			void this.reloadPage(id).catch((err) => this.logError(err));
		});
		header.appendChild(reloadBtn);

		const closeBtn = document.createElement("button");
		closeBtn.type = "button";
		closeBtn.className = "button button-small novamira-visual-frame-action novamira-visual-frame-close";
		closeBtn.title = "Close page";
		closeBtn.setAttribute("aria-label", "Close page");
		closeBtn.textContent = "×";
		closeBtn.addEventListener("click", () => this.closePage(id));
		header.appendChild(closeBtn);

		const iframe = document.createElement("iframe");
		this.applyWorkspaceFrameSandbox(iframe);
		iframe.src = url;
		iframe.title = title;
		iframe.addEventListener("load", () => {
			const page = this.pages.get(id);
			if (!page) return;
			page.status = "ready";
			this.pageElementRefs.delete(id);
			try {
				const loadedUrl = iframe.contentWindow?.location.href ?? page.url;
				if (this.isWordPressLoginUrl(loadedUrl)) {
					page.status = "error";
					this.recoverExpiredWorkspaceAuth();
					return;
				}

				page.url = loadedUrl;
				page.title = cleanPageTitle(iframe.contentDocument?.title, page.title || this.shortTitle(page.url));
			} catch {
				page.url = url;
			}
			urlEl.textContent = page.url;
			titleEl.textContent = page.title;
			this.renderTabs();
			this.persistWorkspaceTabs();
			this.sendEvent("workspace_state", this.getState());
		});

		const selectOverlay = document.createElement("button");
		selectOverlay.type = "button";
		selectOverlay.className = "novamira-visual-frame-select-overlay";
		selectOverlay.title = "Select frame";
		selectOverlay.addEventListener("click", () => this.selectPage(id));

		frameWrap.appendChild(header);
		frameWrap.appendChild(iframe);
		frameWrap.appendChild(selectOverlay);
		this.framesEl.appendChild(frameWrap);

		const page: WorkspacePage = {
			id,
			title,
			url,
			status: "loading",
			openedAt,
			iframe,
		};
		this.pages.set(id, page);
		if (agentAction) this.markAgentAction(id);
		this.focusPage(id, agentAction);
		this.renderTabs();
		this.log(`Opened ${id}: ${url}`);
		this.sendEvent("workspace_state", this.getState());
		return this.serializePage(page);
	}

	private persistWorkspaceTabs(): void {
		const state: StoredWorkspaceTabs = {
			activePageId: this.activePageId,
			pages: Array.from(this.pages.values(), (page) => ({
				id: page.id,
				title: page.title,
				url: page.url,
				openedAt: page.openedAt,
			})),
		};

		try {
			window.localStorage.setItem(WORKSPACE_TABS_KEY, JSON.stringify(state));
		} catch (err) {
			this.logError(err);
		}
	}

	private restoreWorkspaceTabs(): void {
		let state: StoredWorkspaceTabs | null;
		try {
			const raw = window.localStorage.getItem(WORKSPACE_TABS_KEY);
			state = raw ? (JSON.parse(raw) as StoredWorkspaceTabs) : null;
		} catch (err) {
			this.logError(err);
			return;
		}

		if (!state || !Array.isArray(state.pages)) return;

		for (const page of state.pages) {
			if (!this.isStoredWorkspacePage(page)) continue;
			this.openPage({ pageId: page.id, title: page.title, url: page.url, openedAt: page.openedAt }).catch((err) => this.logError(err));
		}

		if (state.activePageId && this.pages.has(state.activePageId)) {
			this.focusPage(state.activePageId);
		}
	}

	private isStoredWorkspacePage(value: unknown): value is StoredWorkspaceTabs["pages"][number] {
		if (!value || typeof value !== "object") return false;
		const page = value as Record<string, unknown>;
		return (
			typeof page.id === "string" &&
			typeof page.title === "string" &&
			typeof page.url === "string" &&
			typeof page.openedAt === "number" &&
			Number.isFinite(page.openedAt)
		);
	}

	private normalizeUrl(raw: string): string {
		const trimmed = raw.trim();
		if (!trimmed) {
			throw new Error("URL is required.");
		}
		const url = new URL(trimmed, workspaceData.adminUrl);
		const allowedOrigins = new Set([
			new URL(workspaceData.adminUrl).origin,
			new URL(workspaceData.siteUrl).origin,
		]);
		if (!allowedOrigins.has(url.origin)) {
			throw new Error("Novamira Visual can only open pages on this WordPress site.");
		}
		return url.toString();
	}

	private shortTitle(url: string): string {
		const parsed = new URL(url);
		const path = `${parsed.pathname}${parsed.search}`;
		return path === "/" ? parsed.host : path;
	}

	private closePage(pageId: string, agentAction = false): unknown {
		const page = this.getPage(pageId);
		if (agentAction) this.markAgentAction(pageId);
		page.iframe.closest(".novamira-visual-frame")?.remove();
		this.pages.delete(pageId);
		this.toolInputsEmittedForPage.delete(pageId);
		this.pageElementRefs.delete(pageId);
		if (this.activePageId === pageId) {
			this.activePageId = this.pages.keys().next().value ?? null;
		}
		if (this.lastAgentActionPageId === pageId) {
			this.clearLastAgentActionIndicator();
		}
		this.renderTabs();
		this.updateFrameVisibility();
		this.persistWorkspaceTabs();
		this.log(`Closed ${pageId}.`);
		this.sendEvent("workspace_state", this.getState());
		return { closed: pageId, activePageId: this.activePageId };
	}

	private focusPage(pageId: string, agentAction = false): unknown {
		const page = this.getPage(pageId);
		if (agentAction) this.markAgentAction(pageId);
		this.activePageId = page.id;
		this.updateFrameVisibility();
		this.renderTabs();
		this.persistWorkspaceTabs();
		this.sendEvent("workspace_state", this.getState());
		return this.serializePage(page);
	}

	private selectPage(pageId: string): unknown {
		const page = this.focusPage(pageId);
		this.setOverviewMode(false);
		return page;
	}

	private updateFrameVisibility(): void {
		for (const frame of Array.from(this.framesEl.querySelectorAll<HTMLElement>(".novamira-visual-frame"))) {
			frame.classList.toggle("novamira-visual-frame--active", frame.dataset.pageId === this.activePageId);
		}
		this.updateChromeVisibility();
	}

	private updateChromeVisibility(): void {
		const working = this.transport.connectedBridgePorts().length > 0 || this.pages.size > 0;
		if (this.tabbarEl) this.tabbarEl.hidden = !working;
	}

	private setOverviewMode(enabled: boolean): void {
		this.overviewMode = enabled;
		this.root.classList.toggle("novamira-visual-workspace--overview", enabled);
		this.overviewBtn.setAttribute("aria-pressed", enabled ? "true" : "false");
		this.overviewBtn.title = enabled ? "Exit overview" : "Overview";
		this.updateFrameVisibility();
	}

	private async reloadPage(pageId: string, agentAction = false): Promise<unknown> {
		const page = this.getPage(pageId);
		if (agentAction) this.markAgentAction(pageId);
		await this.ensureWorkspaceAuth();
		page.status = "loading";
		this.toolInputsEmittedForPage.delete(pageId);
		this.pageElementRefs.delete(pageId);
		this.applyWorkspaceFrameSandbox(page.iframe);
		page.iframe.contentWindow?.location.reload();
		this.renderTabs();
		return this.serializePage(page);
	}

	private applyWorkspaceFrameSandbox(iframe: HTMLIFrameElement): void {
		iframe.setAttribute("sandbox", WORKSPACE_FRAME_SANDBOX_TOKENS);
	}

	private inspectPage(
		pageId: string | null,
		agentAction = false,
		includeText = false,
		textLimit = 2000,
		includeToolInputs: boolean | null = null,
	): unknown {
		const page = this.getPage(pageId ?? "");
		if (agentAction) this.markAgentAction(page.id);
		const win = this.getPageWindow(page);
		const doc = win.document;
		const shouldIncludeToolInputs = includeToolInputs ?? !this.toolInputsEmittedForPage.has(page.id);
		const result: Record<string, unknown> = {
			...this.serializePage(page),
			documentTitle: doc.title,
			location: win.location.href,
			readyState: doc.readyState,
			tools: this.getPageTools(page),
			toolCallGuide: this.guidance.getPageToolCallGuide(page),
		};
		if (shouldIncludeToolInputs) {
			result.toolInputs = this.guidance.getPageToolInputHints(page);
			this.toolInputsEmittedForPage.add(page.id);
		} else {
			result.toolInputsHint = "Omitted after first inspect; pass includeToolInputs true to include tool schemas again.";
		}
		if (includeText) result.text = doc.body?.innerText?.slice(0, textLimit) ?? "";
		return result;
	}

	private confirmPageInteraction(
		name: PageInteractionName,
		params: Record<string, unknown>,
		agentAction: boolean,
		run: (pageId: string, params: Record<string, unknown>) => unknown | Promise<unknown>,
	): unknown {
		const pageId = this.pageIdOrActive(params);
		const page = this.getPage(pageId);
		if (agentAction) this.markAgentAction(pageId);
		const action = `page_interaction:${name}` as ConfirmableModelAction;
		const execute = async () => run(pageId, params);
		if (this.confirmations.isToolAllowed(action)) return execute();

		return this.confirmations.enqueueToolConfirmation(action, {
			title: `Review page action: ${name}`,
			description: "The agent wants to interact with a page in the browser workspace. This can change the visible page, submit forms, or navigate.",
			reason: optionalReason(params),
			details: [
				{ label: "Page", value: `${page.title} (${page.id})` },
				{ label: "URL", value: page.url },
				{ label: "Action", value: name },
				{ label: "Arguments", value: truncateText(JSON.stringify(params, null, 2), 3000), format: "code" },
			],
		}, execute);
	}

	private async evalInPage(pageId: string, code: string, agentAction = false): Promise<unknown> {
		if (!WORKSPACE_EVAL_ENABLED) {
			throw new Error("workspace_eval is disabled.");
		}

		const page = this.getPage(pageId);
		if (agentAction) this.markAgentAction(pageId);
		const request = {
			title: "Run JavaScript in frame",
			description:
				"The agent wants to use the JavaScript escape hatch. Review the target frame and code before allowing it.",
			details: [
				{ label: "Page", value: `${page.title} (${page.id})` },
				{ label: "URL", value: page.url },
				{ label: "Code", value: code, format: "code" },
			],
		} satisfies Pick<PendingConfirmation, "title" | "description" | "details">;

		if (!this.confirmations.isActionAllowed("workspace_eval")) {
			return this.confirmations.enqueueConfirmableAction("workspace_eval", request, () => this.runEvalInPage(pageId, code));
		}

		return this.runEvalInPage(pageId, code);
	}

	private async runEvalInPage(pageId: string, code: string): Promise<unknown> {
		const page = this.getPage(pageId);
		const win = this.getPageWindow(page);
		const fn = (win as unknown as { Function: FunctionConstructor }).Function;
		let runner: (this: Window) => Promise<unknown>;
		try {
			runner = fn(`"use strict"; return (async () => (${code}))();`) as (this: Window) => Promise<unknown>;
		} catch {
			runner = fn(`"use strict"; return (async () => { ${code} })();`) as (this: Window) => Promise<unknown>;
		}
		const result = runner.call(win);
		return cloneSerializable(await result);
	}

	private async callPageTool(
		pageId: string,
		toolName: string,
		args: Record<string, unknown>,
		reason: string,
		agentAction = false
	): Promise<unknown> {
		const page = this.getPage(pageId);
		if (agentAction) this.markAgentAction(pageId);
		const win = this.getPageWindow(page) as Window & {
			__novamiraVisualTools?: Record<string, (args?: unknown) => Promise<unknown>>;
		};
		const tools = win.__novamiraVisualTools;
		if (!tools || typeof tools !== "object") {
			throw new Error(`Frame ${pageId} does not expose Novamira Visual tools yet.`);
		}
		const tool = tools[toolName];
		if (typeof tool !== "function") {
			throw new Error(`Tool "${toolName}" is not available in frame ${pageId}.`);
		}

		this.log(`Calling page tool ${toolName} in ${pageId}. Reason: ${reason}`);
		return cloneSerializable(await tool(args));
	}

	private markAgentAction(pageId: string): void {
		this.lastAgentActionPageId = pageId;
		this.agentActionIndicatorSpinning = true;
		if (this.agentActionIndicatorTimer !== undefined) window.clearTimeout(this.agentActionIndicatorTimer);
		this.agentActionIndicatorTimer = window.setTimeout(() => {
			this.agentActionIndicatorTimer = undefined;
			this.stopAgentActionSpinner(pageId);
		}, AGENT_ACTION_INDICATOR_MS);
		this.renderTabs();
	}

	private stopAgentActionSpinner(pageId?: string): void {
		if (pageId !== undefined && this.lastAgentActionPageId !== pageId) return;
		if (this.agentActionIndicatorTimer !== undefined) {
			window.clearTimeout(this.agentActionIndicatorTimer);
			this.agentActionIndicatorTimer = undefined;
		}
		if (!this.agentActionIndicatorSpinning) return;
		this.agentActionIndicatorSpinning = false;
		this.renderTabs();
	}

	private clearLastAgentActionIndicator(): void {
		if (this.agentActionIndicatorTimer !== undefined) {
			window.clearTimeout(this.agentActionIndicatorTimer);
			this.agentActionIndicatorTimer = undefined;
		}
		if (this.lastAgentActionPageId === null && !this.agentActionIndicatorSpinning) return;
		this.lastAgentActionPageId = null;
		this.agentActionIndicatorSpinning = false;
		this.renderTabs();
	}

	private getActivePageId(): string | null {
		return this.activePageId;
	}

	private pageIdOrActive(params: Record<string, unknown>): string {
		const pageId = typeof params.pageId === "string" && params.pageId.trim()
			? params.pageId.trim()
			: typeof params.page_id === "string" && params.page_id.trim()
				? params.page_id.trim()
				: this.activePageId;
		if (!pageId) {
			throw new Error("Missing required string param: pageId and no active page is selected.");
		}
		return pageId;
	}

	private getActionStatus(actionId: string): unknown {
		return this.confirmations.getActionStatus(actionId);
	}

	private waitForAction(actionId: string, timeoutMs: number): Promise<unknown> {
		return this.confirmations.waitForAction(actionId, timeoutMs);
	}

	private discoverBackendTools(params: Record<string, unknown>): Promise<unknown> {
		return this.backendTools.discoverBackendTools(params);
	}

	private callBackendTool(
		name: string,
		args: Record<string, unknown>,
		reason: string,
		agentAction = false,
	): Promise<unknown> {
		return this.backendTools.callBackendTool(name, args, reason, agentAction);
	}

	private getPage(pageId: string): WorkspacePage {
		const page = this.pages.get(pageId);
		if (!page) {
			throw new Error(`Unknown page: ${pageId || "(none)"}`);
		}
		return page;
	}

	private getPageWindow(page: WorkspacePage): Window {
		const win = page.iframe.contentWindow;
		if (!win) {
			throw new Error(`Frame ${page.id} is not available.`);
		}
		try {
			void win.location.href;
		} catch {
			throw new Error(`Frame ${page.id} is cross-origin and cannot be inspected.`);
		}
		return win;
	}

	private getPageTools(page: WorkspacePage): string[] {
		try {
			const win = this.getPageWindow(page) as Window & { __novamiraVisualTools?: Record<string, unknown> };
			return win.__novamiraVisualTools ? Object.keys(win.__novamiraVisualTools).sort() : [];
		} catch {
			return [];
		}
	}

	private serializePage(page: WorkspacePage): WorkspaceState["pages"][number] {
		return {
			id: page.id,
			title: page.title,
			url: page.url,
			status: page.status,
			openedAt: page.openedAt,
			tools: this.getPageTools(page),
		};
	}

	private getState(): WorkspaceState {
		const activePage = this.activePageId ? this.pages.get(this.activePageId) ?? null : null;
		return {
			connected: this.transport.getConnectedBridgeCount() > 0,
			protocol: {
				name: WORKSPACE_PROTOCOL_NAME,
				version: WORKSPACE_PROTOCOL_VERSION,
				capabilities: Array.from(WORKSPACE_PROTOCOL_CAPABILITIES),
				compatibleMcpPackage: WORKSPACE_MCP_NPM_PACKAGE,
				visualVersion: workspaceData.visualVersion,
			},
			activePageId: this.activePageId,
			requestGuide:
				"Prefer backend tools when available for the job; they are more efficient than driving pages for WordPress data, settings, and files. There is no backend tool for page content: to build or edit a page (Gutenberg blocks or builder content), open it in its editor first (open a new page in the editor when creating one), then use the editor page tools, which only appear once the editor is open. Never write post_content or block markup through execute-php or another backend tool. Use activePageSuggestedNextCall first; it batches initial editor discovery when possible. For builder setup errors before editor tools load, use workspace_discover_backend_tools with search:'check-setup' or the builder name and call a narrow setup tool when available instead of clicking through admin settings. For generic page-only work, call workspace_page_snapshot to get compact @e refs, then read with workspace_page_get_text/get_html/get_attr/get_value or wait with workspace_page_wait. Generic interaction methods workspace_page_click/fill/type/press/select/check/uncheck/scroll require approval in Novamira Visual before running. For builder editors, call workspace_call_page_tool directly with pageId/toolName/args/reason and use page tools, not backend builder tools, for live builder content; editor page tools are curated builder APIs and run without confirmation. Use workspace_discover_backend_tools for backend MCP tools and workspace_call_backend_tool with name/args/reason to run them via WordPress AJAX. For reason, write one short line for the activity log, for example: \"Inspect the active editor structure.\" Avoid custom HTML/CSS/code unless explicitly requested.",
			activePageToolCallGuide: activePage ? this.guidance.getPageToolCallGuide(activePage) : null,
			activePageSuggestedNextCall: activePage ? this.guidance.getPageSuggestedNextCall(activePage) : null,
			methods: [
				"workspace_status",
				"workspace_list_pages",
				"workspace_open_page",
				"workspace_close_page",
				"workspace_focus_page",
				"workspace_reload_page",
				"workspace_inspect_page",
				"workspace_page_snapshot",
				"workspace_page_get_text",
				"workspace_page_get_html",
				"workspace_page_get_attr",
				"workspace_page_get_value",
				"workspace_page_wait",
				"workspace_page_click",
				"workspace_page_fill",
				"workspace_page_type",
				"workspace_page_press",
				"workspace_page_select",
				"workspace_page_check",
				"workspace_page_uncheck",
				"workspace_page_scroll",
				"workspace_eval",
				"workspace_call_page_tool",
				"workspace_get_action_status",
				"workspace_wait_action",
				"workspace_discover_backend_tools",
				"workspace_call_backend_tool",
			],
			pages: Array.from(this.pages.values(), (page) => this.serializePage(page)),
		};
	}

	private renderTabs(): void {
		this.tabsEl.innerHTML = "";
		this.overviewBtn.disabled = this.pages.size === 0;
		for (const page of this.pages.values()) {
			const tab = document.createElement("div");
			tab.className = "novamira-visual-tab";
			const isLastAgentActionPage = page.id === this.lastAgentActionPageId;
			tab.classList.toggle("novamira-visual-tab--active", page.id === this.activePageId);
			tab.classList.toggle("novamira-visual-tab--working", isLastAgentActionPage);
			tab.classList.toggle("novamira-visual-tab--working-active", isLastAgentActionPage && this.agentActionIndicatorSpinning);
			tab.title = isLastAgentActionPage ? `${page.url}\nLast page the agent acted on` : page.url;
			tab.addEventListener("click", () => this.selectPage(page.id));

			const spin = document.createElement("span");
			spin.className = "novamira-visual-tab-spin";
			spin.setAttribute("aria-hidden", "true");
			tab.appendChild(spin);

			const main = document.createElement("span");
			main.className = "novamira-visual-tab-main";
			main.textContent = page.title;
			tab.appendChild(main);

			const close = document.createElement("button");
			close.type = "button";
			close.className = "novamira-visual-tab-close";
			close.title = "Close tab";
			close.textContent = "×";
			close.addEventListener("click", (event) => {
				event.stopPropagation();
				this.closePage(page.id);
			});
			tab.appendChild(close);

			this.tabsEl.appendChild(tab);
		}

		this.updateConnectionPanel();
	}

	private log(message: string): void {
		const line = document.createElement("div");
		line.textContent = `[${new Date().toLocaleTimeString()}] ${message}`;
		this.logEl.prepend(line);
	}

	private logError(err: unknown): void {
		const message = err instanceof Error ? err.message : String(err);
		this.log(`Error: ${message}`);
	}
}

const root = document.getElementById("novamira-visual-workspace-root");
if (root) {
	new AgentWorkspace(root);
}
