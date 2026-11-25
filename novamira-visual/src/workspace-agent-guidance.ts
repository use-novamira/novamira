import { summarizeToolInput } from "./workspace-tool-schema-summary";
import type { PageToolDefinition, WorkspacePage } from "./workspace-types";

interface WorkspaceAgentGuidanceHost {
	getPageWindow: (page: WorkspacePage) => Window;
	getPageTools: (page: WorkspacePage) => string[];
}

export class WorkspaceAgentGuidance {
	constructor(private host: WorkspaceAgentGuidanceHost) {}

	getPageToolCallGuide(page: WorkspacePage): string | null {
		const tools = this.host.getPageTools(page);
		const genericGuidance = "Prefer backend tools when they fit the job; they are more efficient for WordPress data, settings, and files. There is no backend tool for page content: to build or edit a page (Gutenberg blocks or builder content), open it in its editor first (open a new page in the editor when creating one), then use the editor page tools, which only appear once the editor is open. Never write post_content or block markup through execute-php or another backend tool. For builder setup errors before editor tools load, discover backend tools with search:'check-setup' or the builder name and use a narrow setup tool when available instead of clicking through admin settings. For generic page-only work, start with workspace_page_snapshot to get compact @e refs, then use read-only workspace_page_get_text/get_html/get_attr/get_value or workspace_page_wait. Page interaction methods workspace_page_click/fill/type/press/select/check/uncheck take params.attention, 0-10: with approvals on, the person in front of Novamira Visual is asked from the site's cutoff up, since they can change the visible page, submit forms, or navigate. Editor page tools exposed through workspace_call_page_tool are curated builder APIs and run without confirmation; include reason as one short line for the activity log.";
		if (tools.length === 0) return genericGuidance;

		const hasBatchCall = tools.includes("batch_call");
		const builderGuidance = this.getBuilderGuidance(tools);
		return [
			genericGuidance,
			"Call page tools with workspace_call_page_tool and pageId/toolName/args/reason; if args is unavailable, append JSON to toolName.",
			"Use page tools, not inspect, to read or verify builder content.",
			builderGuidance,
			hasBatchCall ? "Use batch_call for initial discovery and related edits. Alias create calls with id, then reference later args as '$id'. Use stop_on_error:false for independent discovery, compact schema formats before exact controls, and low max_text_chars unless auditing." : null,
		].filter((line): line is string => typeof line === "string").join(" ");
	}

	getPageSuggestedNextCall(page: WorkspacePage): unknown {
		const tools = this.host.getPageTools(page);
		if (!tools.includes("batch_call")) {
			return {
				method: "workspace_page_snapshot",
				params: {
					pageId: page.id,
					interactive_only: true,
				},
			};
		}

		const listCalls = tools.includes("list_widgets")
			? [
				{ tool: "list_widgets", args: { category: "layout" } },
				{ tool: "list_widgets", args: { category: "basic" } },
			]
			: tools.includes("list_block_types")
				? [{ tool: "list_block_types" }]
				: tools.includes("list_element_types")
					? [{ tool: "list_element_types" }]
					: null;
		if (!tools.includes("get_page_structure") || !listCalls) return null;

		return {
			method: "workspace_call_page_tool",
			pageId: page.id,
			toolName: "batch_call",
			reason: "May I inspect the active editor structure and available tools?",
			args: {
				stop_on_error: false,
				max_text_chars: 800,
				calls: [
					{ tool: "get_page_structure", args: { include_text: true } },
					...(tools.includes("list_design_assets") ? [{ tool: "list_design_assets", args: { type: "all" } }] : []),
					...listCalls,
				],
			},
		};
	}

	getPageToolInputHints(page: WorkspacePage): Record<string, unknown> {
		const definitions = this.getPageToolDefinitions(page);
		return Object.fromEntries(
			definitions.map((definition) => [definition.name, summarizeToolInput(definition.parameters)]),
		);
	}

	getPageToolDefinition(page: WorkspacePage, toolName: string): PageToolDefinition | null {
		return this.getPageToolDefinitions(page).find((definition) => definition.name === toolName) ?? null;
	}

	private getBuilderGuidance(tools: string[]): string {
		if (tools.includes("list_widgets")) {
			return "Elementor: do not attempt the html widget, custom_css, or inline HTML/CSS unless the user explicitly asks for custom code. First use list_widgets/get_widget_schema and build with native Elementor widgets/settings so users can edit the result in the Elementor UI.";
		}
		if (tools.includes("list_block_types")) {
			return "Gutenberg: do not attempt core/html or inline HTML/CSS unless the user explicitly asks for custom code. First use list_block_types/get_block_schema and build with native blocks, attributes, supports, and preset slugs.";
		}
		if (tools.includes("list_element_types")) {
			return "Bricks: do not attempt code/html elements or custom CSS unless the user explicitly asks for custom code. First use list_element_types/get_element_schema and build with native elements and style controls.";
		}
		return "Use native builder elements and style controls. Do not attempt custom CSS/code unless the user explicitly asks for custom code.";
	}

	private getPageToolDefinitions(page: WorkspacePage): PageToolDefinition[] {
		const win = this.host.getPageWindow(page) as Window & {
			__novamiraVisualToolDefinitions?: Array<{
				name?: unknown;
				label?: unknown;
				description?: unknown;
				parameters?: unknown;
			}>;
		};
		const definitions = Array.isArray(win.__novamiraVisualToolDefinitions) ? win.__novamiraVisualToolDefinitions : [];
		const offered = this.host.getPageTools(page);
		return definitions
			.filter((definition) => typeof definition.name === "string")
			.filter((definition) => offered.includes(definition.name as string))
			.map((definition) => ({
				name: definition.name as string,
				label: typeof definition.label === "string" ? definition.label : undefined,
				description: typeof definition.description === "string" ? definition.description : undefined,
				parameters: definition.parameters,
			}));
	}
}
