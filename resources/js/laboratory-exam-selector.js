export default function laboratoryExamSelector(config = {}) {
    return {
        query: "",
        results: [],
        selected: Array.isArray(config.initial) ? config.initial : [],
        searching: false,
        open: false,

        async search() {
            const term = this.query.trim();
            if (term.length < 2 || !config.searchUrl) {
                this.results = [];
                this.open = false;
                return;
            }

            this.searching = true;
            try {
                const response = await window.axios.get(config.searchUrl, {
                    params: { q: term },
                });
                this.results = response.data.data.filter(
                    (exam) => !this.selected.some((item) => item.id === exam.id),
                );
                this.open = true;
            } finally {
                this.searching = false;
            }
        },

        add(exam) {
            if (this.selected.length >= 30) return;
            if (!this.selected.some((item) => item.id === exam.id)) {
                this.selected.push(exam);
            }
            this.query = "";
            this.results = [];
            this.open = false;
        },

        remove(index) {
            this.selected.splice(index, 1);
        },
    };
}
