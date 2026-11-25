import { workspaceData } from "./workspace-config";

/**
 * Page actions follow the same approvals as MCP and Novamira Chat. The model sends its attention, 0-10, and the
 * person in front of Visual is asked only from the cutoff up, or when Novamira's check finds something in the
 * JavaScript the model wants to run. With approvals skipped nothing asks. Backend tools are checked by the site.
 *
 * Novamira's own admin pages are never acted on: approvals and Novamira's settings are a person's decision there.
 */

export type ApprovalMode = "novamira" | "nobody" | "chat";

export interface ApprovalSettings {
	mode: ApprovalMode;
	cutoff: number;
}

export interface PageActionDecision {
	ask: boolean;
	why: string;
}

export const ATTENTION_MISSING =
	"This action needs attention: an integer from 0 to 10 in params.attention saying how much a person should look at it before it runs. Nothing has run.";

export const NOVAMIRA_PAGE_REFUSAL =
	"Novamira Visual does not act on Novamira's own admin pages: approvals and Novamira's settings are decided there by a person. Nothing ran.";

let reported: ApprovalSettings | null = null;
let reportedInstructions: string | null = null;

/** The approvals as the site reported them last, or as they were when the workspace loaded. */
export function approvalSettings(): ApprovalSettings {
	return reported ?? parseApprovals(workspaceData.approvals) ?? { mode: "novamira", cutoff: 8 };
}

/**
 * What the site tells the AI about its approvals with the scale: the actions its administrator wants to approve
 * (Also ask before) and the abilities that always ask. Empty when approvals are skipped or there is nothing to say.
 */
export function approvalInstructions(): string {
	if (approvalSettings().mode !== "novamira") return "";
	return reportedInstructions ?? parseInstructions(workspaceData.approvals) ?? "";
}

/** Keep what the site's session check reports. */
export function reportApprovals(value: unknown): void {
	const parsed = parseApprovals(value);
	if (parsed) reported = parsed;
	const instructions = parseInstructions(value);
	if (instructions !== null) reportedInstructions = instructions;
}

/** Forget what the session check reported. For tests. */
export function resetReportedApprovals(): void {
	reported = null;
	reportedInstructions = null;
}

function parseInstructions(value: unknown): string | null {
	if (!value || typeof value !== "object") return null;
	const { instructions } = value as { instructions?: unknown };
	return typeof instructions === "string" ? instructions.trim() : null;
}

function parseApprovals(value: unknown): ApprovalSettings | null {
	if (!value || typeof value !== "object") return null;
	const { mode, cutoff } = value as { mode?: unknown; cutoff?: unknown };
	if (mode !== "novamira" && mode !== "nobody" && mode !== "chat") return null;
	const number = typeof cutoff === "string" ? Number(cutoff) : cutoff;
	return { mode, cutoff: typeof number === "number" && Number.isFinite(number) ? number : 8 };
}

/** The model's attention, 0-10, or null when it sent none or an invalid one. */
export function attentionParam(params: Record<string, unknown>): number | null {
	const value = typeof params.attention === "string" && /^\d+$/.test(params.attention) ? Number(params.attention) : params.attention;
	return typeof value === "number" && Number.isInteger(value) && value >= 0 && value <= 10 ? value : null;
}

/** Whether a URL is one of Novamira's admin pages. */
export function isNovamiraAdminPage(rawUrl: string): boolean {
	try {
		const url = new URL(rawUrl, workspaceData.siteUrl);
		const page = url.searchParams.get("page") ?? "";
		return /\/wp-admin\/(network\/)?admin\.php$/.test(url.pathname) && page.startsWith("novamira");
	} catch {
		return false;
	}
}

const JS_RULES: Array<{ words: string; pattern: RegExp }> = [
	{
		words: "Sends requests",
		pattern: /\b(fetch|XMLHttpRequest|sendBeacon|WebSocket|EventSource|apiFetch|apiRequest|ajax|axios|importScripts|saveEntityRecord|saveEditedEntityRecord|deleteEntityRecord|savePost|trashPost)\b|(\$|\bjQuery)\s*\.\s*(post|get|getJSON|getScript|load)\b|\bimport\s*\(/,
	},
	{
		words: "Reaches outside this page",
		pattern: /(?<![.\w$])(parent|top|opener|frames|frameElement)\b|\.\s*(parent|top|opener|frames|frameElement)\s*\.|\bpostMessage\b|\bwindow\s*\.\s*open\b/,
	},
	{ words: "Reads cookies or stored data", pattern: /\b(cookie|localStorage|sessionStorage|indexedDB|caches)\b|nonce/i },
	{
		words: "Navigates or submits forms",
		pattern: /\blocation\s*(=[^=]|\.\s*(href\s*=[^=]|assign\b|replace\b|reload\b))|\b(submit|requestSubmit)\s*\(|\.\s*(action|href|src|formAction)\s*=[^=]/,
	},
	{
		words: "Builds code while it runs",
		pattern: /\b(eval|Function|constructor|atob|fromCharCode|unescape|decodeURIComponent|decodeURI|Reflect|Proxy)\b|\bwith\s*\(|\\x[0-9a-fA-F]{2}|\\u[0-9a-fA-F{]/,
	},
	{ words: "Looks up names it builds", pattern: /\b(window|self|globalThis|document|this)\s*\[/ },
	{ words: "Clicks or fires events", pattern: /\.\s*(click|dispatchEvent)\s*\(|\bnew\s+(Mouse|Keyboard|Pointer|Submit)?Event\b/ },
	{
		words: "Adds markup or scripts",
		pattern: /\b(innerHTML|outerHTML)\s*\+?=[^=]|\b(insertAdjacentHTML|createElement|createContextualFragment)\b|\bdocument\s*\.\s*write(ln)?\b/,
	},
];

/** What Novamira's check finds in JavaScript a model wants to run in a page, in plain words. */
export function javascriptFindings(code: string): string[] {
	return JS_RULES.filter((rule) => rule.pattern.test(code)).map((rule) => rule.words);
}

/**
 * Whether a page action waits for the person in front of Visual. Throws when it cannot run at all.
 */
export function pageActionDecision(pageUrl: string, params: Record<string, unknown>, code?: string): PageActionDecision {
	if (isNovamiraAdminPage(pageUrl)) {
		throw new Error(NOVAMIRA_PAGE_REFUSAL);
	}
	const settings = approvalSettings();
	if (settings.mode === "nobody") return { ask: false, why: "" };
	if (settings.mode === "chat") return { ask: true, why: "" };

	const attention = attentionParam(params);
	if (attention === null) {
		throw new Error(ATTENTION_MISSING);
	}
	const findings = code === undefined ? [] : javascriptFindings(code);
	return {
		ask: attention >= settings.cutoff || findings.length > 0,
		why: findings.map((words) => `${words}.`).join(" "),
	};
}
