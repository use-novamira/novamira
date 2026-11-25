import { reportApprovals } from "./workspace-approvals";
import { workspaceData } from "./workspace-config";

/**
 * Novamira Visual does not work while Novamira read mode is on. The site does not serve the workspace page
 * then, and its backend tools refuse; here every workspace method refuses too, so a workspace that was
 * open before read mode was turned on stops as well. The state comes with the page and is read again from
 * the WordPress session check, at most once every few seconds.
 */

/** How long the answer of the session check is kept. */
const RECHECK_MS = 5000;

const FALLBACK_MESSAGE =
	"Read mode is active on this site, so {subject} is unavailable. Do NOT try to make the change by other means: tell the user that a site administrator must turn off Novamira read mode first.";

let checkedAt = 0;
let reportedMessage = "";

/** Forget what the session check reported. For tests. */
export function resetReadModeCheck(): void {
	checkedAt = 0;
	reportedMessage = "";
}

/** Whether read mode was on when the workspace loaded, or the session check has reported it since. */
export function isReadMode(): boolean {
	return workspaceData.readMode === true || workspaceData.readMode === "1" || reportedMessage !== "";
}

/** The refusal, worded as the site words it. */
export function readModeError(subject: string): Error {
	const template = reportedMessage || workspaceData.readModeMessage || FALLBACK_MESSAGE;
	return new Error(template.split("{subject}").join(subject));
}

/** Ask the site whether read mode is on now. A failed check leaves the last answer. */
async function refreshReadMode(): Promise<void> {
	const now = Date.now();
	if (now - checkedAt < RECHECK_MS) return;
	checkedAt = now;

	try {
		const response = await window.fetch(workspaceData.authCheckUrl, {
			method: "GET",
			credentials: "same-origin",
			cache: "no-store",
			headers: { Accept: "application/json" },
		});
		const body = (await response.json().catch(() => null)) as { data?: { readMode?: unknown; readModeMessage?: unknown; approvals?: unknown } } | null;
		const data = body?.data;
		if (!data || typeof data !== "object") return;
		// The same check says whether approvals were turned on or skipped since the workspace loaded.
		reportApprovals(data.approvals);
		reportedMessage = data.readMode === true ? (typeof data.readModeMessage === "string" && data.readModeMessage !== "" ? data.readModeMessage : FALLBACK_MESSAGE) : "";
	} catch {
		// The next check tries again.
	}
}

/** Refuse every workspace method while read mode is on: Novamira Visual does not work then. */
export async function assertReadModeAllowsMethod(method: string): Promise<void> {
	if (!isReadMode()) {
		await refreshReadMode();
	}
	if (isReadMode()) {
		throw readModeError(`Novamira Visual (${method})`);
	}
}
