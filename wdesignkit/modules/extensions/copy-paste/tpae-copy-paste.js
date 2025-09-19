
/*Plus Cross Copy Paste*/
(function () {

    const { __ } = wp.i18n;

    const Json_error = __("Warning: The data is not in JSON format", "tpebl");
    const elementor_json_error = __('Warning: This is not a valid Elementor JSON. "tpelecode" not found.', "tpebl");

    const keysToClear = [
        'content_template',
        'content_a_template',
        'content_b_template',
        'fp_content_template',
        'protected_content_template',
        'blockTemp'
    ];

    var g = ["section", "column", "widget", "container"],
        a = [];
    elementor.on("preview:loaded", function () {
        g.forEach(function (b, e) {
            elementor.hooks.addFilter("elements/" + g[e] + "/contextMenuGroups", function (b, h) {
                return (
                    a.push(h),
                    b.push({
                        name: "plus_" + g[e],
                        actions: [
                            {
                                name: "tp_plus_copy",
                                title: "Plus Copy",
                                icon: "eicon-copy",
                                callback: function () {
                                    var b = {};

                                    if (g[e] === "widget") {
                                        b.tpeletype = h.model.get("widgetType");
                                    } else {
                                        b.tpeletype = null;
                                    }

                                    b.tpelecode = h.model.toJSON();

                                    console.log(b);

                                    // Create a textarea element
                                    var textarea = document.createElement('textarea');
                                    textarea.value = JSON.stringify(b);

                                    // Append textarea, select its content, copy, and remove it
                                    document.body.appendChild(textarea);
                                    textarea.select();
                                    document.execCommand('copy');
                                    document.body.removeChild(textarea);

                                },
                            },
                            {
                                name: "tp_plus_paste",
                                title: "Plus Paste",
                                icon: "eicon-import-kit",
                                callback: function () {

                                    navigator.permissions.query({ name: "clipboard-read" }).then(permissionStatus => {
                                        var clipboardAllowed = (permissionStatus.state !== "denied");

                                        if (!clipboardAllowed) {

                                            var existingDialog = document.getElementById('tpae-paste-area-dialog');
                                            if (existingDialog) {
                                                existingDialog.parentNode.removeChild(existingDialog);
                                            }

                                            var tpae_paste = document.querySelector('#tpae-paste-area-input');
                                            if (!tpae_paste) {

                                                var container = document.createElement('div'),
                                                    paragraph = document.createElement('p');

                                                paragraph.innerHTML = __("Please grant clipboard permission for smoother copying and pasting.", "tpebl");

                                                var inputArea = document.createElement('input');
                                                inputArea.id = 'tpae-paste-area-input';
                                                inputArea.type = 'text';
                                                inputArea.setAttribute('autocomplete', 'off');
                                                inputArea.setAttribute('autofocus', 'autofocus');
                                                inputArea.focus();

                                                container.appendChild(paragraph);
                                                container.appendChild(inputArea);

                                                inputArea.addEventListener('paste', async function (event) {
                                                    event.preventDefault();
                                                    var pastedData = event.clipboardData.getData("text");
                                                    console.log(pastedData);

                                                    if (tpae_isJSON(pastedData) == false) {
                                                        alert(Json_error);
                                                        return;
                                                    }

                                                    var parsedData = JSON.parse(pastedData);
                                                    clearContentKeys( parsedData, keysToClear );

                                                    if (!parsedData.tpelecode || typeof parsedData !== 'object') {
                                                        alert(elementor_json_error);
                                                        return;
                                                    }

                                                    tpae_manage_paste(parsedData, h);

                                                    var existingDialog = document.getElementById('tpae-paste-area-dialog');
                                                    if (existingDialog) {
                                                        existingDialog.parentNode.removeChild(existingDialog);
                                                    }
                                                });

                                                let getSystem = '';
                                                if (navigator.userAgent.indexOf('Mac OS X') != -1) {
                                                    getSystem = 'Command'
                                                } else {
                                                    getSystem = 'Ctrl'
                                                }

                                                var tpDilouge = elementorCommon.dialogsManager.createWidget('lightbox', {
                                                    id: 'tpae-paste-area-dialog',
                                                    headerMessage: `${getSystem} + V`,
                                                    message: container,
                                                    position: {
                                                        my: 'center center',
                                                        at: 'center center'
                                                    },
                                                    onShow: function onShow() {
                                                        inputArea.focus()
                                                        tpDilouge.getElements('widgetContent').on('click', function () {
                                                            inputArea.focus()
                                                        });
                                                    },
                                                    closeButton: true,
                                                    closeButtonOptions: {
                                                        iconClass: 'eicon-close'
                                                    },
                                                });

                                                tpDilouge.show();
                                            }
                                        } else {

                                            navigator.clipboard.readText().then(function (pastedData) {
                                                if (tpae_isJSON(pastedData) == false) {
                                                    alert(Json_error);
                                                    return;
                                                }

                                                var parsedData = JSON.parse(pastedData);
                                                clearContentKeys( parsedData, keysToClear );

                                                if (!parsedData.tpelecode || typeof parsedData !== 'object') {
                                                    alert(elementor_json_error);
                                                    return;
                                                }

                                                tpae_manage_paste(parsedData, h);

                                            }).catch(function (err) {
                                                console.error("Error reading clipboard data: " + err);
                                            });

                                        }
                                    });


                                },
                            },
                        ],
                    }),
                    b
                );
            });
        });
    });

    const tpae_manage_paste = async (parsedData, h) => {

        let message1 = __('We are pasting your design' , "tpebl");
        let message2 = __('We have pasted your design' , "tpebl");
        let message3 = __('Now we are importing your design' , "tpebl");
        let message4 = __('We have successfully imported the design' , "tpebl");

        showTpaePopup(message1);

        await new Promise(resolve => setTimeout(resolve, 2000));

        const widgets_name = await tpae_get_widgetsname(parsedData.tpelecode);

        showTpaePopup(message2, widgets_name, false, true);

        await new Promise(resolve => setTimeout(resolve, 2000));

        const response = await jQuery.ajax({
            url: theplus_cross_cp.ajax_url,
            method: "POST",
            data: {
                nonce: theplus_cross_cp.nonce,
                action: "tpae_live_paste",
                type: "tpae_enable_widget",
                widgets_name: widgets_name,
            }
        });

        if (response.success === false) {
            alert(response.message);
            hideTpaePopup();
            return;
        }

        showTpaePopup(message3, widgets_name, false, true);

        await new Promise(resolve => setTimeout(resolve, 1000));

        await tpae_widgets_load();

        for (let i = 0; i < widgets_name.length; i++) {
            await new Promise(resolve => setTimeout(resolve, 500));
            const tickEl = document.getElementById(`tpae-widget-status-${i}`);
            if (tickEl) {
                tickEl.classList.remove("loader");
                tickEl.textContent = "✔";
            }
        }

        await tpae_createWidgetElements(parsedData, h);

        showTpaePopup( message4, [], true );

        await new Promise(resolve => setTimeout(resolve, 5000));

        elementor.saver.update.apply();

        hideTpaePopup();
    };

    /**
     * This Function are used for get all widgets list.
     */
    const tpae_get_widgetsname = async (obj, widgetTypes = []) => {

        if (obj.hasOwnProperty("widgetType") && obj.widgetType) {
            widgetTypes.push(obj.widgetType);
        }
        if (Array.isArray(obj.elements)) {
            obj.elements.forEach(element =>
                tpae_get_widgetsname(element, widgetTypes));
        }

        return [...new Set(widgetTypes)];
    }

    const tpae_widgets_load = async () => {
        const Oa = (e) => {
            return new Promise((resolve, reject) => {
                const r = document.createElement(e.nodeName);
                ["id", "rel", "src", "href", "type"].forEach(attr => {
                    if (e[attr]) {
                        r[attr] = e[attr];
                    }
                });
                if (e.innerHTML) {
                    r.appendChild(document.createTextNode(e.innerHTML));
                }
                r.onload = () => {
                    resolve(true);
                };
                r.onerror = () => {
                    reject(new Error("Error loading asset."));
                };
                // Append to document body
                document.body.appendChild(r);
                // Resolve immediately for <link> or <script> without src
                if ((r.nodeName.toLowerCase() === "link" || (r.nodeName.toLowerCase() === "script" && !r.src))) {
                    resolve();
                }
            });
        }
        const fetchAndProcessData = async () => {
            await fetch(document.location.href, { parse: false })
                .then(response => response.text())
                .then(text => {
                    // Step 2: Parse the HTML response
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(text, 'text/html');
                    // Step 3: Define IDs to filter
                    const idsToInclude = ['wp-blocks-js-after', 'plus-editor-css-css', 'plus-editor-js-js', 'elementor-editor-js-before'];
                    // Step 4: Select and filter elements
                    const elements = Array.from(doc.querySelectorAll('link[rel="stylesheet"],script')).filter(element => {
                        return element.id && (idsToInclude.includes(element.id) || !document.getElementById(element.id));
                    });
                    // Step 5: Process each element (assuming Oa is a defined function)
                    return elements.reduce((promise, element) => {
                        return promise.then(() => Oa(element));
                    }, Promise.resolve());
                })
                .catch(error => {
                    console.error('Error fetching or processing data:', error);
                });
        }
        await fetchAndProcessData();
        if (typeof elementor !== 'undefined') {
            elementor.addWidgetsCache(elementor.getConfig().initial_document.widgets);
        }
    }

    const tpae_createWidgetElements = async (data, element) => {
        var targetElement = element,
            targetElementType = element.model.get("elType"),
            sourceElementType = data.tpelecode.elType,
            sourceElementData = data.tpelecode,
            sourceElementJson = JSON.stringify(sourceElementData);

        var containsImage = /\.(jpg|png|jpeg|gif|svg)/gi.test(sourceElementJson),
            elementModel = { elType: sourceElementType, settings: sourceElementData.settings },
            targetContainer = null,
            insertOptions = { index: 0 };

        if (sourceElementType === "section" || sourceElementType === "container") {
            elementModel.elements = tpae_parseElements(sourceElementData.elements);
            targetContainer = elementor.getPreviewContainer();
        } else if (sourceElementType === "column") {
            elementModel.elements = tpae_parseElements(sourceElementData.elements);
            if (targetElementType === "section" || targetElementType === "container") {
                targetContainer = targetElement.getContainer();
            } else if (targetElementType === "column") {
                targetContainer = targetElement.getContainer().parent;
                insertOptions.index = targetElement.getOption("_index") + 1;
            } else if (targetElementType === "widget") {
                targetContainer = targetElement.getContainer().parent.parent;
                insertOptions.index = targetElement.getContainer().parent.view.getOption("_index") + 1;
            }
        } else if (sourceElementType === "widget") {
            elementModel.widgetType = data.tpeletype;
            targetContainer = targetElement.getContainer();
            if (targetElementType === "section" || targetElementType === "container") {
                targetContainer = targetElement.children.findByIndex[0].getContainer();
            } else if (targetElementType === "column") {
                targetContainer = targetElement.getContainer();
            } else if (targetElementType === "widget") {
                targetContainer = targetElement.getContainer().parent;
                targetElement.index = targetElement.getOption("_index") + 1;
                insertOptions.index = targetElement.getOption("_index") + 1;
            }
        }

        var createdElement = $e.run("document/elements/create", {
            model: elementModel,
            container: targetContainer,
            options:
                insertOptions
        });

        if (containsImage) {
            jQuery.ajax({
                url: theplus_cross_cp.ajax_url,
                method: "POST",
                data: {
                    nonce: theplus_cross_cp.nonce,
                    action: "plus_cross_cp_import",
                    copy_content: sourceElementJson
                }
            }).done(function (response) {
                if (response.success) {
                    var importedData = response.data[0];
                    elementModel.elType = importedData.elType;
                    elementModel.settings = importedData.settings;
                    if (elementModel.elType === "widget") {
                        elementModel.widgetType = importedData.widgetType;
                    } else {
                        elementModel.elements = importedData.elements;
                    }
                    $e.run("document/elements/delete", { container: createdElement });
                    $e.run("document/elements/create", { model: elementModel, container: targetContainer, options: insertOptions });
                }
            });
        }
    }

    function tpae_parseElements(elements) {
        return elements ? elements.map(el => ({ ...el })) : [];
    }

    function tpae_isJSON(str) {
        try {
            JSON.parse(str);
            return true;
        } catch (e) {
            return false;
        }
    }

    /**
     * This Functionis use for return HTML.
     * 
     * @returns HTML
     */
    function initTpaePopup() {

        if (document.getElementById("tpae-popup-overlay")) return;

        const style = document.createElement("style");
        document.head.appendChild(style);

        const popup = document.createElement("div");
        popup.id = "tpae-popup-overlay";
        popup.style.display = "none";
        popup.innerHTML = `
                    <div id="tpae-popup-box">
                        <div class="tpae-heading-container">
                        <div id="tpae-popup-icon" class="tpae-spinner-container tpae-spinner">
                            <img id="tpae-popup-spinner" class="tpae-spinner" src="${theplus_cross_cp.asset_url}assets/svg/tp_loader.svg" width="60" height="60" alt=" ` + __("Loading...", "tpebl") + ` " />
                        </div>
                        <div class="tpae-message-container">
                            <span id="tpae-popup-message">` + __("Loading...", "tpebl") + `</span>
                            <span id="tpae-popup-submessage">` + __("You’ve successfully pasted the design from the source site", "tpebl") + `</span>
                        </div>
                        </div>
                        <div id="tpae-widget-info"></div>
                    </div>`;

        document.body.appendChild(popup);
    }

    const typeMessage = (element, text, speed = 50) => {
        element.innerHTML = "";
        let i = 0;

        const typeChar = () => {
            if (i < text.length) {
                element.innerHTML += text.charAt(i);
                i++;
                setTimeout(typeChar, speed);
            }
        };

        typeChar();
    };

    let storedIconImg = null; // Declare once at the global level

    // Initialize the spinner image element
    const initSpinner = () => {
        if (!storedIconImg) {
            storedIconImg = new Image();
            storedIconImg.src = theplus_cross_cp.asset_url + 'assets/svg/tp_loader.svg';
            storedIconImg.width = 60;
            storedIconImg.height = 60;
            storedIconImg.alt = "Loading...";
            storedIconImg.className = "tpae-spinner";
        }
    };

    // Function to show popup with spinner or checkmark
    const showTpaePopup = (message, widgets = [], isSuccess = false, showCount = false) => {
        initTpaePopup();
        initSpinner();

        const iconContainer = document.getElementById("tpae-popup-icon");
        const msg = document.getElementById("tpae-popup-message");
        const info = document.getElementById("tpae-widget-info");

        typeMessage(msg, message);

        let widgetHTML = "";
        if (Array.isArray(widgets) && widgets.length > 0) {
            widgetHTML += `<div class="tpae-widget-list">`;

            if (showCount) {
                widgetHTML += `<div class="tpae-widget-count"><span>` + __("We've found ", "tpebl") + ` ${widgets.length} ` + __("widget(s) used in this design", "tpebl") + `</span></div>`;
            }

            widgetHTML += `<div class="tpae-widget-names">`;
            widgets.forEach((widget, index) => {
                widgetHTML += `
                    <div id="tpae-widget-${index}" class="tpae-widget-row">
                        <span class="tpae-widget-tick loader" id="tpae-widget-status-${index}"></span>
                        <span class="tpae-widget-name">${widget}</span>
                    </div>`;
            });
            widgetHTML += `</div>`;

            if (showCount) {
                widgetHTML += `<div class="tpae-widget-note"><span><strong>` + __("Note :", "tpebl") + `</strong>` + __("We enable these widgets in your WordPress site", "tpebl") + ` </span></div>`;
            }

            widgetHTML += `</div>`;
        }

        info.innerHTML = widgetHTML;

        if (isSuccess) {
            iconContainer.className = "tpae-checkmark";
            iconContainer.textContent = "✔";
            if (storedIconImg) {
                storedIconImg.style.display = "none";
            }
        } else {

            iconContainer.className = "tpae-spinner-container";
            iconContainer.textContent = "";

            if (!iconContainer.contains(storedIconImg) && storedIconImg instanceof Node) {
                iconContainer.appendChild(storedIconImg);
                storedIconImg.style.display = "inline-block";  // Ensure the spinner is visible
            }
        }

        // Show the popup overlay
        document.getElementById("tpae-popup-overlay").style.display = "flex";
    };


    function hideTpaePopup() {
        const el = document.getElementById("tpae-popup-overlay");
        if (el) el.style.display = "none";
    }


})(jQuery);

function clearContentKeys( obj, keysToClear ) {
    if ( typeof obj !== 'object' || obj === null ) {
        return;
    }

    if ( Array.isArray( obj ) ) {
        obj.forEach( item => clearContentKeys( item, keysToClear ) );
    } else {
        for ( let key in obj ) {
            if ( !obj.hasOwnProperty( key ) ) continue;

            if ( keysToClear.includes( key ) ) {
                obj[key] = '';
            } else if (typeof obj[key] === 'object') {
                clearContentKeys( obj[key], keysToClear );
            }
        }
    }
}