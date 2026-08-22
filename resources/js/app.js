import "./bootstrap";
import Alpine from "alpinejs";
import receptionWizard from "./reception-wizard";
import queueBoard from "./queue-board";
import publicPanel from "./public-panel";
import dashboard from "./dashboard";
import cidSearch from "./cid-search";
import prescriptionItems from "./prescription-items";
import examOrderItems from "./exam-order-items";
import laboratoryExamSelector from "./laboratory-exam-selector";
import examGroupItems from "./exam-group-items";
import examRequestModal from "./exam-request-modal";

window.Alpine = Alpine;

Alpine.data("receptionWizard", receptionWizard);
Alpine.data("queueBoard", queueBoard);
Alpine.data("publicPanel", publicPanel);
Alpine.data("dashboard", dashboard);
Alpine.data("cidSearch", cidSearch);
Alpine.data("prescriptionItems", prescriptionItems);
Alpine.data("examOrderItems", examOrderItems);
Alpine.data("laboratoryExamSelector", laboratoryExamSelector);
Alpine.data("examGroupItems", examGroupItems);
Alpine.data("examRequestModal", examRequestModal);
Alpine.start();
