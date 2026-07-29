export default function dashboard(config) {
    return {
        metrics: config.metrics,
        encounters: config.encounters,
        updatedAt: new Date().toLocaleTimeString("pt-BR"),
        disconnected: false,
        timer: null,

        init() {
            this.timer = window.setInterval(() => {
                if (!document.hidden) this.refresh();
            }, 10000);
        },

        async refresh() {
            try {
                const [metrics, encounters] = await Promise.all([
                    window.axios.get(config.metricsUrl),
                    window.axios.get(config.encountersUrl),
                ]);
                this.metrics = metrics.data.data;
                this.encounters = encounters.data.data;
                this.updatedAt = new Date().toLocaleTimeString("pt-BR");
                this.disconnected = false;
            } catch {
                this.disconnected = true;
            }
        },
    };
}
