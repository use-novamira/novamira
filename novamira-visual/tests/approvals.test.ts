/**
 * Page actions in Novamira Visual follow the approvals as MCP and Novamira Chat do: the model's attention against the
 * cutoff, plus Novamira's check on the JavaScript it wants to run. With approvals skipped nothing asks, and Novamira's
 * own admin pages are never acted on.
 */
import { beforeEach, describe, expect, test } from "bun:test";

const page = globalThis as unknown as Record<string, unknown>;
// The test files share one global: the page data object is set once and filled by each file.
page.novamiraVisualWorkspaceData ??= {};
const data = page.novamiraVisualWorkspaceData as Record<string, unknown>;
Object.assign(data, { siteUrl: "https://example.test/", approvals: { mode: "novamira", cutoff: 8 } });

const approvals = await import("../src/workspace-approvals");

const PAGE = "https://example.test/wp-admin/edit.php";

describe("page actions with approvals on", () => {
	beforeEach(() => {
		data.approvals = { mode: "novamira", cutoff: 8 };
		approvals.resetReportedApprovals();
	});

	test("ask only from the cutoff up", () => {
		expect(approvals.pageActionDecision(PAGE, { attention: 1 }).ask).toBe(false);
		expect(approvals.pageActionDecision(PAGE, { attention: 7 }).ask).toBe(false);
		expect(approvals.pageActionDecision(PAGE, { attention: 8 }).ask).toBe(true);
		expect(approvals.pageActionDecision(PAGE, { attention: "9" }).ask).toBe(true);
	});

	test("refuse an action without attention", () => {
		expect(() => approvals.pageActionDecision(PAGE, {})).toThrow(approvals.ATTENTION_MISSING);
		expect(() => approvals.pageActionDecision(PAGE, { attention: 11 })).toThrow(approvals.ATTENTION_MISSING);
		expect(() => approvals.pageActionDecision(PAGE, { attention: 2.5 })).toThrow(approvals.ATTENTION_MISSING);
	});

	test("JavaScript that only reads the page goes by attention", () => {
		const read = "document.querySelectorAll('h2').length";
		expect(approvals.pageActionDecision(PAGE, { attention: 1 }, read)).toEqual({ ask: false, why: "" });
		const styles = "getComputedStyle(document.body).color + el.getBoundingClientRect().width + el.parentNode.tagName";
		expect(approvals.pageActionDecision(PAGE, { attention: 1 }, styles).ask).toBe(false);
		expect(approvals.pageActionDecision(PAGE, { attention: 1 }, "typeof x === 'function'").ask).toBe(false);
		expect(approvals.pageActionDecision(PAGE, { attention: 1 }, "location.href").ask).toBe(false);
	});

	test("JavaScript the check flags asks whatever the attention", () => {
		const cases: Record<string, string> = {
			"fetch('/wp-json/wp/v2/posts/1', { method: 'DELETE' })": "Sends requests.",
			"wp.apiFetch({ path: '/wp/v2/users' })": "Sends requests.",
			"jQuery.post(ajaxurl, {})": "Sends requests.",
			"$.post(ajaxurl, {})": "Sends requests.",
			"parent.document.querySelector('button').click()": "Reaches outside this page.",
			"window.top.location": "Reaches outside this page.",
			"document.cookie": "Reads cookies or stored data.",
			"wpApiSettings.nonce": "Reads cookies or stored data.",
			"document.forms[0].submit()": "Navigates or submits forms.",
			"location.href = '/wp-admin/'": "Navigates or submits forms.",
			"window['fe' + 'tch']('/x')": "Looks up names it builds.",
			"new Function('return 1')()": "Builds code while it runs.",
			"eval(atob('ZmV0Y2g='))": "Builds code while it runs.",
			"'\\x66etch'": "Builds code while it runs.",
			"document.querySelector('.delete').click()": "Clicks or fires events.",
			"el.innerHTML = '<img src=x>'": "Adds markup or scripts.",
			"document.createElement('script')": "Adds markup or scripts.",
		};
		for (const [code, words] of Object.entries(cases)) {
			const decision = approvals.pageActionDecision(PAGE, { attention: 0 }, code);
			expect({ code, ask: decision.ask }).toEqual({ code, ask: true });
			expect(decision.why).toContain(words);
		}
	});

	test("never act on Novamira's admin pages, whatever the attention", () => {
		for (const url of [
			"https://example.test/wp-admin/admin.php?page=novamira-ai-activity",
			"https://example.test/wp-admin/admin.php?page=novamira-connect&tab=x",
			"https://example.test/wp-admin/network/admin.php?page=novamira-abilities",
		]) {
			expect(() => approvals.pageActionDecision(url, { attention: 0 })).toThrow(approvals.NOVAMIRA_PAGE_REFUSAL);
			expect(() => approvals.pageActionDecision(url, { attention: 0 }, "1")).toThrow(approvals.NOVAMIRA_PAGE_REFUSAL);
		}
		expect(approvals.isNovamiraAdminPage("https://example.test/wp-admin/admin.php?page=woocommerce")).toBe(false);
		expect(approvals.isNovamiraAdminPage("https://example.test/novamira-page/?page=novamira")).toBe(false);
	});
});

describe("page actions with approvals skipped or the mode reported later", () => {
	beforeEach(() => approvals.resetReportedApprovals());

	test("skipped approvals ask nothing and need no attention", () => {
		data.approvals = { mode: "nobody", cutoff: 8 };
		expect(approvals.pageActionDecision(PAGE, {}, "fetch('/x')")).toEqual({ ask: false, why: "" });
	});

	test("skipped approvals still keep off Novamira's pages", () => {
		data.approvals = { mode: "nobody", cutoff: 8 };
		const url = "https://example.test/wp-admin/admin.php?page=novamira-ai-activity";
		expect(() => approvals.pageActionDecision(url, {})).toThrow(approvals.NOVAMIRA_PAGE_REFUSAL);
	});

	test("what the session check reports replaces the page data", () => {
		data.approvals = { mode: "nobody", cutoff: 8 };
		approvals.reportApprovals({ mode: "novamira", cutoff: 5 });
		expect(approvals.pageActionDecision(PAGE, { attention: 5 }).ask).toBe(true);
		approvals.reportApprovals({ mode: "unknown" });
		expect(approvals.approvalSettings()).toEqual({ mode: "novamira", cutoff: 5 });
	});

	test("the site's Also ask before reaches the AI while approvals are on", () => {
		data.approvals = { mode: "novamira", cutoff: 8, instructions: " Ask before cancelling an event. " };
		expect(approvals.approvalInstructions()).toBe("Ask before cancelling an event.");
		approvals.reportApprovals({ mode: "novamira", cutoff: 8, instructions: "Ask before editing prices." });
		expect(approvals.approvalInstructions()).toBe("Ask before editing prices.");
		approvals.reportApprovals({ mode: "nobody", cutoff: 8, instructions: "" });
		expect(approvals.approvalInstructions()).toBe("");
	});

	test("a cutoff sent as text still counts", () => {
		data.approvals = { mode: "novamira", cutoff: "5" };
		expect(approvals.pageActionDecision(PAGE, { attention: 5 }).ask).toBe(true);
	});
});
