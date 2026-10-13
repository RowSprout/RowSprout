/**
 * The "If / then" widget's rules that depend on today (ConditionalWidget):
 * the server put "Then" and "Otherwise" in the page and decided every other
 * rule; this picks again with today's date in the site's time zone, so a
 * cached page does not keep a stale outcome.
 *
 * data-rs-conditional = { offset: site UTC offset in minutes, groups: [ [ { d:
 * the property's day (Y-m-d), o: operator, n: days } ] ] }: the "or" groups
 * today can still decide, each with only its rules on today (its other rules
 * matched). "Then" (data-rs-case="then") when every rule of one group matches,
 * else "Otherwise" (data-rs-case="else").
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

	function pick(root) {
		var data;
		try {
			data = JSON.parse(root.getAttribute('data-rs-conditional'));
		} catch (e) {
			return;
		}
		var groups = data && data.groups ? data.groups : [];
		var chosen = 'else';
		for (var i = 0; i < groups.length && chosen === 'else'; i++) {
			var all = groups[i].length > 0;
			for (var j = 0; j < groups[i].length; j++) {
				all = all && test(groups[i][j], data.offset || 0);
			}
			if (all) {
				chosen = 'then';
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
