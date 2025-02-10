(function ($) {
	const { __ } = wp.i18n;

	'use strict';

	function wkit_load_save_tempalte(current_route = {}, el = null) {
		var e;
		if (!e) {
			window.WdkitPopup = elementorCommon.dialogsManager.createWidget("lightbox", {
				id: "wdkit-elementor",
				className: 'wkit-contentbox-modal wdkit-elementor',
				headerMessage: !1,
				message: "",
				hide: {
					auto: !1,
					onClick: !1,
					onOutsideClick: !1,
					onOutsideContextMenu: !1,
					onBackgroundClick: !0
				},
				position: {
					my: "center",
					at: "center"
				},
				onShow: function () {
					var e = window.WdkitPopup.getElements("content");
					window.WdkitPopupToggle.open(current_route, e.get(0), "elementor")
				},
				onHide: function () {
					var e = window.WdkitPopup.getElements("content");
					window.WdkitPopupToggle.close(e.get(0)), window.WdkitPopup.destroy()
				}
			}),
				window.WdkitPopup.getElements("header").remove(), window.WdkitPopup.getElements("message").append(window.WdkitPopup.addElement("content"))
		}
		return window.WdkitPopup.show()
	}

	$("document").ready(function () {
		if (typeof elementor === "object" && elementor.$preview) {
			elementor.on("panel:init", (function () {
				$(".elementor-panel-footer-sub-menu").append('<div id="elementor-panel-footer-sub-menu-item-push-wdkit" class="elementor-panel-footer-sub-menu-item"><i class="elementor-icon eicon-folder" aria-hidden="true"></i><span class="elementor-title">' + __("Save Page in WDesignKit", "wdesignkit") + "</span></div>"), $(".elementor-panel-footer-sub-menu").on("click", "#elementor-panel-footer-sub-menu-item-push-wdkit", (function () {
					wkit_load_save_tempalte({
						route: "/save_template"
					}, null)
				}))
			}));

			var e = ["section", "container"],
				b = [];
			elementor.on("preview:loaded", function () {

				if (window.location.hash.includes('?wdesignkit=open')) {
					wkit_load_save_tempalte({}, null);

					setTimeout(() => {
						window.location.hash = window.location.hash.replaceAll('?wdesignkit=open', '')
					}, 3000);
				}

				e.forEach(function (c, f) {
					elementor.hooks.addFilter("elements/" + e[f] + "/contextMenuGroups", function (c, d) {
						return (
							b.push(d),
							c.push({
								name: "wdkit_" + e[f],
								actions: [
									{
										name: "wdkit_save_section",
										title: "Save in WDesignKit",
										icon: "eicon-save",
										callback: function () {
											localStorage.setItem("wdkit_section", JSON.stringify(d.model.toJSON({
												remove: ["default"],
											})));
											wkit_load_save_tempalte({
												route: "/save_template/section"
											}, null);
											setTimeout((function () {
												if (window.location && window.location.hash != '#/save_template') {
													window.location.hash = '#/save_template/section';
												}
											}), 20)
										},
									},
								],
							}),
							c
						);
					});
				});
			});
		}
		var eleTempContent = $("#tmpl-elementor-add-section");
		if (eleTempContent.length > 0) {
			var actionHtml = eleTempContent.html();
			let pluginDetail = {
				pluginName: wdkitData?.wdkit_white_label?.plugin_name || 'WDesignKit',
				pluginLogo: wdkitData?.wdkit_white_label?.plugin_logo || wdkitData.WDKIT_URL + 'assets/images/jpg/Wdesignkit-logo.png'
			}

			actionHtml = actionHtml.replace(
				'<div class="elementor-add-section-drag-title',
				`<div data-mode="dark" class="elementor-add-section-area-button elementor-action-wdkit-button" title="${pluginDetail.pluginName}">
					<a href="#" class="wkit-main-logo-div">
						<img src="${pluginDetail.pluginLogo}" />
					</a>
				</div><div class="elementor-add-section-drag-title`
			);
			eleTempContent.html(actionHtml), elementor.on("preview:loaded", (function () {
				$(elementor.$previewContents[0].body).on("click", ".elementor-action-wdkit-button", (function (e) {
					wkit_load_save_tempalte({
						route: "/dashboard"
					}, null)
				}))
			}))
		}
	});
})(jQuery);