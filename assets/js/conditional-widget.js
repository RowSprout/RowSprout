/**
 * The "If / then" widget's rules that depend on today (ConditionalWidget):
 * the server put every output that can still match in the page and decided
 * the other rules; this picks the output again with today's date in the
 * site's time zone, so a cached page does not keep a stale one.
 *
 * data-rs-conditional = { offset: site UTC offset in minutes, cases: [ { i: case
 * index, m: "all"|"any", s: [ results decided by the server ], r: [ { d: the
 * property's day (Y-m-d), o: operator, n: days } ] } ] }; an output without a
 * matching case is "else" (data-rs-case="else").
 */
(function () {
	'use strict';

	function pad(n) {
		return (n < 10 ? '0' : '') + n;
	}

	// The site's day, shifted by a number of days.
	function siteDay(offset, plusDays) {
		var d = new Date(Date.now() + offset * 60000 + (plusDays || 0) * 86400000);
		return d.getUTCFullYear() + '-' + pad(d.getUTCMonth() + 1) + '-' + pad(d.getUTCDate());
	}

	function test(rule, offset) {
		var today = siteDay(offset, 0);
		switch (rule.o) {
			case 'is_today':
				return rule.d === today;
			case 'in_past':
				return rule.d < today;
			case 'in_future':
				return rule.d > today;
			case 'next_days':
				return rule.d >= today && rule.d <= siteDay(offset, rule.n);
			case 'last_days':
				return rule.d <= today && rule.d >= siteDay(offset, -rule.n);
		}
		return false;
	}

	function matches(results, match) {
		if (!results.length) {
			return false;
		}
		return match === 'any' ? results.indexOf(true) !== -1 : results.indexOf(false) === -1;
	}

	function pick(root) {
		var data;
		try {
			data = JSON.parse(root.getAttribute('data-rs-conditional'));
		} catch (e) {
			return;
		}
		var chosen = 'else';
		var cases = data && data.cases ? data.cases : [];
		for (var i = 0; i < cases.length; i++) {
			var results = (cases[i].s || []).slice();
			for (var j = 0; j < (cases[i].r || []).length; j++) {
				results.push(test(cases[i].r[j], data.offset || 0));
			}
			if (matches(results, cases[i].m)) {
				chosen = String(cases[i].i);
				break;
			}
		}
		var outputs = root.querySelectorAll('[data-rs-case]');
		for (var k = 0; k < outputs.length; k++) {
			outputs[k].hidden = outputs[k].getAttribute('data-rs-case') !== chosen;
		}
	}

	function init() {
		var roots = document.querySelectorAll('[data-rs-conditional]');
		for (var i = 0; i < roots.length; i++) {
			pick(roots[i]);
		}
	}

	window.rowsproutConditional = { pick: pick, siteDay: siteDay };
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
}());
