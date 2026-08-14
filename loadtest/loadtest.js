import http from 'k6/http';
import { check, sleep } from 'k6';
import { Rate, Trend } from 'k6/metrics';

const BASE_URL = __ENV.BASE_URL || 'http://nginx:80';
const PANEL_CODE = __ENV.PANEL_CODE;
const STAFF_EMAIL = __ENV.STAFF_EMAIL || 'recepcao@syncsus.local';
const STAFF_PASSWORD = __ENV.STAFF_PASSWORD || 'Demo#SyncSUS2026';

export const errorRate = new Rate('errors');
export const panelDuration = new Trend('panel_state_duration');
export const staffDuration = new Trend('staff_read_duration');

export const options = {
    scenarios: {
        panel_polling: {
            executor: 'ramping-vus',
            exec: 'panelPolling',
            startVUs: 0,
            stages: [
                { duration: '30s', target: 20 },
                { duration: '30s', target: 60 },
                { duration: '30s', target: 120 },
                { duration: '30s', target: 200 },
                { duration: '30s', target: 300 },
                { duration: '60s', target: 300 },
                { duration: '20s', target: 0 },
            ],
            gracefulRampDown: '10s',
        },
        staff_reading: {
            executor: 'ramping-vus',
            exec: 'staffReading',
            startVUs: 0,
            stages: [
                { duration: '30s', target: 5 },
                { duration: '30s', target: 15 },
                { duration: '30s', target: 30 },
                { duration: '30s', target: 50 },
                { duration: '30s', target: 70 },
                { duration: '60s', target: 70 },
                { duration: '20s', target: 0 },
            ],
            gracefulRampDown: '10s',
        },
    },
    thresholds: {
        http_req_failed: ['rate<0.05'],
    },
};

export function panelPolling() {
    const res = http.get(`${BASE_URL}/panels/${PANEL_CODE}/state`, {
        tags: { name: 'panel_state' },
    });
    panelDuration.add(res.timings.duration);
    const ok = check(res, { 'panel state 200': (r) => r.status === 200 });
    errorRate.add(!ok);
    sleep(2 + Math.random());
}

export function staffReading() {
    const loginPage = http.get(`${BASE_URL}/login`, { tags: { name: 'login_page' } });
    const tokenMatch = loginPage.body && loginPage.body.match(/name="_token"\s+value="([^"]+)"/);
    if (!tokenMatch) {
        errorRate.add(1);
        sleep(2);
        return;
    }
    const token = tokenMatch[1];

    const loginRes = http.post(
        `${BASE_URL}/login`,
        { email: STAFF_EMAIL, password: STAFF_PASSWORD, _token: token },
        { tags: { name: 'login_post' }, redirects: 3 },
    );
    const loggedIn = check(loginRes, {
        'login redirected or ok': (r) => r.status === 200 || r.status === 302,
    });
    errorRate.add(!loggedIn);

    const dashboard = http.get(`${BASE_URL}/dashboard`, { tags: { name: 'dashboard' } });
    staffDuration.add(dashboard.timings.duration);
    errorRate.add(!check(dashboard, { 'dashboard 200': (r) => r.status === 200 }));

    const dashboardState = http.get(`${BASE_URL}/dashboard/state`, { tags: { name: 'dashboard_state' } });
    staffDuration.add(dashboardState.timings.duration);
    errorRate.add(!check(dashboardState, { 'dashboard state 200': (r) => r.status === 200 }));

    const queues = http.get(`${BASE_URL}/queues`, { tags: { name: 'queues_index' } });
    staffDuration.add(queues.timings.duration);
    errorRate.add(!check(queues, { 'queues 200': (r) => r.status === 200 }));

    sleep(3 + Math.random() * 2);
}
