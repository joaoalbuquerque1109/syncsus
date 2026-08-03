const emptyItem = () => ({
    laboratory_exam_id: "",
    exam_name: "",
    group: "laboratory",
    internal_code: "",
    laterality: "not_applicable",
    preparation: "",
    justification: "",
    catalogResults: [],
    catalogOpen: false,
    catalogSearching: false,
});

export default function examOrderItems(initialItems = [], config = {}) {
    let nextKey = 1;
    const providedItems = Array.isArray(initialItems)
        ? initialItems.slice(0, 30)
        : [];

    return {
        items: (providedItems.length > 0 ? providedItems : [emptyItem()]).map(
            (item) => ({ ...emptyItem(), ...item, _key: nextKey++ }),
        ),

        addItem() {
            if (this.items.length >= 30) return;
            this.items.push({ ...emptyItem(), _key: nextKey++ });
        },

        removeItem(index) {
            if (this.items.length === 1) return;
            this.items.splice(index, 1);
        },

        clearCatalogSelection(item) {
            item.laboratory_exam_id = "";
            item.internal_code = "";
        },

        groupChanged(item) {
            if (item.group !== "laboratory") {
                this.clearCatalogSelection(item);
                item.catalogResults = [];
                item.catalogOpen = false;
            }
        },

        async searchCatalog(index) {
            const item = this.items[index];
            const term = item.exam_name.trim();
            this.clearCatalogSelection(item);

            if (item.group !== "laboratory" || term.length < 2 || !config.searchUrl) {
                item.catalogResults = [];
                item.catalogOpen = false;
                return;
            }

            item.catalogSearching = true;
            try {
                const response = await window.axios.get(config.searchUrl, {
                    params: { q: term },
                });
                item.catalogResults = response.data.data;
                item.catalogOpen = true;
            } finally {
                item.catalogSearching = false;
            }
        },

        selectCatalogExam(item, exam) {
            item.laboratory_exam_id = exam.id;
            item.exam_name = exam.name;
            item.internal_code = exam.code;
            item.preparation = exam.preparation || "";
            item.catalogResults = [];
            item.catalogOpen = false;
        },
    };
}
