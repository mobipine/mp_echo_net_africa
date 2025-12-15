import './bootstrap';
import { createApp } from 'vue';
import SurveyFlowBuilder from './components/survey/SurveyFlowBuilder.vue';
import UssdFlowBuilder from './components/ussd/UssdFlowBuilder.vue';
import Toast from 'vue-toastification';
import 'vue-toastification/dist/index.css';

if (document.getElementById('survey-flow-app')) {

    const app = createApp(SurveyFlowBuilder, {
        surveyId: window.surveyId
    })

    app.use(Toast, {
        position: 'top-right',
        timeout: 5000,
      });


    app.mount('#survey-flow-app');

}

if (document.getElementById('ussd-flow-app')) {

    const app = createApp(UssdFlowBuilder, {
        flowId: window.ussdFlowId
    })

    app.use(Toast, {
        position: 'top-right',
        timeout: 5000,
      });


    app.mount('#ussd-flow-app');

}
