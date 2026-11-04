import { check, sleep } from 'k6';
import http from 'k6/http';
import { SharedArray } from 'k6/data';

if (!__ENV.USERS_CSV) {
    throw new Error('Set USERS_CSV to a private CSV with at least 700 unique student accounts.');
}

const users = new SharedArray('exam users', () => {
    const lines = open(__ENV.USERS_CSV).trim().split(/\r?\n/);
    return lines.slice(1).map((line) => {
        const [username, password] = line.split(',');
        return { username, password };
    });
});
if (users.length < 700 || new Set(users.map((user) => user.username)).size < 700) {
    throw new Error('USERS_CSV must contain at least 700 unique student rows.');
}

const baseUrl = (__ENV.BASE_URL || '').replace(/\/$/, '');
const api = `${baseUrl}/api/v1`;
const jsonHeaders = (token, route) => ({
    headers: {
        Authorization: `Bearer ${token}`,
        'Content-Type': 'application/json',
        Accept: 'application/json',
    },
    tags: { route },
});

export const options = {
    scenarios: {
        exam_load: {
            executor: 'per-vu-iterations',
            vus: 700,
            iterations: 1,
            maxDuration: __ENV.MAX_DURATION || '20m',
        },
    },
    thresholds: {
        'http_req_duration{route:login}': ['p(95)<500'],
        'http_req_duration{route:join}': ['p(95)<500'],
        'http_req_duration{route:state}': ['p(95)<500'],
        'http_req_duration{route:autosave}': ['p(95)<300'],
        'http_req_duration{route:lock}': ['p(95)<300'],
        'http_req_failed': ['rate<0.01'],
    },
};

export function setup() {
    if (!baseUrl || !__ENV.EXAM_TOKEN || !__ENV.OPERATOR_USERNAME || !__ENV.OPERATOR_PASSWORD) {
        throw new Error('Set BASE_URL, EXAM_TOKEN, OPERATOR_USERNAME, and OPERATOR_PASSWORD.');
    }

    const response = http.post(
        `${api}/auth/login`,
        JSON.stringify({ username: __ENV.OPERATOR_USERNAME, password: __ENV.OPERATOR_PASSWORD }),
        { headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, tags: { route: 'operator-login' } },
    );
    if (response.status !== 200) {
        throw new Error(`Operator authentication failed with HTTP ${response.status}.`);
    }

    return { operatorToken: response.json('token') };
}

export default function (data) {
    if (__ITER > 0) {
        return;
    }

    const user = users[__VU - 1];
    const login = http.post(
        `${api}/auth/login`,
        JSON.stringify({ username: user.username, password: user.password }),
        { headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, tags: { route: 'login' } },
    );
    check(login, { 'student login succeeds': (response) => response.status === 200 });
    if (login.status !== 200) {
        return;
    }
    const studentToken = login.json('token');

    const join = http.post(
        `${api}/ujian/join`,
        JSON.stringify({ token: __ENV.EXAM_TOKEN }),
        jsonHeaders(studentToken, 'join'),
    );
    check(join, { 'student joins session': (response) => response.status === 200 });
    if (join.status !== 200) {
        return;
    }

    const participantId = join.json('data.peserta_id');
    const waitStartedUntil = Date.now() + Number(__ENV.START_WAIT_SECONDS || 300) * 1000;
    let state;
    do {
        const response = http.get(`${api}/ujian/state`, jsonHeaders(studentToken, 'state'));
        if (response.status === 200) {
            state = response.json('data');
            if (state.status === 'mengerjakan') {
                break;
            }
            if (state.status === 'selesai') {
                return;
            }
        }
        sleep(2);
    } while (Date.now() < waitStartedUntil);

    if (state?.status !== 'mengerjakan') {
        throw new Error(`Participant ${participantId} did not enter the exam before START_WAIT_SECONDS.`);
    }

    const sheet = http.get(`${api}/ujian/soal`, jsonHeaders(studentToken, 'questions'));
    check(sheet, { 'exam sheet loads': (response) => response.status === 200 });
    if (sheet.status !== 200) {
        return;
    }

    const questions = sheet.json('data');
    if (!questions.length) {
        throw new Error('The active schedule contains no questions.');
    }

    const deadline = Date.parse(state.waktu_selesai);
    if (!Number.isFinite(deadline)) {
        throw new Error('The active exam state did not return a valid server deadline.');
    }

    const finishAt = Math.max(Date.now(), deadline - Number(__ENV.SUBMIT_LEAD_SECONDS || 5) * 1000);
    let clientSeq = 1;
    let lockSent = false;
    const shouldLock = Math.random() < 0.15;
    while (Date.now() < finishAt) {
        const delay = 5 + Math.floor(Math.random() * 11);
        sleep(Math.min(delay, Math.max(1, (finishAt - Date.now()) / 1000)));

        const question = questions[Math.floor(Math.random() * questions.length)];
        const saved = http.put(
            `${api}/ujian/jawaban`,
            JSON.stringify({
                items: [{ soal_id: question.soal_id, jawaban: 'k6-load-test', client_seq: clientSeq++ }],
            }),
            jsonHeaders(studentToken, 'autosave'),
        );
        check(saved, { 'autosave succeeds': (response) => response.status === 200 });

        if (!lockSent && shouldLock) {
            const locked = http.post(
                `${api}/ujian/lock`,
                JSON.stringify({ pemicu: 'visibilitychange' }),
                jsonHeaders(studentToken, 'lock'),
            );
            check(locked, { 'lock succeeds': (response) => response.status === 200 });
            if (locked.status === 200) {
                const unlocked = http.post(
                    `${api}/peserta/${participantId}/unlock`,
                    JSON.stringify({ alasan: 'Simulasi uji beban' }),
                    jsonHeaders(data.operatorToken, 'unlock'),
                );
                check(unlocked, { 'unlock succeeds': (response) => response.status === 200 });
            }
            lockSent = true;
        }
    }

    const submitted = http.post(`${api}/ujian/submit`, null, jsonHeaders(studentToken, 'submit'));
    check(submitted, { 'exam submit succeeds': (response) => response.status === 200 });
}
