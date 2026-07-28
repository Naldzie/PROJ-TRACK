/**
 * Field Validation Module
 * Generic debounced AJAX field validator factory with UI feedback
 * Used for project code and title uniqueness validation
 */

/**
 * Create a debounced field validator with AJAX checking
 * 
 * @param {object} config - Configuration object
 *   - fieldId: ID of input field
 *   - feedbackId: ID of feedback div
 *   - fieldName: Name of field for request body
 *   - validationEndpoint: URL for AJAX validation
 *   - successMessage: Message shown on success (default: 'Available')
 *   - getExtraData: Optional function returning extra data for request body
 * 
 * @returns {object} Validator object with methods:
 *   - validate(): Debounced validation (500ms delay)
 *   - flush(): Immediate validation (no debounce)
 *   - showValidating(): Show loading state
 *   - showValid(): Show success state
 *   - showInvalid(message): Show error state
 *   - showError(message): Show API error
 *   - clear(): Clear all feedback
 *   - isPending(): Check if validation in progress
 */
function createFieldValidator(config) {
    let validationTimeout;
    let isValidationPending = false;
    
    const field = document.getElementById(config.fieldId);
    const feedback = document.getElementById(config.feedbackId);
    
    if (!field || !feedback) {
        console.warn(`Validator initialization failed: field="${config.fieldId}", feedback="${config.feedbackId}"`);
    }
    
    return {
        // ===================================================
        // DEBOUNCED VALIDATION (500ms delay)
        // ===================================================
        validate() {
            clearTimeout(validationTimeout);
            const value = field?.value?.trim();
            
            // If empty, clear feedback
            if (!value) {
                this.clear();
                return;
            }
            
            isValidationPending = true;
            this.showValidating();
            
            // Debounce: wait 500ms after user stops typing before making API call
            validationTimeout = setTimeout(() => {
                this._performValidation();
            }, 500);
        },
        
        // ===================================================
        // IMMEDIATE VALIDATION (no debounce - for form submit)
        // ===================================================
        flush() {
            clearTimeout(validationTimeout);
            const value = field?.value?.trim();
            
            // If empty, clear and return
            if (!value) {
                this.clear();
                isValidationPending = false;
                return;
            }
            
            isValidationPending = true;
            this._performValidation();
        },
        
        // ===================================================
        // INTERNAL: Perform the AJAX validation
        // ===================================================
        _performValidation() {
            const value = field?.value?.trim();
            const body = {
                [config.fieldName]: value
            };
            
            // Add extra data (like project_id for edit mode)
            if (config.getExtraData) {
                Object.assign(body, config.getExtraData());
            }
            
            // Get CSRF token
            const csrfToken = document.querySelector('input[name="_token"]')?.value ||
                            document.querySelector('meta[name="csrf-token"]')?.content;
            
            fetch(config.validationEndpoint, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': csrfToken,
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify(body)
            })
            .then(response => {
                // Handle both success (200) and validation error (422) responses
                return response.json().then(data => ({ status: response.status, data }));
            })
            .then(({ status, data }) => {
                isValidationPending = false;
                if (data.is_unique) {
                    this.showValid(value);
                } else {
                    this.showInvalid(data.message || 'This value is not available');
                }
            })
            .catch(error => {
                isValidationPending = false;
                console.error('Validation error:', error);
                this.showError('Unable to validate');
            });
        },
        
        // ===================================================
        // UI FEEDBACK METHODS
        // ===================================================
        
        /**
         * Show loading state while checking
         */
        showValidating() {
            if (feedback) {
                feedback.innerHTML = '<small class="text-info"><i class="bi bi-hourglass-split"></i> Checking...</small>';
                feedback.style.display = 'block';
            }
        },
        
        /**
         * Show success state (field value is valid/available)
         */
        showValid(value) {
            if (feedback) {
                const message = config.successMessage || 'Available';
                feedback.innerHTML = `<small class="text-success"><i class="bi bi-check-circle"></i> ${message}</small>`;
                feedback.style.display = 'block';
            }
            field?.classList.remove('is-invalid');
            field?.classList.add('is-valid');
        },
        
        /**
         * Show error state (field value already exists / invalid)
         */
        showInvalid(message) {
            if (feedback) {
                feedback.innerHTML = `<small class="text-danger"><i class="bi bi-exclamation-circle"></i> ${message}</small>`;
                feedback.style.display = 'block';
            }
            field?.classList.remove('is-valid');
            field?.classList.add('is-invalid');
        },
        
        /**
         * Show API error state (server error)
         */
        showError(message) {
            if (feedback) {
                feedback.innerHTML = `<small class="text-warning"><i class="bi bi-exclamation-triangle"></i> ${message}</small>`;
                feedback.style.display = 'block';
            }
        },
        
        /**
         * Clear all feedback and input styling
         */
        clear() {
            if (feedback) {
                feedback.innerHTML = '';
                feedback.style.display = 'none';
            }
            field?.classList.remove('is-valid', 'is-invalid');
        },
        
        /**
         * Check if validation is currently pending
         */
        isPending() {
            return isValidationPending;
        }
    };
}
