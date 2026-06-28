/**
 * API Wrapper para comunicarse con public.php
 */

class ApiService {
    constructor() {
        this.endpoint = window.location.pathname;
    }

    async send(changeType, target, action = 'list', data = {}) {
        const payload = {
            changeType,
            target,
            action,
            data
        };

        try {
            const response = await fetch(this.endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(payload)
            });

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            const json = await response.json();
            return json;
        } catch (error) {
            console.error("API Error:", error);
            return { status: 'failed', error: true, message: error.message };
        }
    }

    // Métodos de conveniencia
    async getDevices() {
        return this.send('list', 'devices');
    }

    async saveDevice(action, deviceData) {
        // action puede ser 'insert' o 'edit'
        return this.send('update', 'devices', action, deviceData);
    }

    async getPlans() { return this.send('list', 'plans'); }
    async savePlan(data) { return this.send('update', 'plans', 'edit', data); }
    
    async getConfig() { return this.send('list', 'config'); }
    async saveConfig(data) { return this.send('update', 'config', 'edit', data); }
    
    async getFields() { return this.send('list', 'messages'); }
    
    async getJobs() { return this.send('list', 'jobs'); }
    
    async getBackups() { return this.send('list', 'backups'); }
    async createBackup() { return this.send('update', 'system', 'backup'); }
    
    async rebuild() { return this.send('update', 'devices', 'cache'); }
    async clearLogs() { return this.send('update', 'system', 'log_clear'); }
}

const api = new ApiService();
