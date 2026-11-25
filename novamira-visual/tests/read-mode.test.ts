/**
 * Novamira Visual does not work while Novamira read mode is on: the workspace refuses every method, whether
 * the page was loaded in read mode or read mode was turned on after, and the Gutenberg bundle exposes no tool.
 * Outside read mode both behave as before. Run with `bun test` after `bun run build` (the bundle test loads
 * dist/gutenberg.js).
 */
import { beforeEach, describe, expect, test } from "bun:test";

const page = globalThis as unknown as Record<string, unknown>;
// The test files share one global: the page data object is set once and filled by each file.
page.novamiraVisualWorkspaceData ??= {};
const data = page.novamiraVisualWorkspaceData as Record<string, unknown>;
Object.assign(data, {
	readMode: "1",
	readModeMessage: "Read mode is active on this site, so {subject} is unavailable. Do NOT try to make the change by other means.",
	authCheckUrl: "https://example.test/wp-admin/admin-ajax.php?action=novamira_visual_auth_check",
});
page.window = globalThis;

let sessionCheck: unknown = { success: true, data: { authenticated: true, readMode: false, readModeMessage: "" } };
let sessionChecks = 0;
page.fetch = () => {
	sessionChecks++;
	return Promise.resolve({ ok: true, json: () => Promise.resolve(sessionCheck) });
};

const readMode = await import("../src/workspace-read-mode");
const { WorkspaceDispatcher } = await import("../src/workspace-dispatcher");

const METHODS = [
	"workspace_status",
	"workspace_page_snapshot",
	"workspace_page_get_text",
	"workspace_page_click",
	"workspace_eval",
	"workspace_call_page_tool",
	"workspace_call_backend_tool",
];

/** A dispatcher whose host records the calls that reach it. */
function recordingDispatcher(): { dispatcher: InstanceType<typeof WorkspaceDispatcher>; calls: string[] } {
	const calls: string[] = [];
	const host = new Proxy({}, {
		get: (_target, property) => (...args: unknown[]) => {
			calls.push(String(property));
			if (property === "confirmPageInteraction") return `${String(args[0])} confirmed`;
			return "ran";
		},
	});
	const dispatcher = new WorkspaceDispatcher(host as never, {} as never);
	return { dispatcher, calls };
}

describe("read mode on when the workspace loads", () => {
	beforeEach(() => {
		data.readMode = "1";
		readMode.resetReadModeCheck();
	});

	test("reads the state from the page data", () => {
		expect(readMode.isReadMode()).toBe(true);
		data.readMode = true;
		expect(readMode.isReadMode()).toBe(true);
	});

	test("refuses every method, reads included, with the site's wording, before it reaches the page", async () => {
		const { dispatcher, calls } = recordingDispatcher();
		for (const method of METHODS) {
			await expect(dispatcher.dispatch(method, { pageId: "p1", name: "x", code: "1", selector: "a", toolName: "t" })).rejects.toThrow(
				`Read mode is active on this site, so Novamira Visual (${method}) is unavailable. Do NOT try`,
			);
		}
		expect(calls).toEqual([]);
	});
});

describe("read mode off", () => {
	beforeEach(() => {
		data.readMode = "";
		sessionCheck = { success: true, data: { authenticated: true, readMode: false, readModeMessage: "" } };
		sessionChecks = 0;
		readMode.resetReadModeCheck();
	});

	test("runs methods as usual", async () => {
		expect(readMode.isReadMode()).toBe(false);
		const { dispatcher, calls } = recordingDispatcher();
		await dispatcher.dispatch("workspace_eval", { pageId: "p1", code: "1" });
		await dispatcher.dispatch("workspace_page_click", { pageId: "p1", selector: "a" });
		await dispatcher.dispatch("workspace_call_page_tool", { pageId: "p1", toolName: "create_block", args: { name: "core/paragraph" } });
		expect(calls).toEqual(["evalInPage", "confirmPageInteraction", "callPageTool"]);
	});

	test("asks the site at most once in a few seconds", async () => {
		const { dispatcher } = recordingDispatcher();
		await dispatcher.dispatch("workspace_eval", { pageId: "p1", code: "1" });
		await dispatcher.dispatch("workspace_eval", { pageId: "p1", code: "2" });
		expect(sessionChecks).toBe(1);
	});

	test("stops when the site reports that read mode was turned on after the workspace loaded", async () => {
		sessionCheck = { success: true, data: { authenticated: true, readMode: true, readModeMessage: "Read mode is active on this site, so {subject} is unavailable. Turn it off first." } };
		const { dispatcher, calls } = recordingDispatcher();
		await expect(dispatcher.dispatch("workspace_eval", { pageId: "p1", code: "1" })).rejects.toThrow(
			"Read mode is active on this site, so Novamira Visual (workspace_eval) is unavailable. Turn it off first.",
		);
		expect(calls).toEqual([]);
		expect(readMode.isReadMode()).toBe(true);
	});

	test("keeps working when the check fails", async () => {
		page.fetch = () => Promise.reject(new Error("offline"));
		const { dispatcher, calls } = recordingDispatcher();
		await dispatcher.dispatch("workspace_eval", { pageId: "p1", code: "1" });
		expect(calls).toEqual(["evalInPage"]);
	});
});

describe("the built Gutenberg bundle", () => {
	function load(mode: string): { tools: string[]; definitions: string[] } {
		const script = new URL("./load-gutenberg-bundle.ts", import.meta.url).pathname;
		const run = Bun.spawnSync([process.execPath, script, mode]);
		expect(run.stderr.toString()).toBe("");
		expect(run.exitCode).toBe(0);
		return JSON.parse(run.stdout.toString());
	}

	test("exposes no tool in read mode", () => {
		const exposed = load("1");
		expect(exposed.tools).toEqual([]);
		expect(exposed.definitions).toEqual([]);
	});

	test("exposes every tool outside read mode", () => {
		const exposed = load("");
		expect(exposed.tools).toEqual([
			"list_block_types",
			"get_block_schema",
			"get_page_structure",
			"get_block_attributes",
			"get_editor_settings",
			"create_block",
			"update_block",
			"delete_block",
			"move_block",
			"replace_inner_blocks",
			"undo",
			"redo",
			"save",
			"use_skill",
			"batch_call",
		]);
	});
});
