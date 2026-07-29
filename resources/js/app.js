import "./bootstrap";
import Alpine from "alpinejs";
import receptionWizard from "./reception-wizard";
import queueBoard from "./queue-board";
import publicPanel from "./public-panel";
import dashboard from "./dashboard";

window.Alpine = Alpine;

Alpine.data("receptionWizard", receptionWizard);
Alpine.data("queueBoard", queueBoard);
Alpine.data("publicPanel", publicPanel);
Alpine.data("dashboard", dashboard);
Alpine.start();
