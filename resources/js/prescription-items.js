const emptyItem = () => ({
    medication_name: "",
    presentation: "",
    concentration: "",
    dose: "",
    dose_unit: "",
    route: "",
    frequency: "",
    instructions: "",
});

export default function prescriptionItems(config = {}) {
    let nextKey = 1;
    const providedItems = Array.isArray(config.initialItems)
        ? config.initialItems.slice(0, 30)
        : [];
    const items = (
        providedItems.length > 0 ? providedItems : [emptyItem()]
    ).map((item) => ({ ...emptyItem(), ...item, _key: nextKey++ }));

    return {
        items,
        records: config.records || {},
        prescriptionType: config.initialType || "hospital",
        generalInstructions: config.initialInstructions || "",
        editingId: config.editingId || "",
        replacementReason: config.replacementReason || "",

        addItem() {
            if (this.items.length >= 30) return;
            this.items.push({ ...emptyItem(), _key: nextKey++ });
        },

        removeItem(index) {
            if (this.items.length === 1) return;
            this.items.splice(index, 1);
        },

        startEditing(publicId) {
            const record = this.records[publicId];
            if (!record) return;

            this.editingId = publicId;
            this.replacementReason = "";
            this.prescriptionType = record.prescription_type;
            this.generalInstructions = record.general_instructions || "";
            this.items = record.items.map((item) => ({
                ...emptyItem(),
                ...item,
                _key: nextKey++,
            }));
            document
                .getElementById("prescription_form")
                ?.scrollIntoView({ behavior: "smooth", block: "start" });
        },

        cancelEditing() {
            this.editingId = "";
            this.replacementReason = "";
            this.prescriptionType = "hospital";
            this.generalInstructions = "";
            this.items = [{ ...emptyItem(), _key: nextKey++ }];
        },
    };
}
