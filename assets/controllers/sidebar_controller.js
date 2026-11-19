import { Controller } from '@hotwired/stimulus';

/**
 * Mobile sidebar drawer: toggles an off-canvas sidebar open/closed below the
 * responsive breakpoint, with a backdrop to close on outside click.
 *
 * Also remembers the scroll position of the menu between page loads, so the
 * sidebar doesn't jump back to the top each time a link is followed.
 */
export default class extends Controller {
    static targets = ['sidebar', 'backdrop'];

    connect() {
        this.nav = this.sidebarTarget.querySelector('.sidebar__nav');
        if (!this.nav) {
            return;
        }
        try {
            const saved = parseInt(sessionStorage.getItem('cyllos.sidebarScroll') ?? '', 10);
            if (!Number.isNaN(saved)) {
                this.nav.scrollTop = saved;
            }
        } catch (e) { /* storage unavailable: harmless */ }

        this.saveScroll = () => {
            try {
                sessionStorage.setItem('cyllos.sidebarScroll', String(this.nav.scrollTop));
            } catch (e) { /* storage unavailable: harmless */ }
        };
        window.addEventListener('pagehide', this.saveScroll);
    }

    disconnect() {
        if (this.saveScroll) {
            window.removeEventListener('pagehide', this.saveScroll);
        }
    }

    toggle() {
        this.sidebarTarget.classList.toggle('is-open');
        this.backdropTarget.classList.toggle('is-open');
    }

    close() {
        this.sidebarTarget.classList.remove('is-open');
        this.backdropTarget.classList.remove('is-open');
    }
}
