export default function cidSearch(config) {
    return {
        query: config.selectedLabel || "",
        selectedId: config.selectedId || "",
        selectedLabel: config.selectedLabel || "",
        results: [],
        searching: false,
        open: false,

        async search() {
            const term = this.query.trim();

            if (this.selectedId && term === this.selectedLabel) {
                return;
            }

            this.selectedId = "";
            this.selectedLabel = "";

            if (term.length < 2) {
                this.results = [];
                this.open = false;
                return;
            }

            this.searching = true;
            try {
                const response = await window.axios.get(config.searchUrl, {
                    params: { q: term },
                });
                this.results = response.data.data;
                this.open = true;
            } finally {
                this.searching = false;
            }
        },

        select(item) {
            this.selectedId = item.id;
            this.selectedLabel = item.label;
            this.query = item.label;
            this.results = [];
            this.open = false;
        },

        clear() {
            this.query = "";
            this.selectedId = "";
            this.selectedLabel = "";
            this.results = [];
            this.open = false;
        },
    };
}
