(function ($) {
    const { __ } = wp.i18n;

    const ENABLE_TEMPLATES_TEXT = __("Enable Templates", "tpebl");
    const INSTALLING_TEXT = __("Installing WDesignKit", "tpebl");
    const WAITING_TEXT = __("Waiting...", "tpebl");

    $("document").ready(function () {
        let templateAddSection = $("#tmpl-elementor-add-section");

        if (0 < templateAddSection.length) {
            var oldTemplateButton = templateAddSection.html();
                oldTemplateButton = oldTemplateButton.replace('<div class="elementor-add-section-drag-title', '<div data-mode="dark" class="elementor-add-section-area-button elementor-action-tp-wdkit-button" title="' + __("WDesignKit") + '"><a href="#" class="tp-wkit-main-logo-div"></a></div><div class="elementor-add-section-drag-title');
                templateAddSection.html(oldTemplateButton);
        }

        elementor.on("preview:loaded", function () {
            
            window.tp_wdkit_editor = elementorCommon.dialogsManager.createWidget(
                "lightbox",
                {
                    id: "tp-wdkit-elementorp",
                    headerMessage: !1,
                    message: "",
                    hide: {
                        auto: !1,
                        onClick: !1,
                        onOutsideClick: false,
                        onOutsideContextMenu: !1,
                        onBackgroundClick: !0,
                    },
                    position: {
                        my: "center",
                        at: "center",
                    },
                    onShow: function () {
                        var dialogLightboxContent = $(".dialog-lightbox-message"),
                            clonedWrapElement = $("#tp-wdkit-preview");

                            clonedWrapElement = clonedWrapElement.clone(true).show()
                            dialogLightboxContent.html(clonedWrapElement);

                            dialogLightboxContent.on("click", ".tp-pre-close-btn", function () {
                                window.tp_wdkit_editor.hide();
                            });
                    },
                    onHide: function () {
                        window.tp_wdkit_editor.destroy();
                    }
                }
            );

            $(elementor.$previewContents[0].body).on("click", ".elementor-action-tp-wdkit-button", function (event) {
                window.tp_wdkit_editor.show();
            });

            $(document).on('click', '.tp-pre-not-show-again', function (e) {
                e.preventDefault();
                $.ajax({
                    url: tp_wdkit_preview_popup.ajax_url,
                    dataType: 'json',
                    type: "POST",
                    async: true,
                    data: {
                        action: 'tp_dont_show_again',
                        security: tp_wdkit_preview_popup.nonce
                    },
                    success: function (res) {
                        elementor.saver.update.apply().then(function () {
                            window.location.reload();
                        });
                    },
                    error: function (xhr, status, error) {
                        console.log('Response:', xhr.responseText);
                    }
                });
            });

            $(document).on('click', '.tp-pre-learn-more-about .tp-pre-wdesign-install', function (e) {
                e.preventDefault();

                var $button = $(this);
                var $loader = $button.find('.tp-pre-loader-circle');
                var $text = $button.find('.tpae-pre-enable-text');

                if ($text.length > 0) {
                    $text.text(INSTALLING_TEXT);
                } else {
                    var $tp_visitPlugin = $button.find('.tp-visit-plugin');
                    if ($tp_visitPlugin.length > 0) {
                        $tp_visitPlugin.text(WAITING_TEXT);
                    }
                }

                $loader.css('display', 'block');

                jQuery.ajax({
                    url: tp_wdkit_preview_popup.ajax_url,
                    dataType: 'json',
                    type: "post",
                    async: true,
                    data: {
                        action: 'tp_install_wdkit',
                        security: tp_wdkit_preview_popup.nonce,
                    },
                    success: function (res) {
                        
                        if(!res.success){
                            alert('Only site admins can install presets. Please ask your admin to complete the installation.')
                        }

                        $loader.css('display', 'none');

                        if (true === res.success) {
                            elementor.saver.update.apply().then(function () {
                                window.location.reload();
                            });

                        } else {
                            $text.text(ENABLE_TEMPLATES_TEXT);
                        }
                    },
                    error: function () {
                        $loader.css('display', 'none');
                        $text.css('display', 'block').text(ENABLE_TEMPLATES_TEXT);
                    }
                });
            });
        });
    });
})(jQuery);