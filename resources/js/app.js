import './bootstrap';
import { createApp } from 'vue';
import SurveyFlowBuilder from './components/survey/SurveyFlowBuilder.vue';
import UssdFlowBuilder from './components/ussd/UssdFlowBuilder.vue';
import Toast from 'vue-toastification';
import 'vue-toastification/dist/index.css';

if (document.getElementById('survey-flow-app')) {
    const app = createApp(SurveyFlowBuilder, {
        surveyId: window.surveyId,
    });

    app.use(Toast, {
        position: 'top-right',
        timeout: 5000,
    });

    app.mount('#survey-flow-app');
}

if (document.getElementById('ussd-flow-app')) {
    const app = createApp(UssdFlowBuilder, {
        flowId: window.ussdFlowId,
    });

    app.use(Toast, {
        position: 'top-right',
        timeout: 5000,
    });

    app.mount('#ussd-flow-app');
}

// Listen for report-generation completion events on a private user channel
// so the Survey Reports page updates in real time without a manual refresh.
document.addEventListener('DOMContentLoaded', () => {
    if (! window.Echo) {
        return;
    }

    const userMeta = document.querySelector('meta[name="user-id"]');
    const userId = userMeta?.getAttribute('content');

    if (! userId) {
        return;
    }

    window.Echo.private(`App.Models.User.${userId}`)
        .notification((notification) => {
            if (notification.type === 'report.completed') {
                window.dispatchEvent(new CustomEvent('report-status-updated', {
                    detail: { id: notification.id, status: 'completed' },
                }));
            }
        });
});
