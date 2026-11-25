import { WorkspacePageTools } from "./workspace-page-tools";
import { assertReadModeAllowsMethod } from "./workspace-read-mode";
import type { PageInteractionName } from "./workspace-types";
import {
	backendToolName,
	firstString,
	objectParams,
	optionalReason,
	optionalTextLimit,
	optionalTimeoutMs,
	requiredString,
} from "./workspace-utils";

interface WorkspaceDispatchHost {
	getActivePageId: () => string | null;
	getState: () => unknown;
	openPage: (params: Record<string, unknown>, agentAction?: boolean) => Promise<unknown>;
	closePage: (pageId: string, agentAction?: boolean) => unknown;
	focusPage: (pageId: string, agentAction?: boolean) => unknown;
	reloadPage: (pageId: string, agentAction?: boolean) => Promise<unknown>;
	inspectPage: (
		pageId: string | null,
		agentAction?: boolean,
		includeText?: boolean,
		textLimit?: number,
		includeToolInputs?: boolean | null,
	) => unknown;
	confirmPageInteraction: (
		name: PageInteractionName,
		params: Record<string, unknown>,
		agentAction: boolean,
		run: (pageId: string, params: Record<string, unknown>) => unknown | Promise<unknown>,
	) => unknown;
	evalInPage: (pageId: string, code: string, params: Record<string, unknown>, agentAction?: boolean) => Promise<unknown>;
	getActionStatus: (actionId: string) => unknown;
	waitForAction: (actionId: string, timeoutMs: number) => Promise<unknown>;
	discoverBackendTools: (params: Record<string, unknown>) => Promise<unknown>;
	callBackendTool: (
		name: string,
		args: Record<string, unknown>,
		reason: string,
		attention: unknown,
		agentAction?: boolean,
	) => Promise<unknown>;
	callPageTool: (
		pageId: string,
		toolName: string,
		args: Record<string, unknown>,
		reason: string,
		agentAction?: boolean,
	) => Promise<unknown>;
}

export class WorkspaceDispatcher {
	constructor(
		private host: WorkspaceDispatchHost,
		private pageTools: WorkspacePageTools,
	) {}

	async dispatch(method: string, params: unknown): Promise<unknown> {
		await assertReadModeAllowsMethod(method);

		switch (method) {
			case "workspace_status":
			case "workspace_list_pages":
				return this.host.getState();
			case "workspace_open_page":
				return this.host.openPage(objectParams(params), true);
			case "workspace_close_page":
				return this.host.closePage(requiredString(params, "pageId"), true);
			case "workspace_focus_page":
				return this.host.focusPage(requiredString(params, "pageId"), true);
			case "workspace_reload_page":
				return this.host.reloadPage(requiredString(params, "pageId"), true);
			case "workspace_inspect_page":
				{
					const inspectParams = objectParams(params);
					return this.host.inspectPage(
						this.optionalPageId(params),
						true,
						inspectParams.includeText === true,
						optionalTextLimit(inspectParams),
						typeof inspectParams.includeToolInputs === "boolean" ? inspectParams.includeToolInputs : null,
					);
				}
			case "workspace_page_snapshot":
				return this.pageTools.snapshotGenericPage(this.pageIdOrActive(params), objectParams(params), true);
			case "workspace_page_get_text":
				return this.pageTools.getGenericPageText(this.pageIdOrActive(params), objectParams(params), true);
			case "workspace_page_get_html":
				return this.pageTools.getGenericPageHtml(this.pageIdOrActive(params), objectParams(params), true);
			case "workspace_page_get_attr":
				return this.pageTools.getGenericPageAttr(this.pageIdOrActive(params), objectParams(params), true);
			case "workspace_page_get_value":
				return this.pageTools.getGenericPageValue(this.pageIdOrActive(params), objectParams(params), true);
			case "workspace_page_wait":
				return this.pageTools.waitForGenericPage(this.pageIdOrActive(params), objectParams(params), true);
			case "workspace_page_click":
				return this.host.confirmPageInteraction("click", objectParams(params), true, (pageId, callParams) => this.pageTools.clickGenericPageTarget(pageId, callParams));
			case "workspace_page_fill":
				return this.host.confirmPageInteraction("fill", objectParams(params), true, (pageId, callParams) => this.pageTools.fillGenericPageTarget(pageId, callParams));
			case "workspace_page_type":
				return this.host.confirmPageInteraction("type", objectParams(params), true, (pageId, callParams) => this.pageTools.typeGenericPageTarget(pageId, callParams));
			case "workspace_page_press":
				return this.host.confirmPageInteraction("press", objectParams(params), true, (pageId, callParams) => this.pageTools.pressGenericPageKey(pageId, callParams));
			case "workspace_page_select":
				return this.host.confirmPageInteraction("select", objectParams(params), true, (pageId, callParams) => this.pageTools.selectGenericPageOption(pageId, callParams));
			case "workspace_page_check":
				return this.host.confirmPageInteraction("check", objectParams(params), true, (pageId, callParams) => this.pageTools.setGenericPageChecked(pageId, callParams, true));
			case "workspace_page_uncheck":
				return this.host.confirmPageInteraction("uncheck", objectParams(params), true, (pageId, callParams) => this.pageTools.setGenericPageChecked(pageId, callParams, false));
			case "workspace_page_scroll":
				return this.host.confirmPageInteraction("scroll", objectParams(params), true, (pageId, callParams) => this.pageTools.scrollGenericPage(pageId, callParams));
			case "workspace_eval":
				return this.host.evalInPage(
					requiredString(params, "pageId"),
					requiredString(params, "code"),
					objectParams(params),
					true
				);
			case "workspace_get_action_status":
				return this.host.getActionStatus(requiredString(params, "actionId"));
			case "workspace_wait_action":
				return this.host.waitForAction(
					requiredString(params, "actionId"),
					optionalTimeoutMs(params)
				);
			case "workspace_discover_backend_tools":
				return this.host.discoverBackendTools(objectParams(params));
			case "workspace_call_backend_tool":
				{
					const callParams = objectParams(params);
					return this.host.callBackendTool(
						backendToolName(callParams),
						objectParams(callParams.args ?? {}),
						optionalReason(callParams),
						callParams.attention,
						true,
					);
				}
			case "workspace_call_page_tool":
				{
					const callParams = objectParams(params);
					const parsed = this.parseToolCallParams(
						requiredString(callParams, "toolName"),
						objectParams(callParams.args ?? {}),
					);

					return this.host.callPageTool(
						requiredString(callParams, "pageId"),
						parsed.toolName,
						parsed.args,
						optionalReason(callParams),
						true,
					);
				}
			default:
				throw new Error(`Unknown workspace method: ${method}`);
		}
	}

	private parseToolCallParams(
		toolName: string,
		args: Record<string, unknown>,
	): { toolName: string; args: Record<string, unknown> } {
		if (Object.keys(args).length > 0) return { toolName, args };

		const match = toolName.match(/^([A-Za-z0-9_-]+)\s*(?::|\s)\s*({[\s\S]*})\s*$/);
		if (!match) return { toolName, args };

		try {
			const parsed = JSON.parse(match[2]) as unknown;
			if (!parsed || typeof parsed !== "object" || Array.isArray(parsed)) {
				throw new Error("Tool arguments must be a JSON object.");
			}

			return { toolName: match[1], args: parsed as Record<string, unknown> };
		} catch (error) {
			const message = error instanceof Error ? error.message : String(error);
			throw Object.assign(new Error(`Invalid JSON arguments in toolName for ${match[1]}: ${message}`), { cause: error });
		}
	}

	private optionalPageId(params: unknown): string | null {
		const object = objectParams(params);
		const pageId = firstString(object.pageId, object.page_id);
		return pageId || this.host.getActivePageId();
	}

	private pageIdOrActive(params: unknown): string {
		const pageId = this.optionalPageId(params);
		if (!pageId) {
			throw new Error("Missing required string param: pageId and no active page is selected.");
		}
		return pageId;
	}
}
