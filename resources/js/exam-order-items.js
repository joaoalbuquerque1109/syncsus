const emptyItem = () => ({
    exam_name: "",
    group: "laboratory",
    internal_code: "",
    laterality: "not_applicable",
    preparation: "",
    justification: "",
});

export default function examOrderItems(initialItems = []) {
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
    };
}
