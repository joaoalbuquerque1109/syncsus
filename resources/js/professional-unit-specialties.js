export default function professionalUnitSpecialties({
    units,
    specialties,
    selectedUnitIds,
    selectedSpecialtyIds,
    initialUnitSpecialties,
}) {
    return {
        units,
        specialties,
        unitIds: selectedUnitIds.map(String),
        specialtyIds: selectedSpecialtyIds.map(String),
        unitSpecialties: Object.fromEntries(
            units.map((unit) => [
                String(unit.id),
                (initialUnitSpecialties[unit.id] || []).map(String),
            ]),
        ),

        isUnitAuthorized(unitId) {
            return this.unitIds.includes(String(unitId));
        },

        availableSpecialtiesFor() {
            return this.specialties.filter((specialty) =>
                this.specialtyIds.includes(String(specialty.id)),
            );
        },

        hasUnitSpecialty(unitId, specialtyId) {
            return (this.unitSpecialties[String(unitId)] || []).includes(
                String(specialtyId),
            );
        },

        toggleUnitSpecialty(unitId, specialtyId, checked) {
            const key = String(unitId);
            const id = String(specialtyId);
            const current = this.unitSpecialties[key] || [];
            this.unitSpecialties[key] = checked
                ? [...new Set([...current, id])]
                : current.filter((existing) => existing !== id);
        },

        onGlobalSpecialtyChange() {
            for (const unitId of Object.keys(this.unitSpecialties)) {
                this.unitSpecialties[unitId] = this.unitSpecialties[
                    unitId
                ].filter((id) => this.specialtyIds.includes(id));
            }
        },
    };
}
