import { Controller } from '@hotwired/stimulus';

/**
 * Highlights the sidebar TOC entry matching whichever docs-section is
 * currently most visible in the scrolling content pane, using
 * IntersectionObserver rather than scroll-position math (cheaper, no
 * per-frame scroll listener needed).
 */
export default class extends Controller {
    static targets = ['link', 'section'];

    connect() {
        this.observer = new IntersectionObserver(
            (entries) => this.onIntersect(entries),
            {
                root: this.element.closest('.content'),
                rootMargin: '-10% 0px -70% 0px',
                threshold: 0,
            },
        );

        this.sectionTargets.forEach((section) => this.observer.observe(section));
    }

    disconnect() {
        this.observer?.disconnect();
    }

    onIntersect(entries) {
        const visible = entries.filter((entry) => entry.isIntersecting);
        if (visible.length === 0) {
            return;
        }

        const topMost = visible.reduce((a, b) => (a.boundingClientRect.top < b.boundingClientRect.top ? a : b));
        this.activate(topMost.target.id);
    }

    activate(id) {
        this.linkTargets.forEach((link) => {
            link.classList.toggle('is-active', link.getAttribute('href') === '#' + id);
        });
    }
}
