/* Jewelry Store theme. Small, dependency-free. Navigation works without JavaScript on wide screens. */
(function () {
	'use strict';
	var toggle = document.querySelector('.jwt-menu-toggle');
	var nav = document.getElementById('jwt-nav');
	if (!toggle || !nav) {
		return;
	}
	document.documentElement.classList.add('jwt-js');

	function setOpen(open) {
		toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
		nav.classList.toggle('is-open', open);
		document.body.classList.toggle('jwt-menu-open', open);
		if (open) {
			var first = nav.querySelector('a');
			if (first) {
				first.focus();
			}
		}
	}

	toggle.addEventListener('click', function () {
		setOpen(toggle.getAttribute('aria-expanded') !== 'true');
	});
	document.addEventListener('keydown', function (e) {
		if (e.key === 'Escape' && toggle.getAttribute('aria-expanded') === 'true') {
			setOpen(false);
			toggle.focus();
		}
	});
	window.matchMedia('(min-width: 900px)').addEventListener('change', function (mq) {
		if (mq.matches) {
			setOpen(false);
		}
	});

	// Move focus to form errors so screen reader and keyboard users hear them.
	var err = document.querySelector('.jw-notice--error');
	if (err) {
		err.focus();
	}
})();
