(function() {
    'use strict';

    document.addEventListener('DOMContentLoaded', function() {
        const forms = document.querySelectorAll('.tranas-form');

        forms.forEach(function(form) {
            // Förhindra att event-listeners läggs till flera gånger
            if (form.getAttribute('data-tf-initialized') === 'true') {
                return;
            }
            form.setAttribute('data-tf-initialized', 'true');
            
            form.addEventListener('submit', handleSubmit);
            
            // Lägg till filvalidering för fil-fält
            const fileInputs = form.querySelectorAll('input[type="file"]');
            fileInputs.forEach(function(input) {
                input.addEventListener('change', validateFileInput);
            });
        });
    });

    function validateFileInput(e) {
        const input = e.target;
        const file = input.files[0];
        const maxSizeMB = parseInt(input.getAttribute('data-max-size') || '5', 10);
        const maxSizeBytes = maxSizeMB * 1024 * 1024;
        const field = input.closest('.tf-field');
        
        // Ta bort tidigare felmeddelande
        const existingError = field.querySelector('.tf-file-error');
        if (existingError) {
            existingError.remove();
        }
        input.removeAttribute('aria-invalid');

        if (file && file.size > maxSizeBytes) {
            const errorEl = document.createElement('div');
            errorEl.className = 'tf-file-error tf-message tf-message--error';
            errorEl.style.marginTop = '5px';
            errorEl.style.fontSize = '13px';
            errorEl.textContent = 'Filen är för stor. Max storlek är ' + maxSizeMB + ' MB. Din fil är ' + (file.size / 1024 / 1024).toFixed(1) + ' MB.';
            input.setAttribute('aria-invalid', 'true');
            input.parentNode.insertBefore(errorEl, input.nextSibling);
            
            // Rensa fältet
            input.value = '';
        }
    }

    function handleSubmit(e) {
        e.preventDefault();
        e.stopImmediatePropagation();

        const form = e.target;
        
        // Förhindra dubbla inskick
        if (form.getAttribute('data-submitting') === 'true') {
            return;
        }
        form.setAttribute('data-submitting', 'true');
        
        const submitBtn = form.querySelector('.tf-submit');
        const submitText = submitBtn.querySelector('.tf-submit-text');
        const submitLoading = submitBtn.querySelector('.tf-submit-loading');
        const messageContainer = form.querySelector('.tf-message-container');
        
        // Rensa tidigare meddelanden och felmarkeringar
        messageContainer.innerHTML = '';
        messageContainer.className = 'tf-message-container';
        form.querySelectorAll('[aria-invalid]').forEach(function(el) {
            el.removeAttribute('aria-invalid');
        });

        // Visa laddningsläge
        submitBtn.disabled = true;
        submitBtn.setAttribute('aria-busy', 'true');
        if (submitText) submitText.style.display = 'none';
        if (submitLoading) submitLoading.style.display = 'inline';

        // Samla formulärdata
        const formData = new FormData(form);
        formData.append('action', 'tranas_form_submit');

        fetch(tranasFormsAjax.ajaxurl, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        })
        .then(function(response) {
            return response.json();
        })
        .then(function(data) {
            // Skapa meddelande
            const msgEl = document.createElement('div');
            msgEl.className = 'tf-message ' + (data.success ? 'tf-message--success' : 'tf-message--error');
            msgEl.setAttribute('role', 'status');
            msgEl.textContent = data.data.message;

            // Lägg till i containern
            messageContainer.appendChild(msgEl);

            if (data.success) {
                // Rensa formuläret vid lyckat inskick
                form.reset();
                
                // Flytta fokus till meddelandet för skärmläsare
                msgEl.setAttribute('tabindex', '-1');
                msgEl.focus();
            } else {
                // Markera första fältet med fel om möjligt
                const firstInput = form.querySelector('input:not([type="hidden"]), textarea, select');
                if (firstInput) {
                    firstInput.setAttribute('aria-invalid', 'true');
                    firstInput.focus();
                }
            }

            // Återställ knappen och tillåt nya inskick
            resetButton(submitBtn, submitText, submitLoading);
            form.removeAttribute('data-submitting');

            // Scrolla till meddelandet
            msgEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        })
        .catch(function(error) {
            console.error('Formulärfel:', error);
            
            const msgEl = document.createElement('div');
            msgEl.className = 'tf-message tf-message--error';
            msgEl.setAttribute('role', 'alert');
            msgEl.textContent = 'Ett fel uppstod. Försök igen.';
            messageContainer.appendChild(msgEl);

            resetButton(submitBtn, submitText, submitLoading);
            form.removeAttribute('data-submitting');
        });
    }

    function resetButton(btn, textEl, loadingEl) {
        btn.disabled = false;
        btn.removeAttribute('aria-busy');
        if (textEl) textEl.style.display = 'inline';
        if (loadingEl) loadingEl.style.display = 'none';
    }
})();
