const { ApiService } = Shopware.Classes;

export default class AgentenblickApiService extends ApiService {
    constructor(httpClient, loginService) {
        super(httpClient, loginService, '_action/agentenblick', 'application/json');
    }

    getSummary(params) {
        return this.get('summary', params);
    }

    getAgents(params) {
        return this.get('agents', params);
    }

    getHealth(params) {
        return this.get('health', params);
    }

    probeHealth(body) {
        return this.httpClient.post(
            `${this.getApiBasePath()}/health/probe`, body,
            { headers: this.getBasicHeaders() },
        ).then(ApiService.handleResponse);
    }

    getPendingRanges() {
        return this.get('ranges/pending');
    }

    getJourneySummary(params) {
        return this.get('journey/summary', params);
    }

    getHeuristicSettings() {
        return this.get('heuristics/settings');
    }

    updateHeuristicSettings(body) {
        return this.httpClient.post(
            `${this.getApiBasePath()}/heuristics/settings`,
            body,
            { headers: this.getBasicHeaders() },
        ).then(ApiService.handleResponse);
    }

    getJourneySessions(params) {
        return this.get('journey/sessions', params);
    }

    getJourneySession(id, params) {
        return this.get(`journey/sessions/${encodeURIComponent(id)}`, params);
    }

    getBenchmarkStatus() {
        return this.get('benchmark/status');
    }

    getBenchmarkPreview(params = {}) {
        return this.get('benchmark/preview', params);
    }

    getBenchmarkHistory() {
        return this.get('benchmark/history');
    }

    getBenchmarkComparison() {
        return this.get('benchmark/comparison');
    }

    updateBenchmark(action, body = {}) {
        if (!['enable', 'disable', 'send'].includes(action)) {
            return Promise.reject(new Error('Invalid benchmark action'));
        }
        return this.httpClient.post(
            `${this.getApiBasePath()}/benchmark/${action}`,
            body,
            { headers: this.getBasicHeaders() },
        ).then(ApiService.handleResponse);
    }

    get(path, params = {}) {
        return this.httpClient.get(`${this.getApiBasePath()}/${path}`, {
            headers: this.getBasicHeaders(),
            params,
        }).then(ApiService.handleResponse);
    }

    approveRanges(agent, version) {
        return this.httpClient.post(
            `${this.getApiBasePath()}/ranges/${encodeURIComponent(agent)}/approve`,
            { version },
            { headers: this.getBasicHeaders() },
        ).then(ApiService.handleResponse);
    }

    exportAgents(params) {
        return this.httpClient.get(`${this.getApiBasePath()}/export`, {
            headers: this.getBasicHeaders({ Accept: 'text/csv' }),
            params,
            responseType: 'blob',
        }).then((response) => response.data);
    }
}
