import { Controller } from '@hotwired/stimulus';

/**
 * Global delete-confirmation modal. Any <form data-confirm-message="..."> on
 * the page is intercepted on submit and only actually submitted once the
 * user confirms in the modal, replacing native confirm() dialogs.
 */
export default class extends Controller {
    static targets = ['backdrop', 'title', 'message', 'confirmBtn'];

    pendingForm = null;

    intercept(event) {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-confirm-message')) {
            return;
        }
        if (form.dataset.confirmed === 'true') {
            delete form.dataset.confirmed;
            return;
        }

        event.preventDefault();
        this.pendingForm = form;
        this.titleTarget.textContent = form.dataset.confirmTitle || 'Confirmer la suppression';
        this.messageTarget.textContent = form.dataset.confirmMessage;
        this.confirmBtnTarget.textContent = form.dataset.confirmConfirmLabel || 'Supprimer';
        this.backdropTarget.classList.add('is-open');
        this.confirmBtnTarget.focus();
    }

    proceed() {
        if (this.pendingForm) {
            this.pendingForm.dataset.confirmed = 'true';
            this.pendingForm.requestSubmit();
        }
        this.close();
    }

    cancel() {
        this.close();
    }

    closeOnBackdrop(event) {
        if (event.target === this.backdropTarget) {
            this.close();
        }
    }

    closeOnEscape(event) {
        if (event.key === 'Escape') {
            this.close();
        }
    }

    close() {
        this.backdropTarget.classList.remove('is-open');
        this.pendingForm = null;
    }
}
