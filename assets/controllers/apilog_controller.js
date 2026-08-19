import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['modalBackdrop', 'service', 'meta', 'status', 'methodUrl', 'request', 'response'];

    open(event) {
        const row = event.currentTarget;
        const payload = JSON.parse(row.dataset.apilogPayload);

        this.serviceTarget.textContent = '— ' + (payload.service === 'cyclos' ? 'Cyclos' : 'HelloAsso');
        this.metaTarget.textContent = payload.date + ' · ' + payload.actor + ' · ' + payload.summary;
        this.methodUrlTarget.textContent = payload.method + ' ' + payload.url;

        const status = payload.status;
        this.statusTarget.textContent = status || 'échec réseau';
        this.statusTarget.className = 'badge ' + (status >= 200 && status < 300 ? 'badge--success' : 'badge--fail');

        this.requestTarget.textContent = this.pretty(payload.request);
        this.responseTarget.textContent = this.pretty(payload.response);

        this.modalBackdropTarget.classList.add('is-open');
    }

    pretty(raw) {
        if (!raw) {
            return '(vide)';
        }
        try {
            return JSON.stringify(JSON.parse(raw), null, 2);
        } catch {
            return raw;
        }
    }

    close() {
        this.modalBackdropTarget.classList.remove('is-open');
    }

    closeOnBackdrop(event) {
        if (event.target === this.modalBackdropTarget) {
            this.close();
        }
    }

    closeOnEscape(event) {
        if (event.key === 'Escape') {
            this.close();
        }
    }
}
