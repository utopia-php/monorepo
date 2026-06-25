import http from 'k6/http';
import { check } from 'k6';

const BASE_URL = __ENV.BASE_URL || 'http://localhost:9501';

export const options = {
    scenarios: {
        warmup: {
            executor: 'constant-vus',
            vus: 50,
            duration: '10s',
            gracefulStop: '0s',
            tags: { phase: 'warmup' },
            exec: 'hit',
        },
        load: {
            executor: 'constant-vus',
            vus: 200,
            duration: '60s',
            startTime: '10s',
            gracefulStop: '5s',
            tags: { phase: 'load' },
            exec: 'hit',
        },
    },
    thresholds: {
        'http_req_failed{phase:load}': ['rate<0.01'],
    },
};

export function hit() {
    const res = http.get(`${BASE_URL}/work`, { tags: { route: '/work' } });
    check(res, { 'status is 2xx': (r) => r.status >= 200 && r.status < 300 });
}
