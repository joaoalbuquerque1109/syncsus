export default function examGroupItems(config = {}) {
    let nextKey = 0;
    const maxItems = Number(config.maxItems ?? 50);

    return {
        items: (config.initialItems ?? []).map((item) => ({
            id: Number(item.id),
            label: String(item.label),
            key: nextKey++,
        })),
        query: "",
        results: [],
        searching: false,
        resultsOpen: false,

        async searchExams() {
            const term = this.query.trim();
            if (term.length < 2) {
                this.results = [];
                this.resultsOpen = false;
                return;
            }

            this.searching = true;
            try {
                const response = await window.axios.get(config.searchUrl, {
                    params: { q: term },
                });
                const selectedIds = new Set(this.items.map((item) => item.id));
                this.results = (response.data.data ?? []).filter(
                    (exam) => !selectedIds.has(Number(exam.id)),
                );
                this.resultsOpen = true;
            } catch {
                this.results = [];
                this.resultsOpen = true;
            } finally {
                this.searching = false;
            }
        },

        addExam(exam) {
            const id = Number(exam.id);
            if (
                this.items.length >= maxItems ||
                this.items.some((item) => item.id === id)
            ) {
                return;
            }

            this.items.push({ id, label: String(exam.label), key: nextKey++ });
            this.query = "";
            this.results = [];
            this.resultsOpen = false;
        },

        removeExam(index) {
            this.items.splice(index, 1);
        },
    };
}
