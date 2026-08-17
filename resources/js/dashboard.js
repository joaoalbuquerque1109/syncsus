export default function dashboard(config) {
    return {
        metrics: config.metrics,
        encounters: config.encounters,
        updatedAt: new Date().toLocaleTimeString("pt-BR"),
        disconnected: false,
        timer: null,
        refreshing: false,

        init() {
            this.schedule();
        },

        destroy() {
            window.clearTimeout(this.timer);
        },

        schedule() {
            window.clearTimeout(this.timer);
            const baseDelay = Math.max(5000, Number(config.pollMs) || 15000);
            const jitter = Math.floor(Math.random() * 2000);
            this.timer = window.setTimeout(async () => {
                if (!document.hidden) await this.refresh();
                this.schedule();
            }, baseDelay + jitter);
        },

        async refresh() {
            if (this.refreshing) return;
            this.refreshing = true;
            try {
                const response = await window.axios.get(config.stateUrl);
                this.metrics = response.data.data.metrics;
                this.encounters = response.data.data.active_encounters;
                this.updatedAt = new Date().toLocaleTimeString("pt-BR");
                this.disconnected = false;
            } catch {
                this.disconnected = true;
            } finally {
                this.refreshing = false;
            }
        },
    };
}
