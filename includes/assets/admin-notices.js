// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

document.addEventListener(
	'click',
	(event) => {
		if (!(event.target instanceof Element)) {
			return;
		}

		const button = event.target.closest('.notice-dismiss');
		const notice = button?.closest('.novamira-persistent-notice');
		const url = notice?.getAttribute('data-novamira-dismiss-url');
		if (url) {
			fetch(url, { credentials: 'same-origin' });
		}
	},
	true,
);
