import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
    static targets = ["input", "item", "emptyState"];

    connect() {
    }

    filter(event) {
        const query = event.currentTarget.value.toLowerCase().trim();
        let visibleCount = 0;

        this.itemTargets.forEach((item) => {
            const name = item.dataset.name ? item.dataset.name.toLowerCase() : "";
            if (name.includes(query)) {
                item.classList.remove("hidden");
                visibleCount++;
            } else {
                item.classList.add("hidden");
            }
        });

        if (this.hasEmptyStateTarget) {
            if (visibleCount === 0 && query !== "") {
                this.emptyStateTarget.classList.remove("hidden");
            } else {
                this.emptyStateTarget.classList.add("hidden");
            }
        }
    }
}
