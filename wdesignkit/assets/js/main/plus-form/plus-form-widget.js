(function ($) {
    "use strict";
    var WidgetFormHandler = function ($scope, $) {
        var container = $scope[0].querySelector('.tpae-form-container');
        var form = container.querySelector('.tpae-form');

        var formdata = container.dataset.formdata ? JSON.parse(container.dataset.formdata) : {};
        var requiredMask = formdata.Required_mask;
        var emailData = container?.dataset?.emaildata ? JSON.parse(container.dataset.emaildata) : {};

        var requiredAsterisks = container.querySelectorAll('.tpae-required-asterisk');
        requiredAsterisks.forEach(function (asterisk) {
            asterisk.style.display = requiredMask === 'hide-asterisks' ? 'none' : 'inline';
        });

        var invalidForm = formdata.invalid_form || "Invalid form submission.";
        var successMessage = formdata.success_message || "Your message has been sent successfully.";
        var formError = formdata.form_error || "There was an error with the form submission.";
        var requiredFieldsError = formdata.required_fields || "Please fill in the required fields.";
        var emailFormatError = formdata.email_format_error || "Please enter a valid email address.";
        var serverError = formdata.server_error || "Server error, please try again later.";
        var isSubmitting = false;

        // Email validation regex pattern
        var emailRegex = /^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/;

        // Add real-time email validation
        form.querySelectorAll('input[type="email"], input[name*="email"], input[id*="email"]').forEach(function(input) {
            input.addEventListener('blur', function() {
                var inputValue = this.value.trim();
                if (inputValue !== '' && !emailRegex.test(inputValue)) {
                    showFieldError(this, emailFormatError);
                } else {
                    clearFieldError(this);
                }
            });
            
            input.addEventListener('input', function() {
                clearFieldError(this);
            });
        });

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            if (isSubmitting) return;

            isSubmitting = true;
            clearMessages();
            var isValid = true;
            var formData = {};
            var formFields = [];
            form.querySelectorAll('.tpae-form-field').forEach(function (field) {
                var input = field.querySelector('input, textarea');
                var label = field.querySelector('label') ? field.querySelector('label').textContent.trim() : '';
                                
                if (input) {
                    var inputValue = input.value.trim();
                    var inputID = input.getAttribute('id') || '';
                    var inputName = input.getAttribute('name') || '';
                    var inputType = input.getAttribute('type') || '';
                    
                    formFields.push({
                        field_id: inputID,
                        field_name: inputName,
                        field_value: inputValue
                    });
                    if (input.required && inputValue === '') {
                        isValid = false;
                        showFieldError(input, requiredFieldsError.replace('%field%', label));
                    }
                    
                    // Validate email format for email fields
                    // Check multiple ways to identify email fields
                    var isEmailField = inputType === 'email' || 
                                     inputName.toLowerCase().includes('email') || 
                                     inputID.toLowerCase().includes('email') ||
                                     label.toLowerCase().includes('email');
                    
                    if (isEmailField && inputValue !== '') {
                        if (!emailRegex.test(inputValue)) {
                            isValid = false;
                            showFieldError(input, emailFormatError);
                        }
                    }
                    
                    formData[input.name || label || input.id] = inputValue;

                }
            });
            if (!isValid) {
                displayMessage(invalidForm, 'error');
                isSubmitting = false;
                return false;
            }

            submitForm(formData, formFields);
        });

        var submitForm = function (formData, formFields) {
            var submitBtn = form.querySelector('.tpae-form-submit'),
                btnText   = submitBtn.querySelector('.tpae-button-text'),
                btnLoader = submitBtn.querySelector('.tpae-button-loader');
        
            submitBtn.disabled = true;
            if (btnText) {
                btnText.style.display = 'none';
            }
            if (btnLoader) {
                btnLoader.style.display = 'inline-flex';
            }
        
            $.ajax({
                url: theplus_ajax_url,
                type: 'POST',
                data: {
                    action: 'tpae_form_submission',
                    form_data: JSON.stringify(formData),
                    email_data: emailData,
                    form_fields: JSON.stringify(formFields),
                    security: emailData.nonce
                },
                success: function (response) {
                    if (response?.success) {
                        if (response?.data?.email_sent) {
                            displayMessage(successMessage, 'success');
                            form.reset();
                            var redirection_data = response?.data?.redirection;
                            if (redirection_data && redirection_data.url) {
                                if (redirection_data.is_external) {
                                    window.open(redirection_data.url, '_blank', 'noopener,noreferrer');
                                } else {
                                    window.location.href = redirection_data.url;
                                }
                            }
                        } else {
                            displayMessage("Emails could not be sent. Please try again.", 'error');
                        }
                    } else {
                        displayMessage(formError.replace('%error%', response?.data?.message), 'error');
                    }
                },
                error: function (xhr, status, error) {
                    displayMessage(serverError.replace('%error%', error), 'error');
                },
                complete: function () {
                    isSubmitting = false;
                    if (btnLoader) {
                        btnLoader.style.display = 'none';
                    }
                    if (btnText) {
                        btnText.style.display = 'inline-block';
                    }
                    submitBtn.disabled = false;
                }
            });

            return false;
        };

        var showFieldError = function (input, message) {
            clearFieldError(input); 
            var errorSpan = document.createElement('span');
            errorSpan.className = 'tpae-field-error';
            errorSpan.style.color = 'red';
            errorSpan.textContent = message;
            input.parentElement.appendChild(errorSpan);
        };

        var clearFieldError = function (input) {
            var existingError = input.parentElement.querySelector('.tpae-field-error');
            if (existingError) existingError.remove();
        };

        var clearMessages = function () {
            form.querySelectorAll('.tpae-form-messages').forEach(function (message) {
                message.remove();
            });
            form.querySelectorAll('.tpae-field-error').forEach(function (error) {
                error.remove();
            });
        };

        var displayMessage = function (message, type) {
            type = type || 'success';
            clearMessages();
            var messageDiv = document.createElement('div');
            messageDiv.className = 'tpae-form-message ' + type;
            messageDiv.style.color = type === 'success' ? 'green' : 'red';
            messageDiv.textContent = message;
            form.appendChild(messageDiv);
        };

        function setFormFieldWidth() {
            var formFields = container.querySelectorAll(".tpae-form-field");
                formFields.forEach(el => {
                    let tabletWidth = el.getAttribute("data-tablet-width"),
                        mobileWidth = el.getAttribute("data-mobile-width"),
                        desktopWidth = el.getAttribute("data-width");

                    if (window.innerWidth < 768) {
                        el.style.width = mobileWidth + "%";
                    } else if (window.innerWidth <= 1024) {
                        el.style.width = tabletWidth + "%";
                    } else {
                        el.style.width = desktopWidth + "%";
                    }
                });
        }

        setFormFieldWidth();
        window.addEventListener('resize', setFormFieldWidth);
    };

    window.addEventListener('elementor/frontend/init', function () {
        elementorFrontend.hooks.addAction('frontend/element_ready/tp-plus-form.default', WidgetFormHandler);
    });
})(jQuery);