import { Controller } from '@hotwired/stimulus';

/**
 * Live terminal for an update: polls the log endpoint and appends the new
 * lines as they are written, colouring them by their marker (command, step,
 * success, failure). Stops polling once the update is over.
 */
export default class extends Controller {
    static targets = ['screen', 'status', 'cursor'];
    static values = { url: String, running: Boolean };

    connect() {
        this.offset = 0;
        this.pending = '';
        this.follow = true;
        this.stopped = false;

        this.onScroll = () => {
            const s = this.screenTarget;
            this.follow = s.scrollHeight - s.scrollTop - s.clientHeight < 40;
        };
        this.screenTarget.addEventListener('scroll', this.onScroll);

        this.poll();
    }

    disconnect() {
        this.stopped = true;
        clearTimeout(this.timer);
        this.screenTarget.removeEventListener('scroll', this.onScroll);
    }

    async poll() {
        if (this.stopped) {
            return;
        }

        let running = true;
        try {
            const response = await fetch(`${this.urlValue}?offset=${this.offset}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            if (response.ok) {
                const data = await response.json();
                this.offset = data.offset;
                this.append(data.chunk, !data.running);
                running = data.running;
                this.updateStatus(data);
            }
        } catch (e) {
            /* network hiccup: try again */
        }

        if (running) {
            this.timer = setTimeout(() => this.poll(), 1000);
        } else {
            this.cursorTarget.hidden = true;
        }
    }

    append(chunk, final) {
        this.pending += chunk;
        const lines = this.pending.split('\n');
        this.pending = final ? '' : lines.pop();
        if (final && lines.length && lines[lines.length - 1] === '') {
            lines.pop();
        }

        for (const text of lines) {
            const line = document.createElement('div');
            line.className = 'terminal__line ' + this.classFor(text);
            line.textContent = text === '' ? ' ' : text;
            this.screenTarget.insertBefore(line, this.cursorTarget);
        }

        if (lines.length && this.follow) {
            this.screenTarget.scrollTop = this.screenTarget.scrollHeight;
        }
    }

    classFor(text) {
        if (text.startsWith('$ ')) return 'terminal__line--cmd';
        if (text.startsWith('▶')) return 'terminal__line--step';
        if (text.startsWith('✔')) return 'terminal__line--ok';
        if (text.startsWith('✘')) return 'terminal__line--fail';
        if (text.startsWith('⚠')) return 'terminal__line--warn';
        if (text.startsWith('═══')) return 'terminal__line--banner';
        return '';
    }

    updateStatus(data) {
        if (!this.hasStatusTarget) {
            return;
        }
        this.statusTarget.className = 'badge ' + data.badge;
        this.statusTarget.textContent = data.statusLabel;
        if (!data.running && this.runningValue) {
            this.runningValue = false;
            // Refresh once so the summary (commits, durations, backups) is complete.
            setTimeout(() => window.location.reload(), 900);
        }
    }
}
