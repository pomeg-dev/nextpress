/**
 * Nextpress — copy/move ACF Flexible Content layouts between sibling fields.
 *
 * ACF natively lets you reorder and duplicate flexible-content layouts only
 * WITHIN a single field. On the Nextpress Templates page the Before / After /
 * Sidebar sections (repeated per language) are separate flexible-content
 * fields, so a block can't be moved from one section to another — you'd have to
 * rebuild it by hand.
 *
 * This adds a small control to each layout's toolbar offering "Copy to" / "Move
 * to" the other flexible-content sections that live in the same field group.
 *
 * How it works: every one of these fields is built from the SAME block layout
 * set (see register-templates.php), so a layout in "Before Content" is
 * structurally identical to one in "After Content" — their ACF field keys
 * differ only by the parent flex-field key prefix
 * (…_before_content_… vs …_after_content_…). We reuse ACF's own `acf.duplicate`
 * core (so TinyMCE / select2 / image / nested repeater values survive exactly
 * as a native Duplicate would) and simply remap that key prefix from the source
 * field to the destination field while appending the clone into the
 * destination's layouts list.
 */
(function ($) {
	'use strict';

	if (typeof acf === 'undefined') {
		return;
	}

	var FLEX = '.acf-field[data-type="flexible_content"]';
	var $openMenu = null;
	var $openBtn = null;

	/**
	 * The flexible-content field instance that owns a given layout element.
	 */
	function flexFieldOf($layout) {
		return acf.getField($layout.closest(FLEX));
	}

	/**
	 * Immediate label text of a flexible-content field (e.g. "After Content").
	 */
	function fieldLabel(field) {
		var text = $.trim(field.$el.children('.acf-label').find('label').first().text());
		return text || field.get('label') || field.get('name');
	}

	/**
	 * Sibling flexible-content fields (same `.acf-fields` container) that can
	 * accept the given layout — i.e. that register a clone template for it.
	 */
	function targetsFor(srcField, layoutName) {
		var srcEl = srcField.$el[0];
		var out = [];
		srcField.$el.parent().children(FLEX).each(function () {
			if (this === srcEl) {
				return;
			}
			var field = acf.getField($(this));
			if (field && field.$clone(layoutName).length) {
				out.push(field);
			}
		});
		return out;
	}

	/**
	 * Whether `field` can accept another layout of `layoutName` (respects the
	 * field's overall max and the per-layout max). Pure — no notices/side effects.
	 */
	function isFull(field, layoutName) {
		if (field.isFull()) {
			return true;
		}
		var max = parseInt(field.$clone(layoutName).data('max'), 10) || 0;
		return !!max && field.countLayouts(layoutName) >= max;
	}

	/**
	 * Rewrite every field-key reference inside a cloned layout from the source
	 * field's flex-field key to the destination's. Each sub-field key is prefixed
	 * with the parent flex-field key, so a prefix swap maps the whole tree. The
	 * row id has already been freshened by acf.duplicate(); we only touch the key
	 * prefix (in name / id / for / data-key).
	 */
	function remapKeys($root, from, to) {
		var attrs = ['name', 'id', 'for', 'data-key'];
		$root
			.find('*')
			.addBack()
			.each(function () {
				var el = this;
				if (!el.getAttribute) {
					return;
				}
				for (var i = 0; i < attrs.length; i++) {
					var value = el.getAttribute(attrs[i]);
					if (value && value.indexOf(from) !== -1) {
						el.setAttribute(attrs[i], value.split(from).join(to));
					}
				}
			});
	}

	/**
	 * Clone `$layout` out of `srcField` into `dstField`. We reuse ACF's own
	 * acf.duplicate() (so select / TinyMCE / image / nested-repeater values and
	 * re-mounting all behave exactly like a native Duplicate), and remap the
	 * flex-field key prefix — crucially inside the `append` callback, i.e. BEFORE
	 * acf.duplicate() fires its `append` action to mount the sub-fields, so they
	 * mount already pointing at the destination field's keys.
	 */
	function copyLayout($layout, srcField, dstField) {
		var srcKey = srcField.get('key');
		var dstKey = dstField.get('key');

		var $new = acf.duplicate({
			target: $layout,
			before: function ($el) {
				acf.doAction('unmount', $el);
			},
			after: function ($el) {
				acf.doAction('remount', $el);
			},
			append: function ($el, $clone) {
				if (srcKey !== dstKey) {
					remapKeys($clone, srcKey, dstKey);
				}
				// jQuery.clone() copies value ATTRIBUTES, not the live value of
				// text/hidden/textarea inputs — so edited (unsaved) repeater rows
				// would clone in empty. ACF only fixes <select> (via its
				// after_duplicate handler, which has already run by now). Copy the
				// remaining live values positionally, before the fields mount.
				var $srcInputs = $el.find('[name]');
				var $cloneInputs = $clone.find('[name]');
				if ($srcInputs.length === $cloneInputs.length) {
					$srcInputs.each(function (i) {
						var src = this;
						var dst = $cloneInputs.get(i);
						if (!dst || src.tagName === 'SELECT') {
							return;
						}
						if (src.type === 'checkbox' || src.type === 'radio') {
							dst.checked = src.checked;
						} else {
							dst.value = src.value;
						}
					});
				}
				dstField.$layoutsWrap().append($clone);
			},
		});

		// NB: unlike ACF's add() (which clones a disabled template), we clone an
		// already-enabled row — so we must NOT call acf.enable(), which would also
		// wrongly enable the repeaters' internal clone-template rows. This mirrors
		// ACF's native duplicateLayout().
		dstField.$input().trigger('change');
		dstField.render();
		dstField.setActiveLayout($new);
		acf.focusAttention($new);

		// Mirror the source's enabled/disabled state onto the clone, positionally.
		// ACF disables the inputs of conditionally-hidden fields so they're left
		// out of the POST — but its conditional-logic controller wiring is not
		// reliably re-established when a whole subtree is cloned and mounted, so
		// the clone can wrongly disable fields that are enabled in the source
		// (e.g. a repeater gated by a toggle that IS on), dropping the copied data
		// on save. The source already has the correct state for its values, so we
		// copy that state across verbatim. Clone-template (acfcloneindex) rows
		// stay disabled because they're disabled in the source too.
		var $srcAll = $layout.find('[name]');
		var $cloneAll = $new.find('[name]');
		if ($srcAll.length === $cloneAll.length) {
			$srcAll.each(function (i) {
				var dst = $cloneAll.get(i);
				if (dst) {
					dst.disabled = this.disabled;
				}
			});
		}

		return $new;
	}

	function removeLayout($layout, field) {
		acf.doAction('unmount', $layout);
		$layout.remove();
		field.$input().trigger('change');
		field.render();
	}

	// ---- Menu -----------------------------------------------------------------

	function closeMenu() {
		if ($openMenu) {
			$openMenu.remove();
			$openMenu = null;
		}
		if ($openBtn) {
			$openBtn.removeClass('-active');
			$openBtn = null;
		}
		$(document).off('.npfc');
	}

	function addGroup($menu, label, srcField, targets, $layout, layoutName, move) {
		$menu.append($('<div class="np-fc-menu-group" />').text(label));
		targets.forEach(function (field) {
			var $item = $('<button type="button" class="np-fc-menu-item" role="menuitem" />').text(
				fieldLabel(field)
			);
			if (isFull(field, layoutName)) {
				$item.prop('disabled', true).attr('title', 'This section is full');
			} else {
				$item.on('click', function () {
					copyLayout($layout, srcField, field);
					if (move) {
						removeLayout($layout, srcField);
					}
					closeMenu();
				});
			}
			$menu.append($item);
		});
	}

	function openMenu($btn, $layout) {
		closeMenu();

		var srcField = flexFieldOf($layout);
		var layoutName = $layout.data('layout');
		var targets = targetsFor(srcField, layoutName);

		var $menu = $('<div class="np-fc-menu" role="menu" />');
		if (!targets.length) {
			$menu.append($('<div class="np-fc-menu-empty" />').text('No other sections available'));
		} else {
			addGroup($menu, 'Copy to', srcField, targets, $layout, layoutName, false);
			addGroup($menu, 'Move to', srcField, targets, $layout, layoutName, true);
		}

		$('body').append($menu);

		var rect = $btn[0].getBoundingClientRect();
		var left = rect.right + window.scrollX - $menu.outerWidth();
		$menu.css({
			top: rect.bottom + window.scrollY + 4 + 'px',
			left: Math.max(8, left) + 'px',
		});

		$openMenu = $menu;
		$openBtn = $btn.addClass('-active');

		// Defer binding so this click doesn't immediately close the menu.
		setTimeout(function () {
			$(document).on('mousedown.npfc', function (e) {
				if (!$(e.target).closest('.np-fc-menu, .np-fc-copy-btn').length) {
					closeMenu();
				}
			});
			$(document).on('keydown.npfc', function (e) {
				if (e.key === 'Escape') {
					closeMenu();
				}
			});
		}, 0);
	}

	// ---- Control injection ----------------------------------------------------

	function injectInto($layout) {
		var $controls = $layout
			.find('> .acf-fc-layout-actions-wrap > .acf-fc-layout-controls')
			.first();
		if (!$controls.length || $controls.hasClass('np-fc-ready')) {
			return;
		}
		$controls.addClass('np-fc-ready');

		var $btn = $(
			'<a href="#" class="acf-js-tooltip np-fc-copy-btn" aria-haspopup="menu" ' +
				'title="Copy or move to another section">' +
				'<span class="dashicons dashicons-migrate"></span></a>'
		);
		$btn.on('click', function (e) {
			e.preventDefault();
			e.stopPropagation();
			if ($openBtn && $openBtn[0] === $btn[0]) {
				closeMenu();
			} else {
				openMenu($btn, $layout);
			}
		});

		var $collapse = $controls.find('.acf-layout-collapse').first();
		if ($collapse.length) {
			$btn.insertBefore($collapse);
		} else {
			$controls.append($btn);
		}
	}

	function enhance($scope) {
		if ($scope && $scope.hasClass && $scope.hasClass('layout')) {
			injectInto($scope);
		}
		($scope || $(document))
			.find(FLEX + ' .acf-flexible-content > .values > .layout')
			.each(function () {
				injectInto($(this));
			});
	}

	acf.addAction('ready', function () {
		enhance();
	});
	acf.addAction('append', function ($el) {
		enhance($el);
	});
})(jQuery);
